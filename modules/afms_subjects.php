<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean', 'Rector', 'Admin']);
include '../includes/db.php';

$role = (string)($_SESSION['role'] ?? '');
$is_admin_or_rector = (
    strtolower($role) === 'admin' ||
    strtolower($role) === 'rector' ||
    strtolower($role) === 'dean' ||
    $role === 'Admin' ||
    $role === 'Rector' ||
    $role === 'Dean'
);
$can_edit = ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && !$is_admin_or_rector && $role === 'AcademicFormator');

// Backend Protection: Strictly prevent URL/POST bypassing for Admin and Rector
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'rector' || $is_admin_or_rector)) {
    if (isset($_POST['add_subject']) || isset($_POST['archive_subject']) || isset($_POST['restore_subject']) ||
        isset($_POST['assign_subject']) || isset($_POST['remove_assignment']) || isset($_POST['assign_teacher']) ||
        isset($_POST['remove_teacher_assignment'])) {
        $error = 'Access denied: The Admin and Rector roles have strictly Read-Only access to the Curriculum and Subjects.';
    }
}

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') {
    $filter_academic_year = $current_ay;
}
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) {
    $filter_semester = function_exists('get_active_semester') ? get_active_semester() : '1st';
}

$year_level_labels = [
    'Propaedeutic Stage' => 'Propaedeutic',
    'First Year' => '1st Year',
    'Second Year' => '2nd Year',
    'Third Year' => '3rd Year',
    'Fourth Year' => '4th Year',
];
$filter_year_level = isset($_GET['year_level']) ? trim((string)$_GET['year_level']) : '';
if ($filter_year_level !== '' && !array_key_exists($filter_year_level, $year_level_labels)) {
    $filter_year_level = '';
}
$show_archived_subjects = isset($_GET['show_archived_subjects']) && $_GET['show_archived_subjects'] === '1';

$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM semester_subjects WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM student_grades WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['add_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $code = strtoupper(trim((string)($_POST['subject_code'] ?? '')));
    $name = trim((string)($_POST['subject_name'] ?? ''));
    $units = round((float)($_POST['units'] ?? 0), 1);
    if ($code !== '' && $name !== '' && $units > 0) {
        $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, units, updated_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), units = VALUES(units), updated_at = NOW()");
        $stmt->bind_param('ssd', $code, $name, $units);
        $stmt->execute();
        $success = 'Subject saved.';
    } else {
        $error = 'Please provide subject code, name, and units.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['archive_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    if ($subject_id > 0) {
        $stmt = $conn->prepare("UPDATE subjects SET archived_at = NOW(), updated_at = NOW() WHERE subject_id = ?");
        $stmt->bind_param('i', $subject_id);
        $stmt->execute();
        $success = 'Subject archived.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['restore_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    if ($subject_id > 0) {
        $stmt = $conn->prepare("UPDATE subjects SET archived_at = NULL, updated_at = NOW() WHERE subject_id = ?");
        $stmt->bind_param('i', $subject_id);
        $stmt->execute();
        $success = 'Subject restored.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['assign_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $semester = (string)($_POST['semester'] ?? '');
    $academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $year_level = trim((string)($_POST['year_level'] ?? ''));
    $formation_stage = trim((string)($_POST['formation_stage'] ?? ''));

    if ($subject_id > 0 && in_array($semester, ['1st','2nd','Summer'], true) && $academic_year !== '') {
        $stmt = $conn->prepare("INSERT INTO semester_subjects (subject_id, semester, academic_year, year_level, formation_stage)
            VALUES (?, ?, ?, NULLIF(?,''), NULLIF(?,''))");
        $stmt->bind_param('issss', $subject_id, $semester, $academic_year, $year_level, $formation_stage);
        $stmt->execute();
        $success = 'Subject assigned to semester.';
    } else {
        $error = 'Please select subject, semester, and academic year.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['remove_assignment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['assignment_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM semester_subjects WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $success = 'Assignment removed.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['assign_teacher'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $semester = (string)($_POST['semester'] ?? '');
    $teacher_user_id = isset($_POST['teacher_user_id']) && (int)$_POST['teacher_user_id'] > 0 ? (int)$_POST['teacher_user_id'] : null;
    $teacher_name_override = trim((string)($_POST['teacher_name_override'] ?? ''));

    if ($subject_id > 0 && $academic_year !== '' && in_array($semester, ['1st','2nd','Summer'], true) && ($teacher_user_id !== null || $teacher_name_override !== '')) {
        $conn->query("CREATE TABLE IF NOT EXISTS subject_teacher_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester ENUM('1st','2nd','Summer') NOT NULL,
            teacher_user_id INT NULL,
            teacher_name_override VARCHAR(150) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sta_subject_term (subject_id, academic_year, semester),
            KEY idx_sta_teacher (teacher_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt_ins_t = $conn->prepare("INSERT INTO subject_teacher_assignments (subject_id, academic_year, semester, teacher_user_id, teacher_name_override, updated_at)
            VALUES (?, ?, ?, ?, NULLIF(?,''), NOW())
            ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id), teacher_name_override = VALUES(teacher_name_override), updated_at = NOW()");
        $stmt_ins_t->bind_param('issis', $subject_id, $academic_year, $semester, $teacher_user_id, $teacher_name_override);
        $stmt_ins_t->execute();
        $success = 'Teacher assigned to subject successfully.';
    } else {
        $error = 'Please select a subject, term, and specify a teacher.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['remove_teacher_assignment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['sta_id'] ?? 0);
    if ($id > 0) {
        $stmt_del = $conn->prepare("DELETE FROM subject_teacher_assignments WHERE id = ?");
        $stmt_del->bind_param('i', $id);
        $stmt_del->execute();
        $success = 'Teacher assignment removed.';
    }
}

$subjects = [];
$res_sub = $conn->query("SELECT subject_id, subject_code, subject_name, units FROM subjects WHERE archived_at IS NULL ORDER BY subject_code ASC");
if ($res_sub) {
    while ($row = $res_sub->fetch_assoc()) {
        $subjects[] = $row;
    }
}

$subjects_table = [];
$sql_subjects_table = "SELECT subject_id, subject_code, subject_name, units, archived_at FROM subjects";
if (!$show_archived_subjects) {
    $sql_subjects_table .= " WHERE archived_at IS NULL";
}
$sql_subjects_table .= " ORDER BY subject_code ASC";
$res_sub_all = $conn->query($sql_subjects_table);
if ($res_sub_all) {
    while ($row = $res_sub_all->fetch_assoc()) {
        $subjects_table[] = $row;
    }
}

$curriculum_subject_terms = [];
$cv_stmt = $conn->prepare("SELECT id FROM curriculum_versions WHERE program = 'Bachelor of Arts in Philosophy' AND is_current = 1 AND is_active = 1 AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$cv_stmt->execute();
$cv_row = $cv_stmt->get_result()->fetch_assoc();
$cv_id = (int)($cv_row['id'] ?? 0);
if ($cv_id > 0) {
    $stmt_ct = $conn->prepare("SELECT subject_id, year_level, semester FROM curriculum_subjects WHERE curriculum_version_id = ? AND is_active = 1");
    $stmt_ct->bind_param('i', $cv_id);
    $stmt_ct->execute();
    $res_ct = $stmt_ct->get_result();
    while ($r = $res_ct->fetch_assoc()) {
        $sid = (int)($r['subject_id'] ?? 0);
        $yl = trim((string)($r['year_level'] ?? ''));
        $sem = trim((string)($r['semester'] ?? ''));
        if ($sid > 0 && $yl !== '' && $sem !== '') {
            if (!isset($curriculum_subject_terms[$sid])) {
                $curriculum_subject_terms[$sid] = [];
            }
            $curriculum_subject_terms[$sid][] = ['year_level' => $yl, 'semester' => $sem];
        }
    }
}

$assignments = [];
$sql_as = "SELECT ss.id, ss.subject_id, ss.semester, ss.academic_year, ss.year_level, ss.formation_stage, sub.subject_code, sub.subject_name, sub.units\n"
    . "FROM semester_subjects ss\n"
    . "JOIN subjects sub ON sub.subject_id = ss.subject_id\n"
    . "WHERE ss.academic_year = ? AND ss.semester = ? AND sub.archived_at IS NULL";
$types_as = 'ss';
$params_as = [$filter_academic_year, $filter_semester];
if ($filter_year_level !== '') {
    $sql_as .= " AND (ss.year_level = ? OR ss.year_level IS NULL OR ss.year_level = '')";
    $types_as .= 's';
    $params_as[] = $filter_year_level;
}
$sql_as .= " ORDER BY COALESCE(ss.year_level,''), sub.subject_code ASC";

$stmt_as = $conn->prepare($sql_as);
$bind_as = [];
$bind_as[] = $types_as;
foreach ($params_as as $k => $v) {
    $bind_as[] = &$params_as[$k];
}
call_user_func_array([$stmt_as, 'bind_param'], $bind_as);
$stmt_as->execute();
$res_as = $stmt_as->get_result();
while ($row = $res_as->fetch_assoc()) {
    $assignments[] = $row;
}

$teachers = [];
$res_t = $conn->query("SELECT u.id, u.username, up.full_name FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.role IN ('AcademicFormator', 'Dean', 'Registrar') ORDER BY COALESCE(up.full_name, u.username) ASC");
if ($res_t) {
    while ($r = $res_t->fetch_assoc()) {
        $teachers[] = $r;
    }
}

$teacher_assignments = [];
$has_sta = !empty($skip_runtime_schema_sync) || !empty($_SESSION['cached_has_sta_table']);
if (!$has_sta) {
    $chk_sta = @$conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_teacher_assignments'");
    $has_sta = ($chk_sta && (int)($chk_sta->fetch_assoc()['c'] ?? 0) > 0);
    $_SESSION['cached_has_sta_table'] = $has_sta;
}
if ($has_sta) {
    $stmt_ta = $conn->prepare("SELECT sta.*, sub.subject_code, sub.subject_name, sub.units,
            COALESCE(sta.teacher_name_override, up.full_name, u.username) as teacher_display
        FROM subject_teacher_assignments sta
        JOIN subjects sub ON sub.subject_id = sta.subject_id
        LEFT JOIN users u ON u.id = sta.teacher_user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE sta.academic_year = ? AND sta.semester = ?
        ORDER BY sub.subject_code ASC");
    $stmt_ta->bind_param('ss', $filter_academic_year, $filter_semester);
    $stmt_ta->execute();
    $res_ta = $stmt_ta->get_result();
    while ($row = $res_ta->fetch_assoc()) {
        $teacher_assignments[] = $row;
    }
}

// Resolve active tab cleanly across GET filter changes and form POST submissions
$active_tab = isset($_POST['active_tab'])
    ? trim((string)$_POST['active_tab'])
    : (isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'catalog');
if (isset($_POST['assign_subject']) || isset($_POST['remove_assignment'])) {
    $active_tab = 'semester';
} elseif (isset($_POST['assign_teacher']) || isset($_POST['remove_teacher_assignment'])) {
    $active_tab = 'faculty';
} elseif (isset($_POST['add_subject']) || isset($_POST['archive_subject']) || isset($_POST['restore_subject'])) {
    $active_tab = 'catalog';
}
if (!in_array($active_tab, ['catalog', 'semester', 'faculty'], true)) {
    $active_tab = 'catalog';
}

$pageTitle = 'Subjects';
$activeNav = 'afms_subjects';
require_once __DIR__ . '/../includes/layout_app_start.php';

$ctrlCls = 'w-full px-3 py-2 text-sm text-gray-900 bg-white border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition';
$labelCls = 'block text-xs font-semibold uppercase tracking-wider text-gray-600 mb-1.5';
$primaryBtnCls = 'inline-flex items-center justify-center gap-2 px-4 py-2.5 text-xs font-semibold text-white bg-gray-900 hover:bg-gray-800 rounded-lg shadow-xs focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-1 transition cursor-pointer border-0';
$theadThCls = 'bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3 px-4 whitespace-nowrap';
$hasEditAccess = ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit);
?>
        <div class="space-y-6">
            <!-- Top Module Header + Global Term Filter -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 tracking-tight m-0 flex items-center gap-2.5">
                        <i class="fas fa-book text-gray-700"></i>
                        <span>Subject Management</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-gray-500 mt-1 mb-0">
                        Manage the course catalog, assign subjects per academic term, and designate faculty instructors.
                    </p>
                </div>

                <form method="get" class="flex !flex-row flex-wrap items-end !gap-3 m-0 p-0 bg-transparent border-0 shadow-none">
                    <input type="hidden" name="tab" id="globalTabInput" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="w-36">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Academic Year</label>
                        <select name="academic_year" class="w-full px-3 py-2 text-xs font-semibold text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="w-28">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Semester</label>
                        <select name="semester" class="w-full px-3 py-2 text-xs font-semibold text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="w-40">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Year Level</label>
                        <select name="year_level" class="w-full px-3 py-2 text-xs font-semibold text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="" <?php echo $filter_year_level === '' ? 'selected' : ''; ?>>All Year Levels</option>
                            <?php foreach ($year_level_labels as $value => $label) { ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $value === $filter_year_level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <noscript>
                        <button type="submit" style="width: auto;" class="<?php echo $primaryBtnCls; ?>">
                            <i class="fas fa-filter"></i> View
                        </button>
                    </noscript>
                </form>
            </div>

            <!-- Alerts -->
            <?php if (isset($success)) { ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2.5 shadow-2xs">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>
            <?php if (isset($error)) { ?>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold flex items-center gap-2.5 shadow-2xs">
                    <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php } ?>

            <?php if (!$hasEditAccess) { ?>
                <div class="bg-amber-50/80 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
                    <i class="fas fa-shield-halved text-amber-600 mt-0.5"></i>
                    <div>
                        <h4 class="text-sm font-bold text-gray-900 m-0">Read-Only Access</h4>
                        <p class="text-xs text-gray-600 mt-1 mb-0">
                            Your role (<strong><?php echo htmlspecialchars($role ?: 'Admin/Rector'); ?></strong>) has read-only permission. Adding, editing, archiving, or assigning subjects is restricted to authorized academic personnel.
                        </p>
                    </div>
                </div>
            <?php } ?>

            <!-- Tab Navigation Bar -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-xs p-1.5 inline-flex flex-wrap items-center gap-1 w-full sm:w-auto" role="tablist" aria-label="Subject Management Sections">
                <button type="button"
                        role="tab"
                        id="tabBtnCatalog"
                        data-subjects-tab="catalog"
                        aria-selected="<?php echo $active_tab === 'catalog' ? 'true' : 'false'; ?>"
                        aria-controls="tabPanelCatalog"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition cursor-pointer <?php echo $active_tab === 'catalog' ? 'bg-gray-900 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
                    <i class="fas fa-book-open"></i>
                    <span>Subject Catalog</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?php echo $active_tab === 'catalog' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'; ?>">
                        <?php echo count($subjects_table); ?>
                    </span>
                </button>
                <button type="button"
                        role="tab"
                        id="tabBtnSemester"
                        data-subjects-tab="semester"
                        aria-selected="<?php echo $active_tab === 'semester' ? 'true' : 'false'; ?>"
                        aria-controls="tabPanelSemester"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition cursor-pointer <?php echo $active_tab === 'semester' ? 'bg-gray-900 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Semester Assignments</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?php echo $active_tab === 'semester' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'; ?>">
                        <?php echo count($assignments); ?>
                    </span>
                </button>
                <button type="button"
                        role="tab"
                        id="tabBtnFaculty"
                        data-subjects-tab="faculty"
                        aria-selected="<?php echo $active_tab === 'faculty' ? 'true' : 'false'; ?>"
                        aria-controls="tabPanelFaculty"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition cursor-pointer <?php echo $active_tab === 'faculty' ? 'bg-gray-900 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
                    <i class="fas fa-chalkboard-user"></i>
                    <span>Faculty Assignments</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?php echo $active_tab === 'faculty' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'; ?>">
                        <?php echo count($teacher_assignments); ?>
                    </span>
                </button>
            </div>

            <!-- =============================================================== -->
            <!-- TAB 1: SUBJECT CATALOG                                          -->
            <!-- =============================================================== -->
            <section id="tabPanelCatalog" role="tabpanel" aria-labelledby="tabBtnCatalog" class="<?php echo $active_tab === 'catalog' ? '' : 'hidden'; ?>">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <?php if ($hasEditAccess) { ?>
                    <!-- Left Column (Col-Span 1): Sticky Add / Update Subject Card -->
                    <div class="lg:col-span-1 lg:sticky lg:top-6 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <div class="flex items-center gap-2.5 pb-4 mb-5 border-b border-gray-100">
                            <div class="w-9 h-9 rounded-lg bg-gray-100 text-gray-800 flex items-center justify-center shrink-0">
                                <i class="fas fa-plus text-sm"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 m-0">Add / Update Subject</h2>
                                <p class="text-[11px] text-gray-500 m-0">Create a course or update an existing code</p>
                            </div>
                        </div>

                        <form method="post" class="space-y-4 !gap-0 m-0 p-0 bg-transparent border-0 shadow-none">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="active_tab" value="catalog">

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="<?php echo $labelCls; ?>">Subject Code</label>
                                    <input type="text" name="subject_code" required placeholder="e.g. THEO101" class="<?php echo $ctrlCls; ?>">
                                </div>
                                <div>
                                    <label class="<?php echo $labelCls; ?>">Units</label>
                                    <input type="number" name="units" min="0.5" max="15" step="0.5" required value="3" class="<?php echo $ctrlCls; ?>">
                                </div>
                            </div>

                            <div>
                                <label class="<?php echo $labelCls; ?>">Description</label>
                                <input type="text" name="subject_name" required placeholder="e.g. Introduction to Theology" class="<?php echo $ctrlCls; ?>">
                            </div>

                            <div class="pt-2">
                                <button type="submit" name="add_subject" class="w-full <?php echo $primaryBtnCls; ?>">
                                    <i class="fas fa-save"></i>
                                    <span>Save Subject</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php } ?>

                    <!-- Right Column (Col-Span 2 or 3): Master Table of All Subjects -->
                    <div class="<?php echo $hasEditAccess ? 'lg:col-span-2' : 'lg:col-span-3'; ?> bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 m-0 flex items-center gap-2">
                                    <i class="fas fa-list text-gray-500"></i>
                                    <span>All Subjects</span>
                                </h3>
                                <p class="text-xs text-gray-500 mt-0.5 mb-0">Master course catalog across all programs</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-600 cursor-pointer select-none whitespace-nowrap m-0">
                                    <input type="checkbox" id="showArchivedSubjects" class="rounded border-gray-300 text-gray-900 focus:ring-blue-500" style="width: auto;" <?php echo $show_archived_subjects ? 'checked' : ''; ?>>
                                    <span>Show Archived</span>
                                </label>
                                <div class="relative w-56">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                    <input type="text"
                                           id="subjectSearch"
                                           placeholder="Search subject..."
                                           autocomplete="off"
                                           class="w-full pl-8 pr-3 py-1.5 text-xs bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition">
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table id="subjectsTable" class="w-full min-w-full border-collapse !mt-0 text-left">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="<?php echo $theadThCls; ?> text-left">Code</th>
                                        <th class="<?php echo $theadThCls; ?> text-left">Description</th>
                                        <th class="<?php echo $theadThCls; ?> text-center">Units</th>
                                        <th class="<?php echo $theadThCls; ?> text-center">Status</th>
                                        <?php if ($hasEditAccess) { ?><th class="<?php echo $theadThCls; ?> text-right">Action</th><?php } ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php if (empty($subjects_table)) { ?>
                                        <tr>
                                            <td colspan="<?php echo $hasEditAccess ? 5 : 4; ?>" class="py-10 px-6 text-center text-sm text-gray-500">
                                                No subjects found in the catalog.
                                            </td>
                                        </tr>
                                    <?php } ?>
                                    <?php foreach ($subjects_table as $s) { ?>
                                        <?php $is_archived = ($s['archived_at'] ?? null) !== null; ?>
                                        <tr class="subject-row hover:bg-gray-50 transition-colors">
                                            <td class="subject-code py-3 px-4 text-sm font-bold text-gray-900 whitespace-nowrap">
                                                <?php echo htmlspecialchars((string)$s['subject_code']); ?>
                                            </td>
                                            <td class="subject-name py-3 px-4 text-sm text-gray-700">
                                                <?php echo htmlspecialchars((string)$s['subject_name']); ?>
                                            </td>
                                            <td class="py-3 px-4 text-center whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                                    <?php echo htmlspecialchars(capstone_format_units($s['units'] ?? 0)); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-center whitespace-nowrap">
                                                <?php if ($is_archived) { ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">Archived</span>
                                                <?php } else { ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Active</span>
                                                <?php } ?>
                                            </td>
                                            <?php if ($hasEditAccess) { ?>
                                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                                    <form method="post" class="inline-block m-0 p-0 bg-transparent border-0 shadow-none">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="active_tab" value="catalog">
                                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['subject_id']; ?>">
                                                        <?php if ($is_archived) { ?>
                                                            <button type="submit" name="restore_subject" style="width: auto;" title="Restore Subject" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white border border-emerald-200 transition cursor-pointer">
                                                                <i class="fas fa-rotate-left text-xs"></i>
                                                            </button>
                                                        <?php } else { ?>
                                                            <button type="submit" name="archive_subject" style="width: auto;" title="Archive Subject" onclick="return confirm('Archive this subject?');" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white border border-rose-200 transition cursor-pointer">
                                                                <i class="fas fa-box-archive text-xs"></i>
                                                            </button>
                                                        <?php } ?>
                                                    </form>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- =============================================================== -->
            <!-- TAB 2: SEMESTER ASSIGNMENTS                                     -->
            <!-- =============================================================== -->
            <section id="tabPanelSemester" role="tabpanel" aria-labelledby="tabBtnSemester" class="<?php echo $active_tab === 'semester' ? '' : 'hidden'; ?>">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <?php if ($hasEditAccess) { ?>
                    <!-- Left Column (Col-Span 1): Assign Subject Per Semester Card -->
                    <div class="lg:col-span-1 lg:sticky lg:top-6 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <div class="flex items-center gap-2.5 pb-4 mb-5 border-b border-gray-100">
                            <div class="w-9 h-9 rounded-lg bg-gray-100 text-gray-800 flex items-center justify-center shrink-0">
                                <i class="fas fa-calendar-plus text-sm"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 m-0">Assign Subject Per Semester</h2>
                                <p class="text-[11px] text-gray-500 m-0">Link catalog subjects to an academic term</p>
                            </div>
                        </div>

                        <form method="post" class="space-y-4 !gap-0 m-0 p-0 bg-transparent border-0 shadow-none">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="active_tab" value="semester">

                            <div>
                                <label for="assignSubject" class="<?php echo $labelCls; ?>">Subject</label>
                                <select name="subject_id" id="assignSubject" required class="<?php echo $ctrlCls; ?>">
                                    <option value="">Select subject</option>
                                    <?php foreach ($subjects as $sub) { ?>
                                        <option value="<?php echo (int)$sub['subject_id']; ?>">
                                            <?php echo htmlspecialchars((string)$sub['subject_code']); ?> - <?php echo htmlspecialchars((string)$sub['subject_name']); ?> (<?php echo (int)$sub['units']; ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="<?php echo $labelCls; ?>">Academic Year</label>
                                    <select name="academic_year" required class="<?php echo $ctrlCls; ?>">
                                        <?php foreach ($ay_options as $ay) { ?>
                                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($ay); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="assignSemester" class="<?php echo $labelCls; ?>">Semester</label>
                                    <select name="semester" id="assignSemester" required class="<?php echo $ctrlCls; ?>">
                                        <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                            <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="assignYearLevel" class="<?php echo $labelCls; ?>">Year Level</label>
                                    <select name="year_level" id="assignYearLevel" class="<?php echo $ctrlCls; ?>">
                                        <option value="">All</option>
                                        <?php foreach ($year_level_labels as $value => $label) { ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="<?php echo $labelCls; ?>">Formation Stage</label>
                                    <input type="text" name="formation_stage" placeholder="e.g. Propaedeutic" class="<?php echo $ctrlCls; ?>">
                                </div>
                            </div>

                            <div class="pt-2">
                                <button type="submit" name="assign_subject" class="w-full <?php echo $primaryBtnCls; ?>">
                                    <i class="fas fa-check"></i>
                                    <span>Assign Subject</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php } ?>

                    <!-- Right Column (Col-Span 2 or 3): Assigned Subjects Data Table -->
                    <div class="<?php echo $hasEditAccess ? 'lg:col-span-2' : 'lg:col-span-3'; ?> bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 m-0 flex items-center gap-2">
                                    <i class="fas fa-calendar text-gray-500"></i>
                                    <span>Assigned Subjects (<?php echo htmlspecialchars($filter_academic_year); ?> &middot; <?php echo htmlspecialchars($filter_semester); ?><?php echo $filter_year_level !== '' ? ' &middot; ' . htmlspecialchars($year_level_labels[$filter_year_level] ?? $filter_year_level) : ''; ?>)</span>
                                </h3>
                                <p class="text-xs text-gray-500 mt-0.5 mb-0">Subjects scheduled for the active term filter</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-full border-collapse !mt-0 text-left">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="<?php echo $theadThCls; ?> text-left">Year Level</th>
                                        <th class="<?php echo $theadThCls; ?> text-left">Subject</th>
                                        <th class="<?php echo $theadThCls; ?> text-center">Units</th>
                                        <th class="<?php echo $theadThCls; ?> text-left">Formation Stage</th>
                                        <?php if ($hasEditAccess) { ?><th class="<?php echo $theadThCls; ?> text-right">Action</th><?php } ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php if (count($assignments) === 0) { ?>
                                        <tr>
                                            <td colspan="<?php echo $hasEditAccess ? 5 : 4; ?>" class="py-10 px-6 text-center text-sm text-gray-500">
                                                No assigned subjects found for this term.
                                            </td>
                                        </tr>
                                    <?php } ?>
                                    <?php foreach ($assignments as $a) { ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="py-3 px-4 text-xs font-semibold text-gray-700 whitespace-nowrap">
                                                <?php echo htmlspecialchars(trim((string)($a['year_level'] ?? '')) !== '' ? (string)$a['year_level'] : 'All'); ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars((string)$a['subject_code']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string)$a['subject_name']); ?></div>
                                            </td>
                                            <td class="py-3 px-4 text-center whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                                    <?php echo htmlspecialchars(capstone_format_units($a['units'] ?? 0)); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-xs text-gray-600">
                                                <?php echo htmlspecialchars(trim((string)($a['formation_stage'] ?? '')) !== '' ? (string)$a['formation_stage'] : '—'); ?>
                                            </td>
                                            <?php if ($hasEditAccess) { ?>
                                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                                    <form method="post" class="inline-block m-0 p-0 bg-transparent border-0 shadow-none">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="active_tab" value="semester">
                                                        <input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>">
                                                        <button type="submit" name="remove_assignment" style="width: auto;" title="Remove Assignment" onclick="return confirm('Remove this assignment?');" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-600 hover:bg-red-700 text-white shadow-2xs transition cursor-pointer border-0">
                                                            <i class="fas fa-trash text-xs"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- =============================================================== -->
            <!-- TAB 3: FACULTY ASSIGNMENTS                                      -->
            <!-- =============================================================== -->
            <section id="tabPanelFaculty" role="tabpanel" aria-labelledby="tabBtnFaculty" class="<?php echo $active_tab === 'faculty' ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-sm font-bold text-gray-900 m-0 flex items-center gap-2">
                            <i class="fas fa-chalkboard-user text-gray-500"></i>
                            <span>Subject Teacher Assignments (<?php echo htmlspecialchars($filter_academic_year); ?> &middot; <?php echo htmlspecialchars($filter_semester); ?>)</span>
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5 mb-0">Assign instructors or formators to subjects for this academic term.</p>
                    </div>

                    <?php if ($hasEditAccess) { ?>
                    <!-- Distinct Light Panel Form with Single-Row 5-Col Responsive Grid -->
                    <div class="p-5">
                        <div class="bg-gray-50 border border-gray-200 p-5 rounded-lg">
                            <form method="post" class="space-y-4 !gap-0 m-0 p-0 bg-transparent border-0 shadow-none">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="active_tab" value="faculty">

                                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                                    <div>
                                        <label class="<?php echo $labelCls; ?>">Subject</label>
                                        <select name="subject_id" required class="<?php echo $ctrlCls; ?>">
                                            <option value="">Select subject</option>
                                            <?php foreach ($subjects as $sub) { ?>
                                                <option value="<?php echo (int)$sub['subject_id']; ?>">
                                                    <?php echo htmlspecialchars((string)$sub['subject_code']); ?> - <?php echo htmlspecialchars((string)$sub['subject_name']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="<?php echo $labelCls; ?>">Acad Year</label>
                                        <select name="academic_year" required class="<?php echo $ctrlCls; ?>">
                                            <?php foreach ($ay_options as $ay) { ?>
                                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ay); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="<?php echo $labelCls; ?>">Semester</label>
                                        <select name="semester" required class="<?php echo $ctrlCls; ?>">
                                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="<?php echo $labelCls; ?>">Faculty</label>
                                        <select name="teacher_user_id" class="<?php echo $ctrlCls; ?>">
                                            <option value="">Select faculty</option>
                                            <?php foreach ($teachers as $t) {
                                                $tname = trim((string)($t['full_name'] ?? ''));
                                                if ($tname === '') $tname = (string)($t['username'] ?? '');
                                            ?>
                                                <option value="<?php echo (int)$t['id']; ?>">
                                                    <?php echo htmlspecialchars($tname); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="<?php echo $labelCls; ?>">Override Name</label>
                                        <input type="text" name="teacher_name_override" placeholder="e.g. Fr. Joseph Santos" class="<?php echo $ctrlCls; ?>">
                                    </div>
                                </div>

                                <div class="flex justify-end pt-1">
                                    <button type="submit" name="assign_teacher" style="width: auto;" class="<?php echo $primaryBtnCls; ?>">
                                        <i class="fas fa-save"></i>
                                        <span>Save Teacher</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php } ?>

                    <!-- Assigned Teachers Table -->
                    <div class="overflow-x-auto border-t border-gray-100">
                        <table class="w-full min-w-full border-collapse !mt-0 text-left">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="<?php echo $theadThCls; ?> text-left">Subject Code</th>
                                    <th class="<?php echo $theadThCls; ?> text-left">Description</th>
                                    <th class="<?php echo $theadThCls; ?> text-center">Units</th>
                                    <th class="<?php echo $theadThCls; ?> text-left">Assigned Teacher</th>
                                    <?php if ($hasEditAccess) { ?><th class="<?php echo $theadThCls; ?> text-right">Action</th><?php } ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <?php if (count($teacher_assignments) === 0) { ?>
                                    <tr>
                                        <td colspan="<?php echo $hasEditAccess ? 5 : 4; ?>" class="py-10 px-6 text-center text-sm text-gray-500">
                                            No teachers assigned for this term yet.
                                        </td>
                                    </tr>
                                <?php } ?>
                                <?php foreach ($teacher_assignments as $ta) { ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="py-3 px-4 text-sm font-bold text-gray-900 whitespace-nowrap">
                                            <?php echo htmlspecialchars((string)$ta['subject_code']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-sm text-gray-700">
                                            <?php echo htmlspecialchars((string)$ta['subject_name']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                                <?php echo htmlspecialchars(capstone_format_units($ta['units'] ?? 0)); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-sm font-semibold text-gray-900 whitespace-nowrap">
                                            <i class="fas fa-user-tie text-gray-400 mr-1.5"></i>
                                            <?php echo htmlspecialchars((string)$ta['teacher_display']); ?>
                                        </td>
                                        <?php if ($hasEditAccess) { ?>
                                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                                <form method="post" class="inline-block m-0 p-0 bg-transparent border-0 shadow-none">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="active_tab" value="faculty">
                                                    <input type="hidden" name="sta_id" value="<?php echo (int)$ta['id']; ?>">
                                                    <button type="submit" name="remove_teacher_assignment" style="width: auto;" title="Remove Teacher Assignment" onclick="return confirm('Remove teacher assignment?');" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-600 hover:bg-red-700 text-white shadow-2xs transition cursor-pointer border-0">
                                                        <i class="fas fa-trash text-xs"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <script>
        (function () {
            // Tab switching with URL persistence
            const tabBtns = document.querySelectorAll('[data-subjects-tab]');
            const tabPanels = {
                catalog: document.getElementById('tabPanelCatalog'),
                semester: document.getElementById('tabPanelSemester'),
                faculty: document.getElementById('tabPanelFaculty')
            };
            const globalTabInput = document.getElementById('globalTabInput');

            function switchSubjectsTab(tabName, updateUrl) {
                const target = Object.prototype.hasOwnProperty.call(tabPanels, tabName) ? tabName : 'catalog';
                Object.keys(tabPanels).forEach(function (k) {
                    if (tabPanels[k]) {
                        tabPanels[k].classList.toggle('hidden', k !== target);
                    }
                });

                tabBtns.forEach(function (btn) {
                    const isActive = btn.getAttribute('data-subjects-tab') === target;
                    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    btn.classList.toggle('bg-gray-900', isActive);
                    btn.classList.toggle('text-white', isActive);
                    btn.classList.toggle('shadow-xs', isActive);
                    btn.classList.toggle('text-gray-600', !isActive);
                    btn.classList.toggle('hover:bg-gray-100', !isActive);
                    btn.classList.toggle('hover:text-gray-900', !isActive);

                    const badge = btn.querySelector('span:last-child');
                    if (badge) {
                        badge.classList.toggle('bg-white/20', isActive);
                        badge.classList.toggle('text-white', isActive);
                        badge.classList.toggle('bg-gray-100', !isActive);
                        badge.classList.toggle('text-gray-600', !isActive);
                    }
                });

                if (globalTabInput) {
                    globalTabInput.value = target;
                }

                if (updateUrl && window.history && window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', target);
                    window.history.replaceState(null, '', url.toString());
                }
            }

            tabBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switchSubjectsTab(btn.getAttribute('data-subjects-tab') || 'catalog', true);
                });
            });

            // Live search in Subject Catalog
            const subjectSearchEl = document.getElementById('subjectSearch');
            if (subjectSearchEl) {
                subjectSearchEl.addEventListener('keyup', function () {
                    const q = this.value.toLowerCase();
                    document.querySelectorAll('.subject-row').forEach(function (row) {
                        const t = row.textContent.toLowerCase();
                        row.style.display = t.includes(q) ? '' : 'none';
                    });
                });
            }

            // Show Archived toggle (preserves active tab)
            const cbArchived = document.getElementById('showArchivedSubjects');
            if (cbArchived) {
                cbArchived.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('tab', (globalTabInput && globalTabInput.value) || 'catalog');
                    if (cbArchived.checked) params.set('show_archived_subjects', '1');
                    else params.delete('show_archived_subjects');
                    window.location.search = params.toString();
                });
            }
        })();

        (function () {
            const subjectTerms = <?php echo json_encode($curriculum_subject_terms); ?>;
            const subjectSelect = document.getElementById('assignSubject');
            const yearLevelSelect = document.getElementById('assignYearLevel');
            const semesterSelect = document.getElementById('assignSemester');

            if (!subjectSelect || !yearLevelSelect || !semesterSelect) return;

            const allOptions = Array.from(subjectSelect.options).map(o => ({ value: o.value, label: o.textContent }));

            function shouldInclude(subjectId, yearLevel, semester) {
                const list = subjectTerms[String(subjectId)] || [];
                return list.some(x => x.year_level === yearLevel && x.semester === semester);
            }

            function refreshSubjectOptions() {
                const yl = yearLevelSelect.value;
                const sem = semesterSelect.value;

                const keepAll = yl === '' || sem === '' || sem === 'Summer' || Object.keys(subjectTerms).length === 0;
                const prev = subjectSelect.value;

                subjectSelect.innerHTML = '';
                for (const opt of allOptions) {
                    if (opt.value === '') {
                        const o = document.createElement('option');
                        o.value = '';
                        o.textContent = opt.label;
                        subjectSelect.appendChild(o);
                        continue;
                    }
                    if (keepAll || shouldInclude(opt.value, yl, sem)) {
                        const o = document.createElement('option');
                        o.value = opt.value;
                        o.textContent = opt.label;
                        subjectSelect.appendChild(o);
                    }
                }

                if (prev && Array.from(subjectSelect.options).some(o => o.value === prev)) {
                    subjectSelect.value = prev;
                } else {
                    subjectSelect.value = '';
                }
            }

            yearLevelSelect.addEventListener('change', refreshSubjectOptions);
            semesterSelect.addEventListener('change', refreshSubjectOptions);
            refreshSubjectOptions();
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
