<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';

$role = (string)($_SESSION['role'] ?? '');
$can_edit = $role === 'AcademicFormator';

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($student_id <= 0) {
    header('Location: afms_students.php');
    exit;
}

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = '1st';

$stmt_profile = $conn->prepare("SELECT s.id as student_id, s.year_level, s.formation_stage, s.academic_status, s.enrollment_status, s.academic_remarks, s.profile_picture, s.last_profile_update,
        u.username, up.full_name
    FROM seminarians s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE s.id = ? AND u.role = 'Student'
    LIMIT 1");
$stmt_profile->bind_param('i', $student_id);
$stmt_profile->execute();
$profile = $stmt_profile->get_result()->fetch_assoc();
if (!$profile) {
    header('Location: afms_students.php');
    exit;
}
$display_name = trim((string)($profile['full_name'] ?? ''));
if ($display_name === '') $display_name = (string)($profile['username'] ?? '');

$seminarian_avatar_url = function_exists('get_seminarian_avatar_url') ? get_seminarian_avatar_url($profile['profile_picture'] ?? null) : null;
$first_letter = mb_substr(trim((string)($profile['full_name'] ?? $display_name)), 0, 1);
$words = preg_split('/\s+/', trim((string)($profile['full_name'] ?? $display_name)));
$second_letter = (count($words) > 1) ? mb_substr($words[count($words) - 1], 0, 1) : mb_substr($display_name, 1, 1);
$seminarian_initials = strtoupper($first_letter . $second_letter);
if (mb_strlen($seminarian_initials) < 2) {
    $seminarian_initials = 'SM';
}

$success = null;
$error = null;

/**
 * Delegate single Final Grade evaluation to the shared grading helper in academic_module.php.
 *
 * @return array{computed: ?float, remarks: string, badge_class: string, db_status: string}
 */
function afms_compute_subject_grade_result(?float $final_grade, bool $is_dropped = false): array {
    $eval = afms_evaluate_single_final_grade($final_grade, $is_dropped);
    return [
        'computed' => $eval['grade'],
        'remarks' => $eval['label'],
        'badge_class' => $eval['badge_class'],
        'db_status' => $eval['db_status'],
    ];
}

// Backend Handler: Batch Grade Management for Enrolled Subjects
if ($can_edit && isset($_POST['save_batch_grades'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $post_student_id = (int)($_POST['student_id'] ?? 0);
    $post_academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $post_semester = trim((string)($_POST['semester'] ?? ''));
    $post_formula = trim((string)($_POST['grading_formula'] ?? 'weighted'));
    if (!in_array($post_formula, ['weighted', 'simple'], true)) {
        $post_formula = 'weighted';
    }

    if ($post_student_id !== $student_id || $post_academic_year === '' || !in_array($post_semester, ['1st','2nd','Summer'], true)) {
        $error = "Invalid form submission parameters.";
    } else {
        // Query officially enrolled subjects for this specific student in the selected term
        $stmt_valid_enr = $conn->prepare("SELECT subject_id FROM student_enrolled_subjects WHERE student_id = ? AND academic_year = ? AND semester = ? AND status <> 'Dropped'");
        $stmt_valid_enr->bind_param('iss', $student_id, $post_academic_year, $post_semester);
        $stmt_valid_enr->execute();
        $res_valid_enr = $stmt_valid_enr->get_result();
        $allowed_subject_ids = [];
        while ($vr = $res_valid_enr->fetch_assoc()) {
            $allowed_subject_ids[(int)$vr['subject_id']] = true;
        }

        $submitted_grades = $_POST['grades'] ?? [];
        if (!is_array($submitted_grades)) {
            $submitted_grades = [];
        }

        // Strict Backend Validation: verify every submitted subject is officially enrolled
        $has_unauthorized_subject = false;
        $unauthorized_id = 0;
        foreach (array_keys($submitted_grades) as $subj_id_key) {
            $sid = (int)$subj_id_key;
            if ($sid <= 0 || !isset($allowed_subject_ids[$sid])) {
                $has_unauthorized_subject = true;
                $unauthorized_id = $sid;
                break;
            }
        }

        if ($has_unauthorized_subject) {
            $error = "Security Violation: Subject ID #{$unauthorized_id} is not in the student's officially enrolled subjects list for {$post_academic_year} ({$post_semester}). Grades were not saved.";
        } else {
            $assessed_by = (int)($_SESSION['user'] ?? 0);
            $conn->begin_transaction();
            try {
                $stmt_upsert = $conn->prepare("INSERT INTO student_grades 
                    (student_id, subject_id, semester, academic_year, prelim_grade, midterm_grade, final_grade, final_computed_grade, status, remarks, is_locked, assessed_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,''), 1, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        prelim_grade = IF(student_grades.is_locked = 1, student_grades.prelim_grade, VALUES(prelim_grade)), 
                        midterm_grade = IF(student_grades.is_locked = 1, student_grades.midterm_grade, VALUES(midterm_grade)), 
                        final_grade = IF(student_grades.is_locked = 1, student_grades.final_grade, VALUES(final_grade)), 
                        final_computed_grade = IF(student_grades.is_locked = 1, student_grades.final_computed_grade, VALUES(final_computed_grade)), 
                        status = IF(student_grades.is_locked = 1, student_grades.status, VALUES(status)), 
                        remarks = IF(student_grades.is_locked = 1, student_grades.remarks, VALUES(remarks)), 
                        is_locked = 1,
                        assessed_by = VALUES(assessed_by), 
                        updated_at = NOW()");

                $saved_count = 0;
                $locked_skipped = 0;
                foreach ($submitted_grades as $sub_id => $data) {
                    $sub_id = (int)$sub_id;

                    // If existing grade is already locked, protect from unauthorized overwrite
                    $stmt_ex_lock = $conn->prepare("SELECT is_locked FROM student_grades WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
                    $stmt_ex_lock->bind_param('iiss', $student_id, $sub_id, $post_academic_year, $post_semester);
                    $stmt_ex_lock->execute();
                    $ex_lock_row = $stmt_ex_lock->get_result()->fetch_assoc();
                    if ($ex_lock_row && (int)($ex_lock_row['is_locked'] ?? 0) === 1) {
                        $locked_skipped++;
                        continue;
                    }

                    $final_raw = trim((string)($data['final_grade'] ?? $data['final'] ?? ''));
                    $is_dropped_flag = !empty($data['is_dropped']);

                    $final_input = ($final_raw !== '' && is_numeric($final_raw)) ? (float)$final_raw : null;

                    // Bounds check (0-100)
                    if ($final_input !== null && ($final_input < 0 || $final_input > 100)) {
                        $final_input = null;
                    }

                    $outcome = afms_evaluate_single_final_grade($final_input, $is_dropped_flag);
                    $computed_rating = $outcome['grade'];
                    $remarks = $outcome['remark'];
                    $status = $outcome['db_status'];
                    $null_grade = null;

                    $has_existing = ($ex_lock_row !== null);
                    $has_input = ($final_input !== null || $is_dropped_flag);

                    if ($has_existing || $has_input) {
                        $stmt_upsert->bind_param('iissddddssi',
                            $student_id,
                            $sub_id,
                            $post_semester,
                            $post_academic_year,
                            $null_grade,
                            $null_grade,
                            $computed_rating,
                            $computed_rating,
                            $status,
                            $remarks,
                            $assessed_by
                        );
                        $stmt_upsert->execute();
                        $saved_count++;
                    }
                }

                $conn->commit();
                afms_recompute_student($conn, $student_id, $post_academic_year, $post_semester);
                $success = "Successfully saved and locked final grades for {$saved_count} enrolled subject(s)." . ($locked_skipped > 0 ? " ({$locked_skipped} locked subject(s) remained protected)." : "");
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Action Handler: Assign / Edit Subject Teacher
if ($can_edit && isset($_POST['assign_subject_teacher'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $post_sub_id = (int)($_POST['subject_id'] ?? 0);
    $post_teacher_user_id = (int)($_POST['teacher_user_id'] ?? 0);
    $post_teacher_override = trim((string)($_POST['teacher_name_override'] ?? ''));
    $post_ay = trim((string)($_POST['academic_year'] ?? ''));
    $post_sem = trim((string)($_POST['semester'] ?? ''));

    if ($post_sub_id > 0 && $post_ay !== '' && in_array($post_sem, ['1st','2nd','Summer'], true)) {
        $stmt_sta = $conn->prepare("INSERT INTO subject_teacher_assignments 
            (subject_id, academic_year, semester, teacher_user_id, teacher_name_override, created_at, updated_at)
            VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, ''), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                teacher_user_id = VALUES(teacher_user_id), 
                teacher_name_override = VALUES(teacher_name_override), 
                updated_at = NOW()");
        $stmt_sta->bind_param('issis', $post_sub_id, $post_ay, $post_sem, $post_teacher_user_id, $post_teacher_override);
        if ($stmt_sta->execute()) {
            $success = "Subject teacher successfully assigned/updated.";
        } else {
            $error = "Failed to assign teacher: " . $stmt_sta->error;
        }
    } else {
        $error = "Please specify a valid subject and term for the teacher assignment.";
    }
}

// Action Handler: Request to Edit Locked Grades (Sent to Registrar)
if ($can_edit && isset($_POST['request_grade_edit'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $req_sub_id = (int)($_POST['subject_id'] ?? 0);
    $req_reason = trim((string)($_POST['edit_reason'] ?? ''));
    $post_ay = trim((string)($_POST['academic_year'] ?? ''));
    $post_sem = trim((string)($_POST['semester'] ?? ''));
    $requested_by = (int)($_SESSION['user'] ?? 0);

    if ($req_reason === '') {
        $error = "Please provide a valid justification/reason for the grade edit request.";
    } elseif ($student_id <= 0 || $post_ay === '' || !in_array($post_sem, ['1st','2nd','Summer'], true)) {
        $error = "Invalid parameters for edit request.";
    } else {
        // Check if an identical request is already pending
        $stmt_chk_req = $conn->prepare("SELECT id FROM grade_edit_requests WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? AND status = 'Pending' LIMIT 1");
        $stmt_chk_req->bind_param('iiss', $student_id, $req_sub_id, $post_ay, $post_sem);
        $stmt_chk_req->execute();
        if ($stmt_chk_req->get_result()->fetch_assoc()) {
            $error = "A grade edit request for this selection is already pending review with the Registrar.";
        } else {
            $stmt_ins_req = $conn->prepare("INSERT INTO grade_edit_requests 
                (student_id, subject_id, academic_year, semester, requested_by, reason, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())");
            $stmt_ins_req->bind_param('iissis', $student_id, $req_sub_id, $post_ay, $post_sem, $requested_by, $req_reason);
            if ($stmt_ins_req->execute()) {
                $success = "Grade edit request successfully submitted to the Registrar for review.";
            } else {
                $error = "Failed to submit edit request: " . $stmt_ins_req->error;
            }
        }
    }
}

// Action Handler: Resolve Grade Edit Request (Registrar / Dean approval/rejection)
if (in_array($_SESSION['role'] ?? '', ['Registrar', 'Dean', 'Rector'], true) && isset($_POST['resolve_edit_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $req_id = (int)($_POST['request_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'Approved' ? 'Approved' : 'Rejected';
    $reviewer_id = (int)($_SESSION['user'] ?? 0);

    $stmt_find_req = $conn->prepare("SELECT * FROM grade_edit_requests WHERE id = ? AND status = 'Pending' LIMIT 1");
    $stmt_find_req->bind_param('i', $req_id);
    $stmt_find_req->execute();
    $found_req = $stmt_find_req->get_result()->fetch_assoc();
    if ($found_req) {
        $r_stu = (int)$found_req['student_id'];
        $r_sub = (int)$found_req['subject_id'];
        $r_ay = (string)$found_req['academic_year'];
        $r_sem = (string)$found_req['semester'];

        $conn->begin_transaction();
        try {
            $stmt_up_req = $conn->prepare("UPDATE grade_edit_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt_up_req->bind_param('sii', $decision, $reviewer_id, $req_id);
            $stmt_up_req->execute();

            if ($decision === 'Approved') {
                if ($r_sub > 0) {
                    $stmt_unl = $conn->prepare("UPDATE student_grades SET is_locked = 0, updated_at = NOW() WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ?");
                    $stmt_unl->bind_param('iiss', $r_stu, $r_sub, $r_ay, $r_sem);
                } else {
                    $stmt_unl = $conn->prepare("UPDATE student_grades SET is_locked = 0, updated_at = NOW() WHERE student_id = ? AND academic_year = ? AND semester = ?");
                    $stmt_unl->bind_param('iss', $r_stu, $r_ay, $r_sem);
                }
                $stmt_unl->execute();
            }
            $conn->commit();
            $success = "Grade edit request marked as {$decision}." . ($decision === 'Approved' ? " Grades have been unlocked for editing." : "");
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error resolving request: " . $e->getMessage();
        }
    }
}

// Academic Year options
$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM gpa_records WHERE student_id = {$student_id} AND academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM student_grades WHERE student_id = {$student_id} AND academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM student_enrolled_subjects WHERE student_id = {$student_id} AND academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT label as academic_year FROM academic_years WHERE is_active = 1)
    ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

// Query Officially Enrolled Subjects and their Current Grades for the selected term
$stmt_enrolled = $conn->prepare("SELECT 
    es.id as enrollment_id,
    es.subject_id,
    es.status as enrollment_status,
    s.subject_code,
    s.subject_name,
    s.units,
    sg.grade_id,
    sg.prelim_grade,
    sg.midterm_grade,
    sg.final_grade,
    sg.final_computed_grade,
    sg.status as grade_status,
    sg.remarks,
    COALESCE(sg.is_locked, 0) AS is_locked,
    COALESCE(sta.teacher_name_override, up_teacher.full_name, u_teacher.username) AS teacher_name,
    sta.teacher_user_id,
    sta.teacher_name_override,
    au.username as assessor_username,
    sg.updated_at,
    sg.created_at
FROM student_enrolled_subjects es
JOIN subjects s ON s.subject_id = es.subject_id
LEFT JOIN student_grades sg ON (
    sg.student_id = es.student_id
    AND sg.subject_id = es.subject_id
    AND sg.academic_year = es.academic_year
    AND sg.semester = es.semester
)
LEFT JOIN users au ON au.id = sg.assessed_by
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
$stmt_enrolled->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_enrolled->execute();
$enrolled_list = $stmt_enrolled->get_result()->fetch_all(MYSQLI_ASSOC);

// Also fetch any standalone student_grades rows for display fallback
$stmt_grades = $conn->prepare("SELECT sg.*, sub.subject_code, sub.subject_name, sub.units,
    COALESCE(sg.is_locked, 0) AS is_locked,
    COALESCE(sta.teacher_name_override, up_teacher.full_name, u_teacher.username) AS teacher_name,
    sta.teacher_user_id, sta.teacher_name_override,
    au.username as assessor_username
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    LEFT JOIN users au ON au.id = sg.assessed_by
    LEFT JOIN subject_teacher_assignments sta ON (
        sta.subject_id = sg.subject_id
        AND sta.academic_year = sg.academic_year
        AND sta.semester = sg.semester
    )
    LEFT JOIN users u_teacher ON u_teacher.id = sta.teacher_user_id
    LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = sta.teacher_user_id
    WHERE sg.student_id = ? AND sg.academic_year = ? AND sg.semester = ?
    ORDER BY sub.subject_code ASC, sg.grade_id DESC");
$stmt_grades->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_grades->execute();
$legacy_grades_res = $stmt_grades->get_result();

$display_rows = $enrolled_list;
$enrolled_subject_ids = array_column($enrolled_list, 'subject_id');
while ($gr = $legacy_grades_res->fetch_assoc()) {
    if (!in_array((int)$gr['subject_id'], $enrolled_subject_ids, true)) {
        $display_rows[] = [
            'enrollment_id' => null,
            'subject_id' => $gr['subject_id'],
            'enrollment_status' => 'Enrolled',
            'subject_code' => $gr['subject_code'],
            'subject_name' => $gr['subject_name'],
            'units' => $gr['units'],
            'grade_id' => $gr['grade_id'],
            'prelim_grade' => $gr['prelim_grade'],
            'midterm_grade' => $gr['midterm_grade'],
            'final_grade' => $gr['final_grade'],
            'final_computed_grade' => $gr['final_computed_grade'],
            'grade_status' => $gr['status'],
            'remarks' => $gr['remarks'],
            'is_locked' => (int)($gr['is_locked'] ?? 0),
            'teacher_name' => $gr['teacher_name'] ?? null,
            'teacher_user_id' => $gr['teacher_user_id'] ?? null,
            'teacher_name_override' => $gr['teacher_name_override'] ?? null,
            'assessor_username' => $gr['assessor_username'] ?? '',
            'updated_at' => $gr['updated_at'] ?? $gr['created_at'] ?? '',
            'created_at' => $gr['created_at'] ?? '',
        ];
    }
}

// Fetch pending grade edit requests for this student and term
$pending_edit_requests = [];
$pending_subj_map = []; // subject_id => row, 0 => whole term
$has_term_edit_pending = false;
$stmt_p_ger = $conn->prepare("SELECT * FROM grade_edit_requests WHERE student_id = ? AND academic_year = ? AND semester = ? AND status = 'Pending'");
$stmt_p_ger->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_p_ger->execute();
$res_p_ger = $stmt_p_ger->get_result();
while ($prow = $res_p_ger->fetch_assoc()) {
    $pending_edit_requests[] = $prow;
    $sid_k = (int)$prow['subject_id'];
    $pending_subj_map[$sid_k] = $prow;
    if ($sid_k === 0) {
        $has_term_edit_pending = true;
    }
}

// Fetch available faculty/teachers for the assign teacher modal
$available_teachers = [];
$res_tch = $conn->query("SELECT u.id, u.username, u.role, COALESCE(NULLIF(TRIM(up.full_name),''), u.username) AS display_name 
    FROM users u 
    LEFT JOIN user_profiles up ON up.user_id = u.id 
    WHERE u.role NOT IN ('Student') 
    ORDER BY COALESCE(NULLIF(TRIM(up.full_name),''), u.username) ASC");
if ($res_tch) {
    while ($tr = $res_tch->fetch_assoc()) {
        $available_teachers[] = $tr;
    }
}

$all_locked = !empty($enrolled_list) && count(array_filter($enrolled_list, fn($x) => (int)($x['is_locked'] ?? 0) === 1)) === count($enrolled_list);
$any_locked = !empty($enrolled_list) && count(array_filter($enrolled_list, fn($x) => (int)($x['is_locked'] ?? 0) === 1)) > 0;

$stmt_gpa = $conn->prepare("SELECT * FROM gpa_records WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
$stmt_gpa->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_gpa->execute();
$gpa = $stmt_gpa->get_result()->fetch_assoc() ?: null;

$stmt_prog = $conn->prepare("SELECT * FROM academic_progress WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
$stmt_prog->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_prog->execute();
$progress = $stmt_prog->get_result()->fetch_assoc() ?: null;

$stmt_ir = $conn->prepare("SELECT reason, status, date_flagged FROM irregular_students WHERE student_id = ? AND academic_year = ? AND semester = ? AND status = 'Active' LIMIT 1");
$stmt_ir->bind_param('iss', $student_id, $filter_academic_year, $filter_semester);
$stmt_ir->execute();
$irregular = $stmt_ir->get_result()->fetch_assoc() ?: null;
?>
<?php
$pageTitle = 'Student Academic Record';
$activeNav = 'afms_students';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <a href="afms_students.php" style="color: var(--primary); text-decoration: none;">Academic Records</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span><?php echo htmlspecialchars($display_name); ?></span>
            </nav>

            <?php if ($success) { ?>
                <div style="background: #ecfdf5; border-left: 5px solid #10b981; color: #065f46; padding: 0.9rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <i class="fas fa-circle-check" style="color: #10b981; font-size: 1.25rem;"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>

            <?php if ($error) { ?>
                <div style="background: #fef2f2; border-left: 5px solid #ef4444; color: #991b1b; padding: 0.9rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <i class="fas fa-triangle-exclamation" style="color: #ef4444; font-size: 1.25rem;"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php } ?>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <?php if (!empty($seminarian_avatar_url)): ?>
                        <div style="width: 52px; height: 52px; border-radius: 1rem; overflow: hidden; border: 2px solid #E8C7C5; box-shadow: 0 4px 6px -1px rgba(89,68,63,0.1); flex-shrink: 0; background: #E8C7C5;">
                            <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div style="width: 52px; height: 52px; border-radius: 1rem; background: #E8C7C5; color: #59443F; font-weight: 800; font-size: 1.15rem; display: flex; align-items: center; justify-content: center; border: 2px solid #E8C7C5; box-shadow: 0 4px 6px -1px rgba(89,68,63,0.1); flex-shrink: 0;">
                            <?php echo htmlspecialchars($seminarian_initials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h1 class="page-title" style="margin-bottom: 0.25rem;"><?php echo htmlspecialchars($display_name); ?></h1>
                        <div style="color: var(--text-secondary); font-weight: 800;"><?php echo htmlspecialchars((string)($profile['year_level'] ?? '')); ?> · <?php echo htmlspecialchars((string)($profile['formation_stage'] ?? '')); ?></div>
                    </div>
                </div>
                <form method="get" style="display: flex; gap: 0.75rem; align-items: center; margin: 0; box-shadow: none; background: transparent; padding: 0; border: none;">
                    <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem;">Academic Year</label>
                        <select name="academic_year">
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem;">Semester</label>
                        <select name="semester">
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <button type="submit" style="width: auto; padding: 0.55rem 0.9rem; margin-top: 1.1rem;"><i class="fas fa-filter"></i> View</button>
                </form>
            </div>

            <?php
                $stat_complete = 0;
                $stat_incomplete = 0;
                $stat_dropped = 0;
                foreach ($display_rows as $r_stat) {
                    $st_stat = strtolower(trim((string)($r_stat['grade_status'] ?? '')));
                    $rm_stat = strtolower(trim((string)($r_stat['remarks'] ?? '')));
                    $drop_stat = ($st_stat === 'dropped' || $rm_stat === 'dropped');
                    $grade_stat = afms_resolve_row_single_final_grade($r_stat);
                    if ($drop_stat) {
                        $stat_dropped++;
                    } elseif ($grade_stat !== null) {
                        $stat_complete++;
                    } else {
                        $stat_incomplete++;
                    }
                }
            ?>
            <div class="stat-grid grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4" style="margin-bottom: 1.5rem;">
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #59443F; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-id-badge"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;">#<?php echo (int)$student_id; ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Student ID</div>
                </div>
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #59443F; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-circle-check"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;"><?php echo htmlspecialchars((string)($profile['academic_status'] ?? 'Regular')); ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Academic Status</div>
                </div>
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #59443F; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-book-open"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;"><?php echo count($display_rows); ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Enrolled Subjects</div>
                </div>
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #10b981; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-check-double"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;"><?php echo $stat_complete; ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Complete</div>
                </div>
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #f59e0b; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-hourglass-half"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;"><?php echo $stat_incomplete; ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Incomplete</div>
                </div>
                <div class="stat-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 transition-all hover:shadow-xl hover:-translate-y-0.5" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); padding: 1.5rem 1rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="stat-icon text-[#59443F]" style="color: #ef4444; font-size: 2rem; margin-bottom: 0.75rem;"><i class="fas fa-ban"></i></div>
                    <div class="stat-value text-[#59443F]" style="color: #59443F; font-weight: 800; font-size: 1.65rem; line-height: 1.2; margin-bottom: 0.35rem;"><?php echo $stat_dropped; ?></div>
                    <div class="stat-label text-[#8B7168]" style="color: #8B7168; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Dropped</div>
                </div>
            </div>

            <?php if ($irregular) { ?>
                <div class="card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); border-left: 6px solid #ef4444; margin-bottom: 1.5rem; padding: 1.25rem 1.5rem;">
                    <h3 style="margin-bottom: 0.5rem; color: #59443F; font-weight: 800;"><i class="fas fa-triangle-exclamation text-rose-600"></i> Irregularity Reason</h3>
                    <div style="color: #59443F; font-weight: 700;"><?php echo htmlspecialchars((string)$irregular['reason']); ?></div>
                    <div style="margin-top: 0.35rem; color: #8B7168; font-weight: 600; font-size: 0.85rem;"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$irregular['date_flagged']))); ?></div>
                </div>
            <?php } ?>

            <div class="data-card bg-white rounded-3xl shadow-lg border border-[#E8C7C5]/40 overflow-hidden" style="background: #ffffff; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(89, 68, 63, 0.08), 0 8px 10px -6px rgba(89, 68, 63, 0.04); border: 1px solid rgba(232, 199, 197, 0.45); margin-top: 1.5rem; overflow: hidden;">
                <div class="data-card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(232, 199, 197, 0.35); background: linear-gradient(to right, #ffffff, rgba(248, 238, 238, 0.5));">
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #59443F; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                        <i class="fas fa-book text-[#8B7168]"></i> Grades (<?php echo htmlspecialchars($filter_academic_year); ?> · <?php echo htmlspecialchars($filter_semester); ?>)
                        <?php if ($all_locked): ?>
                            <span class="badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; font-size:0.75rem; padding:0.25rem 0.65rem; border-radius:9999px; display:inline-flex; align-items:center; gap:0.3rem;"><i class="fas fa-lock"></i> All Locked</span>
                        <?php elseif ($any_locked): ?>
                            <span class="badge" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; font-size:0.75rem; padding:0.25rem 0.65rem; border-radius:9999px; display:inline-flex; align-items:center; gap:0.3rem;"><i class="fas fa-lock"></i> Partially Locked</span>
                        <?php endif; ?>
                    </h3>
                    <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                        <?php if ($can_edit) { ?>
                            <button type="button" id="openAssignTeacherBtn" class="action-btn-secondary" style="margin: 0; white-space: nowrap; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1.15rem; border-radius: 0.875rem; font-weight: 700; background: #FFFFFF; color: #59443F; border: 1px solid #E8C7C5; box-shadow: 0 2px 6px -1px rgba(89, 68, 63, 0.08); transition: all 0.15s ease;">
                                <i class="fas fa-chalkboard-user" style="color: #8B7168;"></i> + Assign/Edit Teacher
                            </button>

                            <?php if ($any_locked): ?>
                                <?php if ($has_term_edit_pending || !empty($pending_edit_requests)): ?>
                                    <span class="badge" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; padding:0.55rem 1rem; border-radius:0.875rem; font-weight:700; display:inline-flex; align-items:center; gap:0.4rem; font-size:0.85rem;">
                                        <i class="fas fa-clock-rotate-left"></i> Edit Request Pending (Registrar)
                                    </span>
                                <?php else: ?>
                                    <button type="button" id="openRequestEditBtn" class="action-btn-secondary" style="margin: 0; white-space: nowrap; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1.15rem; border-radius: 0.875rem; font-weight: 700; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; box-shadow: 0 2px 6px -1px rgba(239, 68, 68, 0.12); transition: all 0.15s ease;">
                                        <i class="fas fa-lock-open"></i> Request to Edit
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <button type="button" id="openManageGradesBtn" class="action-btn-primary btn-bubble-primary" style="margin: 0; white-space: nowrap; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1.2rem; border-radius: 0.875rem; font-weight: 700; background: #59443F; color: #fff; border: none; box-shadow: 0 2px 8px -1px rgba(89, 68, 63, 0.25); transition: all 0.15s ease;">
                                <i class="fas fa-pen-to-square"></i> Manage Grades
                            </button>
                        <?php } ?>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="background: rgba(248, 238, 238, 0.65); border-bottom: 1px solid rgba(232, 199, 197, 0.45); color: #8B7168; font-size: 0.78rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">
                                <th style="padding: 0.85rem 1.25rem;">Subject</th>
                                <th style="padding: 0.85rem 0.75rem;">Units</th>
                                <th style="padding: 0.85rem 1rem; min-width: 130px;">Teacher</th>
                                <th style="padding: 0.85rem 0.75rem; text-align: center;">Final Grade</th>
                                <th style="padding: 0.85rem 1rem;">Remarks</th>
                                <th style="padding: 0.85rem 0.75rem; text-align: center;">Status</th>
                                <th style="padding: 0.85rem 1rem;">Assessed By</th>
                                <th style="padding: 0.85rem 1.25rem;">Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($display_rows) === 0) { ?>
                                <tr>
                                    <td colspan="8" style="color: #8B7168; text-align: center; padding: 3rem 1rem;">
                                        <i class="fas fa-folder-open" style="font-size: 2.25rem; display: block; margin-bottom: 0.6rem; color: #D6A6A6; opacity: 0.7;"></i>
                                        <span style="font-weight: 600;">No enrolled subjects or grade records found for this term.</span>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php foreach ($display_rows as $row) { 
                                $saved_remark = strtolower(trim((string)($row['remarks'] ?? '')));
                                $is_row_dropped = (strtolower(trim((string)($row['grade_status'] ?? ''))) === 'dropped') || ($saved_remark === 'dropped');
                                $single_grade = afms_resolve_row_single_final_grade($row);
                                $eval_row = afms_evaluate_single_final_grade($single_grade, $is_row_dropped);
                                $is_row_locked = ((int)($row['is_locked'] ?? 0) === 1);
                                $has_row_pending = isset($pending_subj_map[(int)$row['subject_id']]) || $has_term_edit_pending;
                            ?>
                                <tr style="border-bottom: 1px solid rgba(232, 199, 197, 0.25); transition: background-color 0.15s ease;">
                                    <td style="padding: 0.9rem 1.25rem;">
                                        <div class="font-bold text-[#59443F]" style="font-weight: 700; color: #59443F; font-size: 0.95rem; letter-spacing: -0.01em;"><?php echo htmlspecialchars((string)$row['subject_code']); ?></div>
                                        <div class="text-xs text-[#8B7168]" style="color: #8B7168; font-size: 0.825rem; font-weight: 500; margin-top: 2px;"><?php echo htmlspecialchars((string)$row['subject_name']); ?></div>
                                    </td>
                                    <td style="padding: 0.9rem 0.75rem;"><span class="badge badge-primary"><?php echo (float)($row['units'] ?? 0) == (int)($row['units'] ?? 0) ? (int)($row['units'] ?? 0) : number_format((float)($row['units'] ?? 0), 1); ?></span></td>
                                    <td style="padding: 0.9rem 1rem;">
                                        <?php if (!empty($row['teacher_name'])): ?>
                                            <div style="font-weight: 600; color: #59443F; font-size: 0.85rem; display: flex; align-items: center; gap: 0.35rem;">
                                                <i class="fas fa-chalkboard-user" style="color: #8B7168; font-size: 0.8rem;"></i>
                                                <span><?php echo htmlspecialchars($row['teacher_name']); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <?php if ($can_edit): ?>
                                                <button type="button" class="btn-quick-assign-teacher" 
                                                        data-subject-id="<?php echo (int)$row['subject_id']; ?>" 
                                                        data-subject-name="<?php echo htmlspecialchars($row['subject_code'] . ' - ' . $row['subject_name']); ?>"
                                                        style="font-size: 0.72rem; color: #8B7168; background: #F8EEEE; border: 1px dashed #E8C7C5; padding: 2px 7px; border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                    <i class="fas fa-plus"></i> Assign
                                                </button>
                                            <?php else: ?>
                                                <span style="color: #D6A6A6; font-size: 0.85rem;">—</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.9rem 0.75rem; text-align: center; color: #0f172a; font-weight: 800; font-variant-numeric: tabular-nums;">
                                        <?php echo $eval_row['grade'] !== null ? htmlspecialchars(number_format((float)$eval_row['grade'], 2)) : '<span style="color:#D6A6A6;">—</span>'; ?>
                                    </td>
                                    <td style="padding: 0.9rem 1rem;">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars($eval_row['badge_class']); ?>">
                                            <?php echo htmlspecialchars($eval_row['label']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.9rem 0.75rem; text-align: center;">
                                        <?php if ($is_row_locked): ?>
                                            <span class="badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; font-size:0.72rem; padding:2px 7px; border-radius:9999px; display:inline-flex; align-items:center; gap:0.25rem;">
                                                <i class="fas fa-lock" style="font-size:0.65rem;"></i> Locked
                                            </span>
                                            <?php if ($has_row_pending): ?>
                                                <div style="font-size: 0.68rem; color: #b45309; font-weight: 700; margin-top: 2px;">Request Pending</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge" style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; font-size:0.72rem; padding:2px 7px; border-radius:9999px;">
                                                Unlocked
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.9rem 1rem; color: #59443F; font-size: 0.85rem;"><?php echo htmlspecialchars((string)($row['assessor_username'] ?? '—')); ?></td>
                                    <td style="padding: 0.9rem 1.25rem; color: #8B7168; font-size: 0.85rem;">
                                        <?php
                                            $dt = (string)($row['updated_at'] ?? $row['created_at'] ?? '');
                                            echo $dt !== '' ? htmlspecialchars(date('M d, Y', strtotime($dt))) : '—';
                                        ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="margin-top: 1.25rem;">
                <h3><i class="fas fa-message"></i> Formator Remarks</h3>
                <div style="margin-top: 0.75rem; white-space: pre-wrap; color: #334155; font-weight: 700;"><?php echo htmlspecialchars((string)($profile['academic_remarks'] ?? '')); ?></div>
            </div>
        </div>

        <?php if ($can_edit) { ?>
        <!-- Contextual Grade Management Modal for Enrolled Subjects -->
        <div id="manageGradesModal" data-global-modal="1" class="fixed inset-0 z-[99999] !z-[99999] bg-black/50 backdrop-blur-sm items-center justify-center p-4 sm:p-6" style="display: none;">
            <div class="bg-white rounded-xl w-full max-w-5xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden border border-gray-200">
                <!-- Modal Header -->
                <div class="px-6 py-4 bg-slate-900 text-white flex justify-between items-center gap-4 shrink-0">
                    <div>
                        <h2 class="m-0 text-lg text-white font-extrabold flex items-center gap-2.5">
                            <i class="fas fa-pen-to-square text-sky-400"></i>
                            <span>Manage Enrolled Subject Grades</span>
                        </h2>
                        <div class="text-xs sm:text-sm text-slate-400 mt-1 font-medium">
                            <span class="text-slate-100 font-bold"><?php echo htmlspecialchars($display_name); ?></span> (#<?php echo (int)$student_id; ?>) &middot; <span class="text-sky-400 font-semibold"><?php echo htmlspecialchars($filter_academic_year . ' · ' . $filter_semester . ' Semester'); ?></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0">
                        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-800 text-slate-200 border border-slate-700 text-[11px] font-semibold">
                            Standard: &ge;88 Passed &middot; 75&ndash;87 Below Std &middot; &le;74 Failed
                        </span>
                        <button type="button" id="closeManageGradesBtn" style="width: auto; background: none; border: none; color: #94a3b8; font-size: 1.75rem; cursor: pointer; padding: 0.25rem; line-height: 1; transition: color 0.15s;" title="Close Modal">&times;</button>
                    </div>
                </div>

                <!-- Modal Body -->
                <div class="p-6 overflow-y-auto flex-1 bg-white">
                    <?php if (count($enrolled_list) === 0) { ?>
                        <div style="text-align: center; padding: 3rem 1.5rem;">
                            <div style="width: 68px; height: 68px; margin: 0 auto 1.25rem; border-radius: 50%; background: #fef3c7; display: flex; align-items: center; justify-content: center; color: #d97706; font-size: 2rem;">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                            <h3 style="color: #1e293b; margin-bottom: 0.5rem; font-size: 1.2rem; font-weight: 800;">No Officially Enrolled Subjects</h3>
                            <p style="color: #64748b; max-width: 520px; margin: 0 auto 1.5rem; line-height: 1.5;">
                                This seminarian has no active enrollments recorded for <strong><?php echo htmlspecialchars($filter_academic_year . ' · ' . $filter_semester); ?></strong>. In accordance with system policy, grades can strictly be entered only for officially enrolled subjects.
                            </p>
                            <a href="subject_enrollment.php?student_id=<?php echo (int)$student_id; ?>&academic_year=<?php echo urlencode($filter_academic_year); ?>&semester=<?php echo urlencode($filter_semester); ?>" class="action-btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.65rem 1.35rem; border-radius: 8px; text-decoration: none; font-weight: 700; background: #59443F; color: #fff;">
                                <i class="fas fa-user-plus"></i> Go to Subject Enrollment
                            </a>
                        </div>
                    <?php } else { ?>
                        <form method="post" id="batchGradesForm" class="m-0 p-0 bg-transparent border-0 shadow-none !gap-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="save_batch_grades" value="1">
                            <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                            <div class="mb-5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-900 text-xs sm:text-sm flex items-center gap-3">
                                <i class="fas fa-shield-halved text-emerald-600 text-lg shrink-0"></i>
                                <div>
                                    Displaying <strong><?php echo count($enrolled_list); ?></strong> officially enrolled subjects. Standard Grade: <strong>&ge; 88 Passed</strong> | <strong>75&ndash;87 Passed - Below Standard</strong> | <strong>&le; 74 Failed</strong>.
                                </div>
                            </div>

                            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                                <table class="w-full border-collapse !mt-0 text-left text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 text-xs font-semibold uppercase tracking-wider">
                                            <th class="py-3 px-4 min-w-[260px]">Enrolled Subject</th>
                                            <th class="py-3 px-4 text-center w-40">Final Grade (0-100)</th>
                                            <th class="py-3 px-4 min-w-[260px]">Remarks (Automated)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <?php foreach ($enrolled_list as $item) { 
                                            $sid = (int)$item['subject_id'];
                                            $saved_remark = strtolower(trim((string)($item['remarks'] ?? '')));
                                            $is_dropped_init = (strtolower(trim((string)($item['grade_status'] ?? ''))) === 'dropped') || ($saved_remark === 'dropped');
                                            $resolved_grade = afms_resolve_row_single_final_grade($item);
                                            $init_eval = afms_evaluate_single_final_grade($resolved_grade, $is_dropped_init);

                                            $is_item_locked = ((int)($item['is_locked'] ?? 0) === 1);
                                            $is_item_pending = isset($pending_subj_map[$sid]) || $has_term_edit_pending;
                                        ?>
                                            <tr class="batch-grade-row border-b border-gray-100 <?php echo $is_item_locked ? 'bg-slate-50/70' : 'hover:bg-gray-50/60'; ?>" data-subj-id="<?php echo $sid; ?>" data-is-locked="<?php echo $is_item_locked ? '1' : '0'; ?>">
                                                <td class="py-3.5 px-4 align-middle">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <div class="font-bold text-[#59443F] text-sm"><?php echo htmlspecialchars($item['subject_code']); ?></div>
                                                        <?php if ($is_item_locked): ?>
                                                            <span class="text-[11px] text-red-800 bg-red-100 border border-red-200 px-1.5 py-0.5 rounded font-bold inline-flex items-center gap-1">
                                                                <i class="fas fa-lock text-[10px]"></i> Locked
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($is_item_pending): ?>
                                                            <span class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded font-bold inline-flex items-center gap-1">
                                                                <i class="fas fa-clock-rotate-left text-[10px]"></i> Edit Pending
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-xs text-[#8B7168] max-w-[280px] font-medium mt-0.5"><?php echo htmlspecialchars($item['subject_name']); ?></div>
                                                    <div class="mt-1.5">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200/70">
                                                            <?php echo (float)$item['units'] == (int)$item['units'] ? (int)$item['units'] : number_format((float)$item['units'], 1); ?> units
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="py-3.5 px-4 text-center align-middle">
                                                    <input type="number" 
                                                           name="grades[<?php echo $sid; ?>][final_grade]" 
                                                           class="final-grade-input" 
                                                           min="0" max="100" step="0.01" 
                                                           value="<?php echo $init_eval['grade'] !== null ? htmlspecialchars((string)$init_eval['grade']) : ''; ?>" 
                                                           placeholder="—"
                                                           <?php echo ($is_item_locked || $is_dropped_init) ? ($is_item_locked ? 'readonly disabled' : 'disabled') : ''; ?>
                                                           style="width: 112px; padding: 0.5rem 0.65rem; text-align: center; font-weight: 700; border: 1px solid <?php echo ($is_item_locked || $is_dropped_init) ? '#e2e8f0' : '#cbd5e1'; ?>; border-radius: 6px; font-size: 0.95rem; <?php echo ($is_item_locked || $is_dropped_init) ? 'background: #f1f5f9; color: #64748b; cursor: not-allowed;' : ''; ?>">
                                                </td>
                                                <td class="py-3.5 px-4 align-middle">
                                                    <input type="hidden" name="grades[<?php echo $sid; ?>][remarks]" class="row-remarks-input" value="<?php echo htmlspecialchars($init_eval['remark']); ?>">
                                                    <div class="flex flex-col gap-1.5">
                                                        <div class="flex items-center gap-2 flex-wrap">
                                                            <span class="row-remarks-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars($init_eval['badge_class']); ?>">
                                                                <?php echo htmlspecialchars($init_eval['label']); ?>
                                                            </span>
                                                        </div>
                                                        <label class="inline-flex items-center gap-1.5 text-[11px] font-medium text-gray-500 <?php echo $is_item_locked ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer hover:text-gray-800'; ?> m-0" style="width: auto;">
                                                            <input type="checkbox"
                                                                   name="grades[<?php echo $sid; ?>][is_dropped]"
                                                                   value="1"
                                                                   class="row-dropped-checkbox rounded border-gray-300 text-gray-700 focus:ring-blue-500"
                                                                   style="width: auto;"
                                                                   <?php echo $is_dropped_init ? 'checked' : ''; ?>
                                                                   <?php echo $is_item_locked ? 'disabled' : ''; ?>>
                                                            <span>Mark Dropped</span>
                                                        </label>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Modal Footer Bar -->
                            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-between items-center flex-wrap gap-4">
                                <div class="text-sm text-slate-600 font-semibold flex items-center gap-5 flex-wrap">
                                    <span>Enrolled Subjects: <strong class="text-slate-900"><?php echo count($enrolled_list); ?></strong></span>
                                    <span>Evaluated: <strong id="modalGradedCount" class="text-slate-900">0</strong>/<?php echo count($enrolled_list); ?></span>
                                    <span>Average: <strong id="modalTermAverage" class="text-slate-900">—</strong></span>
                                    <?php if ($all_locked): ?>
                                        <span class="text-red-800 font-bold"><i class="fas fa-lock"></i> All grades permanently locked</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex gap-3 items-center">
                                    <button type="button" id="cancelManageGradesBtn" style="width: auto;" class="action-btn-secondary px-5 py-2.5 font-bold rounded-lg cursor-pointer">
                                        Close
                                    </button>
                                    <?php if ($all_locked): ?>
                                        <?php if ($has_term_edit_pending || !empty($pending_edit_requests)): ?>
                                            <div class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 font-bold text-sm">
                                                <i class="fas fa-clock-rotate-left"></i> Edit Request Pending (Registrar)
                                            </div>
                                        <?php else: ?>
                                            <button type="button" id="modalTriggerRequestEditBtn" style="width: auto;" class="px-6 py-2.5 font-extrabold rounded-lg bg-red-800 hover:bg-red-900 text-white border-0 shadow-xs cursor-pointer inline-flex items-center gap-2">
                                                <i class="fas fa-lock-open"></i> Request to Edit
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button type="submit" name="save_batch_grades" id="saveAllGradesBtn" style="width: auto;" class="action-btn-primary px-6 py-2.5 font-extrabold rounded-lg bg-[#59443F] hover:bg-[#463430] text-white border-0 shadow-xs cursor-pointer inline-flex items-center gap-2">
                                            <i class="fas fa-save"></i> Save All Grades
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- Modal: Assign / Edit Subject Teacher -->
        <div id="assignTeacherModal" style="display: none; position: fixed; inset: 0; background: rgba(89, 68, 63, 0.5); backdrop-filter: blur(4px); z-index: 10050; align-items: center; justify-content: center; padding: 1.25rem;">
            <div style="background: #ffffff; border-radius: 1.5rem; width: 100%; max-width: 520px; box-shadow: 0 20px 45px -10px rgba(89, 68, 63, 0.25); overflow: hidden; border: 1px solid rgba(232, 199, 197, 0.7);">
                <!-- Modal Header -->
                <div style="padding: 1.25rem 1.75rem; background: #F8EEEE; border-bottom: 1px solid rgba(232, 199, 197, 0.6); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #59443F; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chalkboard-user" style="color: #8B7168;"></i> Assign / Edit Subject Teacher
                    </h3>
                    <button type="button" id="closeAssignTeacherBtn" style="background: none; border: none; color: #8B7168; font-size: 1.5rem; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <!-- Modal Body -->
                <form method="post" id="assignTeacherForm" style="padding: 1.5rem; margin: 0; background: none; border: none; box-shadow: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="assign_subject_teacher" value="1">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                    <div style="margin-bottom: 1.15rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Subject</label>
                        <select name="subject_id" id="assign_subject_id" required style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-weight: 700; color: #59443F; background: #fff;">
                            <option value="">— Select Enrolled Subject —</option>
                            <?php foreach ($enrolled_list as $el_item): ?>
                                <option value="<?php echo (int)$el_item['subject_id']; ?>" 
                                        data-teacher-uid="<?php echo (int)($el_item['teacher_user_id'] ?? 0); ?>" 
                                        data-teacher-name="<?php echo htmlspecialchars((string)($el_item['teacher_name_override'] ?? $el_item['teacher_name'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($el_item['subject_code'] . ' · ' . $el_item['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 1.15rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Select from Faculty / Formators</label>
                        <select name="teacher_user_id" id="assign_teacher_user_id" style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-weight: 600; color: #59443F; background: #fff;">
                            <option value="0">— External or Visiting Instructor (Custom Input Below) —</option>
                            <?php foreach ($available_teachers as $tch): ?>
                                <option value="<?php echo (int)$tch['id']; ?>" data-name="<?php echo htmlspecialchars($tch['display_name']); ?>">
                                    <?php echo htmlspecialchars($tch['display_name'] . ' (' . $tch['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Teacher / Instructor Name Override</label>
                        <input type="text" name="teacher_name_override" id="assign_teacher_name_override" placeholder="e.g. Rev. Fr. Emmanuel Santos, S.T.D." style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-weight: 600; color: #59443F; background: #fff;">
                        <span style="display: block; font-size: 0.75rem; color: #8B7168; margin-top: 0.35rem;">Auto-fills from selected faculty or customize with official academic titles/honorifics.</span>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid rgba(232, 199, 197, 0.4);">
                        <button type="button" id="cancelAssignTeacherBtn" class="action-btn-secondary" style="padding: 0.6rem 1.25rem; border-radius: 0.75rem; font-weight: 700; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" class="action-btn-primary" style="padding: 0.6rem 1.5rem; border-radius: 0.75rem; font-weight: 800; background: #59443F; color: #fff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 6px rgba(89, 68, 63, 0.25);">
                            <i class="fas fa-check"></i> Save Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Request to Edit Grades (Directed to Registrar) -->
        <div id="requestEditModal" style="display: none; position: fixed; inset: 0; background: rgba(89, 68, 63, 0.5); backdrop-filter: blur(4px); z-index: 10060; align-items: center; justify-content: center; padding: 1.25rem;">
            <div style="background: #ffffff; border-radius: 1.5rem; width: 100%; max-width: 540px; box-shadow: 0 20px 45px -10px rgba(89, 68, 63, 0.25); overflow: hidden; border: 1px solid rgba(232, 199, 197, 0.7);">
                <!-- Modal Header -->
                <div style="padding: 1.25rem 1.75rem; background: #fef2f2; border-bottom: 1px solid #fecaca; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #991b1b; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-lock-open" style="color: #ef4444;"></i> Request Grade Edit Permission
                    </h3>
                    <button type="button" id="closeRequestEditBtn" style="background: none; border: none; color: #991b1b; font-size: 1.5rem; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <!-- Modal Body -->
                <form method="post" id="requestEditForm" style="padding: 1.5rem; margin: 0; background: none; border: none; box-shadow: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="request_grade_edit" value="1">
                    <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #F8EEEE; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-size: 0.825rem; color: #59443F; display: flex; align-items: center; gap: 0.65rem;">
                        <i class="fas fa-shield-alt text-[#8B7168]" style="font-size: 1.25rem;"></i>
                        <div>
                            Submitting to the <strong>Office of the Registrar</strong> for <strong><?php echo htmlspecialchars($display_name); ?></strong> (<?php echo htmlspecialchars($filter_academic_year . ' · ' . $filter_semester); ?>).
                        </div>
                    </div>

                    <div style="margin-bottom: 1.15rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Select Subject Scope</label>
                        <select name="subject_id" id="edit_req_subject_id" style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-weight: 700; color: #59443F; background: #fff;">
                            <option value="0">All Locked Subjects for this Term (Whole Semester)</option>
                            <?php foreach ($enrolled_list as $e_sub): ?>
                                <option value="<?php echo (int)$e_sub['subject_id']; ?>">
                                    <?php echo htmlspecialchars($e_sub['subject_code'] . ' · ' . $e_sub['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Reason / Justification for Edit (Required)</label>
                        <textarea name="edit_reason" id="edit_reason" rows="3" required placeholder="Please state the specific reason for altering locked grades (e.g., calculation mistake in midterm examination, late compliance submission, encoding correction)..." style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-size: 0.875rem; color: #59443F; resize: vertical;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid rgba(232, 199, 197, 0.4);">
                        <button type="button" id="cancelRequestEditBtn" class="action-btn-secondary" style="padding: 0.6rem 1.25rem; border-radius: 0.75rem; font-weight: 700; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" class="action-btn-primary" style="padding: 0.6rem 1.5rem; border-radius: 0.75rem; font-weight: 800; background: #991b1b; color: #fff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 6px rgba(153, 27, 27, 0.25);">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Manage Grades Modal Elements
            const modal = document.getElementById('manageGradesModal');
            const openBtn = document.getElementById('openManageGradesBtn');
            const closeBtn = document.getElementById('closeManageGradesBtn');
            const cancelBtn = document.getElementById('cancelManageGradesBtn');
            const batchForm = document.getElementById('batchGradesForm');

            function openModal() {
                if (modal) {
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeModal() {
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }

            openBtn?.addEventListener('click', openModal);
            closeBtn?.addEventListener('click', closeModal);
            cancelBtn?.addEventListener('click', closeModal);

            modal?.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });

            // Confirmation on Save Grades: "Are you sure you want to save? Once submitted, grades cannot be edited."
            if (batchForm) {
                batchForm.addEventListener('submit', async function(e) {
                    if (batchForm.dataset.enformasysConfirmed === '1') return;
                    e.preventDefault();
                    const ok = (typeof window.enformasysConfirm === 'function')
                        ? await window.enformasysConfirm({
                            title: 'Save All Grades',
                            message: 'Are you sure you want to save? Once submitted, grades cannot be edited.',
                            confirmText: 'Save Grades',
                            variant: 'primary'
                        })
                        : confirm('Are you sure you want to save? Once submitted, grades cannot be edited.');
                    if (ok) {
                        batchForm.dataset.enformasysConfirmed = '1';
                        const saveBtn = document.getElementById('saveAllGradesBtn') || batchForm.querySelector('button[type="submit"]');
                        if (saveBtn && saveBtn.name) {
                            const h = document.createElement('input');
                            h.type = 'hidden';
                            h.name = saveBtn.name;
                            h.value = saveBtn.value || '1';
                            batchForm.appendChild(h);
                        }
                        if (saveBtn && typeof window.enformasysSetButtonLoading === 'function') {
                            window.enformasysSetButtonLoading(saveBtn, 'Saving...');
                        }
                        HTMLFormElement.prototype.submit.call(batchForm);
                    }
                });
            }

            // Assign Teacher Modal Elements
            const assignTeacherModal = document.getElementById('assignTeacherModal');
            const openAssignTeacherBtn = document.getElementById('openAssignTeacherBtn');
            const closeAssignTeacherBtn = document.getElementById('closeAssignTeacherBtn');
            const cancelAssignTeacherBtn = document.getElementById('cancelAssignTeacherBtn');
            const assignSubSelect = document.getElementById('assign_subject_id');
            const assignTeacherUserSelect = document.getElementById('assign_teacher_user_id');
            const assignTeacherOverrideInput = document.getElementById('assign_teacher_name_override');

            function openAssignModal(subjectId = null) {
                if (assignTeacherModal) {
                    if (subjectId && assignSubSelect) {
                        assignSubSelect.value = String(subjectId);
                        triggerSubjectChange(assignSubSelect);
                    }
                    assignTeacherModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeAssignModal() {
                if (assignTeacherModal) {
                    assignTeacherModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }

            openAssignTeacherBtn?.addEventListener('click', () => openAssignModal());
            closeAssignTeacherBtn?.addEventListener('click', closeAssignModal);
            cancelAssignTeacherBtn?.addEventListener('click', closeAssignModal);
            assignTeacherModal?.addEventListener('click', (e) => {
                if (e.target === assignTeacherModal) closeAssignModal();
            });

            // Quick assign buttons in table
            document.querySelectorAll('.btn-quick-assign-teacher').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sid = this.getAttribute('data-subject-id');
                    openAssignModal(sid);
                });
            });

            function triggerSubjectChange(selectEl) {
                const opt = selectEl.options[selectEl.selectedIndex];
                if (opt) {
                    const uid = opt.getAttribute('data-teacher-uid') || '0';
                    const name = opt.getAttribute('data-teacher-name') || '';
                    if (assignTeacherUserSelect) assignTeacherUserSelect.value = uid;
                    if (assignTeacherOverrideInput) assignTeacherOverrideInput.value = name;
                }
            }

            assignSubSelect?.addEventListener('change', function() {
                triggerSubjectChange(this);
            });

            assignTeacherUserSelect?.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                if (opt && this.value !== '0') {
                    const name = opt.getAttribute('data-name') || '';
                    if (assignTeacherOverrideInput) assignTeacherOverrideInput.value = name;
                }
            });

            // Request Edit Modal Elements
            const requestEditModal = document.getElementById('requestEditModal');
            const openRequestEditBtn = document.getElementById('openRequestEditBtn');
            const modalTriggerRequestEditBtn = document.getElementById('modalTriggerRequestEditBtn');
            const closeRequestEditBtn = document.getElementById('closeRequestEditBtn');
            const cancelRequestEditBtn = document.getElementById('cancelRequestEditBtn');
            const editReqSubSelect = document.getElementById('edit_req_subject_id');

            function openRequestEdit(subjectId = 0) {
                if (requestEditModal) {
                    if (editReqSubSelect) editReqSubSelect.value = String(subjectId);
                    if (modal) closeModal();
                    requestEditModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeRequestEdit() {
                if (requestEditModal) {
                    requestEditModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }

            openRequestEditBtn?.addEventListener('click', () => openRequestEdit(0));
            modalTriggerRequestEditBtn?.addEventListener('click', () => openRequestEdit(0));
            closeRequestEditBtn?.addEventListener('click', closeRequestEdit);
            cancelRequestEditBtn?.addEventListener('click', closeRequestEdit);
            requestEditModal?.addEventListener('click', (e) => {
                if (e.target === requestEditModal) closeRequestEdit();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (assignTeacherModal && assignTeacherModal.style.display === 'flex') closeAssignModal();
                    if (requestEditModal && requestEditModal.style.display === 'flex') closeRequestEdit();
                    if (modal && modal.style.display === 'flex') closeModal();
                }
            });

            // Auto-open modal if URL contains open_manage=1
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('open_manage') === '1') {
                openModal();
                urlParams.delete('open_manage');
                const newQuery = urlParams.toString();
                const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');
                window.history.replaceState({}, '', newUrl);
            }

            /**
             * Automated Single Final Grade & Remarks Logic:
             * - If marked Dropped -> "Dropped" (bg-gray-100 text-gray-600)
             * - >= 88 -> "Passed" (bg-green-100 text-green-700)
             * - 75 to 87.99 -> "Passed - Below Standard" (bg-orange-100 text-orange-700)
             * - < 75 -> "Failed" (bg-red-100 text-red-700)
             * - Otherwise -> "" (Pending)
             */
            function computeAutomatedOutcome(finalVal, isDroppedChecked) {
                if (isDroppedChecked) {
                    return { remark: 'Dropped', rating: null, cls: 'bg-gray-100 text-gray-600' };
                }
                const str = finalVal !== null && finalVal !== undefined ? String(finalVal).trim() : '';
                if (str === '' || isNaN(Number(str))) {
                    return { remark: '', rating: null, cls: 'bg-slate-100 text-slate-500' };
                }
                const rounded = Math.round((Number(str) + Number.EPSILON) * 100) / 100;
                if (rounded >= 88) {
                    return { remark: 'Passed', rating: rounded, cls: 'bg-green-100 text-green-700' };
                }
                if (rounded >= 75) {
                    return { remark: 'Passed - Below Standard', rating: rounded, cls: 'bg-orange-100 text-orange-700' };
                }
                return { remark: 'Failed', rating: rounded, cls: 'bg-red-100 text-red-700' };
            }

            function styleRemarkBadge(row, outcome) {
                const remarksInput = row.querySelector('.row-remarks-input');
                const badgeEl = row.querySelector('.row-remarks-badge');
                const remark = outcome.remark;

                if (remarksInput) remarksInput.value = remark;

                if (badgeEl) {
                    badgeEl.className = 'row-remarks-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ' + outcome.cls;
                    badgeEl.textContent = remark !== '' ? remark : 'Pending';
                }
            }

            function updateBatchRow(row) {
                const gradeInput = row.querySelector('.final-grade-input');
                const dropCheckbox = row.querySelector('.row-dropped-checkbox');
                const isRowLocked = row.dataset.isLocked === '1';

                if (!gradeInput) return;

                const isDropped = dropCheckbox ? dropCheckbox.checked : false;
                if (!isRowLocked) {
                    gradeInput.disabled = isDropped;
                    gradeInput.style.background = isDropped ? '#f1f5f9' : '';
                    gradeInput.style.color = isDropped ? '#64748b' : '';
                    gradeInput.style.cursor = isDropped ? 'not-allowed' : '';
                }

                const outcome = computeAutomatedOutcome(gradeInput.value, isDropped);
                styleRemarkBadge(row, outcome);
                row.dataset.automatedRemark = outcome.remark;
                row.dataset.resolvedGrade = outcome.rating !== null ? String(outcome.rating) : '';
            }

            function updateCompleteCounter() {
                let completeCount = 0;
                let gradeSum = 0;
                let gradeCount = 0;
                document.querySelectorAll('.batch-grade-row').forEach(row => {
                    const r = row.dataset.automatedRemark || '';
                    if (r === 'Passed' || r === 'Passed - Below Standard' || r === 'Failed' || r === 'Dropped') {
                        completeCount++;
                    }
                    const gStr = row.dataset.resolvedGrade || '';
                    if (gStr !== '' && !isNaN(Number(gStr))) {
                        gradeSum += Number(gStr);
                        gradeCount++;
                    }
                });
                const gradedCountEl = document.getElementById('modalGradedCount');
                if (gradedCountEl) gradedCountEl.textContent = completeCount;
                const avgEl = document.getElementById('modalTermAverage');
                if (avgEl) {
                    avgEl.textContent = gradeCount > 0 ? (gradeSum / gradeCount).toFixed(2) : '—';
                }
            }

            // Wire input & checkbox listeners on all rows
            document.querySelectorAll('.batch-grade-row').forEach(row => {
                updateBatchRow(row);
                const gradeInput = row.querySelector('.final-grade-input');
                if (gradeInput) {
                    gradeInput.addEventListener('input', () => {
                        updateBatchRow(row);
                        updateCompleteCounter();
                    });
                    gradeInput.addEventListener('change', () => {
                        updateBatchRow(row);
                        updateCompleteCounter();
                    });
                }
                const dropCb = row.querySelector('.row-dropped-checkbox');
                if (dropCb) {
                    dropCb.addEventListener('change', () => {
                        updateBatchRow(row);
                        updateCompleteCounter();
                    });
                }
            });
            updateCompleteCounter();
        });
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
