<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';

$current_ay = get_active_academic_year();
$active_ays = get_active_academic_years();

// Fetch student list for zero-state picker (if accessed without ID)
$students = [];
$res_students = $conn->query(
    "SELECT s.id as seminarian_id, u.username, COALESCE(up.full_name, u.username) as full_name, s.program, s.year_level, s.academic_status\n"
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

// Support both seminarian_id and student_id from query parameters
$student_id = (int)($_GET['seminarian_id'] ?? ($_GET['student_id'] ?? 0));
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$active_tab = trim((string)($_GET['tab'] ?? ''));

if ($academic_year === '' || !in_array($academic_year, $active_ays, true)) {
    $academic_year = $current_ay;
}

if (!in_array($active_tab, ['1st', '2nd', 'split'], true)) {
    $active_tab = (get_active_semester() === '2nd') ? '2nd' : '1st';
}

// Flash messages from redirect
$success = $_SESSION['flash_success'] ?? null;
$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle dropping an enrolled subject
if (isset($_POST['drop_enrolled_subject'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $enrolled_id = (int)($_POST['drop_enrolled_subject'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);
    $ay = trim((string)($_POST['academic_year'] ?? ''));
    $tab = trim((string)($_POST['tab'] ?? '1st'));

    if ($enrolled_id > 0 && $sid > 0) {
        $stmt_info = $conn->prepare("SELECT subject_id, academic_year, semester FROM student_enrolled_subjects WHERE id = ? AND student_id = ? LIMIT 1");
        $stmt_info->bind_param('ii', $enrolled_id, $sid);
        $stmt_info->execute();
        $dropped_row = $stmt_info->get_result()->fetch_assoc();

        $stmt_del = $conn->prepare("DELETE FROM student_enrolled_subjects WHERE id = ? AND student_id = ?");
        $stmt_del->bind_param('ii', $enrolled_id, $sid);
        if ($stmt_del->execute()) {
            if ($dropped_row && function_exists('registrar_sync_and_migrate')) {
                $d_sub_id = (int)($dropped_row['subject_id'] ?? 0);
                $d_ay = $conn->real_escape_string((string)($dropped_row['academic_year'] ?? ''));
                $d_sem = $conn->real_escape_string((string)($dropped_row['semester'] ?? ''));
                $conn->query("DELETE es FROM enrollment_subjects es JOIN enrollments e ON e.id = es.enrollment_id WHERE e.student_id = " . (int)$sid . " AND e.academic_year = '{$d_ay}' AND e.semester = '{$d_sem}' AND es.subject_id = {$d_sub_id}");
                registrar_sync_and_migrate($conn, true);
            }
            log_activity((int)($_SESSION['user'] ?? 0), "Removed/dropped enrolled subject ID {$enrolled_id} for seminarian ID {$sid}");
            $_SESSION['flash_success'] = 'Subject was successfully removed from enrollment.';
            header('Location: subject_enrollment.php?seminarian_id=' . $sid . '&academic_year=' . urlencode($ay) . '&tab=' . urlencode($tab));
            exit;
        }
    }
}

// Handle Form Submissions for Enrollment
if (isset($_POST['enroll_semester']) || isset($_POST['enroll_both'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $sid = (int)($_POST['student_id'] ?? 0);
    $ay = trim((string)($_POST['academic_year'] ?? ''));
    $target_sem = isset($_POST['enroll_both']) ? 'both' : trim((string)($_POST['enroll_semester'] ?? ''));

    if ($sid <= 0) {
        $error = 'Invalid seminarian ID.';
    } elseif (!in_array($ay, $active_ays, true)) {
        $error = 'Invalid Academic Year.';
    } elseif (!in_array($target_sem, ['1st', '2nd', 'both'], true)) {
        $error = 'Invalid semester target selected.';
    } else {
        $stmt_st = $conn->prepare("SELECT s.id as student_id, s.program, s.year_level, s.academic_status FROM seminarians s WHERE s.id = ? LIMIT 1");
        $stmt_st->bind_param('i', $sid);
        $stmt_st->execute();
        $st = $stmt_st->get_result()->fetch_assoc();

        if (!$st) {
            $error = 'Seminarian not found in database.';
        } else {
            $p = (string)($st['program'] ?? '');
            $yl = function_exists('normalize_year_level') ? normalize_year_level((string)($st['year_level'] ?? '')) : trim((string)($st['year_level'] ?? ''));

            $guard_err = function_exists('registrar_validate_enrollment_guardrails')
                ? registrar_validate_enrollment_guardrails($conn, $sid, $ay, $target_sem === 'both' ? '1st' : $target_sem, $yl, $p)
                : null;
            if ($guard_err !== null) {
                $error = $guard_err;
            } else {
            $status = (string)($st['academic_status'] ?? 'Regular');

            $stmt_cv = $conn->prepare(
                "SELECT cv.id FROM curriculum_versions cv\n"
                . "LEFT JOIN academic_years ay ON ay.id = cv.academic_year_id\n"
                . "WHERE cv.program = ?\n"
                . "  AND cv.is_active = 1\n"
                . "  AND cv.archived_at IS NULL\n"
                . "ORDER BY (ay.label = ?) DESC, (cv.is_current = 1) DESC, cv.id DESC\n"
                . "LIMIT 1"
            );
            $stmt_cv->bind_param('ss', $p, $ay);
            $stmt_cv->execute();
            $cv_row = $stmt_cv->get_result()->fetch_assoc();
            $curriculum_version_id = (int)($cv_row['id'] ?? 0);

            // Build strict allowed subject set for this student's current stage/year level
            $stage_allowed_subjects = [];
            $yl_ranks = ['Propaedeutic Stage' => 0, 'First Year' => 1, 'Second Year' => 2, 'Third Year' => 3, 'Fourth Year' => 4];
            $student_rank = $yl_ranks[$yl] ?? 4;
            $allowed_yls = [];
            if ($yl === 'Propaedeutic Stage') {
                $allowed_yls = ['Propaedeutic Stage'];
            } else {
                foreach ($yl_ranks as $yl_name => $rk) {
                    if ($rk >= 1 && $rk <= $student_rank) {
                        $allowed_yls[] = $yl_name;
                    }
                }
            }
            if (!empty($allowed_yls) && $curriculum_version_id > 0) {
                $yl_in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $allowed_yls)) . "'";
                $res_allowed = $conn->query(
                    "SELECT DISTINCT cs.subject_id FROM curriculum_subjects cs
                     JOIN subjects s ON s.subject_id = cs.subject_id
                     WHERE cs.curriculum_version_id = {$curriculum_version_id}
                       AND cs.year_level IN ({$yl_in})
                       AND cs.is_active = 1 AND s.archived_at IS NULL"
                );
                while ($res_allowed && ($ar = $res_allowed->fetch_assoc())) {
                    $stage_allowed_subjects[(int)$ar['subject_id']] = true;
                }
            }

            $semesters_to_process = ($target_sem === 'both') ? ['1st', '2nd'] : [$target_sem];
            $total_enrolled = 0;
            $has_selected_any = false;

            $conn->begin_transaction();
            try {
                // Fetch student's passed subjects for server-side prerequisite validation
                $passed_ids = [];
                $stmt_passed_check = $conn->prepare(
                    "SELECT DISTINCT sg.subject_id\n"
                    . "FROM student_grades sg\n"
                    . "WHERE sg.student_id = ?\n"
                    . "  AND sg.status = 'Completed'\n"
                    . "  AND (\n"
                    . "    (sg.final_grade IS NOT NULL AND sg.final_grade >= 75.0)\n"
                    . "    OR (sg.final_computed_grade IS NOT NULL AND sg.final_computed_grade <= 3.00 AND sg.final_computed_grade > 0)\n"
                    . "    OR (sg.prelim_grade > 0 AND sg.midterm_grade > 0 AND sg.final_grade > 0 AND ((sg.prelim_grade * 0.3) + (sg.midterm_grade * 0.3) + (sg.final_grade * 0.4)) >= 74.5)\n"
                    . "  )"
                );
                $stmt_passed_check->bind_param('i', $sid);
                $stmt_passed_check->execute();
                $res_passed_check = $stmt_passed_check->get_result();
                while ($r_pass = $res_passed_check->fetch_assoc()) {
                    $passed_ids[(int)$r_pass['subject_id']] = true;
                }

                $stmt_chk_prereq = $conn->prepare(
                    "SELECT s.subject_code, s.subject_name,\n"
                    . "  COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id) AS prerequisite_subject_id,\n"
                    . "  p.subject_code AS prereq_code, p.subject_name AS prereq_name\n"
                    . "FROM subjects s\n"
                    . "LEFT JOIN curriculum_subjects cs ON (cs.subject_id = s.subject_id AND cs.curriculum_version_id = ? AND cs.semester = ?)\n"
                    . "LEFT JOIN subjects p ON p.subject_id = COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id)\n"
                    . "WHERE s.subject_id = ? LIMIT 1"
                );

                $stmt_sem_sub = $conn->prepare("INSERT IGNORE INTO semester_subjects (subject_id, semester, academic_year, year_level) VALUES (?, ?, ?, ?)");
                $stmt_enr = $conn->prepare("INSERT IGNORE INTO student_enrolled_subjects (student_id, subject_id, curriculum_version_id, program, year_level, semester, academic_year, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Enrolled')");

                foreach ($semesters_to_process as $sem) {
                    $posted_key = 'subject_ids_' . $sem;
                    $posted_raw = isset($_POST[$posted_key]) ? (array)$_POST[$posted_key] : (isset($_POST['subject_ids']) ? (array)$_POST['subject_ids'] : []);
                    $selected_ids = array_values(array_unique(array_filter(array_map('intval', $posted_raw), static fn ($v) => $v > 0)));

                    // If "Enroll Both" was clicked without specific checkboxes posted, load default curriculum subjects
                    if (isset($_POST['enroll_both']) && count($selected_ids) === 0 && $curriculum_version_id > 0) {
                        $stmt_def = $conn->prepare("SELECT cs.subject_id FROM curriculum_subjects cs WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = ? AND cs.is_active = 1");
                        $stmt_def->bind_param('iss', $curriculum_version_id, $yl, $sem);
                        $stmt_def->execute();
                        $res_def = $stmt_def->get_result();
                        while ($r = $res_def->fetch_assoc()) {
                            $selected_ids[] = (int)$r['subject_id'];
                        }
                    }

                    if (count($selected_ids) === 0) {
                        continue;
                    }
                    $has_selected_any = true;

                    // Enforce strict stage & year-level subject eligibility
                    $in_list = implode(',', $selected_ids);
                    $chk_sql = "SELECT subject_id, subject_code FROM subjects WHERE subject_id IN ($in_list) AND archived_at IS NULL";
                    $chk_res = $conn->query($chk_sql);
                    $valid_ids = [];
                    while ($r = $chk_res->fetch_assoc()) {
                        $cand_id = (int)$r['subject_id'];
                        if (!empty($stage_allowed_subjects) && !isset($stage_allowed_subjects[$cand_id])) {
                            $bad_code = (string)($r['subject_code'] ?? ('Subject #' . $cand_id));
                            throw new Exception("Curriculum stage violation: Subject '{$bad_code}' does not belong to {$yl} ({$p}).");
                        }
                        $valid_ids[] = $cand_id;
                    }

                    foreach ($valid_ids as $sub_id) {
                        // Strict Server-Side Prerequisite Validation
                        $stmt_chk_prereq->bind_param('isi', $curriculum_version_id, $sem, $sub_id);
                        $stmt_chk_prereq->execute();
                        $pr_row = $stmt_chk_prereq->get_result()->fetch_assoc();
                        $prereq_id = (int)($pr_row['prerequisite_subject_id'] ?? 0);

                        if ($prereq_id > 0 && !isset($passed_ids[$prereq_id])) {
                            $sub_code = (string)($pr_row['subject_code'] ?? 'Subject #' . $sub_id);
                            $prereq_code = (string)($pr_row['prereq_code'] ?? 'Prerequisite #' . $prereq_id);
                            throw new Exception("Enrollment rejected: Subject '{$sub_code}' is locked because prerequisite '{$prereq_code}' has not been completed with a passing grade yet.");
                        }

                        $stmt_sem_sub->bind_param('isss', $sub_id, $sem, $ay, $yl);
                        $stmt_sem_sub->execute();

                        $stmt_enr->bind_param('iiissss', $sid, $sub_id, $curriculum_version_id, $p, $yl, $sem, $ay);
                        $stmt_enr->execute();
                        $total_enrolled++;
                    }
                }

                if (!$has_selected_any) {
                    $conn->rollback();
                    $error = 'No subjects were selected. Please check at least one subject to enroll.';
                } else {
                    $conn->commit();
                    if (function_exists('registrar_sync_and_migrate')) {
                        registrar_sync_and_migrate($conn, true);
                    }
                    $label = ($target_sem === 'both') ? '1st & 2nd Semesters' : ($target_sem . ' Semester');
                    log_activity((int)($_SESSION['user'] ?? 0), "Enrolled {$total_enrolled} subjects for seminarian ID {$sid} ({$ay} {$label})");
                    
                    $_SESSION['flash_success'] = "Successfully enrolled {$total_enrolled} subjects for {$label} (AY {$ay})!";
                    header('Location: subject_enrollment.php?seminarian_id=' . $sid . '&academic_year=' . urlencode($ay) . '&tab=' . urlencode($target_sem));
                    exit;
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to enroll subjects: ' . $e->getMessage();
            }
            }
        }
    }
}

// Fetch Loaded Seminarian Profile Details
$student = null;
$program = '';
$year_level = '';
$academic_status = 'Regular';
$formation_stage = '';
$cv = null;
$cv_id = 0;

if ($student_id > 0) {
    $stmt_st_full = $conn->prepare(
        "SELECT s.id as seminarian_id, s.user_id, s.program, s.year_level, s.academic_status, s.formation_stage, s.formation_status,\n"
        . "  u.username, COALESCE(up.full_name, u.username) as full_name, up.contact, up.address\n"
        . "FROM seminarians s\n"
        . "JOIN users u ON u.id = s.user_id\n"
        . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
        . "WHERE s.id = ? LIMIT 1"
    );
    $stmt_st_full->bind_param('i', $student_id);
    $stmt_st_full->execute();
    $student = $stmt_st_full->get_result()->fetch_assoc();

    if ($student) {
        $program = (string)($student['program'] ?? '');
        $year_level = (string)($student['year_level'] ?? '');
        $academic_status = (string)($student['academic_status'] ?? 'Regular');
        $formation_stage = (string)($student['formation_stage'] ?? '');

        if ($program !== '') {
            $stmt_cv = $conn->prepare(
                "SELECT cv.id, cv.version_name FROM curriculum_versions cv\n"
                . "LEFT JOIN academic_years ay ON ay.id = cv.academic_year_id\n"
                . "WHERE cv.program = ?\n"
                . "  AND cv.is_active = 1\n"
                . "  AND cv.archived_at IS NULL\n"
                . "ORDER BY (ay.label = ?) DESC, (cv.is_current = 1) DESC, cv.id DESC\n"
                . "LIMIT 1"
            );
            $stmt_cv->bind_param('ss', $program, $academic_year);
            $stmt_cv->execute();
            $cv = $stmt_cv->get_result()->fetch_assoc();
            $cv_id = (int)($cv['id'] ?? 0);
        }
    }
}

// Fetch student's passed subjects map (status = Completed and passing grade)
$student_passed_subjects = [];
if ($student_id > 0) {
    $stmt_passed = $conn->prepare(
        "SELECT DISTINCT sg.subject_id\n"
        . "FROM student_grades sg\n"
        . "WHERE sg.student_id = ?\n"
        . "  AND sg.status = 'Completed'\n"
        . "  AND (\n"
        . "    (sg.final_grade IS NOT NULL AND sg.final_grade >= 75.0)\n"
        . "    OR (sg.final_computed_grade IS NOT NULL AND sg.final_computed_grade <= 3.00 AND sg.final_computed_grade > 0)\n"
        . "    OR (sg.prelim_grade > 0 AND sg.midterm_grade > 0 AND sg.final_grade > 0 AND ((sg.prelim_grade * 0.3) + (sg.midterm_grade * 0.3) + (sg.final_grade * 0.4)) >= 74.5)\n"
        . "  )"
    );
    if ($stmt_passed) {
        $stmt_passed->bind_param('i', $student_id);
        $stmt_passed->execute();
        $res_passed = $stmt_passed->get_result();
        while ($r = $res_passed->fetch_assoc()) {
            $student_passed_subjects[(int)$r['subject_id']] = true;
        }
    }
}

// Fetch Active Subjects Catalog filtered strictly to the student's eligible curriculum stage & year level
$all_catalog_subjects = [];
$norm_student_yl = ($year_level !== '' && function_exists('normalize_year_level')) ? normalize_year_level($year_level) : $year_level;
if ($student_id > 0 && $cv_id > 0 && $norm_student_yl !== '') {
    $yl_ranks = ['Propaedeutic Stage' => 0, 'First Year' => 1, 'Second Year' => 2, 'Third Year' => 3, 'Fourth Year' => 4];
    $student_rank = $yl_ranks[$norm_student_yl] ?? 4;
    $allowed_yls = ($norm_student_yl === 'Propaedeutic Stage') ? ['Propaedeutic Stage'] : array_keys(array_filter($yl_ranks, static fn ($rk) => $rk >= 1 && $rk <= $student_rank));
    $yl_in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $allowed_yls)) . "'";
    $res_catalog = $conn->query(
        "SELECT DISTINCT s.subject_id, s.subject_code, s.subject_name, s.units, s.prerequisite_subject_id, p.subject_code AS prereq_subject_code, p.subject_name AS prereq_subject_name\n"
        . "FROM curriculum_subjects cs\n"
        . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
        . "LEFT JOIN subjects p ON p.subject_id = COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id)\n"
        . "WHERE cs.curriculum_version_id = " . (int)$cv_id . "\n"
        . "  AND cs.year_level IN ({$yl_in})\n"
        . "  AND cs.is_active = 1\n"
        . "  AND s.archived_at IS NULL\n"
        . "ORDER BY s.subject_code ASC"
    );
} else {
    $res_catalog = $conn->query(
        "SELECT s.subject_id, s.subject_code, s.subject_name, s.units, s.prerequisite_subject_id, p.subject_code AS prereq_subject_code, p.subject_name AS prereq_subject_name\n"
        . "FROM subjects s\n"
        . "LEFT JOIN subjects p ON p.subject_id = s.prerequisite_subject_id\n"
        . "WHERE s.archived_at IS NULL\n"
        . "ORDER BY s.subject_code ASC"
    );
}
if ($res_catalog) {
    while ($r = $res_catalog->fetch_assoc()) {
        $all_catalog_subjects[] = [
            'id' => (int)$r['subject_id'],
            'code' => (string)$r['subject_code'],
            'name' => (string)$r['subject_name'],
            'units' => (float)$r['units'],
            'prerequisite_subject_id' => (int)($r['prerequisite_subject_id'] ?? 0),
            'prereq_subject_code' => (string)($r['prereq_subject_code'] ?? ''),
            'prereq_subject_name' => (string)($r['prereq_subject_name'] ?? ''),
        ];
    }
}

// Fetch Recommended Retake / Failed Subjects for this student (from previous terms)
$retake_candidates = [];
if ($student_id > 0) {
    $stmt_sg = $conn->prepare(
        "SELECT DISTINCT g.subject_id, s.subject_code, s.subject_name, s.units, g.final_grade, g.final_computed_grade, g.status, g.academic_year, g.semester\n"
        . "FROM student_grades g\n"
        . "JOIN subjects s ON s.subject_id = g.subject_id\n"
        . "WHERE g.student_id = ? AND (g.status IN ('Incomplete', 'Dropped') OR (g.final_computed_grade IS NOT NULL AND g.final_computed_grade >= 3.0) OR (g.final_grade IS NOT NULL AND g.final_grade < 75))\n"
        . "ORDER BY g.academic_year DESC, s.subject_code ASC"
    );
    if ($stmt_sg) {
        $stmt_sg->bind_param('i', $student_id);
        $stmt_sg->execute();
        $res_sg = $stmt_sg->get_result();
        while ($r = $res_sg->fetch_assoc()) {
            $sub_id = (int)$r['subject_id'];
            $retake_candidates[$sub_id] = [
                'id' => $sub_id,
                'code' => (string)$r['subject_code'],
                'name' => (string)$r['subject_name'],
                'units' => (float)$r['units'],
                'note' => 'Term ' . (string)$r['academic_year'] . ' ' . (string)$r['semester'] . ' (' . ($r['status'] !== 'Completed' ? $r['status'] : 'Grade: ' . ($r['final_computed_grade'] ?? $r['final_grade'])) . ')',
            ];
        }
    }
}

// Fetch Curriculum Subjects for BOTH 1st Sem and 2nd Sem (with prerequisite validation fields)
$curriculum_1st = [];
$curriculum_2nd = [];
$total_units_1st = 0;
$total_units_2nd = 0;

if ($student_id > 0 && $cv_id > 0 && $year_level !== '') {
    // 1st Semester Curriculum Items
    $stmt_1st = $conn->prepare(
        "SELECT cs.id as curriculum_subject_id, cs.subject_id, s.subject_code, s.subject_name, s.units,\n"
        . "  COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id) AS prerequisite_subject_id,\n"
        . "  p.subject_code AS prereq_subject_code,\n"
        . "  p.subject_name AS prereq_subject_name\n"
        . "FROM curriculum_subjects cs\n"
        . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
        . "LEFT JOIN subjects p ON p.subject_id = COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id)\n"
        . "WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = '1st' AND cs.is_active = 1 AND s.archived_at IS NULL\n"
        . "ORDER BY cs.sort_order ASC, s.subject_code ASC"
    );
    $stmt_1st->bind_param('is', $cv_id, $year_level);
    $stmt_1st->execute();
    $res_1st = $stmt_1st->get_result();
    while ($r = $res_1st->fetch_assoc()) {
        $curriculum_1st[] = $r;
        $total_units_1st += (float)($r['units'] ?? 0);
    }

    // 2nd Semester Curriculum Items
    $stmt_2nd = $conn->prepare(
        "SELECT cs.id as curriculum_subject_id, cs.subject_id, s.subject_code, s.subject_name, s.units,\n"
        . "  COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id) AS prerequisite_subject_id,\n"
        . "  p.subject_code AS prereq_subject_code,\n"
        . "  p.subject_name AS prereq_subject_name\n"
        . "FROM curriculum_subjects cs\n"
        . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
        . "LEFT JOIN subjects p ON p.subject_id = COALESCE(cs.prerequisite_subject_id, s.prerequisite_subject_id)\n"
        . "WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = '2nd' AND cs.is_active = 1 AND s.archived_at IS NULL\n"
        . "ORDER BY cs.sort_order ASC, s.subject_code ASC"
    );
    $stmt_2nd->bind_param('is', $cv_id, $year_level);
    $stmt_2nd->execute();
    $res_2nd = $stmt_2nd->get_result();
    while ($r = $res_2nd->fetch_assoc()) {
        $curriculum_2nd[] = $r;
        $total_units_2nd += (float)($r['units'] ?? 0);
    }
}

// Fetch Existing Enrollments for BOTH Semesters
$existing_1st = [];
$existing_2nd = [];
$enrolled_ids_1st = [];
$enrolled_ids_2nd = [];
$enrolled_units_1st = 0;
$enrolled_units_2nd = 0;

if ($student_id > 0) {
    $stmt_ex = $conn->prepare(
        "SELECT es.*, s.subject_code, s.subject_name, s.units\n"
        . "FROM student_enrolled_subjects es\n"
        . "JOIN subjects s ON s.subject_id = es.subject_id\n"
        . "WHERE es.student_id = ? AND es.academic_year = ?\n"
        . "ORDER BY es.semester ASC, s.subject_code ASC"
    );
    $stmt_ex->bind_param('is', $student_id, $academic_year);
    $stmt_ex->execute();
    $res_ex = $stmt_ex->get_result();
    while ($r = $res_ex->fetch_assoc()) {
        $sem = (string)($r['semester'] ?? '');
        $u = (float)($r['units'] ?? 0);
        $subId = (int)($r['subject_id'] ?? 0);
        if ($sem === '1st') {
            $existing_1st[] = $r;
            $enrolled_ids_1st[] = $subId;
            $enrolled_units_1st += $u;
        } elseif ($sem === '2nd') {
            $existing_2nd[] = $r;
            $enrolled_ids_2nd[] = $subId;
            $enrolled_units_2nd += $u;
        }
    }
}

// Compute Enrollment Status per Semester
$curr_ids_1st = array_map(static fn ($it) => (int)$it['subject_id'], $curriculum_1st);
$curr_ids_2nd = array_map(static fn ($it) => (int)$it['subject_id'], $curriculum_2nd);

$is_fully_enrolled_1st = count($curr_ids_1st) > 0 && count(array_intersect($curr_ids_1st, $enrolled_ids_1st)) === count($curr_ids_1st);
$is_partially_enrolled_1st = count(array_intersect($curr_ids_1st, $enrolled_ids_1st)) > 0 && !$is_fully_enrolled_1st;

$is_fully_enrolled_2nd = count($curr_ids_2nd) > 0 && count(array_intersect($curr_ids_2nd, $enrolled_ids_2nd)) === count($curr_ids_2nd);
$is_partially_enrolled_2nd = count(array_intersect($curr_ids_2nd, $enrolled_ids_2nd)) > 0 && !$is_fully_enrolled_2nd;

$has_pending_any = (!$is_fully_enrolled_1st && count($curriculum_1st) > 0) || (!$is_fully_enrolled_2nd && count($curriculum_2nd) > 0);

$pageTitle = 'Subject Enrollment';
$activeNav = 'subject_enrollment';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
<style>
/* Modern Subject Enrollment Design System */
.enrollment-hero {
    background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
    border-radius: 14px;
    padding: 1.5rem 1.75rem;
    color: #ffffff;
    box-shadow: 0 10px 25px -5px rgba(15, 118, 110, 0.2), 0 8px 10px -6px rgba(15, 118, 110, 0.1);
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.enrollment-hero::after {
    content: '';
    position: absolute;
    right: -20px;
    bottom: -30px;
    width: 180px;
    height: 180px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
}
.seminarian-badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 0.3rem 0.65rem;
    border-radius: 9999px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #ffffff;
}

/* Tab Navigation Container */
.enroll-tabs-nav {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #f1f5f9;
    padding: 0.35rem;
    border-radius: 12px;
    margin-bottom: 1.25rem;
    border: 1px solid #e2e8f0;
    flex-wrap: wrap;
}
.enroll-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.65rem 1.15rem;
    font-size: 0.85rem;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}
.enroll-tab-btn:hover {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.6);
}
.enroll-tab-btn.active {
    color: #0f766e;
    background: #ffffff;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
}
.tab-counter-pill {
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.72rem;
    font-weight: 800;
}
.tab-counter-active {
    background: #ccfbf1;
    color: #0f766e;
}
.tab-counter-muted {
    background: #e2e8f0;
    color: #64748b;
}

/* Semester Grid Cards */
.term-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
}
.term-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.term-card-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Summary stats tiles */
.stats-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.stat-box {
    background: #ffffff;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.stat-box-left h4 {
    margin: 0;
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 800;
    color: #64748b;
    letter-spacing: 0.04em;
}
.stat-box-left .stat-val {
    font-size: 1.35rem;
    font-weight: 900;
    color: #0f172a;
    margin-top: 0.25rem;
}
.stat-box-right {
    text-align: right;
}

/* Custom Table Styles */
.enrollment-table {
    width: 100%;
    border-collapse: collapse;
}
.enrollment-table th {
    background: #f8fafc;
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
}
.enrollment-table td {
    padding: 0.85rem 1rem;
    font-size: 0.875rem;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.enrollment-table tbody tr:hover {
    background: #f8fafc;
}
.enrollment-table tbody tr:last-child td {
    border-bottom: none;
}
.subject-row-added {
    background: #eff6ff !important;
}

/* Modal Styling */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}
.modal-box {
    background: #ffffff;
    width: 100%;
    max-width: 720px;
    max-height: 88vh;
    border-radius: 14px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalScaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalScaleIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.15rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
    padding: 0.25rem;
    border-radius: 6px;
}
.modal-close-btn:hover {
    color: #0f172a;
    background: #e2e8f0;
}
.catalog-item-row:hover {
    background: #f0fdf4 !important;
}

/* Selection summary banner */
.selection-summary-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    margin: 0.75rem 1.25rem;
    font-size: 0.825rem;
    color: #065f46;
    font-weight: 700;
    flex-wrap: wrap;
    gap: 0.5rem;
}
</style>

<div class="content-body">
    <!-- Breadcrumbs Navigation -->
    <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
        <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
        <span style="margin: 0 0.5rem;">/</span>
        <a href="applicants.php" style="color: var(--primary); text-decoration: none;">Manage Seminarians</a>
        <span style="margin: 0 0.5rem;">/</span>
        <span>Subject Enrollment</span>
    </nav>

    <!-- Success & Error Alert Messages -->
    <?php if ($success) { ?>
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center justify-between gap-2">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-circle-check text-emerald-600"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; color:#065f46; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-semibold flex items-center justify-between gap-2">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-circle-exclamation text-red-600"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <button type="button" onclick="this.parentElement.style.display='none'" style="background:none; border:none; color:#991b1b; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
    <?php } ?>

    <?php if ($student_id <= 0 || !$student) { ?>
        <!-- ZERO-STATE: Seminarian Selection Card (Displayed only when no seminarian context is passed) -->
        <div class="minimal-card" style="margin-bottom: 1.5rem;">
            <div class="minimal-toolbar">
                <strong style="color: #0f172a; font-size: 0.95rem;">
                    <i class="fas fa-user-graduate" style="color: #0f766e; margin-right: 0.35rem;"></i> Select Seminarian for Subject Enrollment
                </strong>
                <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Fast direct enrollment context</span>
            </div>
            <div style="padding: 1.5rem;">
                <div style="max-width: 550px; margin: 0 auto; text-align: center; padding: 1rem 0;">
                    <div style="width: 56px; height: 56px; border-radius: 50%; background: #ccfbf1; color: #0f766e; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-bottom: 0.5rem;">Choose a Seminarian to Begin Enrollment</h2>
                    <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem;">
                        Select a seminarian from the list below, or return to <strong><a href="applicants.php" style="color: #0f766e; text-decoration: underline;">Manage Seminarians</a></strong> and click the <strong>Enroll Subjects</strong> button on any row for instant zero-click loading.
                    </p>

                    <form method="get" action="subject_enrollment.php" style="display: flex; gap: 0.5rem; flex-direction: column;">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <select name="seminarian_id" class="minimal-select" style="width: 100%; font-size: 0.95rem; padding: 0.65rem 0.85rem;" onchange="if(this.value) this.form.submit();" required>
                            <option value="">-- Choose Seminarian --</option>
                            <?php foreach ($students as $s) { 
                                $sid = (int)($s['seminarian_id'] ?? 0);
                                $nm = (string)($s['full_name'] ?? $s['username'] ?? '');
                                $meta = trim((string)($s['program'] ?? '')) . ' • ' . trim((string)($s['year_level'] ?? ''));
                            ?>
                                <option value="<?php echo $sid; ?>">
                                    <?php echo htmlspecialchars($nm . ' (' . $meta . ')'); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 0.75rem;">
                            <button type="submit" class="action-btn-primary" style="padding: 0.55rem 1.25rem;">
                                <i class="fas fa-arrow-right"></i> Open Enrollment
                            </button>
                            <a href="applicants.php" class="action-btn-secondary" style="padding: 0.55rem 1.25rem; text-decoration: none;">
                                <i class="fas fa-users"></i> Back to Seminarians
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php } else { ?>
        <!-- DIRECT CONTEXT: Sleek Seminarian Context Hero (Zero-Click Loaded) -->
        <div class="enrollment-hero">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem; position: relative; z-index: 2;">
                <!-- Student Identity & Meta -->
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 56px; height: 56px; border-radius: 12px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 900; color: #fff;">
                        <?php 
                            $name_parts = explode(' ', trim((string)($student['full_name'] ?? 'S')));
                            $initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
                            echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <h1 style="margin: 0; font-size: 1.45rem; font-weight: 900; letter-spacing: -0.01em; color: #ffffff;">
                                <?php echo htmlspecialchars((string)($student['full_name'] ?? '')); ?>
                            </h1>
                            <span class="seminarian-badge-chip">
                                <i class="fas fa-id-badge"></i> ID: #<?php echo (int)$student_id; ?>
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.4rem;">
                            <span class="seminarian-badge-chip">
                                <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($program ?: 'No Program'); ?>
                            </span>
                            <span class="seminarian-badge-chip">
                                <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($year_level ?: 'No Year Level'); ?>
                            </span>
                            <span class="seminarian-badge-chip" style="<?php echo $academic_status === 'Irregular' ? 'background: rgba(245, 158, 11, 0.35); border-color: rgba(245, 158, 11, 0.5);' : ''; ?>">
                                <i class="fas <?php echo $academic_status === 'Irregular' ? 'fa-triangle-exclamation' : 'fa-check'; ?>"></i> <?php echo htmlspecialchars($academic_status); ?>
                            </span>
                            <?php if ($formation_stage) { ?>
                                <span class="seminarian-badge-chip">
                                    <i class="fas fa-cross"></i> <?php echo htmlspecialchars($formation_stage); ?>
                                </span>
                            <?php } ?>
                            <?php if (count($retake_candidates) > 0) { ?>
                                <span class="seminarian-badge-chip" style="background: rgba(239, 68, 68, 0.3); border-color: rgba(239, 68, 68, 0.5);">
                                    <i class="fas fa-rotate"></i> <?php echo count($retake_candidates); ?> Backlog/Retake Found
                                </span>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Academic Year Switcher & Quick Navigation -->
                <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.25);">
                        <label style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #ccfbf1; display: block; margin-bottom: 2px;">
                            Academic Year
                        </label>
                        <select onchange="changeAcademicYear(this.value)" style="background: transparent; color: #ffffff; border: none; font-weight: 800; font-size: 0.95rem; cursor: pointer; outline: none;">
                            <?php foreach ($active_ays as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $academic_year ? 'selected' : ''; ?> style="color: #0f172a; background: #fff;">
                                    AY <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <a href="enrollment_records.php?student_id=<?php echo (int)$student_id; ?>" class="pill-btn" style="background: rgba(255, 255, 255, 0.2); color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,0.3); font-weight: 700;">
                        <i class="fas fa-layer-group"></i> Term History
                    </a>

                    <a href="seminarian_profile.php?seminarian_id=<?php echo (int)$student_id; ?>" class="pill-btn" style="background: rgba(255, 255, 255, 0.2); color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,0.3); font-weight: 700;">
                        <i class="fas fa-id-card"></i> Profile
                    </a>

                    <a href="applicants.php" class="pill-btn" style="background: #ffffff; color: #0f766e; text-decoration: none; font-weight: 800;">
                        <i class="fas fa-arrow-left"></i> Manage Seminarians
                    </a>
                </div>
            </div>
        </div>

        <?php if ($program === '' || $year_level === '') { ?>
            <div class="mb-4 p-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm font-semibold flex items-center justify-between gap-3">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fa-solid fa-circle-exclamation text-amber-600 text-lg"></i>
                    <div>
                        <strong>Incomplete Seminarian Profile:</strong>
                        This seminarian has no Program or Year Level assigned yet. Please set these in their profile first before enrolling curriculum subjects.
                    </div>
                </div>
                <a href="seminarian_profile.php?seminarian_id=<?php echo (int)$student_id; ?>" class="action-btn-primary" style="padding: 0.4rem 0.85rem; font-size: 0.8rem; text-decoration: none;">
                    Edit Profile
                </a>
            </div>
        <?php } elseif ($cv_id <= 0) { ?>
            <div class="mb-4 p-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm font-semibold flex items-center justify-between gap-3">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 text-lg"></i>
                    <div>
                        <strong>No Active Curriculum Version:</strong>
                        No active curriculum version was found for the program "<?php echo htmlspecialchars($program); ?>". You can still add subjects manually using the <strong>Add Subject</strong> button below.
                    </div>
                </div>
                <a href="curriculum_master.php" class="action-btn-primary" style="padding: 0.4rem 0.85rem; font-size: 0.8rem; text-decoration: none;">
                    Curriculum Master
                </a>
            </div>
        <?php } ?>

        <!-- Quick Term Status & Batch Action Card -->
        <div class="stats-summary-grid">
            <!-- 1st Semester Status Tile -->
            <div class="stat-box" style="border-left: 4px solid #0f766e;">
                <div class="stat-box-left">
                    <h4>First Semester</h4>
                    <div class="stat-val"><?php echo count($curriculum_1st); ?> Subjects</div>
                    <small style="color: #64748b; font-weight: 700;"><?php echo $total_units_1st; ?> Curriculum Units</small>
                </div>
                <div class="stat-box-right">
                    <?php if ($is_fully_enrolled_1st) { ?>
                        <span class="status-badge status-cleared" style="font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-circle-check"></i> Enrolled
                        </span>
                        <div style="font-size: 0.75rem; color: #065f46; font-weight: 700; margin-top: 4px;">
                            <?php echo $enrolled_units_1st; ?> units active
                        </div>
                    <?php } elseif ($is_partially_enrolled_1st) { ?>
                        <span class="status-badge status-warning" style="font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-clock"></i> Partial
                        </span>
                        <div style="font-size: 0.75rem; color: #b45309; font-weight: 700; margin-top: 4px;">
                            <?php echo count($existing_1st); ?> of <?php echo count($curriculum_1st); ?> enrolled
                        </div>
                    <?php } else { ?>
                        <span class="status-badge" style="background:#f1f5f9; color:#475569; font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-circle-notch"></i> Not Enrolled
                        </span>
                    <?php } ?>
                </div>
            </div>

            <!-- 2nd Semester Status Tile -->
            <div class="stat-box" style="border-left: 4px solid #0284c7;">
                <div class="stat-box-left">
                    <h4>Second Semester</h4>
                    <div class="stat-val"><?php echo count($curriculum_2nd); ?> Subjects</div>
                    <small style="color: #64748b; font-weight: 700;"><?php echo $total_units_2nd; ?> Curriculum Units</small>
                </div>
                <div class="stat-box-right">
                    <?php if ($is_fully_enrolled_2nd) { ?>
                        <span class="status-badge status-cleared" style="font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-circle-check"></i> Enrolled
                        </span>
                        <div style="font-size: 0.75rem; color: #065f46; font-weight: 700; margin-top: 4px;">
                            <?php echo $enrolled_units_2nd; ?> units active
                        </div>
                    <?php } elseif ($is_partially_enrolled_2nd) { ?>
                        <span class="status-badge status-warning" style="font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-clock"></i> Partial
                        </span>
                        <div style="font-size: 0.75rem; color: #b45309; font-weight: 700; margin-top: 4px;">
                            <?php echo count($existing_2nd); ?> of <?php echo count($curriculum_2nd); ?> enrolled
                        </div>
                    <?php } else { ?>
                        <span class="status-badge" style="background:#f1f5f9; color:#475569; font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-circle-notch"></i> Not Enrolled
                        </span>
                    <?php } ?>
                </div>
            </div>

            <!-- Full Year Batch Action Tile -->
            <div class="stat-box" style="border-left: 4px solid #6366f1; background: #faf5ff;">
                <div class="stat-box-left">
                    <h4 style="color: #6b21a8;">Full Academic Year</h4>
                    <div class="stat-val" style="color: #581c87;"><?php echo count($curriculum_1st) + count($curriculum_2nd); ?> Subjects</div>
                    <small style="color: #7e22ce; font-weight: 700;"><?php echo $total_units_1st + $total_units_2nd; ?> Total Units</small>
                </div>
                <div class="stat-box-right">
                    <?php if ($has_pending_any && $academic_status === 'Regular') { ?>
                        <form method="post" style="margin: 0; padding: 0; background: none; border: none; box-shadow: none;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                            <button type="submit" name="enroll_both" class="action-btn-primary" style="background: #7e22ce; padding: 0.45rem 0.85rem; font-size: 0.8rem; white-space: nowrap;" data-confirm="Enroll ALL subjects for both 1st and 2nd semesters for AY <?php echo htmlspecialchars($academic_year); ?>?" data-confirm-title="Enroll Both Semesters" data-confirm-text="Enroll All" data-confirm-variant="primary" data-confirm-loading="Enrolling...">
                                <i class="fas fa-bolt"></i> Enroll Full Year
                            </button>
                        </form>
                    <?php } elseif (!$has_pending_any && (count($curriculum_1st) + count($curriculum_2nd)) > 0) { ?>
                        <span class="status-badge status-cleared" style="font-size: 0.8rem; padding: 0.35rem 0.65rem;">
                            <i class="fas fa-check-double"></i> All Enrolled
                        </span>
                    <?php } else { ?>
                        <span style="font-size: 0.8rem; color: #6b7280; font-weight: 700;">Customized load below</span>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- NEW SEPARATED UI: Tabbed Switcher (1st Sem, 2nd Sem, Split/Stacked View) -->
        <div class="enroll-tabs-nav">
            <button type="button" class="enroll-tab-btn <?php echo $active_tab === '1st' ? 'active' : ''; ?>" data-tab="1st" onclick="switchEnrollTab('1st')">
                <i class="fas fa-calendar-day" style="color: #0f766e;"></i>
                <span>First Semester</span>
                <span id="tab_pill_1st" class="tab-counter-pill <?php echo $active_tab === '1st' ? 'tab-counter-active' : 'tab-counter-muted'; ?>">
                    <?php echo count($curriculum_1st); ?> subs • <?php echo $total_units_1st; ?> units
                </span>
                <?php if ($is_fully_enrolled_1st) { ?>
                    <i class="fas fa-check-circle" style="color: #059669; font-size: 0.85rem;" title="1st Sem Fully Enrolled"></i>
                <?php } ?>
            </button>

            <button type="button" class="enroll-tab-btn <?php echo $active_tab === '2nd' ? 'active' : ''; ?>" data-tab="2nd" onclick="switchEnrollTab('2nd')">
                <i class="fas fa-calendar-check" style="color: #0284c7;"></i>
                <span>Second Semester</span>
                <span id="tab_pill_2nd" class="tab-counter-pill <?php echo $active_tab === '2nd' ? 'tab-counter-active' : 'tab-counter-muted'; ?>">
                    <?php echo count($curriculum_2nd); ?> subs • <?php echo $total_units_2nd; ?> units
                </span>
                <?php if ($is_fully_enrolled_2nd) { ?>
                    <i class="fas fa-check-circle" style="color: #059669; font-size: 0.85rem;" title="2nd Sem Fully Enrolled"></i>
                <?php } ?>
            </button>

            <button type="button" class="enroll-tab-btn <?php echo $active_tab === 'split' ? 'active' : ''; ?>" data-tab="split" onclick="switchEnrollTab('split')">
                <i class="fas fa-columns" style="color: #6366f1;"></i>
                <span>Both Semesters (Split View)</span>
                <span class="tab-counter-pill <?php echo $active_tab === 'split' ? 'tab-counter-active' : 'tab-counter-muted'; ?>">
                    Side-by-Side
                </span>
            </button>
        </div>

        <!-- ========================================== -->
        <!-- PANE 1: FIRST SEMESTER ONLY                -->
        <!-- ========================================== -->
        <div id="pane_1st" class="enroll-tab-pane" data-pane="1st" style="display: <?php echo $active_tab === '1st' ? 'block' : 'none'; ?>;">
            <?php render_semester_enrollment_ui('1st', 'First Semester', $curriculum_1st, $total_units_1st, $existing_1st, $enrolled_ids_1st, $enrolled_units_1st, $student_id, $academic_year, $academic_status, $is_fully_enrolled_1st, 'main', $student_passed_subjects); ?>
        </div>

        <!-- ========================================== -->
        <!-- PANE 2: SECOND SEMESTER ONLY               -->
        <!-- ========================================== -->
        <div id="pane_2nd" class="enroll-tab-pane" data-pane="2nd" style="display: <?php echo $active_tab === '2nd' ? 'block' : 'none'; ?>;">
            <?php render_semester_enrollment_ui('2nd', 'Second Semester', $curriculum_2nd, $total_units_2nd, $existing_2nd, $enrolled_ids_2nd, $enrolled_units_2nd, $student_id, $academic_year, $academic_status, $is_fully_enrolled_2nd, 'main', $student_passed_subjects); ?>
        </div>

        <!-- ========================================== -->
        <!-- PANE 3: SPLIT / STACKED VIEW (BOTH SEMS)   -->
        <!-- ========================================== -->
        <div id="pane_split" class="enroll-tab-pane" data-pane="split" style="display: <?php echo $active_tab === 'split' ? 'block' : 'none'; ?>;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 1.5rem;">
                <div>
                    <?php render_semester_enrollment_ui('1st', 'First Semester', $curriculum_1st, $total_units_1st, $existing_1st, $enrolled_ids_1st, $enrolled_units_1st, $student_id, $academic_year, $academic_status, $is_fully_enrolled_1st, 'split', $student_passed_subjects); ?>
                </div>
                <div>
                    <?php render_semester_enrollment_ui('2nd', 'Second Semester', $curriculum_2nd, $total_units_2nd, $existing_2nd, $enrolled_ids_2nd, $enrolled_units_2nd, $student_id, $academic_year, $academic_status, $is_fully_enrolled_2nd, 'split', $student_passed_subjects); ?>
                </div>
            </div>
        </div>

    <?php } ?>
</div>

<!-- ======================================================= -->
<!-- ADD EXTRA / RETAKE SUBJECT MODAL (Search & Dynamic Append) -->
<!-- ======================================================= -->
<div id="addSubjectModal" class="modal-overlay" onclick="handleModalOverlayClick(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3>
                    <i class="fas fa-book-medical" style="color: #0f766e;"></i>
                    <span id="modalTitleSemester">Add Subject</span>
                </h3>
                <small style="color: #64748b; font-weight: 600;">Search active subjects to retake a backlog or add an elective/irregular subject load.</small>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAddSubjectModal()">&times;</button>
        </div>

        <!-- Live Search Input -->
        <div style="padding: 1rem 1.25rem 0.5rem 1.25rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
            <div style="position: relative;">
                <i class="fas fa-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                <input type="text" id="subjectCatalogSearch" placeholder="Search by subject code or title (e.g., Philo 1, Theo, Logic)..." autocomplete="off" oninput="filterSubjectCatalog(this.value)" style="width: 100%; padding: 0.65rem 1rem 0.65rem 2.5rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.9rem; outline: none; background: #fff;">
            </div>
        </div>

        <!-- Modal Body Content -->
        <div style="flex: 1; overflow-y: auto; padding: 1rem 1.25rem;">
            <!-- Recommended Retake Section (if student had previous failed/incomplete grades) -->
            <?php if (count($retake_candidates) > 0) { ?>
                <div style="margin-bottom: 1.25rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 0.75rem 1rem;">
                    <div style="font-weight: 800; font-size: 0.8rem; color: #991b1b; text-transform: uppercase; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fas fa-triangle-exclamation"></i> Previous Incomplete / Backlog Subjects for this Seminarian:
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                        <?php foreach ($retake_candidates as $rc) { ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; background: #fff; padding: 0.45rem 0.75rem; border-radius: 6px; border: 1px solid #fee2e2;">
                                <div>
                                    <strong style="color: #991b1b; font-family: monospace;"><?php echo htmlspecialchars($rc['code']); ?></strong>
                                    <span style="color: #334155; font-size: 0.85rem; margin-left: 0.35rem;"><?php echo htmlspecialchars($rc['name']); ?></span>
                                    <small style="color: #dc2626; display: block; font-weight: 700; font-size: 0.72rem;"><?php echo htmlspecialchars($rc['note']); ?></small>
                                </div>
                                <button type="button" onclick="appendSubjectFromCatalog(<?php echo (int)$rc['id']; ?>, '<?php echo htmlspecialchars(addslashes($rc['code'])); ?>', '<?php echo htmlspecialchars(addslashes($rc['name'])); ?>', <?php echo (int)$rc['units']; ?>)" class="action-btn-primary" style="padding: 0.3rem 0.65rem; font-size: 0.75rem; background: #dc2626; border: none; white-space: nowrap;">
                                    <i class="fas fa-plus"></i> Retake This
                                </button>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>

            <!-- All Catalog Subjects Table -->
            <div style="font-weight: 800; font-size: 0.75rem; color: #64748b; text-transform: uppercase; margin-bottom: 0.5rem;">
                All Subjects in Course Catalog:
            </div>
            <table class="enrollment-table" id="catalogTable">
                <thead>
                    <tr>
                        <th style="width: 130px;">Code</th>
                        <th>Subject Name</th>
                        <th style="width: 70px; text-align: center;">Units</th>
                        <th style="width: 100px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="catalogTbody">
                    <?php foreach ($all_catalog_subjects as $sub) { 
                        $cat_sub_id = (int)$sub['id'];
                        $cat_prereq_id = (int)($sub['prerequisite_subject_id'] ?? 0);
                        $cat_prereq_code = (string)($sub['prereq_subject_code'] ?? '');
                        $cat_prereq_name = (string)($sub['prereq_subject_name'] ?? '');
                        $cat_has_prereq = ($cat_prereq_id > 0);
                        $cat_prereq_met = (!$cat_has_prereq || isset($student_passed_subjects[$cat_prereq_id]));
                        $cat_is_locked = ($cat_has_prereq && !$cat_prereq_met);
                    ?>
                        <tr class="catalog-item-row <?php echo $cat_is_locked ? 'catalog-item-locked' : ''; ?>" data-code="<?php echo htmlspecialchars(strtolower($sub['code'])); ?>" data-name="<?php echo htmlspecialchars(strtolower($sub['name'])); ?>" style="<?php echo $cat_is_locked ? 'background: #f8fafc; opacity: 0.78;' : ''; ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                    <strong style="color: <?php echo $cat_is_locked ? '#64748b' : '#0f172a'; ?>; font-family: monospace; font-size: 0.875rem;">
                                        <?php echo htmlspecialchars($sub['code']); ?>
                                    </strong>
                                    <?php if ($cat_is_locked) { ?>
                                        <span class="badge" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.45rem; border-radius: 9999px;">
                                            <i class="fas fa-lock text-xs"></i> Locked: Requires <?php echo htmlspecialchars($cat_prereq_code); ?> grade
                                        </span>
                                    <?php } ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: <?php echo $cat_is_locked ? '#64748b' : '#334155'; ?>; font-size: 0.85rem;"><?php echo htmlspecialchars($sub['name']); ?></div>
                                <?php if ($cat_is_locked) { ?>
                                    <div style="color: #dc2626; font-size: 0.72rem; font-weight: 700; margin-top: 0.2rem;">
                                        <i class="fas fa-circle-exclamation"></i> Prerequisite required: <?php echo htmlspecialchars($cat_prereq_code . ($cat_prereq_name ? ' - ' . $cat_prereq_name : '')); ?>
                                    </div>
                                <?php } ?>
                            </td>
                            <td style="text-align: center; font-weight: 700; color: <?php echo $cat_is_locked ? '#94a3b8' : '#0f172a'; ?>;">
                                <?php echo htmlspecialchars(capstone_format_units($sub['units'])); ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if ($cat_is_locked) { ?>
                                    <button type="button" disabled class="action-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; white-space: nowrap; opacity: 0.55; cursor: not-allowed; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;" title="Cannot add: Prerequisite <?php echo htmlspecialchars($cat_prereq_code); ?> has not been passed yet.">
                                        <i class="fas fa-lock"></i> Locked
                                    </button>
                                <?php } else { ?>
                                    <button type="button" class="action-btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; white-space: nowrap;" onclick="appendSubjectFromCatalog(<?php echo (int)$sub['id']; ?>, '<?php echo htmlspecialchars(addslashes($sub['code'])); ?>', '<?php echo htmlspecialchars(addslashes($sub['name'])); ?>', <?php echo (float)$sub['units']; ?>)">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <div id="catalogEmptySearch" style="display: none; padding: 2rem; text-align: center; color: #64748b; font-weight: 600;">
                <i class="fas fa-magnifying-glass" style="font-size: 1.5rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                No matching subjects found in catalog.
            </div>
        </div>

        <div style="padding: 0.85rem 1.25rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
            <button type="button" class="action-btn-secondary" onclick="closeAddSubjectModal()" style="padding: 0.45rem 1.15rem; font-size: 0.85rem;">
                Done / Close
            </button>
        </div>
    </div>
</div>

<?php
/**
 * Helper function to render a clean, modular semester enrollment section
 */
function render_semester_enrollment_ui(
    string $sem_code,
    string $sem_title,
    array $curriculum_items,
    float $curriculum_units,
    array $existing_items,
    array $enrolled_ids,
    float $enrolled_units,
    int $student_id,
    string $academic_year,
    string $academic_status,
    bool $is_fully_enrolled,
    string $suffix = 'main',
    array $student_passed_subjects = []
): void {
    $table_id = "table_{$sem_code}_{$suffix}";
    $tbody_id = "tbody_{$sem_code}_{$suffix}";
    $summary_id = "summary_{$sem_code}_{$suffix}";
    $units_id = "units_{$sem_code}_{$suffix}";
?>
    <!-- Curriculum & Custom Load Section -->
    <div class="term-card">
        <div class="term-card-header">
            <h3>
                <i class="fas fa-book" style="color: <?php echo $sem_code === '1st' ? '#0f766e' : '#0284c7'; ?>;"></i>
                <?php echo htmlspecialchars($sem_title); ?> Subjects Load
            </h3>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <?php if ($is_fully_enrolled) { ?>
                    <span class="status-badge status-cleared" style="font-size: 0.75rem; padding: 0.25rem 0.6rem;">
                        <i class="fas fa-check-circle"></i> Fully Enrolled
                    </span>
                <?php } ?>
                <button type="button" class="action-btn-secondary" onclick="openAddSubjectModal('<?php echo $sem_code; ?>', '<?php echo $suffix; ?>')" style="padding: 0.35rem 0.75rem; font-size: 0.78rem; font-weight: 800; background: #0f766e; color: #fff; border: none;">
                    <i class="fas fa-plus-circle"></i> Add Subject (Retake)
                </button>
            </div>
        </div>

        <form method="post" id="form_<?php echo $sem_code; ?>_<?php echo $suffix; ?>" style="padding: 0; margin: 0; background: none; border: none; box-shadow: none;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
            <input type="hidden" name="enroll_semester" value="<?php echo htmlspecialchars($sem_code); ?>">

            <!-- Real-time Selection & Checkbox Summary Bar -->
            <div class="selection-summary-bar">
                <div>
                    <i class="fas fa-list-check"></i>
                    Selected to Enroll: <span id="<?php echo $summary_id; ?>">0</span> subjects (<span id="<?php echo $units_id; ?>">0</span> units)
                </div>
                <div style="display: flex; gap: 0.4rem; font-size: 0.75rem;">
                    <button type="button" onclick="setAllCheckboxes('<?php echo $sem_code; ?>', '<?php echo $suffix; ?>', true)" style="background:none; border:none; color:#0f766e; font-weight:800; cursor:pointer; text-decoration:underline;">
                        Select All
                    </button>
                    <span>•</span>
                    <button type="button" onclick="setAllCheckboxes('<?php echo $sem_code; ?>', '<?php echo $suffix; ?>', false)" style="background:none; border:none; color:#64748b; font-weight:800; cursor:pointer; text-decoration:underline;">
                        Deselect All
                    </button>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="enrollment-table" id="<?php echo $table_id; ?>">
                    <thead>
                        <tr>
                            <th style="width: 44px; text-align: center;">
                                <input type="checkbox" id="master_cb_<?php echo $sem_code; ?>_<?php echo $suffix; ?>" title="Toggle All" onchange="toggleMasterCheckbox(this, '<?php echo $sem_code; ?>', '<?php echo $suffix; ?>')" checked style="cursor: pointer;">
                            </th>
                            <th style="width: 140px;">Code</th>
                            <th>Subject Description</th>
                            <th style="width: 75px; text-align: center;">Units</th>
                            <th style="width: 130px; text-align: center;">Status</th>
                            <th style="width: 44px; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody id="<?php echo $tbody_id; ?>">
                        <?php if (count($curriculum_items) === 0) { ?>
                            <tr id="empty_row_<?php echo $sem_code; ?>_<?php echo $suffix; ?>">
                                <td colspan="6" style="padding: 2rem; text-align: center; color: #64748b; font-weight: 600;">
                                    <i class="fas fa-folder-open" style="font-size: 1.75rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block;"></i>
                                    No standard curriculum subjects loaded. Click <strong>Add Subject</strong> above to add retake/custom subjects.
                                </td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($curriculum_items as $it) { 
                                $sub_id = (int)($it['subject_id'] ?? 0);
                                $code = (string)($it['subject_code'] ?? '');
                                $name = (string)($it['subject_name'] ?? '');
                                $units = (float)($it['units'] ?? 0);
                                $is_enr = in_array($sub_id, $enrolled_ids, true);
                                $prereq_id = (int)($it['prerequisite_subject_id'] ?? 0);
                                $prereq_code = (string)($it['prereq_subject_code'] ?? '');
                                $prereq_name = (string)($it['prereq_subject_name'] ?? '');
                                $has_prereq = ($prereq_id > 0);
                                $prereq_met = (!$has_prereq || isset($student_passed_subjects[$prereq_id]));
                                $is_locked = ($has_prereq && !$prereq_met);

                                $row_style = '';
                                if ($is_enr) {
                                    $row_style = 'background: #f0fdf4;';
                                } elseif ($is_locked) {
                                    $row_style = 'background: #f8fafc; opacity: 0.78;';
                                }
                            ?>
                                <tr style="<?php echo $row_style; ?>" data-sub-id="<?php echo $sub_id; ?>" class="<?php echo $is_locked ? 'row-prereq-locked' : ''; ?>">
                                    <td style="text-align: center;">
                                        <?php if ($is_enr) { ?>
                                            <input type="checkbox" checked disabled title="Already Enrolled" style="cursor: not-allowed; opacity: 0.6;">
                                        <?php } elseif ($is_locked) { ?>
                                            <input type="checkbox" disabled title="Locked: Requires <?php echo htmlspecialchars($prereq_code); ?> grade" style="cursor: not-allowed; opacity: 0.35;">
                                        <?php } else { ?>
                                            <input type="checkbox" name="subject_ids_<?php echo $sem_code; ?>[]" value="<?php echo $sub_id; ?>" class="sub-cb-<?php echo $sem_code; ?>-<?php echo $suffix; ?>" data-units="<?php echo $units; ?>" checked onchange="updateSelectedSummary('<?php echo $sem_code; ?>', '<?php echo $suffix; ?>')" style="cursor: pointer;">
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                                            <strong style="color: <?php echo $is_locked ? '#64748b' : '#0f172a'; ?>; font-size: 0.9rem; font-family: monospace;">
                                                <?php echo htmlspecialchars($code); ?>
                                            </strong>
                                            <?php if ($is_locked) { ?>
                                                <span class="badge" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 9999px; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                    <i class="fas fa-lock text-xs"></i> Locked: Requires <?php echo htmlspecialchars($prereq_code); ?> grade
                                                </span>
                                            <?php } ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: <?php echo $is_locked ? '#64748b' : '#334155'; ?>;">
                                            <?php echo htmlspecialchars($name); ?>
                                        </div>
                                        <?php if ($is_locked) { ?>
                                            <div style="color: #dc2626; font-size: 0.75rem; font-weight: 700; margin-top: 0.2rem; display: flex; align-items: center; gap: 0.3rem;">
                                                <i class="fas fa-circle-exclamation"></i> Prerequisite not met: <?php echo htmlspecialchars($prereq_code . ($prereq_name ? ' - ' . $prereq_name : '')); ?>
                                            </div>
                                        <?php } ?>
                                    </td>
                                    <td style="text-align: center; font-weight: 700; color: <?php echo $is_locked ? '#94a3b8' : '#0f172a'; ?>;">
                                        <?php echo htmlspecialchars(capstone_format_units($units)); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($is_enr) { ?>
                                            <span class="status-badge status-cleared" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                                <i class="fas fa-check"></i> Enrolled
                                            </span>
                                        <?php } elseif ($is_locked) { ?>
                                            <span class="status-badge" style="background: #fee2e2; color: #991b1b; font-size: 0.75rem; padding: 0.25rem 0.55rem; font-weight: 700; border: 1px solid #fecaca;">
                                                <i class="fas fa-lock"></i> Locked
                                            </span>
                                        <?php } else { ?>
                                            <span class="status-badge status-warning" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                                <i class="fas fa-hourglass-start"></i> Pending
                                            </span>
                                        <?php } ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <!-- Curriculum row cannot be deleted from catalog -->
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div style="padding: 1rem 1.25rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="action-btn-secondary" onclick="openAddSubjectModal('<?php echo $sem_code; ?>', '<?php echo $suffix; ?>')" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 700;">
                        <i class="fas fa-plus"></i> Add Extra / Failed Subject
                    </button>
                    <?php if ($is_fully_enrolled) { ?>
                        <span style="color: #059669; font-size: 0.85rem; font-weight: 700;">
                            <i class="fas fa-check-circle"></i> Curriculum load enrolled
                        </span>
                    <?php } ?>
                </div>

                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="submit" name="submit_enroll_<?php echo $sem_code; ?>" class="action-btn-primary" style="padding: 0.45rem 1.2rem; font-size: 0.825rem; font-weight: 800;">
                        <i class="fas fa-check-circle"></i>
                        Enroll Selected <?php echo htmlspecialchars($sem_title); ?> Load
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Active Registered / Enrolled Records Section with Drop/Unenroll Feature -->
    <div class="data-card" style="margin-bottom: 1.5rem;">
        <div class="data-card-header">
            <h3 style="font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 0.45rem;">
                <i class="fas fa-file-circle-check" style="color: #059669;"></i>
                Registered Subjects Record (<?php echo htmlspecialchars($academic_year . ' ' . $sem_title); ?>)
            </h3>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: #065f46;">
                    <?php echo count($existing_items); ?> Enrolled (<?php echo htmlspecialchars(capstone_format_units($enrolled_units)); ?> units)
                </span>
                <a href="enrolled_subjects_report.php?student_id=<?php echo (int)$student_id; ?>&academic_year=<?php echo urlencode($academic_year); ?>&semester=<?php echo urlencode($sem_code); ?>" class="back-link" style="margin: 0; white-space: nowrap; font-size: 0.8rem;">
                    <i class="fas fa-arrow-up-right-from-square"></i> Open Report
                </a>
            </div>
        </div>
        <div style="overflow-x: auto;">
            <table class="enrollment-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Code</th>
                        <th>Subject Name</th>
                        <th style="width: 75px; text-align: center;">Units</th>
                        <th style="width: 110px; text-align: center;">Status</th>
                        <th style="width: 60px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($existing_items) === 0) { ?>
                        <tr>
                            <td colspan="5" style="color: #94a3b8; text-align: center; padding: 1.5rem;">
                                No registered subjects for <?php echo htmlspecialchars($sem_title); ?> yet.
                            </td>
                        </tr>
                    <?php } else { foreach ($existing_items as $r) { ?>
                        <tr>
                            <td>
                                <strong style="color: #0f172a; font-family: monospace; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars((string)($r['subject_code'] ?? '')); ?>
                                </strong>
                            </td>
                            <td><?php echo htmlspecialchars((string)($r['subject_name'] ?? '')); ?></td>
                            <td style="text-align: center; font-weight: 700; color: #0f172a;">
                                <?php echo htmlspecialchars(capstone_format_units($r['units'] ?? 0)); ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="status-badge status-cleared" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                    <?php echo htmlspecialchars((string)($r['status'] ?? 'Enrolled')); ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <form method="post" style="display:inline; margin:0; padding:0; background:none; border:none; box-shadow:none;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($sem_code); ?>">
                                    <input type="hidden" name="drop_enrolled_subject" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                    <button type="submit" title="Unenroll / Drop this subject" data-confirm="Unenroll / drop this subject for <?php echo htmlspecialchars($sem_title); ?>?" data-confirm-title="Drop Subject" data-confirm-text="Drop Subject" data-confirm-variant="danger" data-confirm-loading="Dropping..." style="background:none; border:none; color:#ef4444; cursor:pointer; padding: 0.25rem 0.4rem; border-radius:4px;">
                                        <i class="fas fa-trash-can text-xs"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } } ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
}
?>

<script>
// State tracking for modal target
let currentModalSem = '1st';
let currentModalSuffix = 'main';

// Initialize summaries on load
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedSummary('1st', 'main');
    updateSelectedSummary('2nd', 'main');
    updateSelectedSummary('1st', 'split');
    updateSelectedSummary('2nd', 'split');
});

// Dynamic Tab Switching Logic
function switchEnrollTab(tabName) {
    document.querySelectorAll('.enroll-tab-btn').forEach(btn => {
        const isCurrent = btn.getAttribute('data-tab') === tabName;
        btn.classList.toggle('active', isCurrent);
        const counter = btn.querySelector('.tab-counter-pill');
        if (counter) {
            counter.classList.toggle('tab-counter-active', isCurrent);
            counter.classList.toggle('tab-counter-muted', !isCurrent);
        }
    });

    document.querySelectorAll('.enroll-tab-pane').forEach(pane => {
        pane.style.display = (pane.getAttribute('data-pane') === tabName) ? 'block' : 'none';
    });

    if (history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        history.replaceState(null, '', url.toString());
    }
}

// Academic Year Context Switcher
function changeAcademicYear(selectedAy) {
    const url = new URL(window.location.href);
    url.searchParams.set('academic_year', selectedAy);
    window.location.href = url.toString();
}

// Master Checkbox Toggle
function toggleMasterCheckbox(masterCb, semCode, suffix) {
    document.querySelectorAll('.sub-cb-' + semCode + '-' + suffix).forEach(cb => {
        if (!cb.disabled) {
            cb.checked = masterCb.checked;
        }
    });
    updateSelectedSummary(semCode, suffix);
}

// Quick Select / Deselect All
function setAllCheckboxes(semCode, suffix, state) {
    document.querySelectorAll('.sub-cb-' + semCode + '-' + suffix).forEach(cb => {
        if (!cb.disabled) {
            cb.checked = state;
        }
    });
    const master = document.getElementById('master_cb_' + semCode + '_' + suffix);
    if (master) master.checked = state;
    updateSelectedSummary(semCode, suffix);
}

// Recalculate and update selected subject count & units
function updateSelectedSummary(semCode, suffix) {
    const allCbs = document.querySelectorAll('.sub-cb-' + semCode + '-' + suffix);
    let enabledCount = 0;
    let checkedCount = 0;
    let totalUnits = 0;

    allCbs.forEach(cb => {
        if (!cb.disabled) {
            enabledCount++;
            if (cb.checked) {
                checkedCount++;
                const u = parseFloat(cb.getAttribute('data-units') || '0');
                totalUnits += isNaN(u) ? 0 : u;
            }
        }
    });

    const master = document.getElementById('master_cb_' + semCode + '_' + suffix);
    if (master) {
        master.checked = (enabledCount > 0 && checkedCount === enabledCount);
        master.disabled = (enabledCount === 0);
    }

    const summaryEl = document.getElementById('summary_' + semCode + '_' + suffix);
    const unitsEl = document.getElementById('units_' + semCode + '_' + suffix);
    if (summaryEl) summaryEl.textContent = String(checkedCount);
    if (unitsEl) {
        const roundedUnits = Math.round(totalUnits * 10) / 10;
        unitsEl.textContent = Number.isInteger(roundedUnits) ? String(roundedUnits) : roundedUnits.toFixed(1);
    }
}

// Modal open/close controls
function openAddSubjectModal(semCode, suffix = 'main') {
    currentModalSem = semCode;
    currentModalSuffix = suffix || 'main';

    const modal = document.getElementById('addSubjectModal');
    const titleEl = document.getElementById('modalTitleSemester');
    if (titleEl) {
        titleEl.textContent = 'Add Subject (' + (semCode === '1st' ? 'First Semester' : 'Second Semester') + ')';
    }

    // Reset search
    const searchInput = document.getElementById('subjectCatalogSearch');
    if (searchInput) {
        searchInput.value = '';
        filterSubjectCatalog('');
    }

    modal.style.display = 'flex';
    setTimeout(() => { if (searchInput) searchInput.focus(); }, 100);
}

function closeAddSubjectModal() {
    const modal = document.getElementById('addSubjectModal');
    if (modal) modal.style.display = 'none';
}

function handleModalOverlayClick(e) {
    if (e.target.id === 'addSubjectModal') {
        closeAddSubjectModal();
    }
}

// Live filter of catalog subjects inside modal
function filterSubjectCatalog(query) {
    const q = query.trim().toLowerCase();
    const rows = document.querySelectorAll('#catalogTbody tr.catalog-item-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const code = r.getAttribute('data-code') || '';
        const name = r.getAttribute('data-name') || '';
        if (q === '' || code.includes(q) || name.includes(q)) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    const emptyMsg = document.getElementById('catalogEmptySearch');
    if (emptyMsg) {
        emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

// Dynamically Append a Subject into the Table
function appendSubjectFromCatalog(subId, code, name, units) {
    const tbody = document.getElementById('tbody_' + currentModalSem + '_' + currentModalSuffix);
    if (!tbody) return;

    // Check if row already exists
    const existingInput = tbody.querySelector('input[value="' + subId + '"]');
    if (existingInput) {
        alert('Subject ' + code + ' is already present in this semester\'s list!');
        return;
    }

    // Remove empty notice if present
    const emptyRow = document.getElementById('empty_row_' + currentModalSem + '_' + currentModalSuffix);
    if (emptyRow) emptyRow.style.display = 'none';

    // Construct dynamically added row
    const tr = document.createElement('tr');
    tr.className = 'subject-row-added';
    tr.setAttribute('data-sub-id', String(subId));
    tr.id = 'added_row_' + currentModalSem + '_' + currentModalSuffix + '_' + subId;

    tr.innerHTML = 
        '<td style="text-align: center;">' +
            '<input type="checkbox" name="subject_ids_' + currentModalSem + '[]" value="' + subId + '" class="sub-cb-' + currentModalSem + '-' + currentModalSuffix + '" data-units="' + units + '" checked onchange="updateSelectedSummary(\'' + currentModalSem + '\', \'' + currentModalSuffix + '\')" style="cursor: pointer;">' +
        '</td>' +
        '<td><strong style="color: #0f172a; font-size: 0.9rem; font-family: monospace;">' + escapeHtml(code) + '</strong></td>' +
        '<td><div style="font-weight: 600; color: #334155;">' + escapeHtml(name) + '</div></td>' +
        '<td style="text-align: center; font-weight: 700; color: #0f172a;">' + units + '</td>' +
        '<td style="text-align: center;">' +
            '<span class="status-badge" style="background: #e0e7ff; color: #4338ca; font-size: 0.75rem; padding: 0.25rem 0.55rem;">' +
                '<i class="fas fa-plus"></i> Added (Retake)' +
            '</span>' +
        '</td>' +
        '<td style="text-align: center;">' +
            '<button type="button" onclick="removeAppendedRow(\'' + currentModalSem + '\', \'' + currentModalSuffix + '\', ' + subId + ')" title="Remove from load" style="background:none; border:none; color:#ef4444; cursor:pointer; padding: 0.25rem 0.4rem; border-radius:4px;">' +
                '<i class="fas fa-trash-can text-xs"></i>' +
            '</button>' +
        '</td>';

    tbody.appendChild(tr);
    updateSelectedSummary(currentModalSem, currentModalSuffix);

    // Provide quick visual feedback
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:#0f766e; color:#fff; padding:0.6rem 1.25rem; border-radius:8px; font-weight:700; font-size:0.85rem; box-shadow:0 10px 15px -3px rgba(0,0,0,0.2); z-index:99999; animation:modalScaleIn 0.2s;';
    toast.innerHTML = '<i class="fas fa-check-circle" style="margin-right:0.4rem;"></i> Added <strong>' + escapeHtml(code) + '</strong> to load!';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

// Remove dynamically added row
function removeAppendedRow(semCode, suffix, subId) {
    const row = document.getElementById('added_row_' + semCode + '_' + suffix + '_' + subId);
    if (row) {
        row.remove();
        updateSelectedSummary(semCode, suffix);
    }
}

// Helper to escape HTML safely
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
