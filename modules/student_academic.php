<?php
require_once __DIR__ . '/../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access_requests.php';
require_once __DIR__ . '/../includes/academic_module.php';

$user_id = (int)$_SESSION['user'];
$stmt_sem = $conn->prepare("SELECT id, academic_view_enabled, enrollment_status FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt_sem->bind_param('i', $user_id);
$stmt_sem->execute();
$sem = $stmt_sem->get_result()->fetch_assoc();
if (!$sem) {
    header('Location: ../index.php');
    exit;
}
$student_id = (int)$sem['id'];

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester_raw = isset($_GET['semester']) ? (string)$_GET['semester'] : '';
$filter_semester = capstone_access_normalize_semester($filter_semester_raw);
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) {
    $activeSemRaw = function_exists('get_active_semester') ? (string)get_active_semester() : '1st';
    $filter_semester = capstone_access_normalize_semester($activeSemRaw);
    if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) {
        $filter_semester = '1st';
    }
}

// If submitting a term-scoped access request, preserve requested term across POST
if (isset($_POST['request_access'])) {
    $post_ay = trim((string)($_POST['academic_year'] ?? ''));
    $post_sem = capstone_access_normalize_semester((string)($_POST['semester'] ?? ''));
    if ($post_ay !== '') {
        $filter_academic_year = $post_ay;
    }
    if (in_array($post_sem, ['1st','2nd','Summer'], true)) {
        $filter_semester = $post_sem;
    }
}

$term_perm = capstone_access_get_grade_view_permission($conn, $student_id, $filter_academic_year, $filter_semester);
$is_whole_term_unlocked = !empty($term_perm['is_whole_semester']);
$approved_subject_ids = $term_perm['approved_subject_ids'] ?? [];
$can_view_grades = !empty($term_perm['can_view_any']);
$view_enabled = $can_view_grades;
$approved_terms_map = capstone_access_get_approved_grade_terms($conn, $student_id);

// Mark approved academic requests as read for this term when unlocked
if ($student_id > 0 && $can_view_grades) {
    $sem_vars = capstone_access_semester_variants($filter_semester);
    if (!empty($sem_vars)) {
        $ph_mark = implode(',', array_fill(0, count($sem_vars), '?'));
        $types_mark = 'is' . str_repeat('s', count($sem_vars));
        $stmt_mark = @$conn->prepare("UPDATE seminarian_access_requests SET is_read = 1 WHERE seminarian_id = ? AND assignment_type = 'Academic' AND status = 'Approved' AND is_read = 0 AND TRIM(COALESCE(academic_year, '')) = ? AND semester IN ($ph_mark)");
        if ($stmt_mark) {
            $params_mark = array_merge([$student_id, $filter_academic_year], $sem_vars);
            $stmt_mark->bind_param($types_mark, ...$params_mark);
            $stmt_mark->execute();
            $stmt_mark->close();
        }
    }
}

$ay_options = [$current_ay];
$stmt_ay = $conn->prepare("SELECT DISTINCT academic_year FROM student_grades WHERE student_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
if ($stmt_ay) {
    $stmt_ay->bind_param('i', $student_id);
    if ($stmt_ay->execute()) {
        $ay_res = $stmt_ay->get_result();
        while ($ay_res && ($r = $ay_res->fetch_assoc())) {
            $v = trim((string)($r['academic_year'] ?? ''));
            if ($v !== '' && !in_array($v, $ay_options, true)) {
                $ay_options[] = $v;
            }
        }
    }
    $stmt_ay->close();
}

// Fetch term subjects for selection & display
$term_subjects = [];
$stmt_ts = $conn->prepare("SELECT sub.subject_id, sub.subject_code, sub.subject_name, sub.units
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    WHERE sg.student_id = ? AND sg.academic_year = ? AND sg.semester = ?
    ORDER BY sub.subject_code ASC");
if ($stmt_ts) {
    $stmt_ts->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
    $stmt_ts->execute();
    $r_ts = $stmt_ts->get_result();
    while ($sb = $r_ts->fetch_assoc()) {
        $term_subjects[(int)$sb['subject_id']] = $sb;
    }
}
if (empty($term_subjects)) {
    $stmt_enr_sub = $conn->prepare("SELECT sub.subject_id, sub.subject_code, sub.subject_name, es.units
        FROM enrollments e
        JOIN enrollment_subjects es ON es.enrollment_id = e.id
        JOIN subjects sub ON sub.subject_id = es.subject_id
        WHERE e.student_id = ? AND e.academic_year = ? AND e.semester = ?
        ORDER BY sub.subject_code ASC");
    if ($stmt_enr_sub) {
        $stmt_enr_sub->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
        $stmt_enr_sub->execute();
        $r_enr_sub = $stmt_enr_sub->get_result();
        while ($sb = $r_enr_sub->fetch_assoc()) {
            $term_subjects[(int)$sb['subject_id']] = $sb;
        }
    }
}

$request_error = null;
$request_success = null;
$pending_request = null;
if (!$is_whole_term_unlocked) {
    if (isset($_POST['request_access'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed.');
        }
        $request_type = trim((string)($_POST['request_type'] ?? 'whole'));
        if ($request_type !== 'selected') {
            $request_type = 'whole';
        }

        $chosen_subjects = null;
        if ($request_type === 'selected') {
            $raw_ids = (array)($_POST['subject_ids'] ?? []);
            $selected_ids = array_values(array_filter(array_map('intval', $raw_ids), fn($id) => $id > 0));
            if (empty($selected_ids)) {
                $request_error = 'Please select at least one subject to request, or choose "Whole Semester".';
            } else {
                $chosen_subjects = [];
                foreach ($selected_ids as $sid) {
                    if (isset($term_subjects[$sid])) {
                        $chosen_subjects[] = [
                            'subject_id' => (int)$term_subjects[$sid]['subject_id'],
                            'subject_code' => (string)$term_subjects[$sid]['subject_code'],
                            'subject_name' => (string)$term_subjects[$sid]['subject_name'],
                            'units' => (float)$term_subjects[$sid]['units']
                        ];
                    }
                }
                if (empty($chosen_subjects)) {
                    $in_ph = implode(',', array_fill(0, count($selected_ids), '?'));
                    $types = str_repeat('i', count($selected_ids));
                    $stmt_s = $conn->prepare("SELECT subject_id, subject_code, subject_name, units FROM subjects WHERE subject_id IN ($in_ph)");
                    if ($stmt_s) {
                        $stmt_s->bind_param($types, ...$selected_ids);
                        $stmt_s->execute();
                        $res_s = $stmt_s->get_result();
                        while ($row = $res_s->fetch_assoc()) {
                            $chosen_subjects[] = [
                                'subject_id' => (int)$row['subject_id'],
                                'subject_code' => (string)$row['subject_code'],
                                'subject_name' => (string)$row['subject_name'],
                                'units' => (float)$row['units']
                            ];
                        }
                    }
                }
            }
        }

        if (!$request_error) {
            $message = trim((string)($_POST['request_message'] ?? ''));
            $result = capstone_access_request_create(
                $conn,
                $student_id,
                'Academic',
                $user_id,
                $message,
                $request_type,
                $chosen_subjects,
                $filter_academic_year,
                $filter_semester
            );
            if ($result['ok']) {
                $request_success = "Grade request for AY {$filter_academic_year} ({$filter_semester} Semester) submitted successfully. Please wait for review and approval.";
            } else {
                $request_error = (string)($result['error'] ?? 'Failed to submit request.');
            }
        }
    }
    $pending_request = capstone_access_request_get_pending($conn, $student_id, 'Academic', $filter_academic_year, $filter_semester);
}

// Fetch all student_grades across terms to compute chronological Term Average & Cumulative Average history
$all_term_buckets = [];
$stmt_hist = $conn->prepare("SELECT sg.academic_year, sg.semester, sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status, sg.remarks
    FROM student_grades sg
    WHERE sg.student_id = ?
    ORDER BY sg.academic_year ASC, FIELD(sg.semester, '1st', '2nd', 'Summer') ASC");
$stmt_hist->bind_param('i', $student_id);
$stmt_hist->execute();
$res_hist = $stmt_hist->get_result();
$running_rows = [];
$term_history_rows = [];
while ($res_hist && ($hr = $res_hist->fetch_assoc())) {
    $tk = trim((string)($hr['academic_year'] ?? '')) . '|' . trim((string)($hr['semester'] ?? ''));
    if (!isset($all_term_buckets[$tk])) {
        $all_term_buckets[$tk] = [
            'academic_year' => trim((string)($hr['academic_year'] ?? '')),
            'semester' => trim((string)($hr['semester'] ?? '')),
            'rows' => [],
        ];
    }
    $all_term_buckets[$tk]['rows'][] = $hr;
}
foreach ($all_term_buckets as $tb) {
    $running_rows = array_merge($running_rows, $tb['rows']);
    $term_avg_val = afms_compute_simple_final_average($tb['rows']);
    $cum_avg_val = afms_compute_simple_final_average($running_rows);
    $perf_cat = $term_avg_val !== null ? afms_performance_category($term_avg_val) : null;
    $term_history_rows[] = [
        'academic_year' => $tb['academic_year'],
        'semester' => $tb['semester'],
        'term_average' => $term_avg_val,
        'cumulative_average' => $cum_avg_val,
        'performance_category' => $perf_cat,
    ];
}
$term_history_rows = array_reverse($term_history_rows);

// Fetch enrolled subjects & grades for the selected term
$stmt_grades = $conn->prepare("SELECT sg.subject_id, sub.subject_code, sub.subject_name, sub.units, sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status, sg.remarks,
        COALESCE(NULLIF(TRIM(sta.teacher_name_override),''), NULLIF(TRIM(up_teacher.full_name),''), NULLIF(TRIM(u_teacher.username),''), 'TBA / Faculty') AS instructor
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    LEFT JOIN subject_teacher_assignments sta ON (
        sta.subject_id = sg.subject_id
        AND sta.academic_year = sg.academic_year
        AND sta.semester = sg.semester
    )
    LEFT JOIN users u_teacher ON u_teacher.id = sta.teacher_user_id
    LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = sta.teacher_user_id
    WHERE sg.student_id = ? AND sg.academic_year = ? AND sg.semester = ?
    ORDER BY sub.subject_code ASC");
$stmt_grades->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_grades->execute();
$res_grades = $stmt_grades->get_result();
$grade_rows = [];
while ($res_grades && ($gr = $res_grades->fetch_assoc())) {
    $is_drop = (strtolower(trim((string)($gr['status'] ?? ''))) === 'dropped') || (strtolower(trim((string)($gr['remarks'] ?? ''))) === 'dropped');
    $single_g = afms_resolve_row_single_final_grade($gr);
    $gr['_eval'] = afms_evaluate_single_final_grade($single_g, $is_drop);
    $grade_rows[] = $gr;
}
$term_simple_average = afms_compute_simple_final_average($grade_rows);

// Resolve active enrollment status for selected Academic Year & Semester
$term_enrollment_status = trim((string)($sem['enrollment_status'] ?? ''));
$stmt_term_enr = $conn->prepare("SELECT enrollment_status FROM enrollments WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
if ($stmt_term_enr) {
    $stmt_term_enr->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
    $stmt_term_enr->execute();
    $term_enr_row = $stmt_term_enr->get_result()->fetch_assoc();
    if ($term_enr_row && !empty($term_enr_row['enrollment_status'])) {
        $term_enrollment_status = (string)$term_enr_row['enrollment_status'];
    }
}
if ($term_enrollment_status === '') {
    $term_enrollment_status = 'Pending';
}
?>
<?php
$pageTitle = 'Grades';
$activeNav = 'my_academic';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-1">
                <div>
                    <h1 class="font-heading text-2xl sm:text-3xl font-black text-[#59443F] tracking-tight flex items-center gap-3 flex-wrap">
                        <i class="fa-solid fa-graduation-cap text-[#D6A6A6]"></i>
                        <span>My Enrolled Subjects &amp; Grades</span>
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $term_enrollment_status === 'Enrolled' ? 'bg-green-100 text-green-700' : ($term_enrollment_status === 'Pending' || $term_enrollment_status === 'Partially Paid' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700'); ?>">
                            <?php echo htmlspecialchars($term_enrollment_status); ?>
                        </span>
                    </h1>
                    <p class="text-xs sm:text-sm font-medium text-[#8B7168] mt-1">
                        View term subject enrollments, final grades, and academic performance records.
                    </p>
                </div>
            </div>

            <?php if (!$is_whole_term_unlocked): ?>
                <!-- Locked State Banner & Request Action -->
                <div id="grade-request-form" class="bg-white rounded-3xl border border-[#E8C7C5]/60 p-6 sm:p-7 shadow-soft">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-xl shadow-xs">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <h3 class="font-heading font-extrabold text-lg text-[#59443F]">
                                    <?php echo $can_view_grades ? 'Partial Grade Access (' : 'Grade Viewing Restricted — '; ?><?php echo htmlspecialchars($filter_academic_year . ' · ' . $filter_semester . ' Semester'); ?><?php echo $can_view_grades ? ')' : ''; ?>
                                </h3>
                                <?php if ($pending_request): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/80 shadow-xs self-start">
                                        <i class="fa-regular fa-clock text-amber-600"></i>
                                        <span>Request Pending Approval (<?php echo htmlspecialchars($filter_semester); ?> Sem)</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs sm:text-sm text-[#8B7168] mt-1 leading-relaxed">
                                Grade access is approved per Academic Year and Semester. Enrolled subjects for <strong><?php echo htmlspecialchars($filter_academic_year . ' (' . $filter_semester . ' Semester)'); ?></strong> are visible below, while official grades remain locked until approved for this term.
                            </p>

                            <?php if ($request_success): ?>
                                <div class="mt-4 p-3.5 rounded-2xl bg-emerald-50 border border-emerald-200/80 text-emerald-800 text-xs sm:text-sm font-semibold flex items-center gap-2">
                                    <i class="fa-solid fa-circle-check text-emerald-600 shrink-0"></i>
                                    <span><?php echo htmlspecialchars($request_success); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($request_error): ?>
                                <div class="mt-4 p-3.5 rounded-2xl bg-rose-50 border border-rose-200/80 text-rose-800 text-xs sm:text-sm font-semibold flex items-center gap-2">
                                    <i class="fa-solid fa-circle-exclamation text-rose-600 shrink-0"></i>
                                    <span><?php echo htmlspecialchars($request_error); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($pending_request): ?>
                                <?php
                                $req_scope = (string)($pending_request['request_scope'] ?? 'whole');
                                $req_subs = [];
                                if (!empty($pending_request['requested_subjects'])) {
                                    $req_subs = json_decode((string)$pending_request['requested_subjects'], true) ?: [];
                                }
                                ?>
                                <div class="mt-5 p-4 rounded-2xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/70 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[#8B7168] font-medium">Submitted on:</span>
                                            <span class="font-bold text-[#59443F]"><?php echo htmlspecialchars(date('M d, Y - h:i A', strtotime((string)$pending_request['requested_at']))); ?></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[#8B7168] font-medium">Scope:</span>
                                            <?php if ($req_scope === 'selected' && !empty($req_subs)): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-[#E8C7C5] text-[#59443F]">
                                                    Selected Subjects (<?php echo count($req_subs); ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-[#59443F] text-white">
                                                    Whole Semester
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($req_scope === 'selected' && !empty($req_subs)): ?>
                                        <div class="pt-1">
                                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-2">Requested Subjects:</div>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($req_subs as $sb): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-semibold bg-white text-[#59443F] border border-[#E8C7C5]/70 shadow-xs">
                                                        <i class="fa-solid fa-book text-[10px] text-[#D6A6A6]"></i>
                                                        <strong class="font-extrabold"><?php echo htmlspecialchars($sb['subject_code'] ?? ''); ?></strong>
                                                        <span class="text-[11px] text-[#8B7168] hidden sm:inline">- <?php echo htmlspecialchars($sb['subject_name'] ?? ''); ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($pending_request['message'])): ?>
                                        <p class="text-xs text-[#59443F] bg-white p-3 rounded-xl border border-[#E8C7C5]/40 italic">
                                            "<?php echo htmlspecialchars((string)$pending_request['message']); ?>"
                                        </p>
                                    <?php endif; ?>

                                    <p class="text-[11px] text-[#8B7168] flex items-center gap-1.5">
                                        <i class="fa-solid fa-info-circle text-[#D6A6A6]"></i>
                                        <span>Your Academic Formator has been notified in their dashboard and sidebar. Please check back soon.</span>
                                    </p>
                                </div>
                            <?php else: ?>
                                <!-- Request Form -->
                                <form method="post" class="mt-5 space-y-4">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                                    <!-- Request Type Radios / Pill Toggle -->
                                    <div>
                                        <label class="block text-xs font-bold text-[#8B7168] uppercase tracking-wider mb-2">
                                            Request Type
                                        </label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <label class="relative flex items-center gap-3 p-3.5 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/50 hover:bg-[#F8EEEE] cursor-pointer transition has-[:checked]:border-[#59443F] has-[:checked]:bg-[#59443F]/5 has-[:checked]:ring-1 has-[:checked]:ring-[#59443F]">
                                                <input type="radio" name="request_type" value="whole" checked onchange="toggleGradeRequestType('whole')" class="accent-[#59443F] w-4 h-4">
                                                <div class="min-w-0">
                                                    <div class="font-bold text-xs sm:text-sm text-[#59443F] flex items-center gap-1.5">
                                                        <i class="fa-solid fa-layer-group text-[#D6A6A6]"></i>
                                                        <span>Whole Semester</span>
                                                    </div>
                                                    <div class="text-[11px] text-[#8B7168] mt-0.5">
                                                        Request access to view all subjects for <?php echo htmlspecialchars($filter_academic_year . ' ' . $filter_semester . ' Sem'); ?>
                                                    </div>
                                                </div>
                                            </label>

                                            <label class="relative flex items-center gap-3 p-3.5 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/50 hover:bg-[#F8EEEE] cursor-pointer transition has-[:checked]:border-[#59443F] has-[:checked]:bg-[#59443F]/5 has-[:checked]:ring-1 has-[:checked]:ring-[#59443F]">
                                                <input type="radio" name="request_type" value="selected" onchange="toggleGradeRequestType('selected')" class="accent-[#59443F] w-4 h-4">
                                                <div class="min-w-0">
                                                    <div class="font-bold text-xs sm:text-sm text-[#59443F] flex items-center gap-1.5">
                                                        <i class="fa-solid fa-list-check text-[#D6A6A6]"></i>
                                                        <span>Selected Subjects Only</span>
                                                    </div>
                                                    <div class="text-[11px] text-[#8B7168] mt-0.5">
                                                        Pick specific subjects (e.g., 2 or 4 subjects) you need to view
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Collapsible Subjects Checklist -->
                                    <div id="subject-selection-panel" class="hidden space-y-3 pt-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-bold text-[#8B7168] uppercase tracking-wider">
                                                Choose Subjects to View (<?php echo htmlspecialchars($filter_academic_year . ' ' . $filter_semester . ' Sem'); ?>)
                                            </span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" onclick="setAllSubjectCheckboxes(true)" class="text-[11px] font-bold text-[#59443F] hover:underline cursor-pointer">
                                                    Select All
                                                </button>
                                                <span class="text-[#E8C7C5]">•</span>
                                                <button type="button" onclick="setAllSubjectCheckboxes(false)" class="text-[11px] font-bold text-[#8B7168] hover:underline cursor-pointer">
                                                    Clear All
                                                </button>
                                            </div>
                                        </div>

                                        <?php if (!empty($term_subjects)): ?>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 max-h-60 overflow-y-auto p-1">
                                                <?php foreach ($term_subjects as $sid => $sub): ?>
                                                    <label class="flex items-start gap-3 p-3 rounded-2xl border border-[#E8C7C5]/60 bg-white hover:bg-[#F8EEEE]/40 cursor-pointer transition shadow-xs">
                                                        <input type="checkbox" name="subject_ids[]" value="<?php echo (int)$sid; ?>" class="sub-checkbox accent-[#59443F] w-4 h-4 mt-0.5">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex items-center justify-between gap-1">
                                                                <span class="font-extrabold text-xs text-[#59443F]"><?php echo htmlspecialchars($sub['subject_code']); ?></span>
                                                                <span class="text-[10px] font-bold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded-full border border-[#E8C7C5]/40"><?php echo htmlspecialchars($sub['units']); ?> units</span>
                                                            </div>
                                                            <div class="text-[11px] text-[#8B7168] truncate mt-0.5" title="<?php echo htmlspecialchars($sub['subject_name']); ?>">
                                                                <?php echo htmlspecialchars($sub['subject_name']); ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="p-4 rounded-2xl bg-[#F8EEEE]/50 border border-dashed border-[#E8C7C5] text-center text-xs text-[#8B7168]">
                                                <i class="fa-solid fa-circle-info text-[#D6A6A6] mr-1"></i>
                                                No specific enrolled subjects found for this term yet. You may still select "Whole Semester" to request access once encoded.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Optional Note / Reason -->
                                    <div>
                                        <label for="request_message" class="block text-xs font-bold text-[#8B7168] uppercase tracking-wider mb-1.5">
                                            Reason or Note <span class="font-normal normal-case text-[#8B7168]/70">(Optional)</span>
                                        </label>
                                        <input type="text" id="request_message" name="request_message" placeholder="e.g. Need to review Philosophy grades for thesis prerequisite" class="w-full px-4 py-2.5 rounded-2xl border border-[#E8C7C5] bg-[#F8EEEE]/30 text-xs text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20 focus:border-[#59443F]">
                                    </div>

                                    <!-- Submit Action -->
                                    <div class="pt-1 flex items-center justify-end">
                                        <button type="submit" name="request_access" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-2xl text-xs sm:text-sm font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                            <i class="fa-solid fa-paper-plane text-[#E8C7C5]"></i>
                                            <span>Request Grade Access (<?php echo htmlspecialchars($filter_semester); ?> Semester)</span>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Enrolled Semester Subjects & Grades Card -->
            <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                            <i class="fa-solid fa-book-bookmark text-[#8B7168]"></i>
                        </div>
                        <div>
                            <h3 class="font-heading font-bold text-base text-[#59443F]">Semester Subjects &amp; Grades</h3>
                            <p class="text-xs text-[#8B7168]">
                                <?php echo htmlspecialchars($filter_academic_year); ?> • <?php echo htmlspecialchars($filter_semester); ?> Semester
                            </p>
                        </div>
                    </div>

                    <!-- Filter Form -->
                    <form method="get" class="flex flex-wrap items-center gap-2 m-0 p-0">
                        <select name="academic_year" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-[#F8EEEE] border border-[#E8C7C5]/70 text-[#59443F] focus:outline-none focus:ring-1 focus:ring-[#8B7168]">
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <select name="semester" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-[#F8EEEE] border border-[#E8C7C5]/70 text-[#59443F] focus:outline-none focus:ring-1 focus:ring-[#8B7168]">
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?> Sem</option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-xs transition cursor-pointer">
                            <i class="fa-solid fa-filter text-[10px] mr-1"></i> Filter
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                <th class="px-6 py-3.5">Subject</th>
                                <th class="px-6 py-3.5">Units</th>
                                <th class="px-6 py-3.5 text-center">Final Grade</th>
                                <th class="px-6 py-3.5 text-right">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                            <?php if (count($grade_rows) === 0) { ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-sm text-[#8B7168]">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <i class="fa-regular fa-folder-open text-3xl text-[#D6A6A6]"></i>
                                            <span class="font-semibold">No enrolled subjects found for this term.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php foreach ($grade_rows as $row) {
                                $eval = $row['_eval'];
                                $row_unlocked = $is_whole_term_unlocked || !empty($approved_subject_ids[(int)($row['subject_id'] ?? 0)]);
                            ?>
                                <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-[#59443F]"><?php echo htmlspecialchars((string)$row['subject_code']); ?></div>
                                        <div class="text-xs text-[#8B7168] mt-0.5"><?php echo htmlspecialchars((string)$row['subject_name']); ?></div>
                                        <div class="text-[11px] text-[#8B7168] mt-1.5 flex items-center gap-1.5 font-medium">
                                            <i class="fa-solid fa-chalkboard-user text-[#D6A6A6]"></i>
                                            <span>Teacher: <strong class="text-[#59443F] font-semibold"><?php echo htmlspecialchars((string)($row['instructor'] ?? 'TBA / Faculty')); ?></strong></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/50">
                                            <?php echo (float)$row['units'] == (int)$row['units'] ? (int)$row['units'] : number_format((float)$row['units'], 1); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?php if ($row_unlocked): ?>
                                            <?php if ($eval['grade'] !== null): ?>
                                                <span class="font-extrabold text-[#59443F] tabular-nums">
                                                    <?php echo htmlspecialchars(number_format((float)$eval['grade'], 2)); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[#8B7168] font-bold">—</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                <span>Locked</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <?php if ($row_unlocked): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars($eval['badge_class']); ?>">
                                                <?php echo htmlspecialchars($eval['label']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-0.5 rounded-lg <?php echo $term_enrollment_status === 'Enrolled' ? 'text-green-700 bg-green-100' : ($term_enrollment_status === 'Pending' || $term_enrollment_status === 'Partially Paid' ? 'text-amber-700 bg-amber-100' : 'text-slate-700 bg-slate-100'); ?>">
                                                <span class="w-1.5 h-1.5 rounded-full <?php echo $term_enrollment_status === 'Enrolled' ? 'bg-green-500' : 'bg-amber-500'; ?>"></span>
                                                <?php echo htmlspecialchars($term_enrollment_status); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                        <?php if (count($grade_rows) > 0): ?>
                        <tfoot>
                            <tr class="bg-[#F8EEEE]/50 border-t border-[#E8C7C5]/40 text-sm font-bold text-[#59443F]">
                                <td colspan="2" class="px-6 py-3.5 text-right text-xs uppercase tracking-wider text-[#8B7168]">
                                    Term Final Average
                                </td>
                                <td class="px-6 py-3.5 text-center font-extrabold tabular-nums">
                                    <?php if ($is_whole_term_unlocked): ?>
                                        <?php echo $term_simple_average !== null ? htmlspecialchars(number_format((float)$term_simple_average, 2)) : '—'; ?>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#8B7168]">
                                            <i class="fa-solid fa-lock text-[10px] text-amber-500"></i> Locked
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <?php if ($is_whole_term_unlocked && $term_simple_average !== null):
                                        $avg_eval = afms_evaluate_single_final_grade($term_simple_average, false);
                                    ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars($avg_eval['badge_class']); ?>">
                                            <?php echo htmlspecialchars($avg_eval['label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Term Averages History Card -->
            <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex items-center justify-between gap-3 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                            <i class="fa-solid fa-chart-line text-[#8B7168]"></i>
                        </div>
                        <div>
                            <h3 class="font-heading font-bold text-base text-[#59443F]">Academic Summary History</h3>
                            <p class="text-xs text-[#8B7168]">Term final grade averages and cumulative academic standing</p>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                <th class="px-6 py-3.5">Academic Year</th>
                                <th class="px-6 py-3.5">Semester</th>
                                <th class="px-6 py-3.5">Semester Average</th>
                                <th class="px-6 py-3.5">Cumulative Average</th>
                                <th class="px-6 py-3.5 text-right">Performance Standing</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                            <?php if (count($term_history_rows) === 0) { ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-sm text-[#8B7168]">
                                        <div class="flex flex-col items-center justify-center gap-1.5">
                                            <i class="fa-regular fa-folder-open text-2xl text-[#D6A6A6]"></i>
                                            <span>No academic summary records yet.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php foreach ($term_history_rows as $r) {
                                $hist_key = trim((string)$r['academic_year']) . '|' . capstone_access_normalize_semester((string)$r['semester']);
                                $hist_unlocked = !empty($approved_terms_map[$hist_key]['is_whole']);
                            ?>
                                <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                    <td class="px-6 py-4 font-bold text-[#59443F]"><?php echo htmlspecialchars((string)$r['academic_year']); ?></td>
                                    <td class="px-6 py-4 text-[#8B7168]"><?php echo htmlspecialchars((string)$r['semester']); ?></td>
                                    <td class="px-6 py-4 font-semibold text-[#59443F] tabular-nums">
                                        <?php if ($hist_unlocked): ?>
                                            <?php echo $r['term_average'] === null ? '-' : htmlspecialchars(number_format((float)$r['term_average'], 2)); ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                <i class="fa-solid fa-lock text-[10px] text-amber-500"></i> Locked
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-[#59443F] tabular-nums">
                                        <?php if ($hist_unlocked): ?>
                                            <?php echo $r['cumulative_average'] === null ? '-' : htmlspecialchars(number_format((float)$r['cumulative_average'], 2)); ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                <i class="fa-solid fa-lock text-[10px] text-amber-500"></i> Locked
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($hist_unlocked && !empty($r['performance_category'])): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#E8C7C5]/50 text-[#59443F] border border-[#E8C7C5]">
                                                <?php echo htmlspecialchars((string)$r['performance_category']); ?>
                                            </span>
                                        <?php elseif (!$hist_unlocked): ?>
                                            <span class="text-xs text-[#8B7168] font-semibold">Locked</span>
                                        <?php else: ?>
                                            <span class="text-[#8B7168]">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        function toggleGradeRequestType(type) {
            const panel = document.getElementById('subject-selection-panel');
            if (!panel) return;
            if (type === 'selected') {
                panel.classList.remove('hidden');
            } else {
                panel.classList.add('hidden');
            }
        }

        function setAllSubjectCheckboxes(checked) {
            document.querySelectorAll('.sub-checkbox').forEach(cb => {
                cb.checked = checked;
            });
        }
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
