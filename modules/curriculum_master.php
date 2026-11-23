<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar', 'AcademicFormator', 'Dean', 'Rector', 'Admin']);
require_once __DIR__ . '/../includes/db.php';

$role = (string)($_SESSION['role'] ?? '');
$is_registrar = $role === 'Registrar';
$is_academic_formator = $role === 'AcademicFormator';

// RBAC: Rector and Admin roles have strictly Read-Only access
$is_admin_or_rector = (
    strtolower($role) === 'admin' ||
    strtolower($role) === 'rector' ||
    strtolower($role) === 'dean' ||
    $role === 'Admin' ||
    $role === 'Rector' ||
    $role === 'Dean'
);
$can_edit = ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && !$is_admin_or_rector);

// Backend Protection: Block any forced URL / POST bypassing for Admin and Rector
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'rector' || $is_admin_or_rector)) {
    if (isset($_POST['create_version']) || isset($_POST['clone_curriculum']) || isset($_POST['set_current_version']) || 
        isset($_POST['toggle_version_active']) || isset($_POST['archive_version']) || isset($_POST['restore_version']) || 
        isset($_POST['add_curriculum_subject']) || isset($_POST['toggle_curriculum_subject']) || isset($_POST['delete_curriculum_subject'])) {
        $error = 'Access denied: The Admin and Rector roles have strictly Read-Only access to the Curriculum and Subjects.';
    }
}

$programs = [
    'Bachelor of Arts in Philosophy',
    'Propaedeutic',
];
$program = trim((string)($_GET['program'] ?? ''));
if ($program === '' || !in_array($program, $programs, true)) {
    $program = $programs[0];
}

// Fetch all available Academic Years
$ay_list = [];
$res_ay = $conn->query("SELECT id, label, is_current, is_active FROM academic_years WHERE archived_at IS NULL ORDER BY (is_current = 1) DESC, start_year DESC, end_year DESC");
if ($res_ay) {
    while ($ay = $res_ay->fetch_assoc()) {
        $ay_list[] = $ay;
    }
}

// Determine default academic year
$default_ay_id = 0;
foreach ($ay_list as $ay) {
    if ((int)$ay['is_current'] === 1) {
        $default_ay_id = (int)$ay['id'];
        break;
    }
}
if ($default_ay_id === 0 && !empty($ay_list)) {
    $default_ay_id = (int)$ay_list[0]['id'];
}

// Selected academic year from GET
$selected_ay_id = (int)($_GET['academic_year_id'] ?? 0);
if ($selected_ay_id <= 0) {
    $selected_ay_id = $default_ay_id;
}
$selected_ay = null;
foreach ($ay_list as $ay) {
    if ((int)$ay['id'] === $selected_ay_id) {
        $selected_ay = $ay;
        break;
    }
}
if (!$selected_ay && !empty($ay_list)) {
    $selected_ay = $ay_list[0];
    $selected_ay_id = (int)$selected_ay['id'];
}
$selected_ay_label = (string)($selected_ay['label'] ?? '');

// Create new version for the chosen Academic Year
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['create_version'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $version_name = trim((string)($_POST['version_name'] ?? ''));
    $target_ay_id = (int)($_POST['academic_year_id'] ?? $selected_ay_id);
    if ($version_name === '') {
        $error = 'Please provide a version name.';
    } else {
        $stmt = $conn->prepare("INSERT INTO curriculum_versions (program, academic_year_id, version_name, is_active, is_current) VALUES (?, ?, ?, 1, 0) ON DUPLICATE KEY UPDATE is_active = 1");
        $stmt->bind_param('sis', $program, $target_ay_id, $version_name);
        $stmt->execute();
        $success = 'Curriculum version created.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Created curriculum version: ' . $program . ' - ' . $version_name . ' (AY ID ' . $target_ay_id . ')');
    }
}

// Clone curriculum from another Academic Year / version
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['clone_curriculum'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $source_version_id = (int)($_POST['source_version_id'] ?? 0);
    $target_ay_id = (int)($_POST['target_academic_year_id'] ?? $selected_ay_id);
    $new_version_name = trim((string)($_POST['new_version_name'] ?? ''));

    if ($source_version_id <= 0 || $target_ay_id <= 0) {
        $error = 'Please select a source curriculum version.';
    } else {
        $stmt_src = $conn->prepare("SELECT * FROM curriculum_versions WHERE id = ? LIMIT 1");
        $stmt_src->bind_param('i', $source_version_id);
        $stmt_src->execute();
        $src_row = $stmt_src->get_result()->fetch_assoc();

        if (!$src_row) {
            $error = 'Source curriculum version not found.';
        } else {
            if ($new_version_name === '') {
                $tgt_label = $selected_ay_label;
                foreach ($ay_list as $ay) {
                    if ((int)$ay['id'] === $target_ay_id) {
                        $tgt_label = $ay['label'];
                        break;
                    }
                }
                $new_version_name = ($program === 'Propaedeutic' ? 'Propaedeutic' : 'AB Philosophy') . ' (AY ' . $tgt_label . ')';
            }

            $conn->begin_transaction();
            try {
                // Set existing versions for target year as non-current
                $stmt_rst = $conn->prepare("UPDATE curriculum_versions SET is_current = 0 WHERE program = ? AND academic_year_id = ?");
                $stmt_rst->bind_param('si', $program, $target_ay_id);
                $stmt_rst->execute();

                $stmt_nv = $conn->prepare("INSERT INTO curriculum_versions (program, academic_year_id, version_name, is_active, is_current) VALUES (?, ?, ?, 1, 1)");
                $stmt_nv->bind_param('sis', $program, $target_ay_id, $new_version_name);
                $stmt_nv->execute();
                $new_version_id = $conn->insert_id;

                $stmt_sub_copy = $conn->prepare(
                    "INSERT INTO curriculum_subjects (curriculum_version_id, academic_year_id, subject_id, prerequisite_subject_id, year_level, semester, is_active, sort_order)\n"
                    . "SELECT ?, ?, subject_id, prerequisite_subject_id, year_level, semester, is_active, sort_order\n"
                    . "FROM curriculum_subjects WHERE curriculum_version_id = ?"
                );
                $stmt_sub_copy->bind_param('iii', $new_version_id, $target_ay_id, $source_version_id);
                $stmt_sub_copy->execute();
                $copied_count = $stmt_sub_copy->affected_rows;

                $conn->commit();
                $success = "Successfully cloned " . $copied_count . " subjects into '" . $new_version_name . "' for AY " . $selected_ay_label . ".";
                log_activity((int)($_SESSION['user'] ?? 0), "Cloned curriculum version ID $source_version_id to $new_version_id for AY ID $target_ay_id");
                $selected_version_id = $new_version_id;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to clone curriculum: ' . $e->getMessage();
            }
        }
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['set_current_version'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $conn->begin_transaction();
        try {
            $stmt_cur = $conn->prepare("SELECT version_name, academic_year_id FROM curriculum_versions WHERE id = ? AND program = ? AND archived_at IS NULL LIMIT 1");
            $stmt_cur->bind_param('is', $id, $program);
            $stmt_cur->execute();
            $row = $stmt_cur->get_result()->fetch_assoc();
            $vname = trim((string)($row['version_name'] ?? ''));
            $v_ay_id = (int)($row['academic_year_id'] ?? 0);
            if ($vname === '') {
                throw new Exception('Version not found.');
            }
            if ($v_ay_id > 0) {
                $stmt0 = $conn->prepare("UPDATE curriculum_versions SET is_current = 0 WHERE program = ? AND academic_year_id = ?");
                $stmt0->bind_param('si', $program, $v_ay_id);
            } else {
                $stmt0 = $conn->prepare("UPDATE curriculum_versions SET is_current = 0 WHERE program = ?");
                $stmt0->bind_param('s', $program);
            }
            $stmt0->execute();

            $stmt1 = $conn->prepare("UPDATE curriculum_versions SET is_current = 1, is_active = 1 WHERE id = ?");
            $stmt1->bind_param('i', $id);
            $stmt1->execute();
            $conn->commit();
            $success = 'Current curriculum version updated.';
            log_activity((int)($_SESSION['user'] ?? 0), 'Set current curriculum: ' . $program . ' - ' . $vname . ' (AY ID ' . $v_ay_id . ')');
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to set current version.';
        }
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['toggle_version_active'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE curriculum_versions SET is_active = ?, is_current = CASE WHEN ? = 0 THEN 0 ELSE is_current END WHERE id = ? AND program = ? AND archived_at IS NULL");
        $stmt->bind_param('iiis', $is_active, $is_active, $id, $program);
        $stmt->execute();
        $success = 'Version updated.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Updated curriculum version active status (ID ' . $id . ')');
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['archive_version'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE curriculum_versions SET archived_at = NOW(), is_active = 0, is_current = 0 WHERE id = ? AND program = ?");
        $stmt->bind_param('is', $id, $program);
        $stmt->execute();
        $success = 'Version archived.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Archived curriculum version (ID ' . $id . ')');
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['restore_version'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE curriculum_versions SET archived_at = NULL, is_active = 1 WHERE id = ? AND program = ?");
        $stmt->bind_param('is', $id, $program);
        $stmt->execute();
        $success = 'Version restored.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Restored curriculum version (ID ' . $id . ')');
    }
}

// Fetch curriculum versions for selected Program & Academic Year
$versions = [];
$stmt_v = $conn->prepare("SELECT cv.*, ay.label as ay_label FROM curriculum_versions cv LEFT JOIN academic_years ay ON ay.id = cv.academic_year_id WHERE cv.program = ? AND (cv.academic_year_id = ? OR cv.academic_year_id IS NULL) ORDER BY (cv.is_current = 1) DESC, cv.id DESC");
$stmt_v->bind_param('si', $program, $selected_ay_id);
$stmt_v->execute();
$res_v = $stmt_v->get_result();
while ($r = $res_v->fetch_assoc()) {
    $versions[] = $r;
}

// Fetch cloneable versions across any AY for cloning
$all_cloneable_versions = [];
$stmt_acv = $conn->prepare(
    "SELECT cv.id, cv.program, cv.version_name, cv.academic_year_id, ay.label as ay_label, COUNT(cs.id) as sub_count\n"
    . "FROM curriculum_versions cv\n"
    . "LEFT JOIN academic_years ay ON ay.id = cv.academic_year_id\n"
    . "LEFT JOIN curriculum_subjects cs ON cs.curriculum_version_id = cv.id\n"
    . "WHERE cv.program = ? AND cv.archived_at IS NULL\n"
    . "GROUP BY cv.id\n"
    . "ORDER BY (cv.is_current = 1) DESC, cv.id DESC"
);
$stmt_acv->bind_param('s', $program);
$stmt_acv->execute();
$res_acv = $stmt_acv->get_result();
while ($row_c = $res_acv->fetch_assoc()) {
    $all_cloneable_versions[] = $row_c;
}

$current_version_id = 0;
foreach ($versions as $v) {
    if ((int)($v['is_current'] ?? 0) === 1 && ($v['archived_at'] ?? null) === null) {
        $current_version_id = (int)($v['id'] ?? 0);
        break;
    }
}
if ($current_version_id === 0) {
    foreach ($versions as $v) {
        if (($v['archived_at'] ?? null) === null) {
            $current_version_id = (int)($v['id'] ?? 0);
            break;
        }
    }
}

if (!isset($selected_version_id)) {
    $selected_version_id = (int)($_GET['version_id'] ?? 0);
}
if ($selected_version_id <= 0) {
    $selected_version_id = $current_version_id;
}
$selected_version = null;
foreach ($versions as $v) {
    if ((int)($v['id'] ?? 0) === $selected_version_id) {
        $selected_version = $v;
        break;
    }
}
if (!$selected_version) {
    $selected_version_id = $current_version_id;
    foreach ($versions as $v) {
        if ((int)($v['id'] ?? 0) === $selected_version_id) {
            $selected_version = $v;
            break;
        }
    }
}

$year_levels = ['Propaedeutic Stage','First Year','Second Year','Third Year','Fourth Year','Completed'];
$semesters = [
    '1st' => 'First Semester',
    '2nd' => 'Second Semester',
];

$all_subjects = [];
$res_subs = $conn->query("SELECT subject_id, subject_code, subject_name FROM subjects WHERE archived_at IS NULL ORDER BY subject_code ASC");
if ($res_subs) {
    while ($sub = $res_subs->fetch_assoc()) {
        $all_subjects[] = $sub;
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['add_curriculum_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $version_id = (int)($_POST['curriculum_version_id'] ?? 0);
    $target_ay_id = (int)($_POST['academic_year_id'] ?? $selected_ay_id);
    $subject_code = trim((string)($_POST['subject_code'] ?? ''));
    $subject_name = trim((string)($_POST['subject_name'] ?? ''));
    $units = (float)($_POST['units'] ?? 0);
    $year_level = trim((string)($_POST['year_level'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $prereq_id = (int)($_POST['prerequisite_subject_id'] ?? 0);
    $prereq_val = $prereq_id > 0 ? $prereq_id : null;
    $is_active = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($version_id <= 0) {
        $error = 'Please select a curriculum version.';
    } elseif ($subject_code === '' || $subject_name === '') {
        $error = 'Please provide subject code and title.';
    } elseif (!in_array($year_level, $year_levels, true)) {
        $error = 'Invalid year level.';
    } elseif (!array_key_exists($semester, $semesters)) {
        $error = 'Invalid semester.';
    } else {
        $stmt_sub = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, units, prerequisite_subject_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), units = VALUES(units), prerequisite_subject_id = COALESCE(VALUES(prerequisite_subject_id), prerequisite_subject_id), updated_at = NOW()");
        $stmt_sub->bind_param('ssdi', $subject_code, $subject_name, $units, $prereq_val);
        $stmt_sub->execute();

        $stmt_sid = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_code = ? LIMIT 1");
        $stmt_sid->bind_param('s', $subject_code);
        $stmt_sid->execute();
        $sid_row = $stmt_sid->get_result()->fetch_assoc();
        $subject_id = (int)($sid_row['subject_id'] ?? 0);
        if ($subject_id <= 0) {
            $error = 'Failed to create subject.';
        } else {
            $stmt_ins = $conn->prepare(
                "INSERT INTO curriculum_subjects (curriculum_version_id, academic_year_id, subject_id, prerequisite_subject_id, year_level, semester, is_active, sort_order) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, 0) "
                . "ON DUPLICATE KEY UPDATE prerequisite_subject_id = COALESCE(VALUES(prerequisite_subject_id), prerequisite_subject_id), is_active = VALUES(is_active), academic_year_id = VALUES(academic_year_id)"
            );
            $stmt_ins->bind_param('iiiissi', $version_id, $target_ay_id, $subject_id, $prereq_val, $year_level, $semester, $is_active);
            $stmt_ins->execute();
            $success = 'Curriculum subject added to AY ' . $selected_ay_label . '.';
            log_activity((int)($_SESSION['user'] ?? 0), 'Added curriculum subject: ' . $program . ' - ' . $subject_code . ' (' . $year_level . ' ' . $semester . ') AY ' . $selected_ay_label);
        }
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['toggle_curriculum_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE curriculum_subjects SET is_active = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ii', $is_active, $id);
        $stmt->execute();
        $success = 'Curriculum subject updated.';
    }
}

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && isset($_POST['delete_curriculum_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM curriculum_subjects WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $success = 'Curriculum subject removed.';
    }
}

// Grouped curriculum data structure: Year Level -> Semester -> Subjects
$items = [];
$grouped_curriculum = [];
$total_curriculum_units = 0.0;
$total_curriculum_subjects = 0;

if (!function_exists('cm_format_units')) {
    /** Format unit counts cleanly, preserving decimals (e.g. 1.5, 22.5, 47.5) without float noise. */
    function cm_format_units(float $units): string {
        $rounded = round($units, 1);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (string)(int)round($rounded);
        }
        return number_format($rounded, 1);
    }
}

$canonical_years = [
    'Propaedeutic Stage',
    'First Year',
    'Second Year',
    'Third Year',
    'Fourth Year',
    'Completed'
];

if ($selected_version_id > 0) {
    $stmt_i = $conn->prepare(
        "SELECT cs.*, s.subject_code, s.subject_name, s.units,\n"
        . "       COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id) AS effective_prereq_id,\n"
        . "       pr.subject_code AS prerequisite_code, pr.subject_name AS prerequisite_name,\n"
        . "       ay.label AS academic_year_label\n"
        . "FROM curriculum_subjects cs\n"
        . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
        . "LEFT JOIN academic_years ay ON ay.id = cs.academic_year_id\n"
        . "LEFT JOIN subjects pr ON pr.subject_id = COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id)\n"
        . "WHERE cs.curriculum_version_id = ?\n"
        . "  AND (cs.academic_year_id = ? OR cs.academic_year_id IS NULL)\n"
        . "ORDER BY cs.year_level ASC, cs.semester ASC, cs.sort_order ASC, s.subject_code ASC"
    );
    $stmt_i->bind_param('ii', $selected_version_id, $selected_ay_id);
    $stmt_i->execute();
    $res_i = $stmt_i->get_result();

    while ($r = $res_i->fetch_assoc()) {
        $items[] = $r;
        $total_curriculum_subjects++;
        $units = (float)($r['units'] ?? 0);
        $total_curriculum_units += $units;

        $yl = trim((string)($r['year_level'] ?? ''));
        if ($yl === '') $yl = 'Unassigned';

        $sem = trim((string)($r['semester'] ?? ''));
        if ($sem === '') $sem = '1st';

        if (!isset($grouped_curriculum[$yl])) {
            $grouped_curriculum[$yl] = [
                'year_level' => $yl,
                'total_units' => 0,
                'total_subjects' => 0,
                'semesters' => [
                    '1st' => [
                        'semester_key' => '1st',
                        'semester_label' => '1st Semester',
                        'total_units' => 0,
                        'total_subjects' => 0,
                        'subjects' => []
                    ],
                    '2nd' => [
                        'semester_key' => '2nd',
                        'semester_label' => '2nd Semester',
                        'total_units' => 0,
                        'total_subjects' => 0,
                        'subjects' => []
                    ]
                ]
            ];
        }

        if (!isset($grouped_curriculum[$yl]['semesters'][$sem])) {
            $grouped_curriculum[$yl]['semesters'][$sem] = [
                'semester_key' => $sem,
                'semester_label' => ($semesters[$sem] ?? ($sem . ' Semester')),
                'total_units' => 0,
                'total_subjects' => 0,
                'subjects' => []
            ];
        }

        $grouped_curriculum[$yl]['total_units'] += $units;
        $grouped_curriculum[$yl]['total_subjects']++;
        $grouped_curriculum[$yl]['semesters'][$sem]['total_units'] += $units;
        $grouped_curriculum[$yl]['semesters'][$sem]['total_subjects']++;
        $grouped_curriculum[$yl]['semesters'][$sem]['subjects'][] = $r;
    }

    uksort($grouped_curriculum, function($a, $b) use ($canonical_years) {
        $pos_a = array_search($a, $canonical_years, true);
        $pos_b = array_search($b, $canonical_years, true);
        if ($pos_a === false) $pos_a = 999;
        if ($pos_b === false) $pos_b = 999;
        if ($pos_a === $pos_b) return strcmp($a, $b);
        return $pos_a <=> $pos_b;
    });
}

$pageTitle = 'Curriculum';
$activeNav = 'curriculum_master';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body minimal-container">
            <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin: 0 0 0.25rem 0;"><i class="fas fa-book-open"></i> Curriculum Master</h1>
                    <div style="color: var(--text-secondary); font-size: 0.85rem;">Manage academic programs, curriculum versions per school year, and required subjects per year level and semester.</div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <!-- Academic Year Filter Dropdown -->
                    <div style="display: flex; align-items: center; gap: 0.4rem; background: #ffffff; padding: 0.35rem 0.85rem; border-radius: 999px; border: 1.5px solid #0f766e; box-shadow: 0 1px 3px rgba(15,118,110,0.12);">
                        <label for="ayFilterSelect" style="font-weight: 700; font-size: 0.82rem; color: #0f766e; margin: 0; white-space: nowrap; display: flex; align-items: center; gap: 0.35rem;">
                            <i class="fas fa-calendar-days"></i> Academic Year:
                        </label>
                        <select id="ayFilterSelect" style="border: none; background: transparent; font-weight: 800; color: #0f172a; font-size: 0.875rem; outline: none; cursor: pointer; padding-right: 0.5rem;">
                            <?php foreach ($ay_list as $ay) { ?>
                                <option value="<?php echo (int)$ay['id']; ?>" <?php echo (int)$ay['id'] === $selected_ay_id ? 'selected' : ''; ?>>
                                    AY <?php echo htmlspecialchars($ay['label']); ?><?php echo (int)$ay['is_current'] === 1 ? ' (Current)' : ''; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <!-- 1-Click Program Segmented Control -->
                    <div class="pill-group">
                        <?php foreach ($programs as $p) { ?>
                            <a href="curriculum_master.php?program=<?php echo urlencode($p); ?>&academic_year_id=<?php echo $selected_ay_id; ?>"
                               class="pill-btn <?php echo $p === $program ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($p); ?>
                            </a>
                        <?php } ?>
                    </div>
                    <!-- Hidden for backward compat with JS -->
                    <select id="programSelect" style="display: none;">
                        <?php foreach ($programs as $p) { ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $program ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <!-- Active Curriculum Scope Context Pill -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.65rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; font-size: 0.85rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <span style="font-weight: 700; color: #0f172a;"><i class="fas fa-filter" style="color: #0f766e;"></i> Active Scope:</span>
                    <span class="status-badge status-info"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($program); ?></span>
                    <span class="status-badge status-cleared"><i class="fas fa-calendar-check"></i> AY <?php echo htmlspecialchars($selected_ay_label); ?></span>
                    <?php if ($selected_version) { ?>
                        <span class="status-badge status-warning"><i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($selected_version['version_name']); ?></span>
                    <?php } else { ?>
                        <span class="status-badge status-muted"><i class="fas fa-circle-exclamation"></i> No Version Active</span>
                    <?php } ?>
                </div>
                <div style="color: #64748b; font-size: 0.78rem; font-weight: 500;">
                    <i class="fas fa-shield-halved text-emerald-600"></i> Modifying this curriculum will not alter records of students enrolled under other school years.
                </div>
            </div>

            <?php if (isset($success)) echo "<p class='success' style='margin-bottom: 1rem;'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error' style='margin-bottom: 1rem;'>" . htmlspecialchars($error) . "</p>"; ?>

            <!-- Compact Curriculum Control Panel (Replaces Bulky Version Boxes and Inline Add Form) -->
            <div class="rounded-3xl bg-white border border-[#D6A6A6]/40 shadow-lg p-5 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <!-- Left: Active Version Display & Dropdown -->
                    <div class="flex items-center gap-3.5 flex-wrap">
                        <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-lg shadow-2xs">
                            <i class="fas fa-code-branch"></i>
                        </div>
                        <div>
                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                <span>Curriculum Version</span>
                                <span class="text-[#D6A6A6]">&bull;</span>
                                <span class="text-[#59443F] font-semibold">AY <?php echo htmlspecialchars($selected_ay_label); ?></span>
                            </div>
                            <div class="flex items-center gap-2.5 mt-1 flex-wrap">
                                <select id="versionSelect" class="text-xs sm:text-sm font-extrabold text-[#59443F] bg-[#F8EEEE] border border-[#D6A6A6]/60 rounded-xl px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[#59443F]/20 cursor-pointer shadow-2xs">
                                    <?php if (empty($versions)) { ?>
                                        <option value="">No versions in AY <?php echo htmlspecialchars($selected_ay_label); ?></option>
                                    <?php } else { foreach ($versions as $v) { 
                                        $vid = (int)($v['id'] ?? 0);
                                        $label = (string)($v['version_name'] ?? '');
                                        $archived = ($v['archived_at'] ?? null) !== null;
                                        $cur = (int)($v['is_current'] ?? 0) === 1 && !$archived;
                                    ?>
                                        <option value="<?php echo $vid; ?>" <?php echo $vid === $selected_version_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label . ($cur ? ' (Current Active)' : '') . ($archived ? ' (Archived)' : '')); ?>
                                        </option>
                                    <?php } } ?>
                                </select>

                                <?php if ($selected_version): ?>
                                    <?php if (($selected_version['archived_at'] ?? null) !== null): ?>
                                        <span class="px-2.5 py-1 text-[11px] font-extrabold rounded-full bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs">
                                            <i class="fas fa-box-archive mr-1"></i> Archived
                                        </span>
                                    <?php elseif ((int)($selected_version['is_current'] ?? 0) === 1): ?>
                                        <span class="px-2.5 py-1 text-[11px] font-extrabold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">
                                            <i class="fas fa-check-circle mr-1"></i> Current Active
                                        </span>
                                    <?php elseif ((int)($selected_version['is_active'] ?? 0) === 1): ?>
                                        <span class="px-2.5 py-1 text-[11px] font-extrabold rounded-full bg-blue-50 text-blue-700 border border-blue-200 shadow-2xs">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 text-[11px] font-extrabold rounded-full bg-amber-50 text-amber-700 border border-amber-200 shadow-2xs">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Stylized Action Buttons Row -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit): ?>
                            <!-- Prominent "+ Add Subject to Curriculum" Primary Action Button -->
                            <button type="button" id="openAddSubjectBtn" onclick="openModal('addSubjectModal')" class="btn-trigger-add-subject inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white font-extrabold text-xs shadow-md transition-all hover:scale-[1.02] cursor-pointer">
                                <i class="fas fa-plus-circle text-xs"></i>
                                <span>+ Add Subject to Curriculum</span>
                            </button>

                            <!-- "+ New Version" Button -->
                            <button type="button" id="openNewVersionBtn" onclick="openModal('newVersionModal')" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-[#E8C7C5] hover:bg-[#D6A6A6] text-[#59443F] font-extrabold text-xs shadow-2xs border border-[#D6A6A6]/60 transition-all hover:scale-[1.02] cursor-pointer">
                                <i class="fas fa-plus text-xs"></i>
                                <span>New Version</span>
                            </button>

                            <!-- "Clone Curriculum" Button -->
                            <button type="button" id="openCloneCurriculumBtn" onclick="openModal('cloneCurriculumModal')" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white hover:bg-[#F8EEEE] text-[#59443F] font-extrabold text-xs shadow-2xs border border-[#D6A6A6] transition-all hover:scale-[1.02] cursor-pointer">
                                <i class="fas fa-clone text-xs"></i>
                                <span>Clone Curriculum</span>
                            </button>
                        <?php endif; ?>

                        <!-- Toggle Versions Table Button -->
                        <button type="button" id="toggleVersionsTableBtn" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[#F8EEEE] hover:bg-white text-[#8B7168] hover:text-[#59443F] border border-[#D6A6A6]/40 font-bold text-xs transition-all shadow-2xs">
                            <i class="fas fa-list-check text-xs"></i>
                            <span>Manage Versions (<?php echo count($versions); ?>)</span>
                            <i class="fas fa-chevron-down text-[10px] ml-0.5 transition-transform" id="versionsToggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Collapsible Versions Management Table -->
                <div id="versionsTableCollapse" style="display: none;" class="mt-5 pt-5 border-t border-[#F8EEEE] transition-all">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-extrabold text-[#59443F] uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-table-list text-[#8B7168]"></i>
                            Versions List for <?php echo htmlspecialchars($program); ?> (AY <?php echo htmlspecialchars($selected_ay_label); ?>)
                        </h4>
                        <span class="text-[11px] text-[#8B7168] font-medium">Click a version name to inspect its syllabus</span>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-[#D6A6A6]/30 bg-[#FAF5F4]/30">
                        <table class="minimal-table w-full text-xs">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                    <th>Current Active</th>
                                    <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                                    <th style="text-align: right;">Actions</th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($versions) === 0) { ?>
                                    <tr>
                                        <td colspan="<?php echo ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) ? '5' : '4'; ?>" style="color: var(--text-secondary); text-align: center; padding: 2rem 1rem;">
                                            <i class="fas fa-calendar-xmark text-2xl" style="display: block; margin-bottom: 0.5rem; color: #94a3b8;"></i>
                                            No curriculum versions exist for <strong><?php echo htmlspecialchars($program); ?></strong> in <strong>AY <?php echo htmlspecialchars($selected_ay_label); ?></strong>.
                                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #64748b;">
                                                Use <strong>"Clone Curriculum"</strong> above to quickly duplicate subjects from another school year, or create a <strong>"New Version"</strong>.
                                            </div>
                                        </td>
                                    </tr>
                                <?php } else { foreach ($versions as $v) { ?>
                                    <?php
                                        $vid = (int)($v['id'] ?? 0);
                                        $label = (string)($v['version_name'] ?? '');
                                        $v_ay_label = (string)($v['ay_label'] ?? $selected_ay_label);
                                        $archived = ($v['archived_at'] ?? null) !== null;
                                        $is_active = (int)($v['is_active'] ?? 0) === 1;
                                        $is_current = (int)($v['is_current'] ?? 0) === 1 && !$archived;
                                    ?>
                                    <tr style="<?php echo $archived ? 'opacity: 0.6;' : ''; ?>">
                                        <td>
                                            <a href="curriculum_master.php?program=<?php echo urlencode($program); ?>&academic_year_id=<?php echo $selected_ay_id; ?>&version_id=<?php echo $vid; ?>" style="font-weight: 700; color: #0f172a; text-decoration: none;">
                                                <?php echo htmlspecialchars($label); ?>
                                                <?php if ($vid === $selected_version_id) { ?><span class="status-badge status-info" style="margin-left: 6px;">Selected</span><?php } ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="status-badge status-cleared" style="font-size: 0.75rem;">
                                                <i class="fas fa-calendar-alt"></i> AY <?php echo htmlspecialchars($v_ay_label ?: $selected_ay_label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                                if ($archived) {
                                                    echo "<span class='status-badge status-muted'>Archived</span>";
                                                } elseif ($is_active) {
                                                    echo "<span class='status-badge status-cleared'>Active</span>";
                                                } else {
                                                    echo "<span class='status-badge status-warning'>Inactive</span>";
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo $is_current ? "<span class='status-badge status-info'><i class='fas fa-check-circle'></i> Current</span>" : "<span style='color: var(--text-secondary); font-weight: 600;'>—</span>"; ?></td>
                                        <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <div style="display: inline-flex; flex-wrap: wrap; gap: 0.35rem; justify-content: flex-end;">
                                                <?php if (!$archived) { ?>
                                                    <form method="post" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $vid; ?>">
                                                        <button type="submit" name="set_current_version" class="action-btn-primary" style="padding: 0.35rem 0.65rem;" <?php echo $is_current ? 'disabled' : ''; ?> title="Set as Current Curriculum for AY <?php echo htmlspecialchars($selected_ay_label); ?>">
                                                            <i class="fas fa-flag"></i> Current
                                                        </button>
                                                    </form>
                                                    <form method="post" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $vid; ?>">
                                                        <input type="hidden" name="is_active" value="<?php echo $is_active ? 0 : 1; ?>">
                                                        <button type="submit" name="toggle_version_active" class="action-btn-secondary" style="padding: 0.35rem 0.65rem;">
                                                            <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" style="margin: 0; display: inline;" onsubmit="return confirm('Archive curriculum version?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $vid; ?>">
                                                        <button type="submit" name="archive_version" class="action-btn-danger" style="padding: 0.35rem 0.65rem;" title="Archive Version">
                                                            <i class="fas fa-box-archive"></i>
                                                        </button>
                                                    </form>
                                                <?php } else { ?>
                                                    <form method="post" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $vid; ?>">
                                                        <button type="submit" name="restore_version" class="action-btn-secondary" style="padding: 0.35rem 0.65rem;">Restore</button>
                                                    </form>
                                                <?php } ?>
                                            </div>
                                        </td>
                                        <?php } ?>
                                    </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Curriculum Subjects Toolbar with Live Search & Navigation -->
            <div class="minimal-card" style="margin-bottom: 1.25rem;">
                <div class="minimal-toolbar">
                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <h3 style="margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a;">
                            <i class="fas fa-layer-group" style="color: #0f766e; margin-right: 0.25rem;"></i> Curriculum Structure (AY <?php echo htmlspecialchars($selected_ay_label); ?>)
                        </h3>
                        <span class="status-badge status-info" style="font-size: 0.8rem; font-weight: 700; padding: 0.25rem 0.65rem;">
                            <span id="totalSubjectsDisplay"><?php echo $total_curriculum_subjects; ?></span> Subjects
                        </span>
                        <span class="status-badge status-cleared" style="font-size: 0.8rem; font-weight: 700; padding: 0.25rem 0.65rem;">
                            <?php echo cm_format_units((float)$total_curriculum_units); ?> Units Total
                        </span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <div class="search-container" style="max-width: 280px;">
                            <i class="fas fa-search"></i>
                            <input type="text" id="curriculumSearch" placeholder="Search code, title or prereq..." autocomplete="off">
                            <span class="search-kbd">/</span>
                        </div>
                        <div class="pill-group" id="yearFilterPills">
                            <button type="button" class="pill-btn active" data-year="all">All Years</button>
                            <?php foreach ($grouped_curriculum as $yl => $ydata) { ?>
                                <button type="button" class="pill-btn" data-year="<?php echo htmlspecialchars($yl); ?>">
                                    <?php echo htmlspecialchars($yl); ?>
                                </button>
                            <?php } ?>
                        </div>
                        <button type="button" id="toggleAllYearsBtn" class="action-btn-secondary" style="padding: 0.4rem 0.75rem; font-size: 0.8rem;" title="Expand or Collapse All Year Levels">
                            <i class="fas fa-angles-up"></i> Collapse All
                        </button>
                        <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit && $selected_version_id > 0) { ?>
                            <button type="button" onclick="openModal('addSubjectModal')" class="btn-trigger-add-subject inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white font-bold text-xs shadow-2xs transition-all hover:scale-[1.02] cursor-pointer">
                                <i class="fas fa-plus text-[10px]"></i>
                                <span>+ Add Subject</span>
                            </button>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Main Grouped Curriculum Container -->
            <div id="curriculumGroupedContainer">
                <?php if ($selected_version_id <= 0) { ?>
                    <div class="minimal-card" style="padding: 3rem 1.5rem; text-align: center; color: var(--text-secondary);">
                        <i class="fas fa-calendar-xmark text-4xl" style="display: block; margin-bottom: 0.75rem; color: #94a3b8;"></i>
                        <h4 style="margin: 0; color: #334155; font-size: 1.1rem;">No curriculum version for AY <?php echo htmlspecialchars($selected_ay_label); ?></h4>
                        <p style="margin: 0.35rem 0 1.25rem 0; font-size: 0.875rem;">Create a new version or clone an existing curriculum from another school year above to get started.</p>
                        <?php if (!empty($all_cloneable_versions) && $_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                            <form method="post" style="display: inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="target_academic_year_id" value="<?php echo (int)$selected_ay_id; ?>">
                                <input type="hidden" name="source_version_id" value="<?php echo (int)$all_cloneable_versions[0]['id']; ?>">
                                <button type="submit" name="clone_curriculum" class="action-btn-primary" style="padding: 0.55rem 1.25rem; font-size: 0.9rem;">
                                    <i class="fas fa-clone"></i> Clone from <?php echo htmlspecialchars($all_cloneable_versions[0]['version_name']); ?>
                                </button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } elseif (count($grouped_curriculum) === 0) { ?>
                    <div class="minimal-card" style="padding: 3rem 1.5rem; text-align: center; color: var(--text-secondary);">
                        <i class="fas fa-folder-open text-4xl" style="display: block; margin-bottom: 0.75rem; color: #94a3b8;"></i>
                        <h4 style="margin: 0; color: #334155; font-size: 1.1rem;">No subjects assigned to this version in AY <?php echo htmlspecialchars($selected_ay_label); ?></h4>
                        <p style="margin: 0.35rem 0 1.25rem 0; font-size: 0.875rem;">Use the "Add Subject to Curriculum" form above or clone subjects from another year.</p>
                        <?php if (!empty($all_cloneable_versions) && $_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                            <form method="post" style="display: inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="target_academic_year_id" value="<?php echo (int)$selected_ay_id; ?>">
                                <input type="hidden" name="source_version_id" value="<?php echo (int)$all_cloneable_versions[0]['id']; ?>">
                                <button type="submit" name="clone_curriculum" class="action-btn-primary" style="padding: 0.55rem 1.25rem; font-size: 0.9rem;">
                                    <i class="fas fa-clone"></i> Import Subjects from <?php echo htmlspecialchars($all_cloneable_versions[0]['version_name']); ?>
                                </button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <?php foreach ($grouped_curriculum as $yl => $ydata) { 
                        $yl_slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($yl));
                        $yl_icon = 'fa-graduation-cap';
                        if (stripos($yl, 'propaedeutic') !== false) $yl_icon = 'fa-seedling';
                        elseif (stripos($yl, 'first') !== false || stripos($yl, '1st') !== false) $yl_icon = 'fa-1';
                        elseif (stripos($yl, 'second') !== false || stripos($yl, '2nd') !== false) $yl_icon = 'fa-2';
                        elseif (stripos($yl, 'third') !== false || stripos($yl, '3rd') !== false) $yl_icon = 'fa-3';
                        elseif (stripos($yl, 'fourth') !== false || stripos($yl, '4th') !== false) $yl_icon = 'fa-4';
                    ?>
                        <div class="minimal-card year-card" data-year="<?php echo htmlspecialchars($yl); ?>" id="year-card-<?php echo htmlspecialchars($yl_slug); ?>" style="margin-bottom: 1.5rem; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                            <!-- Year Level Distinct Header Banner -->
                            <div class="year-header" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 0.85rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 36px; height: 36px; border-radius: 8px; background: #0f766e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; font-weight: 800; box-shadow: 0 2px 4px rgba(15, 118, 110, 0.25);">
                                        <i class="fas <?php echo $yl_icon; ?>"></i>
                                    </span>
                                    <div>
                                        <h2 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a; line-height: 1.2;"><?php echo htmlspecialchars($ydata['year_level']); ?></h2>
                                        <span style="font-size: 0.78rem; color: #64748b; font-weight: 600;">
                                            <?php echo htmlspecialchars($program); ?> &middot; <?php echo htmlspecialchars($selected_version['version_name'] ?? ''); ?> &middot; AY <?php echo htmlspecialchars($selected_ay_label); ?>
                                        </span>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                    <span class="status-badge status-info" style="font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.65rem;">
                                        <i class="fas fa-book"></i> <span class="year-sub-count"><?php echo $ydata['total_subjects']; ?></span> Subjects
                                    </span>
                                    <span class="status-badge status-cleared" style="font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.65rem;">
                                        <i class="fas fa-clock"></i> <?php echo cm_format_units((float)$ydata['total_units']); ?> Units Total
                                    </span>
                                    <button type="button" class="action-btn-secondary btn-collapse-year" data-target="year-body-<?php echo htmlspecialchars($yl_slug); ?>" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" title="Toggle Expand/Collapse">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Year Level Content: Distinct Semester Sub-Sections -->
                            <div class="year-body" id="year-body-<?php echo htmlspecialchars($yl_slug); ?>" style="padding: 1.25rem;">
                                <div class="curriculum-semesters-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 1.25rem; align-items: start;">
                                    <?php foreach ($ydata['semesters'] as $semKey => $semData) { 
                                        $subjects_list = $semData['subjects'] ?? [];
                                        $has_subjects = count($subjects_list) > 0;
                                        // For Propaedeutic program with no 2nd sem subjects, hide 2nd sem column so 1st sem expands smoothly
                                        if (!$has_subjects && $program === 'Propaedeutic' && $semKey === '2nd') {
                                            continue;
                                        }
                                    ?>
                                        <div class="semester-card" style="border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                            <!-- Semester Sub-section Header -->
                                            <div class="semester-header" style="background: #f1f5f9; padding: 0.7rem 1rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                                                <div style="display: flex; align-items: center; gap: 0.45rem; font-weight: 700; color: #0f172a; font-size: 0.925rem;">
                                                    <i class="fas fa-calendar-check" style="color: #0f766e;"></i>
                                                    <span><?php echo htmlspecialchars($semData['semester_label']); ?></span>
                                                </div>
                                                <span style="font-size: 0.78rem; font-weight: 700; color: #475569; background: #ffffff; padding: 2px 8px; border-radius: 999px; border: 1px solid #cbd5e1;">
                                                    <?php echo cm_format_units((float)$semData['total_units']); ?> Units &middot; <?php echo $semData['total_subjects']; ?> Subjects
                                                </span>
                                            </div>

                                            <!-- Distinct Semester Table -->
                                            <div style="overflow-x: auto;">
                                                <table class="minimal-table semester-table" style="margin: 0; font-size: 0.85rem;">
                                                    <thead>
                                                        <tr style="background: #fafafa;">
                                                            <th style="min-width: 90px; padding: 0.6rem 0.85rem;">Code</th>
                                                            <th style="min-width: 170px; padding: 0.6rem 0.85rem;">Description</th>
                                                            <th style="text-align: center; width: 60px; padding: 0.6rem 0.5rem;">Units</th>
                                                            <th style="min-width: 110px; padding: 0.6rem 0.85rem;">Prerequisites</th>
                                                            <th style="text-align: center; width: 75px; padding: 0.6rem 0.5rem;">Status</th>
                                                            <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                                                                <th style="text-align: right; width: 95px; padding: 0.6rem 0.85rem;">Actions</th>
                                                            <?php } ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (!$has_subjects) { ?>
                                                            <tr class="empty-semester-row">
                                                                <td colspan="<?php echo ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) ? '6' : '5'; ?>" style="text-align: center; padding: 2rem 1rem; color: #94a3b8; font-size: 0.825rem;">
                                                                    <i class="fas fa-circle-info" style="display: block; margin-bottom: 0.25rem; font-size: 1rem;"></i>
                                                                    No subjects designated for <?php echo htmlspecialchars($semData['semester_label']); ?> in AY <?php echo htmlspecialchars($selected_ay_label); ?>.
                                                                </td>
                                                            </tr>
                                                        <?php } else { foreach ($subjects_list as $it) { 
                                                            $cid = (int)($it['id'] ?? 0);
                                                            $code = (string)($it['subject_code'] ?? '');
                                                            $name = (string)($it['subject_name'] ?? '');
                                                            $units = (float)($it['units'] ?? 0);
                                                            $active = (int)($it['is_active'] ?? 0) === 1;
                                                            $prereq_code = trim((string)($it['prerequisite_code'] ?? ''));
                                                        ?>
                                                            <tr class="curr-subject-row" data-search="<?php echo htmlspecialchars(strtolower($code . ' ' . $name . ' ' . $yl . ' ' . $semKey . ' ' . $prereq_code)); ?>">
                                                                <td style="padding: 0.65rem 0.85rem;">
                                                                    <strong style="color: #0f172a; font-family: monospace; font-size: 0.875rem; letter-spacing: -0.01em;"><?php echo htmlspecialchars($code); ?></strong>
                                                                </td>
                                                                <td style="padding: 0.65rem 0.85rem; font-weight: 500; color: #334155;">
                                                                    <?php echo htmlspecialchars($name); ?>
                                                                </td>
                                                                <td style="text-align: center; padding: 0.65rem 0.5rem;">
                                                                    <span class="status-badge status-info" style="font-weight: 700; padding: 2px 7px;"><?php echo cm_format_units((float)$units); ?></span>
                                                                </td>
                                                                <td style="padding: 0.65rem 0.85rem;">
                                                                    <?php if ($prereq_code !== '') { ?>
                                                                        <span class="status-badge status-warning" style="font-size: 0.75rem; padding: 2px 6px; font-weight: 700;" title="<?php echo htmlspecialchars((string)($it['prerequisite_name'] ?? '')); ?>">
                                                                            <i class="fas fa-link text-xs"></i> <?php echo htmlspecialchars($prereq_code); ?>
                                                                        </span>
                                                                    <?php } else { ?>
                                                                        <span style="color: #94a3b8; font-size: 0.8rem; font-weight: 500;">— None</span>
                                                                    <?php } ?>
                                                                </td>
                                                                <td style="text-align: center; padding: 0.65rem 0.5rem;">
                                                                    <?php echo $active ? "<span class='status-badge status-cleared' style='padding: 2px 6px; font-size: 0.72rem;'>Active</span>" : "<span class='status-badge status-muted' style='padding: 2px 6px; font-size: 0.72rem;'>Inactive</span>"; ?>
                                                                </td>
                                                                <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
                                                                    <td style="text-align: right; white-space: nowrap; padding: 0.65rem 0.85rem;">
                                                                        <div style="display: inline-flex; gap: 0.3rem; justify-content: flex-end;">
                                                                            <form method="post" style="margin: 0; display: inline;">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                                <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                                                                <input type="hidden" name="is_active" value="<?php echo $active ? 0 : 1; ?>">
                                                                                <button type="submit" name="toggle_curriculum_subject" class="action-btn-secondary" style="padding: 0.3rem 0.5rem; font-size: 0.72rem;" title="<?php echo $active ? 'Deactivate' : 'Activate'; ?>">
                                                                                    <i class="fas <?php echo $active ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                                                </button>
                                                                            </form>
                                                                            <form method="post" style="margin: 0; display: inline;" onsubmit="return confirm('Remove <?php echo htmlspecialchars($code); ?> from curriculum?');">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                                <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                                                                <button type="submit" name="delete_curriculum_subject" class="action-btn-danger" style="padding: 0.3rem 0.5rem; font-size: 0.72rem;" title="Remove Subject">
                                                                                    <i class="fas fa-trash"></i>
                                                                                </button>
                                                                            </form>
                                                                        </div>
                                                                    </td>
                                                                <?php } ?>
                                                            </tr>
                                                        <?php } } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>

        <script>
        (function () {
            // Academic Year Dropdown selector change
            const ayFilterSelect = document.getElementById('ayFilterSelect');
            if (ayFilterSelect) {
                ayFilterSelect.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('program', '<?php echo htmlspecialchars($program); ?>');
                    params.set('academic_year_id', this.value);
                    params.delete('version_id'); // Load current version for the newly selected AY
                    window.location.search = params.toString();
                });
            }

            const programSelect = document.getElementById('programSelect');
            if (programSelect) {
                programSelect.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('program', programSelect.value);
                    params.set('academic_year_id', '<?php echo (int)$selected_ay_id; ?>');
                    params.delete('version_id');
                    window.location.search = params.toString();
                });
            }

            const versionSelect = document.getElementById('versionSelect');
            if (versionSelect) {
                versionSelect.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('program', '<?php echo htmlspecialchars($program); ?>');
                    params.set('academic_year_id', '<?php echo (int)$selected_ay_id; ?>');
                    params.set('version_id', versionSelect.value);
                    window.location.search = params.toString();
                });
            }

            // Year Level Collapse / Expand Toggle
            document.querySelectorAll('.btn-collapse-year').forEach(btn => {
                btn.addEventListener('click', function () {
                    const targetId = this.getAttribute('data-target');
                    const body = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    if (!body) return;

                    if (body.style.display === 'none') {
                        body.style.display = '';
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-up');
                    } else {
                        body.style.display = 'none';
                        icon.classList.remove('fa-chevron-up');
                        icon.classList.add('fa-chevron-down');
                    }
                });
            });

            // Expand All / Collapse All Button
            let allCollapsed = false;
            const toggleAllBtn = document.getElementById('toggleAllYearsBtn');
            if (toggleAllBtn) {
                toggleAllBtn.addEventListener('click', () => {
                    allCollapsed = !allCollapsed;
                    document.querySelectorAll('.year-body').forEach(b => {
                        b.style.display = allCollapsed ? 'none' : '';
                    });
                    document.querySelectorAll('.btn-collapse-year i').forEach(icon => {
                        if (allCollapsed) {
                            icon.classList.remove('fa-chevron-up');
                            icon.classList.add('fa-chevron-down');
                        } else {
                            icon.classList.remove('fa-chevron-down');
                            icon.classList.add('fa-chevron-up');
                        }
                    });
                    toggleAllBtn.innerHTML = allCollapsed 
                        ? '<i class="fas fa-angles-down"></i> Expand All' 
                        : '<i class="fas fa-angles-up"></i> Collapse All';
                });
            }

            // Quick Year Filter Pills
            const yearPills = document.querySelectorAll('#yearFilterPills .pill-btn');
            yearPills.forEach(pill => {
                pill.addEventListener('click', function () {
                    yearPills.forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    const chosenYear = this.getAttribute('data-year');

                    document.querySelectorAll('.year-card').forEach(card => {
                        const cardYear = card.getAttribute('data-year');
                        if (chosenYear === 'all' || chosenYear === cardYear) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            });

            // Live instant search across subjects
            const currSearch = document.getElementById('curriculumSearch');
            if (currSearch) {
                currSearch.addEventListener('input', () => {
                    const q = currSearch.value.toLowerCase().trim();
                    let matchCount = 0;

                    document.querySelectorAll('.year-card').forEach(yearCard => {
                        let yearMatches = 0;

                        yearCard.querySelectorAll('.semester-card').forEach(semCard => {
                            let semMatches = 0;
                            semCard.querySelectorAll('tbody tr.curr-subject-row').forEach(tr => {
                                const hay = tr.getAttribute('data-search') || '';
                                const match = !q || hay.includes(q);
                                tr.style.display = match ? '' : 'none';
                                if (match) {
                                    semMatches++;
                                    matchCount++;
                                }
                            });

                            // Handle empty state during search
                            const emptyRow = semCard.querySelector('.empty-semester-row');
                            if (emptyRow) {
                                emptyRow.style.display = (semMatches === 0 && !q) ? '' : 'none';
                            }
                            yearMatches += semMatches;
                        });

                        // Show/hide year card if search query entered
                        if (q) {
                            yearCard.style.display = yearMatches > 0 ? '' : 'none';
                        } else {
                            // Restore pill filter state
                            const activePill = document.querySelector('#yearFilterPills .pill-btn.active');
                            const chosenYear = activePill ? activePill.getAttribute('data-year') : 'all';
                            const cardYear = yearCard.getAttribute('data-year');
                            yearCard.style.display = (chosenYear === 'all' || chosenYear === cardYear) ? '' : 'none';
                        }
                    });

                    const countDisplay = document.getElementById('totalSubjectsDisplay');
                    if (countDisplay) {
                        countDisplay.textContent = q ? matchCount : '<?php echo $total_curriculum_subjects; ?>';
                    }
                });
            }

            // Globally available Modal functions
            window.openModal = function (modalId) {
                const m = document.getElementById(modalId);
                if (m) {
                    m.classList.remove('hidden');
                    m.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            };

            window.closeModal = function (modalId) {
                const m = document.getElementById(modalId);
                if (m) {
                    m.classList.add('hidden');
                    m.style.display = 'none';
                    document.body.style.overflow = '';
                }
            };

            window.handleBackdropClick = function (e, modalId) {
                if (e.target.id === modalId) {
                    window.closeModal(modalId);
                }
            };

            // Trigger Add Subject Modal
            document.querySelectorAll('.btn-trigger-add-subject, #openAddSubjectBtn').forEach(btn => {
                btn.addEventListener('click', () => window.openModal('addSubjectModal'));
            });

            // Trigger New Version Modal
            const openNewVerBtn = document.getElementById('openNewVersionBtn');
            if (openNewVerBtn) {
                openNewVerBtn.addEventListener('click', () => window.openModal('newVersionModal'));
            }

            // Trigger Clone Curriculum Modal
            const openCloneBtn = document.getElementById('openCloneCurriculumBtn');
            if (openCloneBtn) {
                openCloneBtn.addEventListener('click', () => window.openModal('cloneCurriculumModal'));
            }

            // Click outside modal backdrop to close
            ['addSubjectModal', 'newVersionModal', 'cloneCurriculumModal'].forEach(id => {
                const modal = document.getElementById(id);
                if (modal) {
                    modal.addEventListener('click', function (e) {
                        if (e.target === this) {
                            window.closeModal(id);
                        }
                    });
                }
            });

            // Escape key to close any open modal
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    ['addSubjectModal', 'newVersionModal', 'cloneCurriculumModal'].forEach(id => window.closeModal(id));
                }
            });

            // Toggle Versions Table
            const toggleVerTableBtn = document.getElementById('toggleVersionsTableBtn');
            const versionsTableCollapse = document.getElementById('versionsTableCollapse');
            const versionsToggleIcon = document.getElementById('versionsToggleIcon');
            if (toggleVerTableBtn && versionsTableCollapse) {
                toggleVerTableBtn.addEventListener('click', () => {
                    const isHidden = versionsTableCollapse.style.display === 'none';
                    versionsTableCollapse.style.display = isHidden ? 'block' : 'none';
                    if (versionsToggleIcon) {
                        versionsToggleIcon.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                    }
                });
            }
        })();
        </script>

        <!-- ================= MODALS SECTION ================= -->
        <?php if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'rector' && $can_edit) { ?>
        <!-- 1. Modal: Add Subject to Curriculum -->
        <div id="addSubjectModal" data-global-modal="1" onclick="handleBackdropClick(event, 'addSubjectModal')" class="<?php echo htmlspecialchars(global_modal_overlay_classes('transition-opacity hidden'), ENT_QUOTES, 'UTF-8'); ?>" style="display: none;" role="dialog" aria-modal="true">
            <div onclick="event.stopPropagation()" class="bg-white rounded-xl shadow-2xl border border-[#D6A6A6]/40 max-w-xl w-full p-6 transform transition-all max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-4 border-b border-[#F8EEEE]">
                    <div>
                        <h3 class="text-base font-extrabold text-[#59443F] flex items-center gap-2">
                            <i class="fas fa-plus-circle text-[#59443F]"></i>
                            Add Subject to Curriculum
                        </h3>
                        <p class="text-xs text-[#8B7168] font-medium mt-0.5">
                            Target: <span class="font-bold text-[#59443F]"><?php echo htmlspecialchars($program); ?></span> &middot; AY <?php echo htmlspecialchars($selected_ay_label); ?>
                            <?php if ($selected_version) { ?> (<?php echo htmlspecialchars($selected_version['version_name']); ?>)<?php } ?>
                        </p>
                    </div>
                    <button type="button" onclick="closeModal('addSubjectModal')" class="btn-close-modal text-[#8B7168] hover:text-[#59443F] p-1.5 rounded-lg transition-colors cursor-pointer" aria-label="Close modal">
                        <i class="fas fa-xmark text-lg"></i>
                    </button>
                </div>

                <form method="post" class="mt-4 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="curriculum_version_id" value="<?php echo (int)$selected_version_id; ?>">
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$selected_ay_id; ?>">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Subject Code</label>
                            <input type="text" name="subject_code" placeholder="e.g., Philo 1" required
                                   class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Units</label>
                            <input type="number" name="units" value="3" min="0" step="0.5" required
                                   class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[#8B7168] mb-1">Subject Title / Name</label>
                        <input type="text" name="subject_name" placeholder="Full descriptive subject title" required
                               class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Year Level / Stage</label>
                            <select name="year_level" required
                                    class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                                <option value="">Select Year Level</option>
                                <?php foreach ($year_levels as $yl) { ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>"><?php echo htmlspecialchars($yl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Semester</label>
                            <select name="semester" required
                                    class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                                <option value="">Select Semester</option>
                                <?php foreach ($semesters as $k => $lbl) { ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Prerequisite (Optional)</label>
                            <select name="prerequisite_subject_id"
                                    class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                                <option value="">None (No Prerequisite)</option>
                                <?php foreach ($all_subjects as $sub) { ?>
                                    <option value="<?php echo (int)$sub['subject_id']; ?>">
                                        <?php echo htmlspecialchars($sub['subject_code'] . ' - ' . $sub['subject_name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#8B7168] mb-1">Status</label>
                            <select name="is_active"
                                    class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-4 border-t border-[#F8EEEE]">
                        <button type="button" onclick="closeModal('addSubjectModal')" class="btn-close-modal px-4 py-2 rounded-xl text-xs font-bold text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] transition-all cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" name="add_curriculum_subject" class="px-5 py-2 rounded-xl text-xs font-extrabold bg-[#59443F] hover:bg-[#43322E] text-white shadow-md transition-all hover:scale-[1.02]">
                            <i class="fas fa-plus mr-1"></i> Add Subject
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 2. Modal: New Curriculum Version -->
        <div id="newVersionModal" data-global-modal="1" onclick="handleBackdropClick(event, 'newVersionModal')" class="<?php echo htmlspecialchars(global_modal_overlay_classes('transition-opacity hidden'), ENT_QUOTES, 'UTF-8'); ?>" style="display: none;" role="dialog" aria-modal="true">
            <div onclick="event.stopPropagation()" class="bg-white rounded-xl shadow-2xl border border-[#D6A6A6]/40 max-w-md w-full max-h-[90vh] overflow-y-auto p-6 transform transition-all">
                <div class="flex items-center justify-between pb-4 border-b border-[#F8EEEE]">
                    <div>
                        <h3 class="text-base font-extrabold text-[#59443F] flex items-center gap-2">
                            <i class="fas fa-plus-circle text-[#59443F]"></i>
                            New Curriculum Version
                        </h3>
                        <p class="text-xs text-[#8B7168] font-medium mt-0.5">
                            AY <?php echo htmlspecialchars($selected_ay_label); ?> &middot; <?php echo htmlspecialchars($program); ?>
                        </p>
                    </div>
                    <button type="button" onclick="closeModal('newVersionModal')" class="btn-close-modal text-[#8B7168] hover:text-[#59443F] p-1.5 rounded-lg transition-colors cursor-pointer" aria-label="Close modal">
                        <i class="fas fa-xmark text-lg"></i>
                    </button>
                </div>

                <form method="post" class="mt-4 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$selected_ay_id; ?>">

                    <div>
                        <label class="block text-xs font-bold text-[#8B7168] mb-1">Version Name</label>
                        <input type="text" name="version_name" placeholder="e.g., <?php echo $program === 'Propaedeutic' ? 'Propaedeutic' : 'AB Philosophy'; ?> (AY <?php echo htmlspecialchars($selected_ay_label); ?>)" required
                               class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-4 border-t border-[#F8EEEE]">
                        <button type="button" onclick="closeModal('newVersionModal')" class="btn-close-modal px-4 py-2 rounded-xl text-xs font-bold text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] transition-all cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" name="create_version" class="px-5 py-2 rounded-xl text-xs font-extrabold bg-[#59443F] hover:bg-[#43322E] text-white shadow-md transition-all hover:scale-[1.02]">
                            <i class="fas fa-plus mr-1"></i> Create Version
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 3. Modal: Clone Curriculum from Another Year -->
        <div id="cloneCurriculumModal" data-global-modal="1" onclick="handleBackdropClick(event, 'cloneCurriculumModal')" class="<?php echo htmlspecialchars(global_modal_overlay_classes('transition-opacity hidden'), ENT_QUOTES, 'UTF-8'); ?>" style="display: none;" role="dialog" aria-modal="true">
            <div onclick="event.stopPropagation()" class="bg-white rounded-xl shadow-2xl border border-[#D6A6A6]/40 max-w-lg w-full max-h-[90vh] overflow-y-auto p-6 transform transition-all">
                <div class="flex items-center justify-between pb-4 border-b border-[#F8EEEE]">
                    <div>
                        <h3 class="text-base font-extrabold text-[#59443F] flex items-center gap-2">
                            <i class="fas fa-copy text-[#59443F]"></i>
                            Clone Curriculum
                        </h3>
                        <p class="text-xs text-[#8B7168] font-medium mt-0.5">
                            Duplicate syllabus subjects into <span class="font-bold text-[#59443F]">AY <?php echo htmlspecialchars($selected_ay_label); ?></span>
                        </p>
                    </div>
                    <button type="button" onclick="closeModal('cloneCurriculumModal')" class="btn-close-modal text-[#8B7168] hover:text-[#59443F] p-1.5 rounded-lg transition-colors cursor-pointer" aria-label="Close modal">
                        <i class="fas fa-xmark text-lg"></i>
                    </button>
                </div>

                <form method="post" class="mt-4 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="target_academic_year_id" value="<?php echo (int)$selected_ay_id; ?>">

                    <div>
                        <label class="block text-xs font-bold text-[#8B7168] mb-1">Source Curriculum to Duplicate</label>
                        <select name="source_version_id" required
                                class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                            <option value="">Select source curriculum to duplicate...</option>
                            <?php foreach ($all_cloneable_versions as $acv) { ?>
                                <option value="<?php echo (int)$acv['id']; ?>">
                                    <?php echo htmlspecialchars($acv['version_name'] . ' · AY ' . ($acv['ay_label'] ?: 'N/A') . ' (' . $acv['sub_count'] . ' subjects)'); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[#8B7168] mb-1">New Version Name (Optional)</label>
                        <input type="text" name="new_version_name" placeholder="Leave empty for auto-generated name"
                               class="w-full text-xs font-semibold px-3 py-2 rounded-xl border border-[#D6A6A6]/60 bg-[#F8EEEE]/40 text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                        <span class="text-[10px] text-[#8B7168] mt-1 block">If empty, defaults to "<?php echo $program === 'Propaedeutic' ? 'Propaedeutic' : 'AB Philosophy'; ?> (AY <?php echo htmlspecialchars($selected_ay_label); ?>)"</span>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-4 border-t border-[#F8EEEE]">
                        <button type="button" onclick="closeModal('cloneCurriculumModal')" class="btn-close-modal px-4 py-2 rounded-xl text-xs font-bold text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] transition-all cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" name="clone_curriculum" class="px-5 py-2 rounded-xl text-xs font-extrabold bg-[#59443F] hover:bg-[#43322E] text-white shadow-md transition-all hover:scale-[1.02]">
                            <i class="fas fa-clone mr-1"></i> Clone to AY <?php echo htmlspecialchars($selected_ay_label); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
