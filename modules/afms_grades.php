<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && function_exists('enformasys_invalidate_dashboard_cache')) {
    enformasys_invalidate_dashboard_cache();
}

$role = (string)($_SESSION['role'] ?? '');
$can_edit = $role === 'AcademicFormator';

// AJAX Handler for resolving grade viewing requests (Approve / Deny)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_grade_request', 'deny_grade_request'], true)) {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security token validation failed.']);
        exit;
    }
    if (!$can_edit && $role !== 'Dean') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized action.']);
        exit;
    }

    $request_id = (int)($_POST['request_id'] ?? 0);
    $action = (string)$_POST['action'];
    $current_user_id = (int)($_SESSION['user'] ?? 0);

    if ($request_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid request ID.']);
        exit;
    }

    $stmt_r = $conn->prepare("SELECT id, seminarian_id, status FROM seminarian_access_requests WHERE id = ? AND assignment_type = 'Academic' LIMIT 1");
    $stmt_r->bind_param('i', $request_id);
    $stmt_r->execute();
    $req = $stmt_r->get_result()->fetch_assoc();

    if (!$req) {
        echo json_encode(['success' => false, 'error' => 'Request not found.']);
        exit;
    }
    if ($req['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'error' => 'This request has already been processed (' . htmlspecialchars($req['status']) . ').']);
        exit;
    }

    $seminarian_id = (int)$req['seminarian_id'];
    $conn->begin_transaction();
    try {
        if ($action === 'approve_grade_request') {
            // Enable grade viewing on seminarians table so student can now see grades in their portal
            $stmt_sem = $conn->prepare("UPDATE seminarians SET academic_view_enabled = 1 WHERE id = ?");
            $stmt_sem->bind_param('i', $seminarian_id);
            $stmt_sem->execute();

            // Mark request Approved
            $stmt_res = $conn->prepare("UPDATE seminarian_access_requests
                SET status = 'Approved',
                    assigned_user_id = COALESCE(assigned_user_id, ?),
                    resolved_by = ?,
                    resolved_at = NOW()
                WHERE id = ?");
            $stmt_res->bind_param('iii', $current_user_id, $current_user_id, $request_id);
            $stmt_res->execute();
            $msg = 'Grade access approved. The seminarian can now view their grades.';
        } else {
            // Mark request Rejected
            $stmt_res = $conn->prepare("UPDATE seminarian_access_requests
                SET status = 'Rejected',
                    assigned_user_id = COALESCE(assigned_user_id, ?),
                    resolved_by = ?,
                    resolved_at = NOW()
                WHERE id = ?");
            $stmt_res->bind_param('iii', $current_user_id, $current_user_id, $request_id);
            $stmt_res->execute();
            $msg = 'Grade access request denied.';
        }
        $conn->commit();

        // Get new pending count
        $cnt_res = $conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE assignment_type = 'Academic' AND status = 'Pending'");
        $new_cnt = (int)($cnt_res->fetch_assoc()['c'] ?? 0);

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'request_id' => $request_id,
            'action' => $action,
            'pending_count' => $new_cnt
        ]);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = get_active_academic_year();
$filter_semester = isset($_GET['semester']) ? afms_normalize_semester_value((string)$_GET['semester']) : afms_normalize_semester_value(get_active_semester());
$filter_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

$pending_access_requests = [];
$stmt_par = $conn->query("SELECT r.id, r.seminarian_id, r.message, r.request_scope, r.requested_subjects, r.requested_at,
        u.username, up.full_name, COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no
    FROM seminarian_access_requests r
    JOIN seminarians s ON s.id = r.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE r.assignment_type = 'Academic' AND r.status = 'Pending'
    ORDER BY r.requested_at DESC, r.id DESC");
if ($stmt_par) {
    while ($row = $stmt_par->fetch_assoc()) {
        $pending_access_requests[] = $row;
    }
}
$pending_requests_cnt = count($pending_access_requests);

if ($can_edit && isset($_POST['add_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $student_id = (int)($_POST['student_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $semester = (string)($_POST['semester'] ?? '');
    $academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $raw_final = trim((string)($_POST['final_grade'] ?? ''));
    $final_input = ($raw_final !== '' && is_numeric($raw_final)) ? (float)$raw_final : null;
    if ($final_input !== null && ($final_input < 0 || $final_input > 100)) {
        $final_input = null;
    }
    $is_dropped = !empty($_POST['is_dropped']) || ((string)($_POST['status'] ?? '') === 'Dropped');

    $eval = afms_evaluate_single_final_grade($final_input, $is_dropped);
    $final_val = $eval['grade'];
    $status = $eval['db_status'];
    $remarks = $eval['remark'];
    $null_grade = null;

    if ($student_id > 0 && $subject_id > 0 && $academic_year !== '' && in_array($semester, ['1st','2nd','Summer'], true)) {
        // Strict Backend Validation: verify student is officially enrolled in this subject
        $stmt_enr = $conn->prepare("SELECT COUNT(*) as c FROM student_enrolled_subjects WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? AND status <> 'Dropped'");
        $stmt_enr->bind_param('iiss', $student_id, $subject_id, $academic_year, $semester);
        $stmt_enr->execute();
        $enr_cnt = (int)($stmt_enr->get_result()->fetch_assoc()['c'] ?? 0);
        if ($enr_cnt === 0) {
            $error = 'Security Violation: Cannot record grade. The student is not officially enrolled in this subject for the selected academic year and semester.';
        }
    }

    if (!isset($error) && $student_id > 0 && $subject_id > 0 && $academic_year !== '' && in_array($semester, ['1st','2nd','Summer'], true)) {
        $assessed_by = (int)$_SESSION['user'];
        $stmt = $conn->prepare("INSERT INTO student_grades (student_id, subject_id, semester, academic_year, prelim_grade, midterm_grade, final_grade, final_computed_grade, status, remarks, is_locked, assessed_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,''), 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE prelim_grade = NULL, midterm_grade = NULL, final_grade = VALUES(final_grade), final_computed_grade = VALUES(final_computed_grade), status = VALUES(status), remarks = VALUES(remarks), is_locked = 1, assessed_by = VALUES(assessed_by), updated_at = NOW()");
        $stmt->bind_param('iissddddssi', $student_id, $subject_id, $semester, $academic_year, $null_grade, $null_grade, $final_val, $final_val, $status, $remarks, $assessed_by);
        $stmt->execute();

        afms_recompute_student($conn, $student_id, $academic_year, $semester);
        $success = 'Final grade saved and locked successfully.';

        $filter_student_id = $student_id;
        $filter_academic_year = $academic_year;
        $filter_semester = $semester;
    } elseif (!isset($error)) {
        $error = 'Please fill out student, subject, academic year, and semester.';
    }
}

if ($can_edit && isset($_POST['edit_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $grade_id = (int)($_POST['grade_id'] ?? 0);
    $raw_final = trim((string)($_POST['final_grade'] ?? ''));
    $final_input = ($raw_final !== '' && is_numeric($raw_final)) ? (float)$raw_final : null;
    if ($final_input !== null && ($final_input < 0 || $final_input > 100)) {
        $final_input = null;
    }
    $is_dropped = !empty($_POST['is_dropped']) || ((string)($_POST['status'] ?? '') === 'Dropped');

    $eval = afms_evaluate_single_final_grade($final_input, $is_dropped);
    $final_val = $eval['grade'];
    $status = $eval['db_status'];
    $remarks = $eval['remark'];
    $null_grade = null;

    if ($grade_id > 0) {
        $stmt_check = $conn->prepare("SELECT student_id, subject_id, academic_year, semester, is_locked FROM student_grades WHERE grade_id = ? LIMIT 1");
        $stmt_check->bind_param('i', $grade_id);
        $stmt_check->execute();
        $g_row = $stmt_check->get_result()->fetch_assoc();
        if ($g_row) {
            if ((int)($g_row['is_locked'] ?? 0) === 1) {
                $error = 'Permission Denied: This grade record is permanently locked. Please submit a "Request to Edit" to the Registrar to request modification access.';
            } else {
                $student_id = (int)$g_row['student_id'];
                $subject_id = (int)$g_row['subject_id'];
                $academic_year = (string)$g_row['academic_year'];
                $semester = (string)$g_row['semester'];
                $assessed_by = (int)$_SESSION['user'];

                // Strict Backend Validation: verify student is officially enrolled
                $stmt_enr = $conn->prepare("SELECT COUNT(*) as c FROM student_enrolled_subjects WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? AND status <> 'Dropped'");
                $stmt_enr->bind_param('iiss', $student_id, $subject_id, $academic_year, $semester);
                $stmt_enr->execute();
                $enr_cnt = (int)($stmt_enr->get_result()->fetch_assoc()['c'] ?? 0);
                if ($enr_cnt === 0) {
                    $error = 'Security Violation: Cannot update grade. The student is not officially enrolled in this subject for this semester.';
                } else {
                    $stmt_upd = $conn->prepare("UPDATE student_grades SET prelim_grade = ?, midterm_grade = ?, final_grade = ?, final_computed_grade = ?, status = ?, remarks = NULLIF(?,''), is_locked = 1, assessed_by = ?, updated_at = NOW() WHERE grade_id = ?");
                    $stmt_upd->bind_param('ddddssii', $null_grade, $null_grade, $final_val, $final_val, $status, $remarks, $assessed_by, $grade_id);
                    $stmt_upd->execute();

                    afms_recompute_student($conn, $student_id, $academic_year, $semester);
                    $success = 'Final grade updated and locked successfully.';

                    $filter_student_id = $student_id;
                    $filter_academic_year = $academic_year;
                    $filter_semester = $semester;
                }
            }
        } else {
            $error = 'Grade record not found.';
        }
    } else {
        $error = 'Invalid grade ID.';
    }
}

// Action Handler: Request to Edit Locked Grades
if ($can_edit && isset($_POST['request_grade_edit'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $req_student_id = (int)($_POST['student_id'] ?? 0);
    $req_sub_id = (int)($_POST['subject_id'] ?? 0);
    $req_reason = trim((string)($_POST['edit_reason'] ?? ''));
    $post_ay = trim((string)($_POST['academic_year'] ?? ''));
    $post_sem = trim((string)($_POST['semester'] ?? ''));
    $requested_by = (int)($_SESSION['user'] ?? 0);

    if ($req_reason === '') {
        $error = "Please provide a valid justification for requesting grade edits.";
    } elseif ($req_student_id <= 0 || $post_ay === '' || !in_array($post_sem, ['1st','2nd','Summer'], true)) {
        $error = "Invalid parameters for edit request.";
    } else {
        $stmt_chk_req = $conn->prepare("SELECT id FROM grade_edit_requests WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? AND status = 'Pending' LIMIT 1");
        $stmt_chk_req->bind_param('iiss', $req_student_id, $req_sub_id, $post_ay, $post_sem);
        $stmt_chk_req->execute();
        if ($stmt_chk_req->get_result()->fetch_assoc()) {
            $error = "A grade edit request for this student and subject is already pending review with the Registrar.";
        } else {
            $stmt_ins_req = $conn->prepare("INSERT INTO grade_edit_requests 
                (student_id, subject_id, academic_year, semester, requested_by, reason, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())");
            $stmt_ins_req->bind_param('iissis', $req_student_id, $req_sub_id, $post_ay, $post_sem, $requested_by, $req_reason);
            if ($stmt_ins_req->execute()) {
                $success = "Grade edit request successfully submitted to the Registrar.";
            } else {
                $error = "Failed to submit request: " . $stmt_ins_req->error;
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

if ($can_edit && isset($_POST['recompute'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $student_id = (int)($_POST['student_id'] ?? 0);
    $academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $semester = (string)($_POST['semester'] ?? '');
    if ($student_id > 0 && $academic_year !== '' && in_array($semester, ['1st','2nd','Summer'], true)) {
        afms_recompute_student($conn, $student_id, $academic_year, $semester);
        $success = 'Recomputed general average and academic status.';
    }
}

// 1. Seed student groups from canonical enrolled/active seminarians for the selected term
// (ensures students with enrolled subjects or term enrollment but 0 grades yet still appear)
$student_groups = [];
$base_where = "u.role = 'Student'";
$base_types_clean = 'ss';
$base_params_clean = [$filter_academic_year, $filter_semester];

if ($filter_student_id > 0) {
    $base_where .= " AND sem.id = ?";
    $base_types_clean .= 'i';
    $base_params_clean[] = $filter_student_id;
} else {
    $base_where .= " AND (
        e.id IS NOT NULL
        OR EXISTS (SELECT 1 FROM student_enrolled_subjects ses WHERE ses.student_id = sem.id AND ses.academic_year = ? AND ses.semester = ? AND ses.status <> 'Dropped')
        OR EXISTS (SELECT 1 FROM student_grades sg2 WHERE sg2.student_id = sem.id AND sg2.academic_year = ? AND sg2.semester = ?)
    )";
    $base_types_clean .= 'ssss';
    array_push(
        $base_params_clean,
        $filter_academic_year, $filter_semester,
        $filter_academic_year, $filter_semester
    );
}

$sql_base = "SELECT sem.id AS student_id,
        u.username,
        COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) AS full_name,
        COALESCE(NULLIF(TRIM(stu.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', LPAD(sem.id, 4, '0'))) AS student_no,
        COALESCE(NULLIF(TRIM(e.year_level), ''), sem.year_level) AS year_level,
        COALESCE(NULLIF(TRIM(e.program), ''), sem.program) AS program
    FROM seminarians sem
    JOIN users u ON u.id = sem.user_id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN enrollments e ON e.student_id = sem.id AND e.academic_year = ? AND e.semester = ?
    WHERE {$base_where}
    ORDER BY COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) ASC";

$stmt_base = $conn->prepare($sql_base);
if ($stmt_base) {
    $stmt_base->bind_param($base_types_clean, ...$base_params_clean);
    $stmt_base->execute();
    $res_base = $stmt_base->get_result();
    while ($res_base && ($brow = $res_base->fetch_assoc())) {
        $sid = (int)$brow['student_id'];
        $gkey = $sid . '_' . $filter_academic_year . '_' . $filter_semester;
        $student_groups[$gkey] = [
            'student_id' => $sid,
            'student_name' => (string)$brow['full_name'],
            'student_no' => (string)($brow['student_no'] ?? ''),
            'year_level' => (string)($brow['year_level'] ?? ''),
            'program' => (string)($brow['program'] ?? ''),
            'academic_year' => $filter_academic_year,
            'semester' => $filter_semester,
            'items' => [],
            'total_units' => 0,
            'weighted_sum' => 0,
            'graded_units' => 0,
            'grades_sum' => 0,
            'grades_count' => 0,
            'has_failed' => false,
            'has_incomplete' => false,
        ];
    }
}

$seen_subject_keys = [];
$append_grade_row = function (array $row) use (&$student_groups, &$seen_subject_keys): void {
    $sid = (int)$row['student_id'];
    $sub_id = (int)$row['subject_id'];
    $ay = (string)$row['academic_year'];
    $sem = (string)$row['semester'];
    $skey = $sid . '_' . $sub_id . '_' . $ay . '_' . $sem;
    if (isset($seen_subject_keys[$skey])) {
        return;
    }
    $seen_subject_keys[$skey] = true;

    $group_key = $sid . '_' . $ay . '_' . $sem;
    if (!isset($student_groups[$group_key])) {
        $sname = trim((string)($row['full_name'] ?? ''));
        if ($sname === '') $sname = (string)($row['username'] ?? '');
        $student_groups[$group_key] = [
            'student_id' => $sid,
            'student_name' => $sname,
            'student_no' => (string)($row['student_no'] ?? ''),
            'year_level' => (string)($row['year_level'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
            'academic_year' => $ay,
            'semester' => $sem,
            'items' => [],
            'total_units' => 0,
            'weighted_sum' => 0,
            'graded_units' => 0,
            'grades_sum' => 0,
            'grades_count' => 0,
            'has_failed' => false,
            'has_incomplete' => false,
        ];
    }

    $units = (float)($row['units'] ?? 0);
    $st = strtolower(trim((string)($row['status'] ?? 'Incomplete')));
    $rm = strtolower(trim((string)($row['remarks'] ?? '')));
    $is_dropped = ($st === 'dropped' || $rm === 'dropped');
    $single_final = afms_resolve_row_single_final_grade($row);
    $eval = afms_evaluate_single_final_grade($single_final, $is_dropped);

    if ($eval['label'] === 'Failed') {
        $student_groups[$group_key]['has_failed'] = true;
    } elseif ($eval['label'] === 'Pending') {
        $student_groups[$group_key]['has_incomplete'] = true;
    }

    $row['_final_grade'] = $eval['grade'];
    $row['_remark_label'] = $eval['label'];
    $row['_badge_class'] = $eval['badge_class'];
    $row['_is_dropped'] = $is_dropped;

    $student_groups[$group_key]['total_units'] += $units;
    if ($eval['grade'] !== null) {
        $student_groups[$group_key]['grades_sum'] += $eval['grade'];
        $student_groups[$group_key]['grades_count']++;
    }

    $student_groups[$group_key]['items'][] = $row;
};

// 2. Enrolled subjects LEFT JOIN student_grades (includes enrolled students with 0 grades like Clark)
$es_where = "u.role = 'Student' AND es.status <> 'Dropped'";
$es_types = '';
$es_params = [];
if ($filter_student_id > 0) {
    $es_where .= " AND es.student_id = ?";
    $es_types .= 'i';
    $es_params[] = $filter_student_id;
}
if ($filter_academic_year !== '') {
    $es_where .= " AND es.academic_year = ?";
    $es_types .= 's';
    $es_params[] = $filter_academic_year;
}
if ($filter_semester !== '') {
    $es_where .= " AND es.semester = ?";
    $es_types .= 's';
    $es_params[] = $filter_semester;
}

$sql_es = "SELECT
        COALESCE(sg.grade_id, 0) AS grade_id,
        es.student_id,
        es.subject_id,
        es.semester,
        es.academic_year,
        sg.prelim_grade,
        sg.midterm_grade,
        sg.final_grade,
        sg.final_computed_grade,
        COALESCE(sg.status, 'Incomplete') AS status,
        sg.remarks,
        COALESCE(sg.is_locked, 0) AS is_locked,
        sub.subject_code, sub.subject_name, sub.units,
        u.username, up.full_name,
        COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(sem.id, 4, '0'))) AS student_no,
        sem.year_level, sem.program,
        au.username AS assessor_username
    FROM student_enrolled_subjects es
    JOIN subjects sub ON sub.subject_id = es.subject_id
    JOIN seminarians sem ON sem.id = es.student_id
    JOIN users u ON u.id = sem.user_id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN student_grades sg ON sg.student_id = es.student_id
        AND sg.subject_id = es.subject_id
        AND sg.academic_year = es.academic_year
        AND sg.semester = es.semester
    LEFT JOIN users au ON au.id = sg.assessed_by
    WHERE {$es_where}
    ORDER BY es.academic_year DESC, FIELD(es.semester,'Summer','2nd','1st') DESC, COALESCE(up.full_name, u.username) ASC, sub.subject_code ASC";

$stmt_es = $conn->prepare($sql_es);
if ($stmt_es) {
    if ($es_types !== '') {
        $stmt_es->bind_param($es_types, ...$es_params);
    }
    $stmt_es->execute();
    $res_es = $stmt_es->get_result();
    while ($res_es && ($row = $res_es->fetch_assoc())) {
        $append_grade_row($row);
    }
}

// 3. Also include any standalone student_grades rows not paired with student_enrolled_subjects
$where = "u.role = 'Student'";
$types = '';
$params = [];
if ($filter_student_id > 0) {
    $where .= " AND sg.student_id = ?";
    $types .= 'i';
    $params[] = $filter_student_id;
}
if ($filter_academic_year !== '') {
    $where .= " AND sg.academic_year = ?";
    $types .= 's';
    $params[] = $filter_academic_year;
}
if ($filter_semester !== '') {
    $where .= " AND sg.semester = ?";
    $types .= 's';
    $params[] = $filter_semester;
}

$sql = "SELECT sg.*, sub.subject_code, sub.subject_name, sub.units,\n"
    . "    u.username, up.full_name, COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(sem.id, 4, '0'))) AS student_no,\n"
    . "    sem.year_level, sem.program,\n"
    . "    au.username as assessor_username\n"
    . "FROM student_grades sg\n"
    . "JOIN subjects sub ON sub.subject_id = sg.subject_id\n"
    . "JOIN seminarians sem ON sem.id = sg.student_id\n"
    . "JOIN users u ON u.id = sem.user_id\n"
    . "LEFT JOIN students stu ON stu.user_id = u.id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "LEFT JOIN users au ON au.id = sg.assessed_by\n"
    . "WHERE {$where}\n"
    . "ORDER BY sg.academic_year DESC, FIELD(sg.semester,'Summer','2nd','1st') DESC, COALESCE(up.full_name, u.username) ASC, sub.subject_code ASC";

$stmt_list = $conn->prepare($sql);
if ($types !== '') {
    $stmt_list->bind_param($types, ...$params);
}
$stmt_list->execute();
$grades = $stmt_list->get_result();

while ($row = $grades->fetch_assoc()) {
    $append_grade_row($row);
}

// Fetch pending grade edit requests map
$pending_edit_requests_map = [];
$res_ger_all = $conn->query("SELECT id, student_id, subject_id, academic_year, semester, reason FROM grade_edit_requests WHERE status = 'Pending'");
if ($res_ger_all) {
    while ($ger_item = $res_ger_all->fetch_assoc()) {
        $k1 = $ger_item['student_id'] . '_' . $ger_item['subject_id'] . '_' . $ger_item['academic_year'] . '_' . $ger_item['semester'];
        $pending_edit_requests_map[$k1] = $ger_item;
        $k0 = $ger_item['student_id'] . '_0_' . $ger_item['academic_year'] . '_' . $ger_item['semester'];
        $pending_edit_requests_map[$k0] = $ger_item;
    }
}
?>
<?php
$pageTitle = 'Grade Management';
$activeNav = 'afms_grades';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body" style="padding-top: 0.75rem;">
            <!-- Page Header -->
            <div style="margin-bottom: 1rem;">
                <h1 class="page-title" style="margin: 0; font-size: 1.5rem; font-weight: 800;">
                    <i class="fas fa-clipboard-list" style="color: #0f766e; margin-right: 0.35rem;"></i> Subject Grade Management
                </h1>
            </div>

            <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>

            <div id="pendingRequestsBanner" class="p-4 mb-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs font-['Inter',sans-serif]" style="<?php echo $pending_requests_cnt > 0 ? '' : 'display: none;'; ?>">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <div class="font-bold text-sm text-[#59443F]">Pending Grade Viewing Requests (<span id="pendingRequestsCount"><?php echo $pending_requests_cnt; ?></span>)</div>
                        <p class="text-xs text-[#8B7168] mt-0.5">Seminarians have submitted requests to view their semester or subject grades.</p>
                    </div>
                </div>
                <button type="button" onclick="openGradeRequestsModal()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-[#59443F] text-white hover:bg-[#8B7168] transition shrink-0 self-start sm:self-center shadow-xs cursor-pointer">
                    <span>Review Requests</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </button>
            </div>

            <div style="margin-bottom: 0.85rem;">
                <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
                    <i class="fas fa-list-check" style="color: #3b82f6; margin-right: 0.25rem;"></i> Grades by Seminarian
                </h2>
            </div>

            <?php if (count($student_groups) === 0) { ?>
                <div class="card" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                    <i class="fas fa-clipboard" style="font-size: 2.5rem; margin-bottom: 0.75rem; display: block; opacity: 0.4;"></i>
                    No grade records found matching the filter criteria.
                </div>
            <?php } ?>

            <?php foreach ($student_groups as $group) {
                $avg = $group['grades_count'] > 0
                    ? round($group['grades_sum'] / $group['grades_count'], 2)
                    : null;
                $avg_color = '#0f172a';
                if ($avg !== null) {
                    if ($avg < 75) {
                        $avg_color = '#b91c1c';
                    } elseif ($avg < 88) {
                        $avg_color = '#c2410c';
                    } else {
                        $avg_color = '#15803d';
                    }
                }
            ?>
            <div class="minimal-card student-grade-group" data-status="<?php echo ($group['has_failed'] || $group['has_incomplete']) ? 'deficiencies' : 'passed'; ?>" data-student="<?php echo htmlspecialchars(strtolower($group['student_name'] . ' ' . $group['student_no'] . ' ' . $group['student_id'])); ?>" style="margin-bottom: 1.5rem;">
                <div class="minimal-toolbar" style="background: #f8fafc;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a;" class="student-name">
                            <i class="fas fa-user-graduate" style="color: #0f766e; margin-right: 0.35rem;"></i>
                            <?php echo htmlspecialchars($group['student_name']); ?>
                        </div>
                        <?php if ($group['student_no'] !== '') { ?>
                            <span class="status-badge status-info"><?php echo htmlspecialchars($group['student_no']); ?></span>
                        <?php } else { ?>
                            <span class="status-badge status-muted">#<?php echo (int)$group['student_id']; ?></span>
                        <?php } ?>
                        <?php if ($group['year_level'] !== '') { ?>
                            <span class="status-badge status-muted"><?php echo htmlspecialchars($group['year_level']); ?></span>
                        <?php } ?>
                        <span class="status-badge status-info"><?php echo htmlspecialchars($group['academic_year'] . ' · ' . $group['semester']); ?></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <div style="font-size: 0.9rem; color: #334155; font-weight: 600;">
                            Average: <strong style="font-size: 1.1rem; font-variant-numeric: tabular-nums; color: <?php echo $avg_color; ?>;"><?php echo $avg !== null ? htmlspecialchars(number_format($avg, 2)) : '—'; ?></strong>
                        </div>
                        <?php if ($can_edit) { ?>
                        <a href="afms_student_record.php?student_id=<?php echo (int)$group['student_id']; ?>&academic_year=<?php echo urlencode($group['academic_year']); ?>&semester=<?php echo urlencode($group['semester']); ?>&open_manage=1" class="action-btn-primary" style="font-size: 0.78rem; padding: 0.3rem 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 6px; font-weight: 700; color: #fff; background: #0f766e;" title="Manage grades for this seminarian in Academic Record">
                            <i class="fas fa-pen-to-square"></i> Manage Grades
                        </a>
                        <form method="post" style="display: inline; margin: 0; padding: 0; background: none; border: none; box-shadow: none;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="student_id" value="<?php echo (int)$group['student_id']; ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($group['academic_year']); ?>">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($group['semester']); ?>">
                            <button type="submit" name="recompute" title="Recompute Average &amp; Status" class="action-btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.65rem;"><i class="fas fa-rotate"></i> Recompute</button>
                        </form>
                        <?php } ?>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="minimal-table w-full" style="width: 100%; table-layout: auto;">
                        <thead>
                            <tr>
                                <th class="whitespace-nowrap" style="width: 14%;">Code</th>
                                <th style="width: 34%;">Subject Name</th>
                                <th class="whitespace-nowrap" style="width: 10%; text-align: center;">Units</th>
                                <th class="num-col whitespace-nowrap" style="width: 14%; text-align: center;">Final Grade</th>
                                <th class="whitespace-nowrap" style="width: 16%;">Remarks</th>
                                <?php if ($can_edit) { ?><th class="whitespace-nowrap" style="width: 12%; text-align: center;">Action</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($group['items'])) { ?>
                                <tr>
                                    <td colspan="<?php echo $can_edit ? '6' : '5'; ?>" style="text-align: center; color: #64748b; padding: 1.5rem; font-style: italic;">
                                        No enrolled subjects or grades recorded yet for this term.
                                    </td>
                                </tr>
                            <?php } else { foreach ($group['items'] as $item) { ?>
                                <tr class="grade-row">
                                    <td class="subject-code whitespace-nowrap"><strong style="color: #59443F; font-weight: 700;"><?php echo htmlspecialchars((string)$item['subject_code']); ?></strong></td>
                                    <td class="subject-name">
                                        <div style="font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars((string)$item['subject_name']); ?></div>
                                    </td>
                                    <td style="text-align: center;" class="whitespace-nowrap"><span class="status-badge status-info"><?php echo (float)($item['units'] ?? 0) == (int)($item['units'] ?? 0) ? (int)($item['units'] ?? 0) : number_format((float)($item['units'] ?? 0), 1); ?></span></td>
                                    <td class="num-col whitespace-nowrap" style="text-align: center; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums;">
                                        <?php echo $item['_final_grade'] !== null ? htmlspecialchars(number_format((float)$item['_final_grade'], 2)) : '—'; ?>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars((string)$item['_badge_class']); ?>">
                                            <?php echo htmlspecialchars((string)$item['_remark_label']); ?>
                                        </span>
                                        <?php if ((int)($item['is_locked'] ?? 0) === 1): ?>
                                            <span title="Locked" style="color:#991b1b; font-size:0.75rem; margin-left:4px;"><i class="fas fa-lock"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($can_edit) { 
                                        $item_grade_id = (int)($item['grade_id'] ?? 0);
                                        $row_is_locked = ($item_grade_id > 0 && (int)($item['is_locked'] ?? 0) === 1);
                                        $k_sub = $group['student_id'] . '_' . $item['subject_id'] . '_' . $group['academic_year'] . '_' . $group['semester'];
                                        $k_term = $group['student_id'] . '_0_' . $group['academic_year'] . '_' . $group['semester'];
                                        $has_edit_pending = isset($pending_edit_requests_map[$k_sub]) || isset($pending_edit_requests_map[$k_term]);
                                    ?>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <?php if ($item_grade_id <= 0): ?>
                                            <a href="afms_student_record.php?student_id=<?php echo (int)$group['student_id']; ?>&academic_year=<?php echo urlencode($group['academic_year']); ?>&semester=<?php echo urlencode($group['semester']); ?>&open_manage=1" class="action-btn-secondary" style="font-size: 0.78rem; padding: 0.35rem 0.65rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                <i class="fas fa-plus-circle"></i> Encode
                                            </a>
                                        <?php elseif ($row_is_locked): ?>
                                            <?php if ($has_edit_pending): ?>
                                                <span class="badge" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; font-size:0.75rem; padding:0.35rem 0.65rem; border-radius:6px; display:inline-flex; align-items:center; gap:0.3rem;" title="Edit request is pending review with Registrar">
                                                    <i class="fas fa-clock-rotate-left"></i> Pending
                                                </span>
                                            <?php else: ?>
                                                <button type="button" class="btn-request-edit-row action-btn-secondary"
                                                    data-student-id="<?php echo (int)$group['student_id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($group['student_name']); ?>"
                                                    data-subject-id="<?php echo (int)$item['subject_id']; ?>"
                                                    data-subject-name="<?php echo htmlspecialchars($item['subject_code'] . ' - ' . $item['subject_name']); ?>"
                                                    data-academic-year="<?php echo htmlspecialchars($group['academic_year']); ?>"
                                                    data-semester="<?php echo htmlspecialchars($group['semester']); ?>"
                                                    style="width: auto; padding: 0.35rem 0.65rem; font-size: 0.78rem; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                    <i class="fas fa-lock-open"></i> Request Edit
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <button type="button" class="btn-edit-grade"
                                                data-grade-id="<?php echo $item_grade_id; ?>"
                                                data-student-name="<?php echo htmlspecialchars($group['student_name']); ?>"
                                                data-subject-code="<?php echo htmlspecialchars((string)$item['subject_code']); ?>"
                                                data-subject-name="<?php echo htmlspecialchars((string)$item['subject_name']); ?>"
                                                data-final-grade="<?php echo $item['_final_grade'] !== null ? htmlspecialchars((string)$item['_final_grade']) : ''; ?>"
                                                data-dropped="<?php echo !empty($item['_is_dropped']) ? '1' : '0'; ?>"
                                                style="width: auto; padding: 0.35rem 0.65rem; font-size: 0.78rem;">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <?php } ?>
                                </tr>
                            <?php } } ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 800; border-top: 2px solid #e2e8f0;">
                                <td colspan="2" style="text-align: right; color: #475569;">Semester Total:</td>
                                <td style="text-align: center;"><span class="badge badge-primary"><?php echo (float)$group['total_units'] == (int)$group['total_units'] ? (int)$group['total_units'] : number_format((float)$group['total_units'], 1); ?> units</span></td>
                                <td style="text-align: center; font-weight: 800; color: <?php echo $avg_color; ?>;">
                                    <?php echo $avg !== null ? htmlspecialchars(number_format($avg, 2)) : '—'; ?>
                                </td>
                                <td>
                                    <?php
                                    if ($group['has_failed']) {
                                        echo "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700'>Has Failed</span>";
                                    } elseif ($group['has_incomplete']) {
                                        echo "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600'>Pending Grades</span>";
                                    } elseif ($avg !== null && $avg >= 88) {
                                        echo "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700'>Passed</span>";
                                    } elseif ($avg !== null && $avg >= 75) {
                                        echo "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-700'>Passed - Below Standard</span>";
                                    } else {
                                        echo "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600'>Active</span>";
                                    }
                                    ?>
                                </td>
                                <?php if ($can_edit) { ?><td></td><?php } ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php } ?>
        </div>

        <?php if ($can_edit) { ?>
        <!-- Edit Grade Modal -->
        <div id="editGradeModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 10000; align-items: center; justify-content: center; padding: 1rem;">
            <div style="background: #fff; border-radius: 1rem; width: 100%; max-width: 520px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
                <div style="padding: 1.25rem 1.5rem; background: #0f172a; color: #fff; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; color: #fff;"><i class="fas fa-edit"></i> Edit Subject Grade</h3>
                        <div id="modalSubTitle" style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.2rem;"></div>
                    </div>
                    <button type="button" id="closeModalBtn" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer; padding: 0.25rem; line-height: 1;">&times;</button>
                </div>
                <form method="post" id="editGradeForm" style="padding: 1.5rem; margin: 0; box-shadow: none; border: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="grade_id" id="editGradeId">
                    <div class="field" style="margin: 0;">
                        <label for="editFinalGrade" style="font-size: 0.75rem; font-weight: 700;">Final Grade (0-100)</label>
                        <input type="number" name="final_grade" id="editFinalGrade" min="0" max="100" step="0.01" placeholder="e.g. 90.00">
                    </div>

                    <div style="margin-top: 0.85rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: #475569; margin: 0;">
                            <input type="checkbox" name="is_dropped" id="editMarkDropped" value="1" style="width: auto; margin: 0;">
                            <span>Mark as Dropped</span>
                        </label>
                    </div>

                    <!-- Auto-calculate preview in modal -->
                    <div id="modalCalcPreview" style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; gap: 0.75rem;">
                        <div>Final Grade: <strong id="modal_preview_grade" style="font-size: 1.1rem; color: #0f172a;">—</strong></div>
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <span style="font-size: 0.8rem; color: #64748b;">Remark:</span>
                            <span id="modal_preview_status" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-500">Pending</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
                        <button type="button" id="cancelModalBtn" style="width: auto; padding: 0.55rem 1rem; background: #e2e8f0; color: #334155; border: none; border-radius: 6px; cursor: pointer; font-weight: 700;">Cancel</button>
                        <button type="submit" name="edit_grade" style="width: auto; padding: 0.55rem 1.25rem;"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Global Request Grade Edit Modal -->
        <div id="requestEditModalGlobal" style="display: none; position: fixed; inset: 0; background: rgba(89, 68, 63, 0.5); backdrop-filter: blur(4px); z-index: 10060; align-items: center; justify-content: center; padding: 1.25rem;">
            <div style="background: #ffffff; border-radius: 1.5rem; width: 100%; max-width: 520px; box-shadow: 0 20px 45px -10px rgba(89, 68, 63, 0.25); overflow: hidden; border: 1px solid rgba(232, 199, 197, 0.7); font-family: 'Inter', sans-serif;">
                <div style="padding: 1.25rem 1.75rem; background: #fef2f2; border-bottom: 1px solid #fecaca; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #991b1b; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-lock-open" style="color: #ef4444;"></i> Request Grade Edit Permission
                    </h3>
                    <button type="button" id="closeGlobalRequestEditBtn" style="background: none; border: none; color: #991b1b; font-size: 1.5rem; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <form method="post" id="globalRequestEditForm" style="padding: 1.5rem; margin: 0; background: none; border: none; box-shadow: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="request_grade_edit" value="1">
                    <input type="hidden" name="student_id" id="edit_modal_student_id">
                    <input type="hidden" name="subject_id" id="edit_modal_subject_id">
                    <input type="hidden" name="academic_year" id="edit_modal_academic_year">
                    <input type="hidden" name="semester" id="edit_modal_semester">

                    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #F8EEEE; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-size: 0.825rem; color: #59443F;">
                        <div>Target Seminarian: <strong id="edit_modal_student_label" class="text-[#59443F]">—</strong></div>
                        <div style="margin-top: 2px;">Subject: <strong id="edit_modal_subject_label" class="text-[#59443F]">—</strong></div>
                        <div style="margin-top: 2px; font-size: 0.75rem; color: #8B7168;">Submitting request to the <strong>Office of the Registrar</strong>.</div>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #8B7168; margin-bottom: 0.4rem;">Reason / Justification for Edit (Required)</label>
                        <textarea name="edit_reason" id="global_edit_reason" rows="3" required placeholder="Please state the specific reason for altering locked grades (e.g., calculation mistake in midterm examination, late compliance submission, encoding correction)..." style="width: 100%; padding: 0.65rem 0.85rem; border-radius: 0.75rem; border: 1px solid #E8C7C5; font-size: 0.875rem; color: #59443F; resize: vertical;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid rgba(232, 199, 197, 0.4);">
                        <button type="button" id="cancelGlobalRequestEditBtn" class="action-btn-secondary" style="padding: 0.6rem 1.25rem; border-radius: 0.75rem; font-weight: 700; cursor: pointer;">
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

        <!-- Review Grade Viewing Requests Modal -->
        <div id="gradeRequestsModal" data-global-modal="1" class="<?php echo htmlspecialchars(global_modal_overlay_classes('opacity-0 pointer-events-none transition-opacity duration-200'), ENT_QUOTES, 'UTF-8'); ?>" onclick="if(event.target === this) closeGradeRequestsModal();" aria-modal="true" role="dialog">
            <div class="bg-white rounded-3xl sm:rounded-[2rem] border border-[#E8C7C5]/70 shadow-2xl w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-200 font-['Inter',sans-serif]">
                
                <!-- Modal Header -->
                <div class="px-6 py-5 border-b border-[#E8C7C5]/40 flex items-center justify-between bg-gradient-to-r from-white to-[#F8EEEE]/40 shrink-0">
                    <div class="flex items-center gap-3.5">
                        <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 flex items-center justify-center text-lg shadow-xs shrink-0">
                            <i class="fa-solid fa-list-check text-[#8B7168]"></i>
                        </div>
                        <div>
                            <h3 class="font-heading font-black text-lg sm:text-xl text-[#59443F] tracking-tight">Review Grade Viewing Requests</h3>
                            <p class="text-xs text-[#8B7168] mt-0.5">Approve or deny seminarians requesting access to view their semester or subject grades.</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeGradeRequestsModal()" class="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] hover:bg-[#E8C7C5] hover:text-[#59443F] transition-all flex items-center justify-center cursor-pointer shadow-xs" title="Close Modal">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <!-- Alert Box inside modal -->
                <div id="modalAlertBox" class="hidden mx-6 mt-4 p-3 rounded-2xl text-xs font-bold transition-all duration-200"></div>

                <!-- Modal Body (Table) -->
                <div class="overflow-x-auto flex-1 p-6">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/50 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                <th class="px-5 py-3.5 rounded-l-2xl">Seminarian</th>
                                <th class="px-5 py-3.5">Requested Scope / Subjects</th>
                                <th class="px-5 py-3.5">Date Requested</th>
                                <th class="px-5 py-3.5 text-right rounded-r-2xl">Action</th>
                            </tr>
                        </thead>
                        <tbody id="modalRequestsTableBody" class="divide-y divide-[#E8C7C5]/30 text-xs text-[#59443F]">
                            <?php if (empty($pending_access_requests)): ?>
                                <tr id="modalEmptyRow">
                                    <td colspan="4" class="px-6 py-12 text-center text-[#8B7168]">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                                                <i class="fa-solid fa-circle-check"></i>
                                            </div>
                                            <span class="font-bold text-[#59443F]">All caught up!</span>
                                            <span class="text-xs">There are no pending grade viewing requests.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending_access_requests as $req): 
                                    $req_id = (int)$req['id'];
                                    $sname = trim((string)($req['full_name'] ?? ''));
                                    if ($sname === '') $sname = (string)($req['username'] ?? 'Seminarian');
                                    $sno = trim((string)($req['student_no'] ?? ''));
                                    $rscope = (string)($req['request_scope'] ?? 'whole');
                                    $rsubs = [];
                                    if (!empty($req['requested_subjects'])) {
                                        $rsubs = json_decode((string)$req['requested_subjects'], true) ?: [];
                                    }
                                    $rdate = date('M d, Y - h:i A', strtotime((string)$req['requested_at']));
                                ?>
                                    <tr id="request-row-<?= $req_id ?>" class="hover:bg-[#F8EEEE]/30 transition-colors">
                                        <td class="px-5 py-4">
                                            <div class="font-bold text-sm text-[#59443F]"><?= htmlspecialchars($sname) ?></div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <?php if ($sno !== ''): ?>
                                                    <span class="text-[10px] font-bold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded-full border border-[#E8C7C5]/60"><?= htmlspecialchars($sno) ?></span>
                                                <?php endif; ?>
                                                <span class="text-[10px] text-[#8B7168]">ID #<?= (int)$req['seminarian_id'] ?></span>
                                            </div>
                                            <?php if (!empty($req['message'])): ?>
                                                <div class="text-[11px] text-[#8B7168] italic bg-[#F8EEEE]/40 p-2 rounded-xl border border-[#E8C7C5]/40 mt-1.5 max-w-xs">
                                                    "<?= htmlspecialchars((string)$req['message']) ?>"
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4">
                                            <?php if ($rscope === 'selected' && !empty($rsubs)): ?>
                                                <div class="space-y-1.5">
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#E8C7C5] text-[#59443F]">
                                                        <i class="fa-solid fa-list-check text-[9px]"></i>
                                                        Selected Subjects (<?= count($rsubs) ?>)
                                                    </span>
                                                    <div class="flex flex-wrap gap-1.5 pt-1">
                                                        <?php foreach ($rsubs as $sb): ?>
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-white text-[#59443F] border border-[#E8C7C5]/60 shadow-2xs" title="<?= htmlspecialchars($sb['subject_name'] ?? '') ?>">
                                                                <i class="fa-solid fa-book text-[8px] text-[#D6A6A6]"></i>
                                                                <?= htmlspecialchars($sb['subject_code'] ?? '') ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#59443F] text-white shadow-2xs">
                                                    <i class="fa-solid fa-layer-group text-[10px] text-[#E8C7C5]"></i>
                                                    Whole Semester
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap text-[#8B7168] font-medium text-[11px]">
                                            <?= htmlspecialchars($rdate) ?>
                                        </td>
                                        <td class="px-5 py-4 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-2">
                                                <button type="button" onclick="resolveGradeRequest(<?= $req_id ?>, 'approve_grade_request', this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition-all duration-150 cursor-pointer disabled:opacity-50">
                                                    <i class="fa-solid fa-check text-[11px]"></i>
                                                    <span>Approve</span>
                                                </button>
                                                <button type="button" onclick="resolveGradeRequest(<?= $req_id ?>, 'deny_grade_request', this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 hover:border-rose-300 shadow-xs transition-all duration-150 cursor-pointer disabled:opacity-50">
                                                    <i class="fa-solid fa-xmark text-[11px]"></i>
                                                    <span>Deny</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 border-t border-[#E8C7C5]/40 bg-[#F8EEEE]/30 flex items-center justify-between shrink-0">
                    <span class="text-xs text-[#8B7168]">
                        Showing <strong id="modalTableCount" class="text-[#59443F] font-bold"><?= count($pending_access_requests) ?></strong> pending request(s)
                    </span>
                    <button type="button" onclick="closeGradeRequestsModal()" class="px-4 py-2 rounded-xl text-xs font-bold bg-white hover:bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 shadow-xs transition cursor-pointer">
                        Close
                    </button>
                </div>

            </div>
        </div>

        <script>
        // Automated single Final Grade evaluation
        function evaluateFinalGrade(rawVal, isDropped) {
            if (isDropped) {
                return { grade: null, remark: 'Dropped', cls: 'bg-gray-100 text-gray-600' };
            }
            const str = rawVal !== null && rawVal !== undefined ? String(rawVal).trim() : '';
            if (str === '' || isNaN(Number(str))) {
                return { grade: null, remark: 'Pending', cls: 'bg-slate-100 text-slate-500' };
            }
            const num = Math.round((Number(str) + Number.EPSILON) * 100) / 100;
            if (num >= 88) {
                return { grade: num, remark: 'Passed', cls: 'bg-green-100 text-green-700' };
            }
            if (num >= 75) {
                return { grade: num, remark: 'Passed - Below Standard', cls: 'bg-orange-100 text-orange-700' };
            }
            return { grade: num, remark: 'Failed', cls: 'bg-red-100 text-red-700' };
        }

        // Edit Grade Modal wiring
        const modal = document.getElementById('editGradeModal');
        const modalFinalGrade = document.getElementById('editFinalGrade');
        const modalDropped = document.getElementById('editMarkDropped');

        function updateModalPreview() {
            if (!modalFinalGrade) return;
            const isDropped = modalDropped ? modalDropped.checked : false;
            modalFinalGrade.disabled = isDropped;
            modalFinalGrade.style.background = isDropped ? '#f1f5f9' : '';
            if (!isDropped) {
                modalFinalGrade.required = true;
            } else {
                modalFinalGrade.required = false;
            }

            const outcome = evaluateFinalGrade(modalFinalGrade.value, isDropped);
            const gEl = document.getElementById('modal_preview_grade');
            const sEl = document.getElementById('modal_preview_status');
            if (gEl) {
                gEl.textContent = outcome.grade !== null ? outcome.grade.toFixed(2) : '—';
            }
            if (sEl) {
                sEl.textContent = outcome.remark;
                sEl.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ' + outcome.cls;
            }
        }
        modalFinalGrade?.addEventListener('input', updateModalPreview);
        modalDropped?.addEventListener('change', updateModalPreview);

        function openEditModal(btn) {
            if (!modal) return;
            document.getElementById('editGradeId').value = btn.dataset.gradeId;
            document.getElementById('modalSubTitle').textContent = btn.dataset.studentName + ' · ' + btn.dataset.subjectCode + ' - ' + btn.dataset.subjectName;
            modalFinalGrade.value = btn.dataset.finalGrade || '';
            if (modalDropped) {
                modalDropped.checked = (btn.dataset.dropped === '1');
            }
            updateModalPreview();
            modal.style.display = 'flex';
        }

        function closeEditModal() {
            if (modal) modal.style.display = 'none';
        }

        document.getElementById('closeModalBtn')?.addEventListener('click', closeEditModal);
        document.getElementById('cancelModalBtn')?.addEventListener('click', closeEditModal);
        modal?.addEventListener('click', function(e) {
            if (e.target === modal) closeEditModal();
        });

        document.querySelectorAll('.btn-edit-grade').forEach(btn => {
            btn.addEventListener('click', () => openEditModal(btn));
        });

        // Confirmation on Edit Grade Save
        document.getElementById('editGradeForm')?.addEventListener('submit', function(e) {
            if (!confirm("Are you sure you want to save? Once submitted, grades cannot be edited.")) {
                e.preventDefault();
                return false;
            }
        });

        // Global Request Edit Modal
        const globalReqEditModal = document.getElementById('requestEditModalGlobal');
        const closeGlobalReqEditBtn = document.getElementById('closeGlobalRequestEditBtn');
        const cancelGlobalReqEditBtn = document.getElementById('cancelGlobalRequestEditBtn');

        function openGlobalReqEdit(studentId, studentName, subjectId, subjectName, ay, sem) {
            if (!globalReqEditModal) return;
            document.getElementById('edit_modal_student_id').value = studentId;
            document.getElementById('edit_modal_subject_id').value = subjectId;
            document.getElementById('edit_modal_academic_year').value = ay;
            document.getElementById('edit_modal_semester').value = sem;
            document.getElementById('edit_modal_student_label').textContent = studentName + ' (ID #' + studentId + ')';
            document.getElementById('edit_modal_subject_label').textContent = subjectName;
            globalReqEditModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeGlobalReqEdit() {
            if (globalReqEditModal) {
                globalReqEditModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        closeGlobalReqEditBtn?.addEventListener('click', closeGlobalReqEdit);
        cancelGlobalReqEditBtn?.addEventListener('click', closeGlobalReqEdit);
        globalReqEditModal?.addEventListener('click', (e) => {
            if (e.target === globalReqEditModal) closeGlobalReqEdit();
        });

        document.querySelectorAll('.btn-request-edit-row').forEach(btn => {
            btn.addEventListener('click', function() {
                openGlobalReqEdit(
                    this.dataset.studentId,
                    this.dataset.studentName,
                    this.dataset.subjectId,
                    this.dataset.subjectName,
                    this.dataset.academicYear,
                    this.dataset.semester
                );
            });
        });



        // Review Grade Requests Modal Controller & AJAX Resolution
        function openGradeRequestsModal() {
            const modal = document.getElementById('gradeRequestsModal');
            if (!modal) return;
            modal.classList.remove('opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100', 'pointer-events-auto');
            const dialog = modal.querySelector('div[class*="rounded-3xl"], div[class*="rounded-[2rem]"]');
            if (dialog) {
                dialog.classList.remove('scale-95');
                dialog.classList.add('scale-100');
            }
        }

        function closeGradeRequestsModal() {
            const modal = document.getElementById('gradeRequestsModal');
            if (!modal) return;
            modal.classList.remove('opacity-100', 'pointer-events-auto');
            modal.classList.add('opacity-0', 'pointer-events-none');
            const dialog = modal.querySelector('div[class*="rounded-3xl"], div[class*="rounded-[2rem]"]');
            if (dialog) {
                dialog.classList.remove('scale-100');
                dialog.classList.add('scale-95');
            }
        }

        async function resolveGradeRequest(requestId, action, btnElement) {
            const actionVerb = (action === 'approve_grade_request') ? 'approve' : 'deny';
            if (!confirm(`Are you sure you want to ${actionVerb} this grade viewing request?`)) {
                return;
            }

            const row = document.getElementById(`request-row-${requestId}`);
            const buttons = row ? row.querySelectorAll('button') : [];
            buttons.forEach(b => b.disabled = true);

            const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';
            const formData = new FormData();
            formData.append('action', action);
            formData.append('request_id', requestId);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('afms_grades.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json();

                if (data.success) {
                    showModalNotification(data.message, 'success');
                    if (row) {
                        row.classList.add('bg-emerald-50/60', 'transition-all', 'duration-300');
                        setTimeout(() => {
                            row.remove();
                            updateModalState(data.pending_count);
                        }, 300);
                    } else {
                        updateModalState(data.pending_count);
                    }
                } else {
                    buttons.forEach(b => b.disabled = false);
                    showModalNotification(data.error || 'Failed to process request.', 'error');
                }
            } catch (err) {
                buttons.forEach(b => b.disabled = false);
                showModalNotification('A network error occurred. Please try again.', 'error');
            }
        }

        function updateModalState(newCount) {
            // 1. Update yellow banner
            const banner = document.getElementById('pendingRequestsBanner');
            const bannerCount = document.getElementById('pendingRequestsCount');
            if (bannerCount) bannerCount.textContent = newCount;
            if (banner) {
                if (newCount <= 0) {
                    banner.style.display = 'none';
                } else {
                    banner.style.display = '';
                }
            }

            // 2. Update modal counter & empty state
            const modalCount = document.getElementById('modalTableCount');
            if (modalCount) modalCount.textContent = newCount;

            const tbody = document.getElementById('modalRequestsTableBody');
            const emptyRow = document.getElementById('modalEmptyRow');
            if (newCount <= 0 && tbody && !emptyRow) {
                tbody.innerHTML = `
                    <tr id="modalEmptyRow">
                        <td colspan="4" class="px-6 py-12 text-center text-[#8B7168]">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                                <span class="font-bold text-[#59443F]">All caught up!</span>
                                <span class="text-xs">There are no pending grade viewing requests.</span>
                            </div>
                        </td>
                    </tr>`;
            }

            // 3. Update sidebar grade badge
            const sidebarBadges = document.querySelectorAll('[data-badge-for="afms_grades"]');
            sidebarBadges.forEach(badge => {
                if (newCount <= 0) {
                    badge.style.display = 'none';
                } else {
                    badge.textContent = newCount;
                    badge.style.display = 'inline-flex';
                }
            });

            // 4. Update parent group badge if present
            const groupBadges = document.querySelectorAll('[data-group-badge-for="afms_records_group"]');
            groupBadges.forEach(gb => {
                if (newCount <= 0) {
                    gb.style.display = 'none';
                } else {
                    gb.textContent = newCount;
                    gb.style.display = 'inline-flex';
                }
            });
        }

        function showModalNotification(msg, type) {
            const box = document.getElementById('modalAlertBox');
            if (!box) return;
            box.textContent = msg;
            box.className = `mx-6 mt-4 p-3 rounded-2xl text-xs font-bold transition-all duration-200 ${type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'}`;
            box.classList.remove('hidden');
            setTimeout(() => {
                box.classList.add('hidden');
            }, 4500);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeGradeRequestsModal();
        });
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
