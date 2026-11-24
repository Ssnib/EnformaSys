<?php
require_once __DIR__ . '/../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access_requests.php';

$user_id = (int)$_SESSION['user'];

// 1. Seminarian Record & Basic Identity
$stmt = $conn->prepare("SELECT * FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$seminarian = $stmt->get_result()->fetch_assoc() ?: [];
$seminarian_id = (int)($seminarian['id'] ?? 0);

// 2. Active Term and Filter Setup (Captures $_GET with fallback to current active term)
$current_ay = get_active_academic_year();
$current_sem = get_active_semester();

$filter_ay = isset($_GET['academic_year']) && trim((string)$_GET['academic_year']) !== '' 
    ? trim((string)$_GET['academic_year']) 
    : $current_ay;

$filter_sem_raw = isset($_GET['semester']) ? capstone_access_normalize_semester((string)$_GET['semester']) : '';
$filter_sem = in_array($filter_sem_raw, ['1st', '2nd', 'Summer'], true)
    ? $filter_sem_raw
    : capstone_access_normalize_semester((string)$current_sem);
if (!in_array($filter_sem, ['1st', '2nd', 'Summer'], true)) {
    $filter_sem = '1st';
}
$term_grade_perm = capstone_access_get_grade_view_permission($conn, $seminarian_id, $filter_ay, $filter_sem);
$can_view_grades = !empty($term_grade_perm['can_view_any']);

// Gather available Academic Years for the top header filter dropdown
$ay_options = [$current_ay];
if ($seminarian_id > 0) {
    $ay_res = $conn->query("
        (SELECT DISTINCT academic_year FROM student_enrolled_subjects WHERE student_id = {$seminarian_id} AND academic_year IS NOT NULL AND academic_year <> '')
        UNION
        (SELECT DISTINCT academic_year FROM enrollments WHERE student_id = {$seminarian_id} AND academic_year IS NOT NULL AND academic_year <> '')
        UNION
        (SELECT DISTINCT label AS academic_year FROM academic_years WHERE archived_at IS NULL AND is_active = 1)
        ORDER BY academic_year DESC
    ");
    if ($ay_res) {
        while ($r = $ay_res->fetch_assoc()) {
            $v = trim((string)($r['academic_year'] ?? ''));
            if ($v !== '' && !in_array($v, $ay_options, true)) {
                $ay_options[] = $v;
            }
        }
    }
}
rsort($ay_options);

// 3. Today's Class Schedule (Vertical Timeline) - Filtered by selected Academic Year & Semester
$saved_schedules = [];
if ($seminarian_id > 0) {
    // 1. Query schedules tied to enrolled subjects for the filtered term
    $stmt_sched = $conn->prepare("SELECT ss.subject_code, ss.subject_name, ss.days, ss.start_time, ss.end_time, ss.room 
        FROM student_schedules ss
        JOIN subjects s ON s.subject_code = ss.subject_code
        JOIN student_enrolled_subjects es ON es.subject_id = s.subject_id AND es.student_id = ss.seminarian_id
        WHERE ss.seminarian_id = ? 
          AND es.academic_year = ? 
          AND es.semester = ?
          AND es.status <> 'Dropped'
        ORDER BY ss.start_time ASC");
    if ($stmt_sched) {
        $stmt_sched->bind_param("iss", $seminarian_id, $filter_ay, $filter_sem);
        $stmt_sched->execute();
        $res_sched = $stmt_sched->get_result();
        while ($row = $res_sched->fetch_assoc()) {
            $saved_schedules[$row['subject_code']] = $row;
        }
    }

    // 2. Secondary fallback for legacy enrollments table for this term
    if (empty($saved_schedules)) {
        $stmt_sched_leg = $conn->prepare("SELECT ss.subject_code, ss.subject_name, ss.days, ss.start_time, ss.end_time, ss.room 
            FROM student_schedules ss
            JOIN subjects s ON s.subject_code = ss.subject_code
            JOIN enrollment_subjects es ON es.subject_id = s.subject_id
            JOIN enrollments e ON e.id = es.enrollment_id AND e.student_id = ss.seminarian_id
            WHERE ss.seminarian_id = ? 
              AND e.academic_year = ? 
              AND e.semester = ?
              AND es.subject_status <> 'Dropped'
            ORDER BY ss.start_time ASC");
        if ($stmt_sched_leg) {
            $stmt_sched_leg->bind_param("iss", $seminarian_id, $filter_ay, $filter_sem);
            $stmt_sched_leg->execute();
            $res_sched_leg = $stmt_sched_leg->get_result();
            while ($row = $res_sched_leg->fetch_assoc()) {
                $saved_schedules[$row['subject_code']] = $row;
            }
        }
    }
}

// 4. Enrolled Subjects Query (Filtered by Term + Assigned Subject Teacher)
$enrolled_subjects = [];
if ($seminarian_id > 0) {
    $stmt_sub = $conn->prepare("SELECT es.id AS enrollment_subject_id, es.subject_id, es.academic_year, es.semester, es.status AS enrollment_status,
            s.subject_code, s.subject_name, s.units,
            COALESCE(NULLIF(TRIM(sta.teacher_name_override),''), NULLIF(TRIM(up_teacher.full_name),''), NULLIF(TRIM(u_teacher.username),''), 'TBA / Faculty') AS instructor
        FROM student_enrolled_subjects es 
        JOIN subjects s ON s.subject_id = es.subject_id 
        LEFT JOIN subject_teacher_assignments sta ON (
            sta.subject_id = es.subject_id 
            AND sta.academic_year = es.academic_year 
            AND sta.semester = es.semester
        )
        LEFT JOIN users u_teacher ON u_teacher.id = sta.teacher_user_id
        LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = sta.teacher_user_id
        WHERE es.student_id = ? 
          AND es.academic_year = ? 
          AND es.semester = ?
          AND es.status <> 'Dropped'
          AND s.archived_at IS NULL
        ORDER BY s.subject_code ASC");
    if ($stmt_sub) {
        $stmt_sub->bind_param("iss", $seminarian_id, $filter_ay, $filter_sem);
        $stmt_sub->execute();
        $res_sub = $stmt_sub->get_result();
        $dummy_progress = [75, 88, 62, 90, 80, 70];
        $idx = 0;
        while ($r = $res_sub->fetch_assoc()) {
            $r['progress'] = $dummy_progress[$idx % count($dummy_progress)];
            $code = $r['subject_code'] ?? '';
            if (isset($saved_schedules[$code])) {
                $r['raw_days'] = $saved_schedules[$code]['days'] ?? 'MWF';
                $r['raw_start'] = $saved_schedules[$code]['start_time'] ?? '08:00 AM';
                $r['raw_end'] = $saved_schedules[$code]['end_time'] ?? '09:30 AM';
                $r['raw_room'] = $saved_schedules[$code]['room'] ?? 'St. Thomas Hall 201';
                $r['time'] = ($saved_schedules[$code]['days'] ?? 'MWF') . ' ' . ($saved_schedules[$code]['start_time'] ?? '08:00 AM') . ' - ' . ($saved_schedules[$code]['end_time'] ?? '09:30 AM');
                $r['room'] = $saved_schedules[$code]['room'] ?? 'St. Thomas Hall 201';
            } else {
                $r['raw_days'] = 'MWF';
                $r['raw_start'] = '08:00 AM';
                $r['raw_end'] = '09:30 AM';
                $r['raw_room'] = 'St. Thomas Hall 201';
                $r['time'] = 'MWF 08:00 AM - 09:30 AM';
                $r['room'] = 'St. Thomas Hall 201';
            }
            $enrolled_subjects[] = $r;
            $idx++;
        }
    }

    // Secondary fallback to legacy enrollments & enrollment_subjects if student_enrolled_subjects has no records
    if (empty($enrolled_subjects)) {
        $stmt_leg = $conn->prepare("SELECT es.id AS enrollment_subject_id, es.subject_id, e.academic_year, e.semester, es.subject_status AS enrollment_status,
                s.subject_code, s.subject_name, es.units,
                COALESCE(NULLIF(TRIM(sta.teacher_name_override),''), NULLIF(TRIM(up_teacher.full_name),''), NULLIF(TRIM(u_teacher.username),''), 'TBA / Faculty') AS instructor
            FROM enrollments e
            JOIN enrollment_subjects es ON es.enrollment_id = e.id
            JOIN subjects s ON s.subject_id = es.subject_id
            LEFT JOIN subject_teacher_assignments sta ON (
                sta.subject_id = es.subject_id
                AND sta.academic_year = e.academic_year
                AND sta.semester = e.semester
            )
            LEFT JOIN users u_teacher ON u_teacher.id = sta.teacher_user_id
            LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = sta.teacher_user_id
            WHERE e.student_id = ?
              AND e.academic_year = ?
              AND e.semester = ?
              AND es.subject_status <> 'Dropped'
              AND s.archived_at IS NULL
            ORDER BY s.subject_code ASC");
        if ($stmt_leg) {
            $stmt_leg->bind_param("iss", $seminarian_id, $filter_ay, $filter_sem);
            $stmt_leg->execute();
            $res_leg = $stmt_leg->get_result();
            $dummy_progress = [75, 88, 62, 90, 80, 70];
            $idx = 0;
            while ($r = $res_leg->fetch_assoc()) {
                $r['progress'] = $dummy_progress[$idx % count($dummy_progress)];
                $code = $r['subject_code'] ?? '';
                if (isset($saved_schedules[$code])) {
                    $r['raw_days'] = $saved_schedules[$code]['days'] ?? 'MWF';
                    $r['raw_start'] = $saved_schedules[$code]['start_time'] ?? '08:00 AM';
                    $r['raw_end'] = $saved_schedules[$code]['end_time'] ?? '09:30 AM';
                    $r['raw_room'] = $saved_schedules[$code]['room'] ?? 'St. Thomas Hall 201';
                    $r['time'] = ($saved_schedules[$code]['days'] ?? 'MWF') . ' ' . ($saved_schedules[$code]['start_time'] ?? '08:00 AM') . ' - ' . ($saved_schedules[$code]['end_time'] ?? '09:30 AM');
                    $r['room'] = $saved_schedules[$code]['room'] ?? 'St. Thomas Hall 201';
                } else {
                    $r['raw_days'] = 'MWF';
                    $r['raw_start'] = '08:00 AM';
                    $r['raw_end'] = '09:30 AM';
                    $r['raw_room'] = 'St. Thomas Hall 201';
                    $r['time'] = 'MWF 08:00 AM - 09:30 AM';
                    $r['room'] = 'St. Thomas Hall 201';
                }
                $enrolled_subjects[] = $r;
                $idx++;
            }
        }
    }
}

$total_enrolled_units = !empty($enrolled_subjects) ? array_sum(array_column($enrolled_subjects, 'units')) : 0.0;

$today_schedule = [];
if (!empty($saved_schedules)) {
    foreach ($saved_schedules as $s) {
        $today_schedule[] = [
            'time' => $s['start_time'] . ' - ' . $s['end_time'],
            'code' => $s['subject_code'],
            'subject' => $s['subject_name'],
            'room' => $s['room'],
            'days' => $s['days'],
            'status' => 'Scheduled'
        ];
    }
} elseif (!empty($enrolled_subjects)) {
    // Generate initial timetable entries matching the officially enrolled subjects for this term
    $slots = [
        ['08:00 AM - 09:30 AM', 'MWF', 'St. Thomas Hall 201'],
        ['10:00 AM - 11:30 AM', 'TTH', 'Sacred Heart Pavilion'],
        ['01:30 PM - 03:00 PM', 'MWF', 'Language Lab Room 4'],
        ['03:30 PM - 05:00 PM', 'TTH', 'St. Peter Lecture Hall']
    ];
    $s_idx = 0;
    foreach ($enrolled_subjects as $es) {
        $slot = $slots[$s_idx % count($slots)];
        $today_schedule[] = [
            'time' => $slot[0],
            'code' => $es['subject_code'],
            'subject' => $es['subject_name'],
            'room' => $slot[2],
            'days' => $slot[1],
            'status' => $s_idx === 0 ? 'Ongoing' : 'Upcoming'
        ];
        $s_idx++;
    }
} else {
    $today_schedule = [];
}

$pageTitle = 'My Class';
$activeNav = 'my_class';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>

<div class="space-y-6">
    <!-- Page Header & Overview Stats -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-1">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-black text-[#59443F] tracking-tight flex items-center gap-3">
                <i class="fa-solid fa-chalkboard-user text-[#D6A6A6]"></i>
                <span>My Class</span>
            </h1>
            <p class="text-xs sm:text-sm font-medium text-[#8B7168] mt-1">
                View your officially enrolled subjects, subject teachers, and daily class schedules.
            </p>
        </div>

        <!-- Quick Summary Pills -->
        <div class="flex flex-wrap items-center gap-2 self-start md:self-auto">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-2xl text-xs font-bold bg-white text-[#59443F] border border-[#E8C7C5]/60 shadow-xs">
                <i class="fa-solid fa-book-open text-[#D6A6A6]"></i>
                <span><?php echo count($enrolled_subjects); ?> Subjects</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-2xl text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 shadow-xs">
                <i class="fa-solid fa-layer-group text-[#D6A6A6]"></i>
                <span><?php echo number_format($total_enrolled_units, 1); ?> Total Units</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-2xl text-xs font-bold bg-white text-[#59443F] border border-[#E8C7C5]/60 shadow-xs">
                <i class="fa-regular fa-calendar-check text-[#D6A6A6]"></i>
                <span><?php echo count($today_schedule); ?> Timetable Items</span>
            </span>
        </div>
    </div>

    <!-- MAIN CONTAINER (Enrolled Subjects - Full Width) -->
    <div class="w-full space-y-4">

        <!-- Subject Cards Container (Horizontal Modern Soft UI Cards) -->
        <?php if (empty($enrolled_subjects)): ?>
            <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-10 text-center shadow-soft">
                <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-[#F8EEEE] flex items-center justify-center text-[#D6A6A6]">
                    <i class="fa-regular fa-folder-open text-2xl"></i>
                </div>
                <h4 class="font-heading font-bold text-base text-[#59443F] mb-1">No enrolled subjects found for this term.</h4>
                <p class="text-xs text-[#8B7168] max-w-sm mx-auto">
                    No subject enrollment records found for Academic Year <span class="font-bold text-[#59443F]"><?php echo htmlspecialchars($filter_ay); ?></span>, <span class="font-bold text-[#59443F]"><?php echo htmlspecialchars($filter_sem); ?> Semester</span>.
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4">
                <?php foreach ($enrolled_subjects as $subj): 
                    $code = $subj['subject_code'] ?? 'SUBJ';
                    $title = $subj['subject_name'] ?? 'Subject Description';
                    $units = (float)($subj['units'] ?? 3.0);
                    $progress = (int)($subj['progress'] ?? 75);
                    $time = $subj['time'] ?? 'MWF 08:00 AM - 09:30 AM';
                    $room = $subj['room'] ?? 'St. Thomas Hall 201';
                    $instructor = $subj['instructor'] ?? 'TBA / Faculty';
                    $raw_days = $subj['raw_days'] ?? 'MWF';
                    $raw_start = $subj['raw_start'] ?? '08:00 AM';
                    $raw_end = $subj['raw_end'] ?? '09:30 AM';
                    $raw_room = $subj['raw_room'] ?? 'St. Thomas Hall 201';
                ?>
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-soft hover:shadow-soft-lg hover:-translate-y-0.5 transition-all duration-300" data-card-code="<?php echo htmlspecialchars($code); ?>">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                        <!-- Subject Details Left Section -->
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="px-3 py-1 rounded-xl text-xs font-black bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70">
                                    <?php echo htmlspecialchars($code); ?>
                                </span>
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-[#8B7168] bg-white px-2.5 py-1 rounded-xl border border-[#E8C7C5]/40">
                                    <i class="fa-solid fa-layer-group text-[10px] text-[#D6A6A6]"></i>
                                    <span><?php echo number_format($units, 1); ?> Units</span>
                                </span>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-800 bg-emerald-50 px-2.5 py-0.5 rounded-lg border border-emerald-200/60">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Officially Enrolled
                                </span>
                            </div>

                            <h4 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight">
                                <?php echo htmlspecialchars($title); ?>
                            </h4>

                            <!-- Dedicated Subject Teacher / Instructor line/badge -->
                            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-semibold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 shadow-xs">
                                    <i class="fa-solid fa-chalkboard-user text-[#D6A6A6] text-xs"></i>
                                    <span>Teacher: <strong class="text-[#59443F] font-bold"><?php echo htmlspecialchars($instructor); ?></strong></span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-medium text-[#8B7168] bg-white border border-[#E8C7C5]/40">
                                    <i class="fa-solid fa-location-dot text-[#D6A6A6] text-[11px]"></i>
                                    <span class="subject-room-label"><?php echo htmlspecialchars($room); ?></span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-medium text-[#8B7168] bg-white border border-[#E8C7C5]/40">
                                    <i class="fa-regular fa-clock text-[#D6A6A6] text-[11px]"></i>
                                    <span class="subject-time-label"><?php echo htmlspecialchars($time); ?></span>
                                </span>
                            </div>
                        </div>

                        <!-- Progress Bar & Action Right Section -->
                        <div class="lg:w-80 flex flex-col justify-between pt-3 lg:pt-0 border-t lg:border-t-0 border-[#E8C7C5]/30 shrink-0">
                            <div class="mb-2.5 flex items-center justify-between text-xs">
                                <span class="text-[#8B7168] font-bold">Subject Progress</span>
                                <span class="font-extrabold text-[#59443F]"><?php echo $progress; ?>%</span>
                            </div>
                            <!-- Progress Bar Styled with Rose & Dusty Pink -->
                            <div class="w-full h-2.5 rounded-full bg-[#F8EEEE] overflow-hidden p-0.5 border border-[#E8C7C5]/40 mb-3">
                                <div class="h-full rounded-full bg-gradient-to-r from-[#D6A6A6] to-[#E8C7C5] transition-all duration-700" style="width: <?php echo $progress; ?>%;"></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="student_academic.php?academic_year=<?php echo urlencode($filter_ay); ?>&semester=<?php echo urlencode($filter_sem); ?>" class="flex-1 text-center py-2 px-3 rounded-xl text-xs font-bold bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] transition-all border border-[#E8C7C5]/60 shadow-2xs">
                                    Subject Details
                                </a>
                                <button type="button" 
                                        class="open-edit-subject-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-[#E8C7C5] hover:bg-[#D6A6A6] text-[#59443F] border border-[#D6A6A6]/60 transition-all shadow-2xs flex items-center justify-center gap-1.5 cursor-pointer shrink-0"
                                        data-subject-id="<?php echo (int)($subj['subject_id'] ?? 0); ?>"
                                        data-subject-code="<?php echo htmlspecialchars($code); ?>"
                                        data-subject-name="<?php echo htmlspecialchars($title); ?>"
                                        data-days="<?php echo htmlspecialchars($raw_days); ?>"
                                        data-start="<?php echo htmlspecialchars($raw_start); ?>"
                                        data-end="<?php echo htmlspecialchars($raw_end); ?>"
                                        data-room="<?php echo htmlspecialchars($raw_room); ?>"
                                        title="Edit Schedule for <?php echo htmlspecialchars($code); ?>">
                                    <i class="fa-solid fa-calendar-pen text-xs"></i>
                                    <span>Edit Schedule</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- EDIT SCHEDULE MODAL (Single-Subject Customizer)                           -->
<!-- ========================================================================= -->
<div id="editScheduleModal" data-global-modal="1" class="<?php echo htmlspecialchars(global_modal_overlay_classes('transition-opacity hidden'), ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true" role="dialog" aria-labelledby="editScheduleModalTitle">
    <div class="relative w-full max-w-lg max-h-[90vh] overflow-y-auto bg-white rounded-xl border border-[#E8C7C5] shadow-2xl p-6 sm:p-7 flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between pb-4 border-b border-[#E8C7C5]/40">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center border border-[#E8C7C5]/60 shadow-xs">
                    <i class="fa-solid fa-calendar-days text-[#D6A6A6]"></i>
                </div>
                <div>
                    <h3 id="editScheduleModalTitle" class="font-heading font-extrabold text-base text-[#59443F] tracking-tight">
                        Edit Subject Schedule
                    </h3>
                    <p class="text-xs text-[#8B7168] font-medium">Customize days, timing, and classroom for this subject</p>
                </div>
            </div>
            <button id="closeEditScheduleBtn" type="button" class="w-8 h-8 rounded-full bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center transition cursor-pointer" aria-label="Close modal">
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        </div>

        <!-- Modal Form Body -->
        <form id="editScheduleForm" class="flex flex-col flex-1 mt-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" id="modalSubjectId" name="subject_id" value="">
            <input type="hidden" id="modalSubjectCode" name="schedules[0][subject_code]" value="">
            <input type="hidden" id="modalSubjectName" name="schedules[0][subject_name]" value="">

            <div class="space-y-4">
                <!-- Subject Badge & Title Header in Modal -->
                <div class="p-3.5 bg-[#F8EEEE]/70 rounded-2xl border border-[#E8C7C5]/50 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span id="modalDisplayCode" class="text-xs font-black px-2.5 py-1 rounded-xl bg-white text-[#59443F] border border-[#E8C7C5] shrink-0">
                            SUBJ
                        </span>
                        <h4 id="modalDisplayName" class="font-heading font-bold text-xs sm:text-sm text-[#59443F] truncate">
                            Subject Description
                        </h4>
                    </div>
                </div>

                <!-- Input Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 text-xs">
                    <!-- Day(s) Input -->
                    <div>
                        <label for="modalDays" class="block text-[10px] font-bold text-[#8B7168] uppercase tracking-wider mb-1">
                            Lecture Day(s)
                        </label>
                        <input type="text" id="modalDays" name="schedules[0][days]" placeholder="e.g. MWF, TTH, Mon" class="w-full px-3.5 py-2 rounded-xl border border-[#E8C7C5] bg-[#F8EEEE]/30 text-[#59443F] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                    </div>

                    <!-- Room Input -->
                    <div>
                        <label for="modalRoom" class="block text-[10px] font-bold text-[#8B7168] uppercase tracking-wider mb-1">
                            Room / Hall
                        </label>
                        <input type="text" id="modalRoom" name="schedules[0][room]" placeholder="e.g. St. Thomas Hall 201" class="w-full px-3.5 py-2 rounded-xl border border-[#E8C7C5] bg-[#F8EEEE]/30 text-[#59443F] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                    </div>

                    <!-- Start Time Input -->
                    <div>
                        <label for="modalStartTime" class="block text-[10px] font-bold text-[#8B7168] uppercase tracking-wider mb-1">
                            Start Time
                        </label>
                        <input type="text" id="modalStartTime" name="schedules[0][start_time]" placeholder="e.g. 08:00 AM" class="w-full px-3.5 py-2 rounded-xl border border-[#E8C7C5] bg-[#F8EEEE]/30 text-[#59443F] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                    </div>

                    <!-- End Time Input -->
                    <div>
                        <label for="modalEndTime" class="block text-[10px] font-bold text-[#8B7168] uppercase tracking-wider mb-1">
                            End Time
                        </label>
                        <input type="text" id="modalEndTime" name="schedules[0][end_time]" placeholder="e.g. 09:30 AM" class="w-full px-3.5 py-2 rounded-xl border border-[#E8C7C5] bg-[#F8EEEE]/30 text-[#59443F] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="mt-6 pt-4 border-t border-[#E8C7C5]/40 flex items-center justify-between gap-3">
                <span id="scheduleSaveStatus" class="text-xs font-semibold text-[#8B7168]"></span>
                <div class="flex items-center gap-2">
                    <button id="cancelScheduleBtn" type="button" class="px-4 py-2 rounded-xl text-xs font-bold text-[#8B7168] hover:bg-[#F8EEEE] transition cursor-pointer">
                        Cancel
                    </button>
                    <button id="saveScheduleBtn" type="submit" class="px-5 py-2 rounded-xl text-xs font-bold bg-[#E8C7C5] hover:bg-[#D6A6A6] text-[#59443F] border border-[#D6A6A6]/60 shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-floppy-disk text-xs"></i>
                        <span>Save Schedule</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Utility: HTML Escape
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Modal elements
    const editScheduleModal = document.getElementById('editScheduleModal');
    const closeEditScheduleBtn = document.getElementById('closeEditScheduleBtn');
    const cancelScheduleBtn = document.getElementById('cancelScheduleBtn');
    const editScheduleForm = document.getElementById('editScheduleForm');
    const scheduleSaveStatus = document.getElementById('scheduleSaveStatus');

    const modalSubjectId = document.getElementById('modalSubjectId');
    const modalSubjectCode = document.getElementById('modalSubjectCode');
    const modalSubjectName = document.getElementById('modalSubjectName');
    const modalDisplayCode = document.getElementById('modalDisplayCode');
    const modalDisplayName = document.getElementById('modalDisplayName');
    const modalDays = document.getElementById('modalDays');
    const modalRoom = document.getElementById('modalRoom');
    const modalStartTime = document.getElementById('modalStartTime');
    const modalEndTime = document.getElementById('modalEndTime');
    const editScheduleModalTitle = document.getElementById('editScheduleModalTitle');

    let activeTriggerBtn = null;

    function openScheduleModal(btn) {
        if (!editScheduleModal) return;
        activeTriggerBtn = btn;

        const subId = btn.dataset.subjectId || '';
        const subCode = btn.dataset.subjectCode || '';
        const subName = btn.dataset.subjectName || '';
        const days = btn.dataset.days || 'MWF';
        const start = btn.dataset.start || '08:00 AM';
        const end = btn.dataset.end || '09:30 AM';
        const room = btn.dataset.room || 'St. Thomas Hall 201';

        if (modalSubjectId) modalSubjectId.value = subId;
        if (modalSubjectCode) modalSubjectCode.value = subCode;
        if (modalSubjectName) modalSubjectName.value = subName;
        if (modalDisplayCode) modalDisplayCode.textContent = subCode;
        if (modalDisplayName) modalDisplayName.textContent = subName;
        if (modalDays) modalDays.value = days;
        if (modalRoom) modalRoom.value = room;
        if (modalStartTime) modalStartTime.value = start;
        if (modalEndTime) modalEndTime.value = end;
        if (editScheduleModalTitle) editScheduleModalTitle.textContent = 'Edit Schedule: ' + subCode;
        if (scheduleSaveStatus) scheduleSaveStatus.textContent = '';

        editScheduleModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeScheduleModal() {
        if (!editScheduleModal) return;
        editScheduleModal.classList.add('hidden');
        document.body.style.overflow = '';
        activeTriggerBtn = null;
    }

    // Attach click listeners to all Edit Schedule buttons in subject cards
    document.querySelectorAll('.open-edit-subject-btn').forEach(btn => {
        btn.addEventListener('click', () => openScheduleModal(btn));
    });

    if (closeEditScheduleBtn) closeEditScheduleBtn.addEventListener('click', closeScheduleModal);
    if (cancelScheduleBtn) cancelScheduleBtn.addEventListener('click', closeScheduleModal);

    if (editScheduleModal) {
        editScheduleModal.addEventListener('click', (e) => {
            if (e.target === editScheduleModal) {
                closeScheduleModal();
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && editScheduleModal && !editScheduleModal.classList.contains('hidden')) {
            closeScheduleModal();
        }
    });

    if (editScheduleForm) {
        editScheduleForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const saveBtn = document.getElementById('saveScheduleBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-70');
            }
            if (scheduleSaveStatus) {
                scheduleSaveStatus.textContent = 'Saving schedule...';
                scheduleSaveStatus.className = 'text-xs font-semibold text-[#8B7168]';
            }

            try {
                const formData = new FormData(editScheduleForm);
                const res = await fetch('ajax.php?action=save_student_schedule', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data && data.ok) {
                    if (scheduleSaveStatus) {
                        scheduleSaveStatus.textContent = 'Schedule saved successfully!';
                        scheduleSaveStatus.className = 'text-xs font-semibold text-emerald-700';
                    }

                    // Dynamically update the specific subject card in the DOM
                    const updatedCode = modalSubjectCode ? modalSubjectCode.value : '';
                    const newDays = modalDays ? modalDays.value : 'MWF';
                    const newStart = modalStartTime ? modalStartTime.value : '08:00 AM';
                    const newEnd = modalEndTime ? modalEndTime.value : '09:30 AM';
                    const newRoom = modalRoom ? modalRoom.value : 'St. Thomas Hall 201';
                    const newTime = `${newDays} ${newStart} - ${newEnd}`;

                    const card = document.querySelector(`[data-card-code="${updatedCode}"]`);
                    if (card) {
                        const roomLabel = card.querySelector('.subject-room-label');
                        if (roomLabel) roomLabel.textContent = newRoom;
                        const timeLabel = card.querySelector('.subject-time-label');
                        if (timeLabel) timeLabel.textContent = newTime;
                    }

                    if (activeTriggerBtn) {
                        activeTriggerBtn.dataset.days = newDays;
                        activeTriggerBtn.dataset.start = newStart;
                        activeTriggerBtn.dataset.end = newEnd;
                        activeTriggerBtn.dataset.room = newRoom;
                    }

                    setTimeout(() => {
                        closeScheduleModal();
                    }, 600);
                } else {
                    if (scheduleSaveStatus) {
                        scheduleSaveStatus.textContent = data.error || 'Failed to save schedule.';
                        scheduleSaveStatus.className = 'text-xs font-semibold text-rose-700';
                    }
                }
            } catch (err) {
                console.error('Error saving schedule:', err);
                if (scheduleSaveStatus) {
                    scheduleSaveStatus.textContent = 'Network or server error.';
                    scheduleSaveStatus.className = 'text-xs font-semibold text-rose-700';
                }
            } finally {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('opacity-70');
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>

