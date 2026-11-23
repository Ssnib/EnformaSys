<?php
include '../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'AcademicFormator']);
include '../includes/db.php';

$current_ay = get_current_academic_year();
$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$filter_student_id = (int)($_GET['student_id'] ?? 0);
$filter_ay = trim((string)($_GET['academic_year'] ?? ''));
$filter_semester = trim((string)($_GET['semester'] ?? ''));
$filter_program = trim((string)($_GET['program'] ?? ''));
$filter_year_level = trim((string)($_GET['year_level'] ?? ''));

if ($filter_ay === '') {
    $filter_ay = $current_ay;
}
if ($filter_semester !== '' && !in_array($filter_semester, ['1st','2nd','Summer'], true)) {
    $filter_semester = '';
}

$where = "1=1";
$params = [];
$types = '';
if ($filter_student_id > 0) {
    $where .= " AND es.student_id = ?";
    $params[] = $filter_student_id;
    $types .= 'i';
}
if ($filter_ay !== '' && $filter_ay !== 'all') {
    $where .= " AND es.academic_year = ?";
    $params[] = $filter_ay;
    $types .= 's';
}
if ($filter_semester !== '') {
    $where .= " AND es.semester = ?";
    $params[] = $filter_semester;
    $types .= 's';
}
if ($filter_program !== '') {
    $where .= " AND COALESCE(es.program,'') = ?";
    $params[] = $filter_program;
    $types .= 's';
}
if ($filter_year_level !== '') {
    $where .= " AND COALESCE(es.year_level,'') = ?";
    $params[] = $filter_year_level;
    $types .= 's';
}

$rows = [];
$sql = "SELECT es.*, s.subject_code, s.subject_name, s.units,\n"
    . "COALESCE(up.full_name, u.username) as full_name\n"
    . "FROM student_enrolled_subjects es\n"
    . "JOIN subjects s ON s.subject_id = es.subject_id\n"
    . "JOIN seminarians sem ON sem.id = es.student_id\n"
    . "JOIN users u ON u.id = sem.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "WHERE {$where}\n"
    . "ORDER BY es.academic_year DESC, es.semester ASC, full_name ASC, s.subject_code ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

$students = [];
$res_students = $conn->query(
    "SELECT s.id as seminarian_id, COALESCE(up.full_name, u.username) as full_name\n"
    . "FROM seminarians s\n"
    . "JOIN users u ON u.id = s.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "ORDER BY full_name ASC"
);
if ($res_students) {
    while ($r = $res_students->fetch_assoc()) {
        $students[] = $r;
    }
}

$programs = [];
$res_prog = $conn->query("SELECT DISTINCT program FROM student_enrolled_subjects WHERE program IS NOT NULL AND program <> '' ORDER BY program ASC");
if ($res_prog) {
    while ($r = $res_prog->fetch_assoc()) {
        $programs[] = (string)($r['program'] ?? '');
    }
}

$year_levels = [];
$res_yl = $conn->query("SELECT DISTINCT year_level FROM student_enrolled_subjects WHERE year_level IS NOT NULL AND year_level <> '' ORDER BY year_level ASC");
if ($res_yl) {
    while ($r = $res_yl->fetch_assoc()) {
        $year_levels[] = (string)($r['year_level'] ?? '');
    }
}

$pageTitle = 'Enrolled Subjects';
$activeNav = 'enrolled_subjects_report';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <h1 class="page-title"><i class="fas fa-file-lines"></i> Enrolled Subjects Report</h1>

            <div class="card" style="margin: 0 0 1.25rem 0;">
                <h3><i class="fas fa-filter"></i> Filters</h3>
                <form method="get" style="margin-top: 1rem;">
                    <div class="form-grid two">
                        <div class="field">
                            <label>Seminarian</label>
                            <select name="student_id">
                                <option value="0">All</option>
                                <?php foreach ($students as $st) { ?>
                                    <?php $sid = (int)($st['seminarian_id'] ?? 0); ?>
                                    <option value="<?php echo $sid; ?>" <?php echo $sid === $filter_student_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)($st['full_name'] ?? '')); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Academic Year</label>
                            <select name="academic_year">
                                <option value="all" <?php echo $filter_ay === 'all' ? 'selected' : ''; ?>>All</option>
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid two">
                        <div class="field">
                            <label>Semester</label>
                            <select name="semester">
                                <option value="" <?php echo $filter_semester === '' ? 'selected' : ''; ?>>All</option>
                                <option value="1st" <?php echo $filter_semester === '1st' ? 'selected' : ''; ?>>First Semester</option>
                                <option value="2nd" <?php echo $filter_semester === '2nd' ? 'selected' : ''; ?>>Second Semester</option>
                                <option value="Summer" <?php echo $filter_semester === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Program</label>
                            <select name="program">
                                <option value="" <?php echo $filter_program === '' ? 'selected' : ''; ?>>All</option>
                                <?php foreach ($programs as $p) { ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $filter_program ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid two">
                        <div class="field">
                            <label>Year Level</label>
                            <select name="year_level">
                                <option value="" <?php echo $filter_year_level === '' ? 'selected' : ''; ?>>All</option>
                                <?php foreach ($year_levels as $yl) { ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $yl === $filter_year_level ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yl); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div></div>
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit">Apply</button>
                    </div>
                </form>
            </div>

            <div class="data-card">
                <div class="data-card-header">
                    <h3><i class="fas fa-list"></i> Results (<?php echo count($rows); ?>)</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Academic Year</th>
                                <th>Semester</th>
                                <th>Program</th>
                                <th>Year Level</th>
                                <th>Code</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rows) === 0) { ?>
                                <tr><td colspan="9" style="color: var(--text-secondary);">No enrolled subjects found.</td></tr>
                            <?php } else { foreach ($rows as $r) { ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string)($r['academic_year'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['semester'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['program'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['year_level'] ?? '')); ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)($r['subject_code'] ?? '')); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string)($r['subject_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(capstone_format_units($r['units'] ?? 0)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? 'Enrolled')); ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>

