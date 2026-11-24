<?php
require_once __DIR__ . '/includes/session_fix.php';
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
$role = $_SESSION['role'];

$is_dean = $role === 'Dean';
$is_registrar = $role === 'Registrar';
$is_admin = $is_dean || $is_registrar;
$is_student = $role === 'Student';
$is_cashier = $role === 'Cashier';
$is_shrine = $role === 'Shrine';
$is_academic_formator = $role === 'AcademicFormator' || $role === 'Academic Formator';
$is_formator = $is_academic_formator 
    || in_array($role, ['SpiritualDirector', 'HumanFormator', 'PastoralFormator', 'Formator'], true)
    || (stripos((string)$role, 'formator') !== false)
    || (stripos((string)$role, 'spiritual') !== false);

if ($is_cashier) {
    header('Location: modules/cashier_dashboard.php');
    exit;
}
if ($is_student) {
    header('Location: modules/student_view.php');
    exit;
}
if ($is_academic_formator) {
    header('Location: modules/afms_dashboard.php');
    exit;
}

// Fetch Statistics & Initialize Variables
$total_students = 0;
$pending_enrollments = 0;
$missing_prereq_grades_count = 0;
$irregular_students_count = 0;
$pending_applicants_list = [];
$total_subjects = 0;
$academic_year_label = 'Not set';
$active_semester = '1st';

$enrollment_status = '';
$total_units = 0;
$enrolled_subjects = 0;

// Explicit variable initialization to prevent undefined variable warnings
$recent_enrollments_display = [];
$activities = [];
$student_summaries = [];
$student_search_options = [];

$ay = trim((string)(function_exists('get_active_academic_year') ? get_active_academic_year() : get_current_academic_year()));
if ($ay !== '') {
    $academic_year_label = $ay;
}
$sem_raw = function_exists('enformasys_normalize_semester')
    ? enformasys_normalize_semester((string)(function_exists('get_active_semester') ? get_active_semester() : '1st'))
    : trim((string)(function_exists('get_active_semester') ? get_active_semester() : '1st'));
if (in_array($sem_raw, ['1st', '2nd', 'Summer'], true)) {
    $active_semester = $sem_raw;
}

$year_counts = [0, 0, 0, 0, 0];

if ($is_admin || $is_formator) {
    $dashCacheKey = $role . '|' . $academic_year_label . '|' . $active_semester;
    $cachedDash = $_SESSION['cached_dashboard_summary'][$dashCacheKey] ?? null;
    if (is_array($cachedDash) && isset($cachedDash['ts'], $cachedDash['missing_prereq_grades_count']) && (time() - (int)$cachedDash['ts']) < 60) {
        $total_students = (int)($cachedDash['total_students'] ?? 0);
        $irregular_students_count = (int)($cachedDash['irregular_students_count'] ?? 0);
        $total_subjects = (int)($cachedDash['total_subjects'] ?? 0);
        $pending_enrollments = (int)($cachedDash['pending_enrollments'] ?? 0);
        $missing_prereq_grades_count = (int)($cachedDash['missing_prereq_grades_count'] ?? 0);
        $year_counts = $cachedDash['year_counts'] ?? [0, 0, 0, 0, 0];
        $pending_applicants_list = $cachedDash['pending_applicants_list'] ?? [];
        $student_search_options = $cachedDash['student_search_options'] ?? [];
        $student_summaries = $cachedDash['student_summaries'] ?? [];
        $activities = $cachedDash['activities'] ?? [];
        $recent_enrollments_display = $cachedDash['recent_enrollments_display'] ?? [];
    } else {
        // 1. Batched student & year-level counts (single pass over seminarians)
        $sem_agg_res = @$conn->query("
            SELECT
                COUNT(*) AS total_students,
                COALESCE(SUM(CASE WHEN academic_status = 'Irregular' THEN 1 ELSE 0 END), 0) AS irregular_count,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(year_level, '')) LIKE '%propaedeutic%' OR LOWER(COALESCE(year_level, '')) LIKE '%prep%' THEN 1 ELSE 0 END), 0) AS yl_prep,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(year_level, '')) LIKE '%first%' OR LOWER(COALESCE(year_level, '')) LIKE '%1st%' THEN 1 ELSE 0 END), 0) AS yl_1st,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(year_level, '')) LIKE '%second%' OR LOWER(COALESCE(year_level, '')) LIKE '%2nd%' THEN 1 ELSE 0 END), 0) AS yl_2nd,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(year_level, '')) LIKE '%third%' OR LOWER(COALESCE(year_level, '')) LIKE '%3rd%' THEN 1 ELSE 0 END), 0) AS yl_3rd,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(year_level, '')) LIKE '%fourth%' OR LOWER(COALESCE(year_level, '')) LIKE '%4th%' THEN 1 ELSE 0 END), 0) AS yl_4th
            FROM seminarians
            WHERE is_graduate = 0 OR is_graduate IS NULL
        ");
        if ($sem_agg_res && ($agg = $sem_agg_res->fetch_assoc())) {
            $total_students = (int)($agg['total_students'] ?? 0);
            $irregular_students_count = (int)($agg['irregular_count'] ?? 0);
            $year_counts = [
                (int)($agg['yl_prep'] ?? 0),
                (int)($agg['yl_1st'] ?? 0),
                (int)($agg['yl_2nd'] ?? 0),
                (int)($agg['yl_3rd'] ?? 0),
                (int)($agg['yl_4th'] ?? 0),
            ];
        }

        // 2. Batched scalar lookup counts (pending applicants + active subjects) without INFORMATION_SCHEMA probes
        $meta_res = @$conn->query("
            SELECT
                (SELECT COUNT(*) FROM applicants WHERE archived_at IS NULL AND (status = 'Pending' OR status IS NULL)) AS pending_enrollments,
                (SELECT COUNT(*) FROM subjects WHERE archived_at IS NULL) AS total_subjects
        ");
        if ($meta_res && ($m = $meta_res->fetch_assoc())) {
            $pending_enrollments = (int)($m['pending_enrollments'] ?? 0);
            $total_subjects = (int)($m['total_subjects'] ?? 0);
        }

        // 2b. Enrolled seminarians with missing/unencoded prerequisite grades for active AY & Semester
        $stmt_prereq = @$conn->prepare("
            SELECT COUNT(DISTINCT ses.student_id) AS missing_prereq_count
            FROM student_enrolled_subjects ses
            JOIN seminarians sem ON sem.id = ses.student_id
            JOIN subjects sub ON sub.subject_id = ses.subject_id
            WHERE (sem.is_graduate = 0 OR sem.is_graduate IS NULL)
              AND ses.academic_year = ?
              AND ses.semester = ?
              AND ses.status <> 'Dropped'
              AND sub.prerequisite_subject_id IS NOT NULL
              AND sub.prerequisite_subject_id > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM student_grades sg
                  WHERE sg.student_id = ses.student_id
                    AND sg.subject_id = sub.prerequisite_subject_id
                    AND sg.status = 'Completed'
                    AND (sg.final_computed_grade IS NOT NULL OR sg.final_grade IS NOT NULL)
              )
        ");
        if ($stmt_prereq) {
            $stmt_prereq->bind_param('ss', $academic_year_label, $active_semester);
            if ($stmt_prereq->execute()) {
                $row_pr = $stmt_prereq->get_result()->fetch_assoc();
                $missing_prereq_grades_count = (int)($row_pr['missing_prereq_count'] ?? 0);
            }
            $stmt_prereq->close();
        }

        // 3. Top 5 pending applicants
        $stmt_app = @$conn->query("
            SELECT a.id, a.user_id, a.academic_year, a.program, a.created_at,
                   COALESCE(up.full_name, a.full_name, u.username) AS display_name,
                   u.username
            FROM applicants a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE a.archived_at IS NULL AND (a.status = 'Pending' OR a.status IS NULL)
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 5
        ");
        if ($stmt_app) {
            while ($row = $stmt_app->fetch_assoc()) {
                $pending_applicants_list[] = $row;
            }
        }

        // 4. Student search options & evaluation summaries
        $student_opt_res = @$conn->query("
            SELECT u.username, COALESCE(up.full_name, u.username) AS display_name
            FROM seminarians s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            ORDER BY display_name
            LIMIT 250
        ");
        if ($student_opt_res) {
            while ($row = $student_opt_res->fetch_assoc()) {
                $student_search_options[] = [
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                ];
            }
        }

        $summary_res = @$conn->query("
            SELECT u.username, COALESCE(up.full_name, u.username) AS display_name, e.type, AVG(e.score) AS avg_score
            FROM evaluations e
            JOIN seminarians s ON e.seminarian_id = s.id
            JOIN users u ON s.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE e.archived_at IS NULL
            GROUP BY u.username, display_name, e.type
            ORDER BY display_name ASC
            LIMIT 250
        ");
        if ($summary_res) {
            while ($row = $summary_res->fetch_assoc()) {
                if (!isset($student_summaries[$row['username']])) {
                    $student_summaries[$row['username']] = ['display_name' => $row['display_name']];
                }
                $student_summaries[$row['username']][$row['type']] = $row['avg_score'];
            }
        }

        if (!empty($student_summaries)) {
            $acad_res = @$conn->query("
                SELECT u.username, AVG(g.grade) AS avg_grade
                FROM grades g
                JOIN seminarians s ON g.seminarian_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE g.archived_at IS NULL
                GROUP BY u.username
                LIMIT 250
            ");
            if ($acad_res) {
                while ($row = $acad_res->fetch_assoc()) {
                    if (isset($student_summaries[$row['username']])) {
                        $student_summaries[$row['username']]['Academic'] = $row['avg_grade'];
                    }
                }
            }
        }

        // 5. Recent Activities (LEFT JOIN users so system/security events also render cleanly)
        $activities_res = @$conn->query("
            SELECT a.*, COALESCE(u.username, 'System') AS username
            FROM activities a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.timestamp DESC, a.id DESC
            LIMIT 5
        ");
        if ($activities_res) {
            while ($row = $activities_res->fetch_assoc()) {
                $activities[] = $row;
            }
        }

        // 6. Latest 5 Enrolled Seminarians
        $recent_enrolled_res = @$conn->query("
            SELECT s.id AS seminarian_id,
                   u.username,
                   COALESCE(up.full_name, a.full_name, u.username) AS display_name,
                   COALESCE(NULLIF(s.year_level, ''), 'Propaedeutic') AS year_level,
                   COALESCE(s.admission_date, a.created_at) AS date_enrolled
            FROM seminarians s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            LEFT JOIN applicants a ON a.user_id = u.id
            ORDER BY s.id DESC
            LIMIT 5
        ");
        if ($recent_enrolled_res) {
            while ($row = $recent_enrolled_res->fetch_assoc()) {
                $name = trim((string)$row['display_name']);
                $parts = preg_split('/\s+/', $name);
                $initials = count($parts) >= 2
                    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
                    : strtoupper(substr($name, 0, 2));

                $yl = (string)$row['year_level'];
                $badge_class = 'bg-slate-100 text-slate-700 border border-slate-200';
                if (stripos($yl, 'propaedeutic') !== false) {
                    $badge_class = 'bg-emerald-50 text-emerald-700 border border-emerald-200/80';
                } elseif (stripos($yl, 'first') !== false || stripos($yl, '1st') !== false) {
                    $badge_class = 'bg-blue-50 text-blue-700 border border-blue-200/80';
                } elseif (stripos($yl, 'second') !== false || stripos($yl, '2nd') !== false) {
                    $badge_class = 'bg-indigo-50 text-indigo-700 border border-indigo-200/80';
                } elseif (stripos($yl, 'third') !== false || stripos($yl, '3rd') !== false) {
                    $badge_class = 'bg-purple-50 text-purple-700 border border-purple-200/80';
                } elseif (stripos($yl, 'fourth') !== false || stripos($yl, '4th') !== false) {
                    $badge_class = 'bg-amber-50 text-amber-800 border border-amber-200/80';
                }

                $date_str = !empty($row['date_enrolled']) ? date('M j, Y', strtotime((string)$row['date_enrolled'])) : '—';
                $recent_enrollments_display[] = [
                    'seminarian_id' => (int)$row['seminarian_id'],
                    'name'          => $name,
                    'username'      => (string)$row['username'],
                    'year_level'    => $yl,
                    'date_enrolled' => $date_str,
                    'initials'      => $initials,
                    'badge_class'   => $badge_class,
                ];
            }
        }

        $_SESSION['cached_dashboard_summary'][$dashCacheKey] = [
            'ts' => time(),
            'total_students' => $total_students,
            'irregular_students_count' => $irregular_students_count,
            'total_subjects' => $total_subjects,
            'pending_enrollments' => $pending_enrollments,
            'missing_prereq_grades_count' => $missing_prereq_grades_count,
            'year_counts' => $year_counts,
            'pending_applicants_list' => $pending_applicants_list,
            'student_search_options' => $student_search_options,
            'student_summaries' => $student_summaries,
            'activities' => $activities,
            'recent_enrollments_display' => $recent_enrollments_display,
        ];
    }
} elseif (!$is_student) {
    $meta_res = @$conn->query("
        SELECT
            (SELECT COUNT(*) FROM seminarians WHERE is_graduate = 0 OR is_graduate IS NULL) AS total_students,
            (SELECT COUNT(*) FROM subjects WHERE archived_at IS NULL) AS total_subjects
    ");
    if ($meta_res && ($m = $meta_res->fetch_assoc())) {
        $total_students = (int)($m['total_students'] ?? 0);
        $total_subjects = (int)($m['total_subjects'] ?? 0);
    }
}

$prep_count = (int)($year_counts[0] ?? 0);
$philo12_count = (int)($year_counts[1] ?? 0) + (int)($year_counts[2] ?? 0);
$philo34_count = (int)($year_counts[3] ?? 0) + (int)($year_counts[4] ?? 0);
$total_year_pop = array_sum($year_counts);
$prep_pct = $total_year_pop > 0 ? (int)round(($prep_count / $total_year_pop) * 100) : 0;
$philo12_pct = $total_year_pop > 0 ? (int)round(($philo12_count / $total_year_pop) * 100) : 0;
$philo34_pct = $total_year_pop > 0 ? (int)round(($philo34_count / $total_year_pop) * 100) : 0;
$active_status_pct = $total_students > 0 ? 100 : 0;
?>
<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$suppressHeader = true;
$headerMbClass = 'mb-0';
require_once __DIR__ . '/includes/layout_app_start.php';
?>

        <!-- MASTER GRID: 2-Column Layout from the very top (Left ~70% / Right ~30%) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start font-['Inter',sans-serif]">

            <!-- LEFT COLUMN (lg:col-span-8): Top Header, Stat Cards, Chart, Performance Table -->
            <div class="col-span-1 lg:col-span-8 flex flex-col gap-6">

                <!-- 1. Top Header (Title, Search, Filters) -->
                <?php require __DIR__ . '/includes/header.php'; ?>

                <!-- 2. 3 Stat Cards in Single Horizontal Row (Interactive KPI Drill-Down Links) -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Card 1: Enrolled Seminarians -->
                        <a href="modules/student_profiles.php"
                           class="kpi-card-link dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg flex flex-col justify-between"
                           style="--reveal-delay: 0ms;"
                           aria-label="View Enrolled Seminarians in Student Profiles">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Enrolled Seminarians</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-users text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="js-kpi-count text-2xl sm:text-3xl font-black text-[#59443F] leading-tight"
                                     data-target="<?php echo (int)$total_students; ?>">
                                    <?php echo (int)$total_students; ?>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <span class="text-[11px] sm:text-xs font-medium text-[#8B7168]">Total Enrolled</span>
                                    <i class="fa-solid fa-arrow-right text-[10px] text-[#8B7168] kpi-card-chevron" aria-hidden="true"></i>
                                </div>
                            </div>
                        </a>

                        <!-- Card 2: Irregular Students -->
                        <a href="modules/student_profiles.php?standing=irregular"
                           class="kpi-card-link dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg flex flex-col justify-between"
                           style="--reveal-delay: 50ms;"
                           aria-label="View Irregular Seminarians">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Irregular Students</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-triangle-exclamation text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="js-kpi-count text-2xl sm:text-3xl font-black text-[#59443F] leading-tight"
                                     data-target="<?php echo (int)$irregular_students_count; ?>">
                                    <?php echo (int)$irregular_students_count; ?>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] truncate" title="<?php echo $irregular_students_count > 0 ? 'Requires Evaluation' : 'All Regular Standing'; ?>"><?php echo $irregular_students_count > 0 ? 'Requires Evaluation' : 'All Regular Standing'; ?></span>
                                    <i class="fa-solid fa-arrow-right text-[10px] text-[#8B7168] kpi-card-chevron shrink-0" aria-hidden="true"></i>
                                </div>
                            </div>
                        </a>

                        <!-- Card 3: Total Subjects -->
                        <a href="modules/curriculum_master.php"
                           class="kpi-card-link dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg flex flex-col justify-between"
                           style="--reveal-delay: 100ms;"
                           aria-label="View Curriculum and Subjects">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Total Subjects</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-book-open text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="js-kpi-count text-2xl sm:text-3xl font-black text-[#59443F] leading-tight"
                                     data-target="<?php echo (int)$total_subjects; ?>">
                                    <?php echo (int)$total_subjects; ?>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <span class="text-[11px] sm:text-xs font-medium text-[#8B7168]">Curriculum Offerings</span>
                                    <i class="fa-solid fa-arrow-right text-[10px] text-[#8B7168] kpi-card-chevron shrink-0" aria-hidden="true"></i>
                                </div>
                            </div>
                        </a>
                    </div>

                <!-- 3. Enrollment by Year Level (Bar Chart) (Floating Bubble Card) -->
                    <div class="dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]" style="--reveal-delay: 150ms;">
                        <!-- Card Header -->
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-chart-column text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Enrollment by Year Level</h2>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                            AY <?php echo htmlspecialchars($academic_year_label); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#8B7168] font-medium">Population of seminarians across academic &amp; formation stages</p>
                                </div>
                            </div>

                            <!-- Total Population Indicator -->
                            <div class="flex items-center gap-1.5 self-start sm:self-center">
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 shadow-xs">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Total: <span id="totalEnrollmentCount" class="js-kpi-count" data-target="<?php echo (int)$total_year_pop; ?>"><?php echo (int)$total_year_pop; ?></span> Enrolled
                                </span>
                            </div>
                        </div>

                        <!-- Card Body: Micro Stat Pills & Bar Chart Canvas -->
                        <div class="p-4 sm:p-6">
                            <!-- Stage Population Distribution Cards (Interactive 2x2 Grid on Mobile < 768px, 4-Column Row >= 768px) -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                                <a href="modules/student_profiles.php?year_level=propaedeutic"
                                   class="kpi-card-link p-3 sm:p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30"
                                   aria-label="Filter Propaedeutic Seminarians">
                                    <div class="flex items-center justify-between gap-1.5">
                                        <span class="text-[10px] sm:text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">Propaedeutic</span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-[#10b981] shrink-0"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline justify-between gap-1.5">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="js-kpi-count text-xl font-black text-[#59443F]" data-target="<?php echo (int)$prep_count; ?>"><?php echo (int)$prep_count; ?></span>
                                            <span class="text-xs font-semibold text-[#8B7168]"><?php echo (int)$prep_pct; ?>%</span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[9px] text-[#8B7168] kpi-card-chevron" aria-hidden="true"></i>
                                    </div>
                                </a>
                                <a href="modules/student_profiles.php?year_level=1st-2nd"
                                   class="kpi-card-link p-3 sm:p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30"
                                   aria-label="Filter 1st and 2nd Year Seminarians">
                                    <div class="flex items-center justify-between gap-1.5">
                                        <span class="text-[10px] sm:text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">1st &amp; 2nd Year</span>
                                        <span class="inline-flex items-center gap-1 shrink-0">
                                            <span class="w-2 h-2 rounded-full bg-[#3b82f6]"></span>
                                            <span class="w-2 h-2 rounded-full bg-[#6366f1]"></span>
                                        </span>
                                    </div>
                                    <div class="mt-1 flex items-baseline justify-between gap-1.5">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="js-kpi-count text-xl font-black text-[#59443F]" data-target="<?php echo (int)$philo12_count; ?>"><?php echo (int)$philo12_count; ?></span>
                                            <span class="text-xs font-semibold text-[#8B7168]"><?php echo (int)$philo12_pct; ?>%</span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[9px] text-[#8B7168] kpi-card-chevron" aria-hidden="true"></i>
                                    </div>
                                </a>
                                <a href="modules/student_profiles.php?year_level=3rd-4th"
                                   class="kpi-card-link p-3 sm:p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30"
                                   aria-label="Filter 3rd and 4th Year Seminarians">
                                    <div class="flex items-center justify-between gap-1.5">
                                        <span class="text-[10px] sm:text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">3rd &amp; 4th Year</span>
                                        <span class="inline-flex items-center gap-1 shrink-0">
                                            <span class="w-2 h-2 rounded-full bg-[#8b5cf6]"></span>
                                            <span class="w-2 h-2 rounded-full bg-[#0f172a]"></span>
                                        </span>
                                    </div>
                                    <div class="mt-1 flex items-baseline justify-between gap-1.5">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="js-kpi-count text-xl font-black text-[#59443F]" data-target="<?php echo (int)$philo34_count; ?>"><?php echo (int)$philo34_count; ?></span>
                                            <span class="text-xs font-semibold text-[#8B7168]"><?php echo (int)$philo34_pct; ?>%</span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[9px] text-[#8B7168] kpi-card-chevron" aria-hidden="true"></i>
                                    </div>
                                </a>
                                <a href="modules/student_profiles.php?status=Active"
                                   class="kpi-card-link p-3 sm:p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30"
                                   aria-label="Filter Active Seminarians">
                                    <div class="flex items-center justify-between gap-1.5">
                                        <span class="text-[10px] sm:text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">Active Status</span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-[#10b981] shrink-0"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline justify-between gap-1.5">
                                        <div class="flex items-baseline gap-1.5">
                                            <span class="js-kpi-count text-xl font-black text-[#59443F]" data-target="<?php echo (int)$active_status_pct; ?>" data-suffix="%"><?php echo (int)$active_status_pct; ?>%</span>
                                            <span class="text-xs font-semibold text-emerald-700"><?php echo $total_students > 0 ? 'Enrolled' : 'Ready'; ?></span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[9px] text-[#8B7168] kpi-card-chevron" aria-hidden="true"></i>
                                    </div>
                                </a>
                            </div>

                            <!-- Responsive Fixed-Height Canvas Container -->
                            <div class="relative w-full h-[230px] sm:h-[270px]">
                                <canvas id="enrollmentYearChart"></canvas>
                            </div>
                        </div>

                        <!-- Card Footer Legend (Synced 1:1 with Bar Colors) -->
                        <div class="px-5 sm:px-6 py-3.5 bg-[#F8EEEE]/40 border-t border-[#E8C7C5]/30 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs">
                            <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 text-[#59443F] text-[11px] sm:text-xs font-semibold">
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#10b981] shrink-0"></span> Propaedeutic</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#3b82f6] shrink-0"></span> 1st Year</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#6366f1] shrink-0"></span> 2nd Year</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#8b5cf6] shrink-0"></span> 3rd Year</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[#0f172a] shrink-0"></span> 4th Year</span>
                            </div>
                            <a href="modules/registrar_analytics.php" class="text-xs font-bold text-[#59443F] hover:text-[#8B7168] transition inline-flex items-center gap-1 shrink-0">
                                Full Analytics <i class="fa-solid fa-arrow-right text-[10px] text-[#D6A6A6]"></i>
                            </a>
                        </div>
                    </div>

                    <?php
                    $active_alerts_count = ($missing_prereq_grades_count > 0 ? 1 : 0) + ($pending_enrollments > 0 ? 1 : 0);
                    $resolve_grades_url = ($role === 'Rector')
                        ? 'modules/curriculum_master.php'
                        : ('modules/afms_grades.php?academic_year=' . urlencode($academic_year_label) . '&semester=' . urlencode($active_semester));
                    $review_admission_url = 'modules/applicants.php?status=pending';
                    ?>
                    <!-- 4. Action Required / Alerts (Floating Bubble Card) -->
                    <div class="dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]" style="--reveal-delay: 200ms;">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-bell text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Action Required &amp; Alerts</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Administrative items needing attention (AY <?php echo htmlspecialchars($academic_year_label); ?> &bull; <?php echo htmlspecialchars($active_semester); ?> Sem)</p>
                                </div>
                            </div>
                            <?php if ($active_alerts_count > 0) { ?>
                            <span class="self-start sm:self-center inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 shadow-xs shrink-0">
                                <span class="w-2 h-2 rounded-full bg-[#D6A6A6] animate-pulse"></span> <?php echo (int)$active_alerts_count; ?> <?php echo $active_alerts_count === 1 ? 'Alert' : 'Alerts'; ?>
                            </span>
                            <?php } else { ?>
                            <span class="self-start sm:self-center inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/70 shadow-xs shrink-0">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> 0 Alerts
                            </span>
                            <?php } ?>
                        </div>

                        <!-- Alert Notification List Items -->
                        <div class="p-4 sm:p-6 space-y-3">
                            <?php if ($active_alerts_count === 0) { ?>
                                <div class="dash-reveal p-5 rounded-2xl bg-[#F8EEEE]/40 border border-[#E8C7C5]/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3" style="--reveal-delay: 240ms;">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                                            <i class="fa-solid fa-circle-check text-sm" aria-hidden="true"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs sm:text-sm font-bold text-[#59443F]">All caught up &mdash; No pending actions</p>
                                            <p class="text-[11px] sm:text-xs text-[#8B7168] mt-0.5">No missing prerequisite grades or pending admission reviews for AY <?php echo htmlspecialchars($academic_year_label); ?> (<?php echo htmlspecialchars($active_semester); ?> Sem).</p>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>

                            <?php $alert_delay_ms = 240; ?>
                            <?php if ($missing_prereq_grades_count > 0) { ?>
                            <!-- Alert Item 1: Missing Prerequisite Grades -->
                            <div class="alert-action-card dash-reveal p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/50 hover:bg-[#F8EEEE]" style="--reveal-delay: <?php echo (int)$alert_delay_ms; ?>ms;">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 mt-0.5">
                                        <i class="fa-solid fa-triangle-exclamation text-xs" aria-hidden="true"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h3 class="text-xs font-bold text-[#59443F]">Missing Prerequisite Grades</h3>
                                            <span class="text-[9px] font-black px-1.5 py-0.5 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 uppercase tracking-wider shrink-0">
                                                High Priority
                                            </span>
                                        </div>
                                        <p class="text-xs text-[#8B7168] mt-1 leading-snug">
                                            <strong class="text-[#59443F]"><?php echo (int)$missing_prereq_grades_count; ?> <?php echo $missing_prereq_grades_count === 1 ? 'student' : 'students'; ?></strong> <?php echo $missing_prereq_grades_count === 1 ? 'has' : 'have'; ?> unencoded prerequisite grades preventing semester course completion &amp; enlistment validation.
                                        </p>
                                        <div class="mt-3 flex flex-col min-[576px]:flex-row min-[576px]:items-center min-[576px]:justify-between gap-2.5">
                                            <span class="text-[10px] text-[#8B7168] font-medium">AY <?php echo htmlspecialchars($academic_year_label); ?> &bull; <?php echo htmlspecialchars($active_semester); ?> Sem</span>
                                            <a href="<?php echo htmlspecialchars($resolve_grades_url); ?>" class="w-full min-[576px]:w-auto inline-flex items-center justify-center gap-1.5 text-xs font-bold text-white bg-[#59443F] hover:bg-[#8B7168] px-3.5 py-2 min-[576px]:py-1.5 rounded-xl shadow-soft transition">
                                                Resolve Grades <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php $alert_delay_ms += 50; } ?>

                            <?php if ($pending_enrollments > 0) { ?>
                            <!-- Alert Item 2: Pending Admission Review -->
                            <div class="alert-action-card dash-reveal p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/50 hover:bg-[#F8EEEE]" style="--reveal-delay: <?php echo (int)$alert_delay_ms; ?>ms;">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 mt-0.5">
                                        <i class="fa-solid fa-user-clock text-xs" aria-hidden="true"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h3 class="text-xs font-bold text-[#59443F]">Pending Admission Review</h3>
                                            <span class="text-[9px] font-black px-1.5 py-0.5 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 uppercase tracking-wider shrink-0">
                                                Attention
                                            </span>
                                        </div>
                                        <p class="text-xs text-[#8B7168] mt-1 leading-snug">
                                            <strong class="text-[#59443F]"><?php echo (int)$pending_enrollments; ?> pending <?php echo $pending_enrollments === 1 ? 'applicant' : 'applicants'; ?></strong> awaiting registrar document validation &amp; enrollment approval.
                                        </p>
                                        <div class="mt-3 flex flex-col min-[576px]:flex-row min-[576px]:items-center min-[576px]:justify-between gap-2.5">
                                            <span class="text-[10px] text-[#8B7168] font-medium">AY <?php echo htmlspecialchars($academic_year_label); ?></span>
                                            <a href="<?php echo htmlspecialchars($review_admission_url); ?>" class="w-full min-[576px]:w-auto inline-flex items-center justify-center gap-1.5 text-xs font-bold text-[#59443F] bg-[#E8C7C5] hover:bg-[#D6A6A6] px-3.5 py-2 min-[576px]:py-1.5 rounded-xl transition border border-[#D6A6A6]/60">
                                                Review Admission <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- 5. Formation Performance Overview (Floating Bubble Card) -->
                    <?php if (!empty($student_summaries)) { ?>
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-chart-pie text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Formation Performance Overview</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Recent 4-Pillar Evaluation Balance Matrix</p>
                                </div>
                            </div>
                            <div class="relative w-full sm:w-64">
                                <i class="fa-solid fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-[#8B7168]"></i>
                                <input type="text" id="studentSearch" class="w-full pl-9 pr-8 py-2 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Search seminarian... (/)" autocomplete="off">
                                <kbd class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-[#8B7168] bg-white px-1.5 py-0.5 rounded border border-[#E8C7C5]/60">/</kbd>
                                <div id="studentSearchList" class="typeahead-list" hidden></div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="performanceTable" class="w-full text-left text-xs">
                                <thead>
                                    <tr class="border-b border-[#E8C7C5]/30 bg-[#F8EEEE]/50 text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">
                                        <th class="py-3 px-5">Seminarian</th>
                                        <th class="py-3 px-5 text-center">4-Pillar Evaluation Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php foreach ($student_summaries as $username => $data) { 
                                        $pillar_scores = [
                                            'Academic' => $data['Academic'] ?? 0,
                                            'Spiritual' => $data['Spiritual'] ?? 0,
                                            'Human' => $data['Human'] ?? 0,
                                            'Pastoral' => $data['Pastoral'] ?? 0,
                                        ];
                                        
                                        $overall_components = [];
                                        foreach ($pillar_scores as $v) {
                                            if (is_numeric($v) && (float)$v > 0) {
                                                $overall_components[] = (float)$v;
                                            }
                                        }
                                        $overall_avg = 0;
                                        if (count($overall_components) > 0) {
                                            $overall_avg = array_sum($overall_components) / count($overall_components);
                                        }
                                        $overall_percent = 0;
                                        if ($overall_avg > 0) {
                                            $overall_percent = (int)round(($overall_avg / 5) * 100);
                                        }
                                    ?>
                                    <tr class="student-row hover:bg-[#F8EEEE]/40 transition" data-search="<?php echo htmlspecialchars(strtolower(($data['display_name'] ?? $username) . ' ' . $username)); ?>">
                                        <td class="student-name py-3.5 px-5 align-middle">
                                            <strong class="text-[#59443F] font-bold text-xs"><?php echo htmlspecialchars(($data['display_name'] ?? $username)); ?></strong>
                                            <div class="text-[11px] text-[#8B7168]"><?php echo htmlspecialchars($username); ?></div>
                                        </td>
                                        <td class="text-center py-3.5 px-5">
                                            <?php echo render_category_pie_chart($pillar_scores, $overall_percent, 140); ?>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <!-- RIGHT COLUMN (approx 30% width, lg:col-span-4): Calendar, Recent Enrollments, Recent Activities -->
                <div class="col-span-1 lg:col-span-4 flex flex-col gap-6">
                    <!-- 1. Academic Calendar Widget (Moved to the very top!) -->
                    <div id="academicCalendarWidget"></div>

                    <!-- 2. Recent Enrollments (Floating Bubble Card) -->
                    <div class="dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]" style="--reveal-delay: 200ms;">
                        <div class="p-5 pb-3 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-user-graduate text-xs text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">Recent Enrollments</h2>
                                    <p class="text-[10px] text-[#8B7168] font-medium">Latest enrolled seminarians</p>
                                </div>
                            </div>
                            <a href="modules/applicants.php?filter=enrolled" class="inline-flex items-center gap-1 text-[11px] font-bold text-[#59443F] hover:text-[#8B7168] transition">
                                View All <i class="fa-solid fa-arrow-right text-[9px] text-[#D6A6A6]"></i>
                            </a>
                        </div>

                        <!-- Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="border-b border-[#E8C7C5]/30 bg-[#F8EEEE]/50 text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">
                                        <th class="py-2.5 px-4">Name</th>
                                        <th class="py-2.5 px-3">Year Level</th>
                                        <th class="py-2.5 px-3">Date</th>
                                        <th class="py-2.5 px-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (!empty($recent_enrollments_display)): ?>
                                        <?php foreach ($recent_enrollments_display as $item): ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-2.5 px-4">
                                                <div class="flex items-center gap-2">
                                                    <div class="w-7 h-7 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center font-black text-[10px] shrink-0 border border-white shadow-2xs">
                                                        <?php echo htmlspecialchars($item['initials']); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="font-bold text-[#59443F] truncate text-xs"><?php echo htmlspecialchars($item['name']); ?></div>
                                                        <div class="text-[10px] text-[#8B7168]"><?php echo htmlspecialchars($item['username']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2.5 px-3 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                                    <?php echo htmlspecialchars($item['year_level']); ?>
                                                </span>
                                            </td>
                                            <td class="py-2.5 px-3 whitespace-nowrap text-[#8B7168] text-[10px]">
                                                <?php echo htmlspecialchars($item['date_enrolled']); ?>
                                            </td>
                                            <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                                <a href="modules/seminarian_profile.php?seminarian_id=<?php echo (int)$item['seminarian_id']; ?>" 
                                                   class="inline-flex items-center gap-1 text-[10px] font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5] px-2 py-1 rounded-lg transition border border-[#E8C7C5]/50"
                                                   title="View Seminarian Profile">
                                                    Profile <i class="fa-solid fa-arrow-up-right-from-square text-[8px] text-[#D6A6A6]"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="py-6 px-4 text-center text-xs text-[#8B7168] font-medium italic">
                                                No recent enrollments found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 3. Recent Activities Feed (Floating Bubble Card) -->
                    <div class="dash-reveal bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]" style="--reveal-delay: 250ms;">
                        <div class="p-5 pb-3 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-bell text-xs text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">Recent Activities</h2>
                                    <p class="text-[10px] text-[#8B7168] font-medium">System Audit Stream</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                Live Stream
                            </span>
                        </div>
                        <div class="p-5 space-y-2.5">
                            <?php if (!empty($activities)) { ?>
                                <?php foreach ($activities as $activity) { ?>
                                <div class="p-3 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#59443F]"><?php echo htmlspecialchars($activity['username']); ?></span>
                                        <span class="text-[10px] font-medium text-[#8B7168] shrink-0">
                                            <i class="far fa-clock mr-1 text-[#D6A6A6]"></i><?php echo date('M j, Y g:i A', strtotime($activity['timestamp'])); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#8B7168] mt-1"><?php echo htmlspecialchars($activity['action']); ?></p>
                                </div>
                                <?php } ?>
                            <?php } else { ?>
                                <p class="text-[#8B7168] text-xs text-center py-6 italic">No recent activities logged.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>

            </div>

            <script>
            if (document.getElementById('studentSearch')) {
                const input = document.getElementById('studentSearch');
                const list = document.getElementById('studentSearchList');
                const options = <?php echo json_encode($student_search_options); ?>;

                function applyFilter() {
                    const searchTerm = (input.value || '').toLowerCase();
                    const rows = document.querySelectorAll('.student-row');
                    rows.forEach(row => {
                        const haystack = (row.dataset.search || row.querySelector('.student-name').textContent).toLowerCase();
                        row.style.display = haystack.includes(searchTerm) ? '' : 'none';
                    });
                }

                function renderList(items) {
                    if (!list) return;
                    list.innerHTML = '';
                    items.slice(0, 8).forEach(function (s) {
                        const div = document.createElement('div');
                        div.className = 'typeahead-item';
                        const label = s.display_name || s.username || '';
                        const meta = (s.username && s.display_name && s.username !== s.display_name) ? s.username : '';
                        div.innerHTML = `<span>${label}</span>${meta ? `<span class="typeahead-meta">${meta}</span>` : '<span></span>'}`;
                        div.addEventListener('click', function () {
                            input.value = label;
                            list.hidden = true;
                            applyFilter();
                        });
                        list.appendChild(div);
                    });
                    list.hidden = items.length === 0;
                }

                function filterOptions() {
                    const q = (input.value || '').trim().toLowerCase();
                    if (!q) return options;
                    return options.filter(function (s) {
                        const hay = `${s.display_name || ''} ${s.username || ''}`.toLowerCase();
                        return hay.includes(q);
                    });
                }

                input.addEventListener('focus', function () {
                    renderList(filterOptions());
                });
                input.addEventListener('input', function () {
                    applyFilter();
                    renderList(filterOptions());
                });
                input.addEventListener('keyup', function (e) {
                    if (e.key === 'Escape' && list) list.hidden = true;
                });

                document.addEventListener('click', function (e) {
                    if (!list || list.hidden) return;
                    if (e.target === input || list.contains(e.target)) return;
                    list.hidden = true;
                });
            }
            </script>

<?php if (!$is_student && !$is_shrine && !$is_cashier): ?>
<!-- Chart.js CDN for Enrollment Analytics Chart -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// ── Enrollment by Year Level Bar Chart Initialization ───────────
function initEnrollmentYearChart() {
    const canvas = document.getElementById('enrollmentYearChart');
    if (!canvas) return;

    if (typeof Chart === 'undefined') {
        console.warn('Chart.js library is not available.');
        return;
    }

    const fullYearLabels = [
        'Propaedeutic',
        '1st Year Philo',
        '2nd Year Philo',
        '3rd Year Philo',
        '4th Year Philo'
    ];
    const compactYearLabels = [
        'Prep',
        '1st Yr',
        '2nd Yr',
        '3rd Yr',
        '4th Yr'
    ];

    const populationData = <?php echo json_encode(array_values($year_counts)); ?>;
    const totalPopulation = populationData.reduce((a, b) => a + b, 0);

    // Seminary-tailored modern color palette (matched 1:1 to footer legend & cards)
    const barColors = [
        '#10b981', // Emerald for Propaedeutic
        '#3b82f6', // Royal Blue for 1st Year
        '#6366f1', // Indigo for 2nd Year
        '#8b5cf6', // Violet/Purple for 3rd Year
        '#0f172a'  // Deep Navy for 4th Year Senior
    ];

    const hoverColors = [
        '#059669',
        '#2563eb',
        '#4f46e5',
        '#7c3aed',
        '#1e293b'
    ];

    const isCompactPhone = window.innerWidth < 576;
    const ctx = canvas.getContext('2d');

    if (window._enrollmentChartInstance) {
        window._enrollmentChartInstance.destroy();
    }

    window._enrollmentChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: isCompactPhone ? compactYearLabels : fullYearLabels,
            datasets: [{
                label: 'Enrolled Seminarians',
                data: populationData,
                backgroundColor: barColors,
                hoverBackgroundColor: hoverColors,
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: isCompactPhone ? 0.65 : 0.72,
                maxBarThickness: isCompactPhone ? 28 : 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleColor: '#ffffff',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        title: function(items) {
                            const idx = items && items[0] ? items[0].dataIndex : 0;
                            return fullYearLabels[idx] || '';
                        },
                        label: function(context) {
                            const val = Number(context.parsed.y || 0);
                            const pct = totalPopulation > 0 ? Math.round((val / totalPopulation) * 100) : 0;
                            return ` ${val} Seminarian${val === 1 ? '' : 's'} (${pct}% of total)`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#64748b',
                        maxRotation: 0,
                        autoSkip: false,
                        font: {
                            family: "'Inter', sans-serif",
                            size: isCompactPhone ? 10 : 11,
                            weight: '600'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: totalPopulation <= 4 ? 4 : undefined,
                    grid: {
                        color: '#f1f5f9',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        precision: 0,
                        font: {
                            family: "'Inter', sans-serif",
                            size: 11
                        }
                    }
                }
            },
            animation: {
                duration: (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) ? 0 : 700,
                easing: 'easeOutQuart'
            }
        }
    });

    if (!window._enrollmentChartResizeBound) {
        window._enrollmentChartResizeBound = true;
        let lastCompact = isCompactPhone;
        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const nowCompact = window.innerWidth < 576;
                if (nowCompact !== lastCompact && window._enrollmentChartInstance) {
                    lastCompact = nowCompact;
                    const chart = window._enrollmentChartInstance;
                    chart.data.labels = nowCompact ? compactYearLabels : fullYearLabels;
                    chart.data.datasets[0].maxBarThickness = nowCompact ? 28 : 44;
                    chart.data.datasets[0].barPercentage = nowCompact ? 0.65 : 0.72;
                    chart.options.scales.x.ticks.font.size = nowCompact ? 10 : 11;
                    chart.update('none');
                }
            }, 150);
        }, { passive: true });
    }
}
</script>
<script defer src="assets/academic_calendar.js?v=<?php echo file_exists(__DIR__ . '/assets/academic_calendar.js') ? filemtime(__DIR__ . '/assets/academic_calendar.js') : '1'; ?>"></script>
<script>
function initKpiCountUp() {
    const els = document.querySelectorAll('.js-kpi-count[data-target]');
    if (!els.length) return;

    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const duration = 600;

    els.forEach((el) => {
        const target = parseInt(el.dataset.target || '0', 10);
        const prefix = el.dataset.prefix || '';
        const suffix = el.dataset.suffix || '';

        if (prefersReducedMotion || !Number.isFinite(target) || target <= 0) {
            el.textContent = prefix + (Number.isFinite(target) ? target.toLocaleString() : '0') + suffix;
            return;
        }

        const startTime = performance.now();
        el.textContent = prefix + '0' + suffix;

        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(target * eased);
            el.textContent = prefix + current.toLocaleString() + suffix;
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = prefix + target.toLocaleString() + suffix;
            }
        }

        requestAnimationFrame(tick);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Animate KPI count-up values
    initKpiCountUp();

    // Initialize Enrollment by Year Level Chart
    initEnrollmentYearChart();

    // Initialize Academic Calendar
    if (document.getElementById('academicCalendarWidget') && window.AcademicCalendar) {
        const isRegistrar = <?php echo ($role === 'Registrar' ? 'true' : 'false'); ?>;
        new AcademicCalendar('academicCalendarWidget', { 
            baseUrl: 'modules/ajax.php',
            timetableUrl: 'modules/timetable.php',
            // Role-based permissions: strictly Read-Only for Registrar
            readOnly: isRegistrar,
            selectable: !isRegistrar,
            canManage: <?php echo (($role !== 'Registrar' && ($is_dean || $is_shrine)) ? 'true' : 'false'); ?>,
            enableDummyData: true,
            // Disable create action on date click for Registrar
            dateClick: isRegistrar ? null : function(info) {
                window.location.href = "modules/timetable.php?prefill_date=" + (info.dateStr || info);
            }
        });
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/layout_app_end.php'; ?>
<?php exit; ?>
