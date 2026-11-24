<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';

$role = (string)($_SESSION['role'] ?? '');
$is_registrar = ($role === 'Registrar');
$can_edit = $is_registrar;
$actor_id = (int)($_SESSION['user'] ?? 0);

// Sync decoupled tables only in local/non-serverless mode
$__er_skip_sync = function_exists('enformasys_env')
    && (enformasys_is_production() || enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== '');
if (!$__er_skip_sync) {
    registrar_sync_and_migrate($conn, false);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && function_exists('enformasys_invalidate_dashboard_cache')) {
    enformasys_invalidate_dashboard_cache();
}

$current_ay = get_current_academic_year();
$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}
// Include next academic year option for forward enrollment
if (preg_match('/^(\d{4})-(\d{4})$/', $current_ay, $m)) {
    $next_ay = ((int)$m[1] + 1) . '-' . ((int)$m[2] + 1);
    if (!in_array($next_ay, $ay_options, true)) {
        array_unshift($ay_options, $next_ay);
    }
}

$success = $_SESSION['flash_enr_success'] ?? null;
$error = $_SESSION['flash_enr_error'] ?? null;
unset($_SESSION['flash_enr_success'], $_SESSION['flash_enr_error']);

// 1. Handle One-Click Backup & Sync Action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['trigger_backup_sync'])) {
    if (!$can_edit) {
        $error = 'Unauthorized action.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    } else {
        $sync_res = registrar_sync_and_migrate($conn, true);
        $snap = registrar_export_backup_snapshot($conn, 'manual');
        $success = "Backup & Sync complete! Saved snapshot '{$snap['file']}' ({$snap['students_count']} student profiles, {$snap['enrollments_count']} term enrollments).";
    }
}

// 1b. Handle Adding & Enrolling a New Seminarian (migrated from ManageSeminarian / applicants.php)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_applicant'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can add or enroll seminarians.";
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    } else {
        $student_id = trim((string)($_POST['student_id'] ?? ''));
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $middle_name = trim((string)($_POST['middle_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $suffix = trim((string)($_POST['suffix'] ?? ''));

        $dob = trim((string)($_POST['date_of_birth'] ?? ''));
        $place_of_birth = trim((string)($_POST['place_of_birth'] ?? ''));
        $age = (int)($_POST['age'] ?? 0);
        if ($age <= 0 && $dob !== '') {
            try {
                $dob_dt = new DateTimeImmutable($dob);
                $today_dt = new DateTimeImmutable('today');
                if ($dob_dt <= $today_dt) {
                    $age = (int)$dob_dt->diff($today_dt)->y;
                }
            } catch (Throwable $e) {
                $age = 0;
            }
        }
        $gender = trim((string)($_POST['gender'] ?? 'Male'));
        if ($gender === '') {
            $gender = 'Male';
        }
        $civil_status = trim((string)($_POST['civil_status'] ?? ''));
        $nationality = trim((string)($_POST['nationality'] ?? ''));
        $religion = trim((string)($_POST['religion'] ?? ''));
        $parish_priest = trim((string)($_POST['parish_priest'] ?? ''));
        $parish_chaplaincy = trim((string)($_POST['parish_chaplaincy'] ?? ''));

        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        $email_address = trim((string)($_POST['email_address'] ?? ''));

        $permanent_address = trim((string)($_POST['permanent_address'] ?? ''));
        $present_address = trim((string)($_POST['present_address'] ?? ''));
        $province = trim((string)($_POST['province'] ?? ''));
        $municipality = trim((string)($_POST['municipality'] ?? ''));
        $barangay = trim((string)($_POST['barangay'] ?? ''));
        $zip_code = trim((string)($_POST['zip_code'] ?? ''));

        $father_name = trim((string)($_POST['father_name'] ?? ''));
        $father_occupation = trim((string)($_POST['father_occupation'] ?? ''));
        $mother_name = trim((string)($_POST['mother_name'] ?? ''));
        $mother_occupation = trim((string)($_POST['mother_occupation'] ?? ''));
        $guardian_name = trim((string)($_POST['guardian_name'] ?? ''));
        $guardian_contact_number = trim((string)($_POST['guardian_contact_number'] ?? ''));

        $previous_school = trim((string)($_POST['previous_school'] ?? ''));
        $educational_attainment = trim((string)($_POST['educational_attainment'] ?? ''));
        $year_graduated = trim((string)($_POST['year_graduated'] ?? ''));
        $academic_records = trim((string)($_POST['academic_records'] ?? ''));

        $year_level = trim((string)($_POST['year_level'] ?? ''));
        $program = trim((string)($_POST['program'] ?? ''));
        $admission_date = (string)($_POST['admission_date'] ?? '');
        $enrollment_semester = trim((string)($_POST['enrollment_semester'] ?? ''));
        if (!in_array($enrollment_semester, ['1st', '2nd'], true)) {
            $enrollment_semester = '1st';
        }
        $formation_status = trim((string)($_POST['formation_status'] ?? ''));
        $is_grad_on_add = ($year_level === 'Completed') || (function_exists('registrar_is_graduated_status') && registrar_is_graduated_status($formation_status));
        $enrollment_status = $is_grad_on_add ? 'Graduated' : 'Pending';

        $name_parts = array_values(array_filter([$first_name, $middle_name, $last_name], static function ($v) {
            return trim((string)$v) !== '';
        }));
        $full_name = trim(implode(' ', $name_parts) . ($suffix !== '' ? (' ' . $suffix) : ''));
        if ($full_name === '') {
            $error = "Please provide the student's name.";
        } elseif ($dob === '') {
            $error = "Please provide Date of Birth.";
        } elseif ($student_id !== '' && is_student_id_taken($conn, $student_id)) {
            $error = "Student ID \"" . htmlspecialchars($student_id) . "\" is already assigned to another student.";
        } else {
            if ($student_id === '') {
                $student_id = generate_unique_student_id($conn);
            }
            $academic_year = trim((string)($_POST['academic_year'] ?? ''));
            if ($academic_year === '') {
                $academic_year = $current_ay;
            }
            if ($year_level === 'Completed' && $formation_status === '') {
                $formation_status = 'Completed';
            }  
            $username_in = trim((string)($_POST['username'] ?? ''));
            $password_in = (string)($_POST['password'] ?? '');

            $seed = $student_id !== '' ? $student_id : $full_name;
            $username = $username_in !== '' ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username_in)) : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$seed));
            if ($username === '') {
                $username = "user" . rand(1000, 9999);
            }

            $password_plain = $password_in !== '' ? $password_in : 'pass';
            $role_student = 'Student';

            $academic_history = trim(implode(" | ", array_values(array_filter([$previous_school, $educational_attainment, $year_graduated], static function ($v) {
                return trim((string)$v) !== '';
            }))));

            $parent_info_parts = [];
            if ($father_name !== '') $parent_info_parts[] = "Father: " . $father_name . ($father_occupation !== '' ? (" (" . $father_occupation . ")") : "");
            if ($mother_name !== '') $parent_info_parts[] = "Mother: " . $mother_name . ($mother_occupation !== '' ? (" (" . $mother_occupation . ")") : "");
            if ($guardian_name !== '') $parent_info_parts[] = "Guardian: " . $guardian_name . ($guardian_contact_number !== '' ? (" (" . $guardian_contact_number . ")") : "");
            $parent_info = trim(implode(" | ", $parent_info_parts));

            $conn->begin_transaction();
            try {
                $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $candidate = $username;
                for ($i = 0; $i < 6; $i++) {
                    $stmt_check->bind_param("s", $candidate);
                    $stmt_check->execute();
                    $res_check = $stmt_check->get_result();
                    if ($res_check && $res_check->num_rows === 0) {
                        break;
                    }
                    $candidate = $username . rand(10, 99);
                }
                $username = $candidate;

                $hashed_password = password_hash($password_plain, PASSWORD_DEFAULT);
                $stmt_user = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt_user->bind_param("sss", $username, $hashed_password, $role_student);
                if (!$stmt_user->execute()) {
                    throw new Exception("Failed to create user: " . $stmt_user->error);
                }
                $user_id = (int)$conn->insert_id;

                $stmt_sem = $conn->prepare("INSERT INTO seminarians (user_id, current_academic_year, year_level, program, admission_date, formation_stage, formation_status, enrollment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $admission_date_value = $admission_date !== '' ? $admission_date : null;
                $stmt_sem->bind_param("isssssss", $user_id, $academic_year, $year_level, $program, $admission_date_value, $formation_status, $formation_status, $enrollment_status);
                if (!$stmt_sem->execute()) {
                    throw new Exception("Failed to create seminarian record: " . $stmt_sem->error);
                }
                $seminarian_id = (int)$conn->insert_id;
                if ($seminarian_id > 0) {
                    create_default_seminarian_assignments($conn, $seminarian_id, (int)($_SESSION['user'] ?? 0));
                }
                if ($seminarian_id > 0 && $is_grad_on_add) {
                    $grad_status_val = $formation_status !== '' ? $formation_status : 'Graduated';
                    $grad_prog_val = $program !== '' ? $program : 'Bachelor of Arts in Philosophy';
                    $stmt_grad = $conn->prepare("UPDATE seminarians SET year_level = 'Completed', formation_stage = 'Completed', is_graduate = 1, completed_at = CURDATE(), academic_year_completed = ?, program_completed = ?, formation_status = ?, enrollment_status = 'Graduated' WHERE id = ?");
                    $stmt_grad->bind_param('sssi', $academic_year, $grad_prog_val, $grad_status_val, $seminarian_id);
                    $stmt_grad->execute();
                }

                if ($seminarian_id > 0 && $program !== '' && $year_level !== '' && $year_level !== 'Completed') {
                    $stmt_cv = $conn->prepare("SELECT id FROM curriculum_versions WHERE program = ? AND is_current = 1 AND is_active = 1 AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
                    $stmt_cv->bind_param('s', $program);
                    $stmt_cv->execute();
                    $cv_row = $stmt_cv->get_result()->fetch_assoc();
                    $curriculum_version_id = (int)($cv_row['id'] ?? 0);

                    if ($curriculum_version_id > 0) {
                        $subject_ids = [];
                        $stmt_items = $conn->prepare(
                            "SELECT cs.subject_id\n"
                            . "FROM curriculum_subjects cs\n"
                            . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
                            . "WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = ? AND cs.is_active = 1 AND s.archived_at IS NULL"
                        );
                        $stmt_items->bind_param('iss', $curriculum_version_id, $year_level, $enrollment_semester);
                        $stmt_items->execute();
                        $res_items = $stmt_items->get_result();
                        while ($r = $res_items->fetch_assoc()) {
                            $sid = (int)($r['subject_id'] ?? 0);
                            if ($sid > 0) $subject_ids[] = $sid;
                        }

                        if (count($subject_ids) > 0) {
                            $stmt_sem_sub = $conn->prepare("INSERT IGNORE INTO semester_subjects (subject_id, semester, academic_year, year_level) VALUES (?, ?, ?, ?)");
                            $stmt_enr = $conn->prepare("INSERT IGNORE INTO student_enrolled_subjects (student_id, subject_id, curriculum_version_id, program, year_level, semester, academic_year, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Enrolled')");
                            foreach ($subject_ids as $sub_id) {
                                $stmt_sem_sub->bind_param('isss', $sub_id, $enrollment_semester, $academic_year, $year_level);
                                $stmt_sem_sub->execute();

                                $stmt_enr->bind_param('iiissss', $seminarian_id, $sub_id, $curriculum_version_id, $program, $year_level, $enrollment_semester, $academic_year);
                                $stmt_enr->execute();
                            }
                            $auto_subjects_count = count($subject_ids);
                        } else {
                            $auto_subjects_count = 0;
                        }
                    }
                }

                $stmt_prof = $conn->prepare(
                    "INSERT INTO user_profiles (user_id, student_id, first_name, middle_name, last_name, suffix, date_of_birth, place_of_birth, age, gender, civil_status, nationality, religion, parish_priest, parish_chaplaincy, contact_number, email_address, permanent_address, present_address, province, municipality, barangay, zip_code, father_name, father_occupation, mother_name, mother_occupation, guardian_name, guardian_contact_number, previous_school, educational_attainment, year_graduated, academic_records, full_name, email, contact, address)\n"
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $email_value = $email_address !== '' ? $email_address : '';
                $contact_value = $contact_number !== '' ? $contact_number : '';
                $address_value = $present_address !== '' ? $present_address : ($permanent_address !== '' ? $permanent_address : '');
                $stmt_prof->bind_param(
                    "i" . str_repeat("s", 7) . "i" . str_repeat("s", 28),
                    $user_id,
                    $student_id,
                    $first_name,
                    $middle_name,
                    $last_name,
                    $suffix,
                    $dob,
                    $place_of_birth,
                    $age,
                    $gender,
                    $civil_status,
                    $nationality,
                    $religion,
                    $parish_priest,
                    $parish_chaplaincy,
                    $contact_number,
                    $email_address,
                    $permanent_address,
                    $present_address,
                    $province,
                    $municipality,
                    $barangay,
                    $zip_code,
                    $father_name,
                    $father_occupation,
                    $mother_name,
                    $mother_occupation,
                    $guardian_name,
                    $guardian_contact_number,
                    $previous_school,
                    $educational_attainment,
                    $year_graduated,
                    $academic_records,
                    $full_name,
                    $email_value,
                    $contact_value,
                    $address_value
                );
                if (!$stmt_prof->execute()) {
                    throw new Exception("Failed to create profile: " . $stmt_prof->error);
                }

                $stmt_app = $conn->prepare("INSERT INTO applicants (full_name, dob, age, gender, contact, address, academic_history, parent_info, academic_year, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Enrolled', ?)");
                $stmt_app->bind_param("ssissssssi", $full_name, $dob, $age, $gender, $contact_number, $address_value, $academic_history, $parent_info, $academic_year, $user_id);
                if (!$stmt_app->execute()) {
                    throw new Exception("Failed to create applicant record: " . $stmt_app->error);
                }

                log_activity($_SESSION['user'], "Encoded seminarian: " . $full_name);
                $conn->commit();
                if (function_exists('registrar_sync_and_migrate')) {
                    registrar_sync_and_migrate($conn, true);
                }
                if (function_exists('registrar_enroll_student_in_term')) {
                    registrar_enroll_student_in_term(
                        $conn,
                        (int)$seminarian_id,
                        (string)$academic_year,
                        (string)$enrollment_semester,
                        (string)$year_level,
                        (string)$program,
                        'Regular',
                        $subject_ids ?? [],
                        'Initial admission & enrollment',
                        $actor_id
                    );
                }

                $flash_msg = $password_in !== ''
                    ? "Seminarian encoded successfully as " . $username
                    : "Seminarian encoded successfully as " . $username . " (default password: pass)";
                if (isset($auto_subjects_count) && (int)$auto_subjects_count > 0) {
                    $flash_msg .= " • Auto-loaded subjects: " . (int)$auto_subjects_count;
                }
                $_SESSION['flash_enr_success'] = $flash_msg;
                $_SESSION['toast_success'] = $flash_msg;
                header('Location: enrollment_records.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to encode seminarian: " . $e->getMessage();
            }
        }
    }
}

// 2. Handle Enrolling a Student in a New Semester (`enrollments` table)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_term_enrollment'])) {
    if (!$can_edit) {
        $error = 'Only the Registrar can create semester enrollments.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    } else {
        $target_student_id = (int)($_POST['student_id'] ?? 0);
        $target_ay = trim((string)($_POST['academic_year'] ?? $current_ay));
        $target_sem = trim((string)($_POST['semester'] ?? '1st'));
        $target_yl = trim((string)($_POST['year_level'] ?? 'First Year'));
        $target_prog = trim((string)($_POST['program'] ?? ''));
        $target_standing = trim((string)($_POST['academic_standing'] ?? 'Regular'));
        $target_remarks = trim((string)($_POST['remarks'] ?? ''));
        $return_student_id = !empty($_POST['return_student_id']) ? (int)$_POST['return_student_id'] : 0;

        $res_enr = registrar_enroll_student_in_term(
            $conn,
            $target_student_id,
            $target_ay,
            $target_sem,
            $target_yl,
            $target_prog,
            $target_standing,
            [],
            $target_remarks !== '' ? $target_remarks : null,
            $actor_id
        );

        if (!empty($res_enr['ok'])) {
            $flash_enr_msg = sprintf(
                'Created term enrollment for AY %s (%s Semester) — %s (%d curriculum subjects enlisted). Previous terms were safely locked in history.',
                $res_enr['academic_year'],
                $res_enr['semester'],
                $res_enr['year_level'],
                (int)$res_enr['subjects_count']
            );
            $_SESSION['flash_enr_success'] = $flash_enr_msg;
            $_SESSION['toast_success'] = $flash_enr_msg;
            if ($return_student_id > 0) {
                header('Location: enrollment_records.php?student_id=' . $target_student_id . '&enrollment_id=' . (int)$res_enr['enrollment_id']);
            } else {
                header('Location: enrollment_records.php');
            }
            exit;
        } else {
            $error = $res_enr['error'] ?? 'Failed to create semester enrollment.';
        }
    }
}

// 3. Handle Locking / Unlocking a Term Enrollment Record
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['toggle_lock_enrollment'])) {
    if (!$can_edit) {
        $error = 'Unauthorized action.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    } else {
        $enr_id = (int)($_POST['enrollment_id'] ?? 0);
        $sid = (int)($_POST['student_id'] ?? 0);
        $new_lock = (int)($_POST['new_lock'] ?? 1) === 1 ? 1 : 0;

        if ($enr_id > 0) {
            $stmt_lk = $conn->prepare("UPDATE enrollments SET is_locked = ? WHERE id = ?");
            $stmt_lk->bind_param('ii', $new_lock, $enr_id);
            $stmt_lk->execute();
            $_SESSION['flash_enr_success'] = $new_lock === 1
                ? 'Semester enrollment record locked as an immutable historical archive.'
                : 'Semester enrollment record unlocked for editing.';
            header('Location: enrollment_records.php?student_id=' . $sid . '&enrollment_id=' . $enr_id);
            exit;
        }
    }
}

// Check if viewing a specific student's Enrollment History Workspace
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$selected_enrollment_id = isset($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : 0;

// Fetch all students for dropdown picker along with their latest enrollment history
$all_students = [];
$sql_all_st = "SELECT
        COALESCE(s.id, sem.id) AS id,
        COALESCE(NULLIF(TRIM(s.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', YEAR(NOW()), '-', LPAD(sem.id, 4, '0'))) AS student_no,
        COALESCE(
            NULLIF(TRIM(s.full_name), ''),
            NULLIF(TRIM(up.full_name), ''),
            NULLIF(TRIM(CONCAT_WS(' ', up.first_name, up.middle_name, up.last_name, up.suffix)), ''),
            u.username,
            CONCAT('Seminarian #', sem.id)
        ) AS full_name,
        COALESCE(NULLIF(TRIM(s.default_program), ''), NULLIF(TRIM(sem.program), ''), 'Bachelor of Arts in Philosophy') AS default_program
    FROM seminarians sem
    LEFT JOIN students s ON s.id = sem.id
    LEFT JOIN users u ON u.id = COALESCE(s.user_id, sem.user_id)
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE NOT (u.username = 'student1' AND up.full_name IS NULL AND sem.year_level IS NULL)
    ORDER BY COALESCE(s.last_name, up.last_name, up.full_name, u.username) ASC,
             COALESCE(s.first_name, up.first_name, '') ASC";
$res_all_st = $conn->query($sql_all_st);
if ($res_all_st) {
    while ($r = $res_all_st->fetch_assoc()) {
        $sid_check = (int)($r['id'] ?? 0);
        $last_rec = get_student_last_enrollment_record($sid_check);
        $last_yl = !empty($last_rec['year_level']) ? (string)$last_rec['year_level'] : 'Propaedeutic Stage';
        $norm_last_yl = normalize_year_level($last_yl);
        $r['last_academic_year'] = (string)($last_rec['academic_year'] ?? '');
        $r['last_semester'] = (string)($last_rec['semester'] ?? '');
        $r['last_year_level'] = $norm_last_yl;
        $r['next_year_level'] = get_next_year_level($norm_last_yl);
        $r['prop_completed'] = ($norm_last_yl === 'Propaedeutic Stage')
            ? (registrar_has_completed_propaedeutic($conn, $sid_check) ? '1' : '0')
            : '1';
        $all_students[] = $r;
    }
}

$student_profile = null;
$student_enrollments = [];
$active_enrollment = null;
$active_subjects = [];
$suggested_next_yl = 'First Year';

if ($student_id > 0) {
    $stmt_sp = $conn->prepare(
        "SELECT
            COALESCE(s.id, sem.id) AS id,
            COALESCE(s.user_id, sem.user_id) AS user_id,
            COALESCE(NULLIF(TRIM(s.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', YEAR(NOW()), '-', LPAD(sem.id, 4, '0'))) AS student_no,
            COALESCE(s.first_name, up.first_name, '') AS first_name,
            COALESCE(s.middle_name, up.middle_name, '') AS middle_name,
            COALESCE(s.last_name, up.last_name, '') AS last_name,
            COALESCE(s.suffix, up.suffix, '') AS suffix,
            COALESCE(
                NULLIF(TRIM(s.full_name), ''),
                NULLIF(TRIM(up.full_name), ''),
                NULLIF(TRIM(CONCAT_WS(' ', up.first_name, up.middle_name, up.last_name, up.suffix)), ''),
                u.username,
                CONCAT('Seminarian #', sem.id)
            ) AS full_name,
            COALESCE(s.contact_number, up.contact_number, up.contact) AS contact_number,
            COALESCE(s.email, up.email_address, up.email) AS email,
            COALESCE(s.email, up.email_address, up.email) AS email_address,
            COALESCE(s.parish_priest, up.parish_priest) AS parish_priest,
            COALESCE(s.parish_chaplaincy, up.parish_chaplaincy) AS parish_chaplaincy,
            COALESCE(s.home_address, s.permanent_address, up.permanent_address, up.present_address, up.address) AS home_address,
            COALESCE(NULLIF(TRIM(s.admission_academic_year), ''), sem.current_academic_year) AS admission_academic_year,
            COALESCE(s.default_program, sem.program, 'Bachelor of Arts in Philosophy') AS default_program,
            COALESCE(s.is_graduate, sem.is_graduate, 0) AS is_graduate
        FROM seminarians sem
        LEFT JOIN students s ON s.id = sem.id
        LEFT JOIN users u ON u.id = COALESCE(s.user_id, sem.user_id)
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE sem.id = ? OR s.id = ?
        LIMIT 1"
    );
    $stmt_sp->bind_param('ii', $student_id, $student_id);
    $stmt_sp->execute();
    $student_profile = $stmt_sp->get_result()->fetch_assoc();

    if ($student_profile) {
        // Fetch all enrollments for this student chronologically (newest first)
        $stmt_se = $conn->prepare(
            "SELECT e.*,\n"
            . "  (SELECT COUNT(*) FROM enrollment_subjects es WHERE es.enrollment_id = e.id) AS subjects_count,\n"
            . "  (SELECT COALESCE(SUM(es.units), 0) FROM enrollment_subjects es WHERE es.enrollment_id = e.id) AS total_units\n"
            . "FROM enrollments e\n"
            . "WHERE e.student_id = ?\n"
            . "ORDER BY e.academic_year DESC, FIELD(e.semester, 'Summer', '2nd', '1st') ASC, e.id DESC"
        );
        $stmt_se->bind_param('i', $student_id);
        $stmt_se->execute();
        $res_se = $stmt_se->get_result();
        while ($row = $res_se->fetch_assoc()) {
            // Live-sync fee balance with Cashier ledger for accuracy
            $bal = lodging_get_seminarian_balance($conn, $student_id, (string)$row['academic_year'], (string)$row['semester']);
            $row['total_assessed_fee'] = (float)($bal['current_term_due'] ?? $row['total_assessed_fee']);
            $row['total_paid'] = (float)($bal['total_paid'] ?? $row['total_paid']);
            $row['fee_balance'] = max(0.0, round($row['total_assessed_fee'] - $row['total_paid'], 2));
            $student_enrollments[] = $row;
        }

        if (!empty($student_enrollments)) {
            foreach ($student_enrollments as $enr) {
                if ((int)$enr['id'] === $selected_enrollment_id) {
                    $active_enrollment = $enr;
                    break;
                }
            }
            if (!$active_enrollment) {
                $active_enrollment = $student_enrollments[0];
            }

            // Suggest next year level based on latest enrollment
            $latest = $student_enrollments[0];
            $suggested_next_yl = calculate_target_year_level(
                (string)$latest['year_level'],
                (string)$latest['semester'],
                $latest['semester'] === '1st' ? '2nd' : '1st'
            );
        }

        if ($active_enrollment) {
            $enr_id = (int)$active_enrollment['id'];
            $stmt_sub = $conn->prepare(
                "SELECT es.*, s.subject_code, s.subject_name, s.units AS catalog_units,\n"
                . "  sg.prelim_grade, sg.midterm_grade, sg.final_grade AS sg_final_grade, sg.final_computed_grade, sg.status AS sg_status\n"
                . "FROM enrollment_subjects es\n"
                . "LEFT JOIN subjects s ON s.subject_id = es.subject_id\n"
                . "LEFT JOIN student_grades sg ON (sg.student_id = ? AND sg.subject_id = es.subject_id AND sg.academic_year = ? AND sg.semester = ?)\n"
                . "WHERE es.enrollment_id = ?\n"
                . "ORDER BY s.subject_code ASC"
            );
            $stmt_sub->bind_param(
                'issi',
                $student_id,
                $active_enrollment['academic_year'],
                $active_enrollment['semester'],
                $enr_id
            );
            $stmt_sub->execute();
            $res_sub = $stmt_sub->get_result();
            while ($srow = $res_sub->fetch_assoc()) {
                $active_subjects[] = $srow;
            }
        }
    }
}

// Roster Mode Filters (when viewing all enrollments across students)
$filter_ay = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$filter_sem = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
$filter_yl = isset($_GET['year_level']) ? trim((string)$_GET['year_level']) : '';
$filter_q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$roster_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$roster_per_page_opts = [10, 20, 50];
$roster_per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $roster_per_page_opts, true)
    ? (int)$_GET['per_page']
    : 10;

$all_roster_rows = [];
if (!$student_profile) {
    $sql_roster = "SELECT
                    e.*,
                    COALESCE(
                        NULLIF(TRIM(s.student_no), ''),
                        NULLIF(TRIM(up.student_id), ''),
                        CONCAT('SEM-', LEFT(e.academic_year, 4), '-', LPAD(e.student_id, 4, '0'))
                    ) AS student_no,
                    COALESCE(
                        NULLIF(TRIM(s.full_name), ''),
                        NULLIF(TRIM(up.full_name), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', up.first_name, up.middle_name, up.last_name, up.suffix)), ''),
                        NULLIF(TRIM(a.full_name), ''),
                        u.username,
                        CONCAT('Seminarian #', e.student_id)
                    ) AS full_name,
                    COALESCE(s.contact_number, up.contact_number, up.contact, a.contact) AS contact_number,
                    (SELECT COUNT(*) FROM enrollment_subjects es WHERE es.enrollment_id = e.id) AS subjects_count,
                    (SELECT COALESCE(SUM(es.units), 0) FROM enrollment_subjects es WHERE es.enrollment_id = e.id) AS total_units
                FROM enrollments e
                LEFT JOIN students s ON s.id = e.student_id
                LEFT JOIN seminarians sem ON sem.id = e.student_id
                LEFT JOIN users u ON u.id = COALESCE(s.user_id, sem.user_id)
                LEFT JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN applicants a ON a.id = (
                    SELECT a2.id FROM applicants a2
                    WHERE a2.user_id = u.id AND a2.archived_at IS NULL
                    ORDER BY a2.id DESC LIMIT 1
                )
                WHERE 1=1";
    $r_params = [];
    $r_types = '';

    if ($filter_ay !== '') {
        $sql_roster .= " AND e.academic_year = ?";
        $r_params[] = $filter_ay;
        $r_types .= 's';
    }
    if ($filter_sem !== '') {
        $sql_roster .= " AND e.semester = ?";
        $r_params[] = $filter_sem;
        $r_types .= 's';
    }
    if ($filter_yl !== '') {
        $norm_filter_yl = normalize_year_level($filter_yl);
        $sql_roster .= " AND (e.year_level = ? OR e.year_level = ?)";
        $r_params[] = $filter_yl;
        $r_params[] = $norm_filter_yl;
        $r_types .= 'ss';
    }
    if ($filter_q !== '') {
        $sql_roster .= " AND (
            COALESCE(s.full_name, up.full_name, a.full_name, u.username, '') LIKE ?
            OR COALESCE(s.student_no, up.student_id, '') LIKE ?
        )";
        $lk = '%' . $filter_q . '%';
        $r_params[] = $lk;
        $r_params[] = $lk;
        $r_types .= 'ss';
    }

    $sql_roster .= " ORDER BY e.academic_year DESC, FIELD(e.semester, 'Summer', '2nd', '1st') ASC, COALESCE(s.last_name, up.last_name, s.full_name, up.full_name, a.full_name, u.username) ASC, e.id DESC";
    $stmt_r = $conn->prepare($sql_roster);
    if ($stmt_r) {
        if ($r_types !== '') {
            $bind = [&$r_types];
            foreach ($r_params as $k => $v) {
                $bind[] = &$r_params[$k];
            }
            call_user_func_array([$stmt_r, 'bind_param'], $bind);
        }
        $stmt_r->execute();
        $res_r = $stmt_r->get_result();
        while ($r = $res_r->fetch_assoc()) {
            $all_roster_rows[] = $r;
        }
    }
}

$roster_total_rows = count($all_roster_rows);
$roster_total_pages = max(1, (int)ceil($roster_total_rows / $roster_per_page));
if ($roster_page > $roster_total_pages) {
    $roster_page = $roster_total_pages;
}
$roster_offset = ($roster_page - 1) * $roster_per_page;
$roster_rows = array_slice($all_roster_rows, $roster_offset, $roster_per_page);

function enrollment_status_badge_classes(string $status): string {
    switch ($status) {
        case 'Enrolled':
            return 'bg-green-100 text-green-700';
        case 'Pending':
        case 'Partially Paid':
            return 'bg-amber-100 text-amber-700';
        case 'Completed':
            return 'bg-blue-100 text-blue-700';
        case 'Graduated':
            return 'bg-indigo-100 text-indigo-700';
        case 'Dropped':
        case 'Withdrawn':
            return 'bg-rose-100 text-rose-700';
        default:
            return 'bg-slate-200 text-slate-700';
    }
}

function render_enrollment_roster_tbody_rows(array $roster_rows): string {
    ob_start();
    if (empty($roster_rows)) {
        ?>
        <tr>
            <td colspan="7" class="px-6 py-10 text-center text-slate-400 font-medium">
                No records found
            </td>
        </tr>
        <?php
    } else {
        foreach ($roster_rows as $r) {
            $locked = (int)$r['is_locked'] === 1;
            ?>
            <tr class="hover:bg-[#F8EEEE]/30 transition">
                <td class="px-5 py-4 align-top whitespace-nowrap">
                    <div class="font-extrabold text-[#59443F] flex items-center gap-1.5">
                        <?php if ($locked) { ?>
                            <i class="fa-solid fa-lock text-amber-600 text-xs" title="Locked Historical Record"></i>
                        <?php } else { ?>
                            <i class="fa-solid fa-circle-dot text-emerald-600 text-xs" title="Active Term"></i>
                        <?php } ?>
                        AY <?php echo htmlspecialchars((string)$r['academic_year']); ?>
                    </div>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 font-bold text-[11px]">
                        <?php echo htmlspecialchars((string)$r['semester']); ?> Semester
                    </span>
                </td>

                <td class="px-5 py-4 align-top">
                    <div class="font-bold text-[#59443F]">
                        <?php echo htmlspecialchars((string)$r['full_name']); ?>
                    </div>
                    <span class="font-mono text-xs text-slate-500">
                        <?php echo htmlspecialchars((string)$r['student_no']); ?>
                    </span>
                </td>

                <td class="px-5 py-4 align-top">
                    <div class="font-bold text-slate-800 text-xs">
                        <?php echo htmlspecialchars((string)$r['year_level']); ?>
                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] <?php echo $r['academic_standing'] === 'Regular' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800'; ?>">
                            <?php echo htmlspecialchars((string)$r['academic_standing']); ?>
                        </span>
                    </div>
                    <div class="text-xs text-slate-500 mt-0.5">
                        <?php echo htmlspecialchars((string)$r['program']); ?>
                    </div>
                </td>

                <td class="px-5 py-4 align-top text-center">
                    <div class="font-extrabold text-slate-800 text-xs">
                        <?php echo (int)$r['subjects_count']; ?> Subjects
                    </div>
                    <div class="text-[11px] text-slate-500">
                        <?php echo number_format((float)$r['total_units'], 1); ?> Units
                    </div>
                </td>

                <td class="px-5 py-4 align-top text-right whitespace-nowrap">
                    <div class="font-bold text-xs <?php echo (float)$r['fee_balance'] > 0 ? 'text-rose-600' : 'text-emerald-700'; ?>">
                        ₱<?php echo number_format((float)$r['fee_balance'], 2); ?>
                    </div>
                    <div class="text-[11px] text-slate-400">
                        Assessed: ₱<?php echo number_format((float)$r['total_assessed_fee'], 2); ?>
                    </div>
                </td>

                <td class="px-5 py-4 align-top text-center">
                    <?php $term_status_label = (string)($r['enrollment_status'] ?? 'Pending'); ?>
                    <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo enrollment_status_badge_classes($term_status_label); ?>">
                        <?php echo htmlspecialchars($term_status_label); ?>
                    </span>
                    <?php if ($locked) { ?>
                        <div class="text-[10px] font-bold text-amber-700 mt-1">Archived</div>
                    <?php } ?>
                </td>

                <td class="px-5 py-4 align-top text-right whitespace-nowrap">
                    <a
                        href="enrollment_records.php?student_id=<?php echo (int)$r['student_id']; ?>&enrollment_id=<?php echo (int)$r['id']; ?>"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 transition no-underline"
                    >
                        <span>Open Term &amp; History</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </td>
            </tr>
            <?php
        }
    }
    return (string)ob_get_clean();
}

// Handle AJAX dynamic filter requests by returning only the <tbody> rows
$is_ajax_request = isset($_GET['ajax_filter']) || (
    isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !$student_profile
);
if ($is_ajax_request) {
    $ajax_from = $roster_total_rows > 0 ? ($roster_offset + 1) : 0;
    $ajax_to = min($roster_total_rows, $roster_offset + $roster_per_page);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Total-Rows: ' . (int)$roster_total_rows);
    header('X-From-Row: ' . (int)$ajax_from);
    header('X-To-Row: ' . (int)$ajax_to);
    echo render_enrollment_roster_tbody_rows($roster_rows);
    exit;
}

function enrollment_build_url(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return 'enrollment_records.php' . ($qs ? ('?' . $qs) : '');
}

$pageTitle = $student_profile
    ? ('Enrollment — ' . $student_profile['full_name'])
    : 'Enrollment';
$activeNav = 'enrollment_records';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>

<div class="w-full space-y-6">
    <!-- Top Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-2xl p-6 border border-[#E8C7C5]/60 shadow-soft">
        <div>
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-[11px] font-extrabold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-layer-group"></i> Dedicated Term Enrollment Module (<code>enrollments</code> &amp; <code>enrollment_subjects</code>)
            </div>
            <h1 class="text-2xl font-extrabold text-[#59443F] tracking-tight m-0 flex items-center gap-2.5">
                <i class="fa-solid fa-clock-rotate-left text-indigo-700"></i>
                <?php if ($student_profile) { ?>
                    Enrollment &amp; Historical Term Records — <?php echo htmlspecialchars((string)$student_profile['full_name']); ?>
                <?php } else { ?>
                    Enrollment (Semester &amp; Academic Year Management)
                <?php } ?>
            </h1>
            <p class="text-xs font-medium text-[#8B7168] mt-1 mb-0">
                Every time a seminarian enrolls for a new semester or academic year, a new record is appended to <code>enrollments</code>—preserving all past semesters as an intact historical backup.
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <?php if ($is_registrar) { ?>
                <button type="button" id="openAddApplicantModal" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-teal-700 hover:bg-teal-800 transition shadow-sm border-0 cursor-pointer">
                    <i class="fa-solid fa-plus"></i>
                    <span>Add Seminarian</span>
                </button>
            <?php } ?>
            <?php if ($student_profile) { ?>
                <a href="enrollment_records.php" class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5]/60 border border-[#E8C7C5] transition no-underline">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>All Term Enrollments</span>
                </a>
                <?php if ($can_edit) { ?>
                <button type="button" onclick="openNewTermModal(<?php echo (int)$student_profile['id']; ?>)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-sm border-0 cursor-pointer">
                    <i class="fa-solid fa-plus"></i>
                    <span>Enroll in New Semester</span>
                </button>
                <?php } ?>
            <?php } else { ?>
                <a href="student_profiles.php" class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5]/60 border border-[#E8C7C5] transition no-underline">
                    <i class="fa-solid fa-id-card"></i>
                    <span>Student Profiles Masterfile</span>
                </a>
                <?php if ($can_edit) { ?>
                <form method="post" class="m-0 p-0 bg-transparent border-0 shadow-none inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="trigger_backup_sync" value="1">
                    <button type="submit" class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-bold text-teal-800 bg-teal-50 hover:bg-teal-100 border border-teal-200 transition cursor-pointer">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                        <span>Backup &amp; Sync Snapshot</span>
                    </button>
                </form>
                <button type="button" onclick="openNewTermModal(0)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-sm border-0 cursor-pointer">
                    <i class="fa-solid fa-plus"></i>
                    <span>Enroll Student in Term</span>
                </button>
                <?php } ?>
            <?php } ?>
        </div>
    </div>

    <?php if ($success) { ?>
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2.5 shadow-xs">
            <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold flex items-center gap-2.5 shadow-xs">
            <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php } ?>

    <?php if ($student_profile) { ?>
        <!-- =================================================================== -->
        <!-- SPLIT WORKSPACE: LEFT STATIC STUDENT PROFILE + RIGHT TERM HISTORY   -->
        <!-- =================================================================== -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- LEFT COLUMN (4 Cols): Static Student Profile Card + Semester Switcher -->
            <aside class="lg:col-span-4 space-y-6">
                <!-- Static Student Profile Card -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/60 p-6 shadow-soft">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3.5">
                            <div class="w-13 h-13 rounded-2xl bg-indigo-100 text-indigo-700 font-extrabold text-base flex items-center justify-center shrink-0 px-3.5 py-3">
                                <?php
                                $ini1 = strtoupper(substr((string)$student_profile['first_name'], 0, 1));
                                $ini2 = strtoupper(substr((string)$student_profile['last_name'], 0, 1));
                                echo htmlspecialchars($ini1 . $ini2);
                                ?>
                            </div>
                            <div>
                                <span class="inline-block font-mono text-xs font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200">
                                    <?php echo htmlspecialchars((string)$student_profile['student_no']); ?>
                                </span>
                                <h2 class="text-lg font-extrabold text-[#59443F] m-0 mt-1">
                                    <?php echo htmlspecialchars((string)$student_profile['full_name']); ?>
                                </h2>
                                <p class="text-xs text-[#8B7168] m-0 mt-0.5">
                                    <?php echo htmlspecialchars((string)$student_profile['default_program']); ?>
                                </p>
                            </div>
                        </div>
                        <a href="student_profiles.php?q=<?php echo urlencode((string)$student_profile['student_no']); ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 no-underline shrink-0">
                            Edit Profile
                        </a>
                    </div>

                    <div class="mt-4 p-3 rounded-xl bg-slate-50 border border-slate-200/70 text-[11px] text-slate-600">
                        <strong class="text-slate-800">Static Identity Module:</strong> Stored in <code>students</code>. Personal details stay persistent across all enrolled semesters.
                    </div>

                    <dl class="mt-4 pt-4 border-t border-slate-100 space-y-2.5 text-xs">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 font-medium">Admission Cohort</dt>
                            <dd class="font-bold text-slate-800">AY <?php echo htmlspecialchars((string)($student_profile['admission_academic_year'] ?: '—')); ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 font-medium">Contact Number</dt>
                            <dd class="font-bold text-slate-800"><?php echo htmlspecialchars((string)($student_profile['contact_number'] ?: '—')); ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 font-medium">Email Address</dt>
                            <dd class="font-bold text-slate-800 truncate max-w-[180px]"><?php echo htmlspecialchars((string)($student_profile['email'] ?: '—')); ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 font-medium">Parish / Chaplaincy</dt>
                            <dd class="font-bold text-slate-800 text-right"><?php echo htmlspecialchars((string)($student_profile['parish_chaplaincy'] ?: '—')); ?></dd>
                        </div>
                        <div class="pt-1">
                            <dt class="text-slate-500 font-medium">Permanent Address</dt>
                            <dd class="font-semibold text-slate-800 mt-0.5"><?php echo htmlspecialchars((string)($student_profile['home_address'] ?: '—')); ?></dd>
                        </div>
                    </dl>
                </div>

                <!-- Chronological Semester Enrollment Timeline -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/60 p-5 shadow-soft">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-[#59443F] m-0">
                            Semester Enrollment History (<?php echo count($student_enrollments); ?>)
                        </h3>
                        <span class="text-[11px] font-semibold text-indigo-600">Select Term</span>
                    </div>

                    <div class="space-y-2.5">
                        <?php if (empty($student_enrollments)) { ?>
                            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/70 p-4 text-center text-xs text-slate-500">
                                No enrollments found for this term.
                            </div>
                        <?php } else { ?>
                            <?php foreach ($student_enrollments as $enr) {
                                $is_sel = $active_enrollment && ((int)$enr['id'] === (int)$active_enrollment['id']);
                                $locked = (int)$enr['is_locked'] === 1;
                            ?>
                            <a
                                href="enrollment_records.php?student_id=<?php echo (int)$student_profile['id']; ?>&enrollment_id=<?php echo (int)$enr['id']; ?>"
                                class="block rounded-xl border p-3.5 transition no-underline <?php echo $is_sel ? 'border-indigo-600 bg-indigo-50/70 ring-1 ring-indigo-600' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'; ?>"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-extrabold text-sm text-[#59443F] flex items-center gap-1.5">
                                        <?php if ($locked) { ?>
                                            <i class="fa-solid fa-lock text-amber-600 text-xs" title="Historical Archive (Locked)"></i>
                                        <?php } else { ?>
                                            <i class="fa-solid fa-circle-dot text-emerald-600 text-xs" title="Active Term"></i>
                                        <?php } ?>
                                        AY <?php echo htmlspecialchars((string)$enr['academic_year']); ?> • <?php echo htmlspecialchars((string)$enr['semester']); ?> Sem
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase <?php echo enrollment_status_badge_classes((string)$enr['enrollment_status']); ?>">
                                        <?php echo htmlspecialchars((string)$enr['enrollment_status']); ?>
                                    </span>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-xs text-slate-500">
                                    <span class="font-semibold text-slate-700">
                                        <?php echo htmlspecialchars((string)$enr['year_level']); ?> (<?php echo htmlspecialchars((string)$enr['academic_standing']); ?>)
                                    </span>
                                    <span>
                                        <?php echo (int)$enr['subjects_count']; ?> Subj • Bal: ₱<?php echo number_format((float)$enr['fee_balance'], 2); ?>
                                    </span>
                                </div>
                            </a>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            </aside>

            <!-- RIGHT COLUMN (8 Cols): Active/Historical Term Snapshot & Subjects -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/60 p-6 shadow-soft">
                <?php if ($active_enrollment) {
                    $is_locked = (int)$active_enrollment['is_locked'] === 1;
                ?>
                    <!-- Historical Lock Status Banner -->
                    <?php if ($is_locked) { ?>
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-shield-halved text-amber-600 text-base"></i>
                                <div>
                                    <strong>Historical Term Record (Locked Archive):</strong>
                                    This record preserves the student's state during AY <?php echo htmlspecialchars((string)$active_enrollment['academic_year']); ?> (<?php echo htmlspecialchars((string)$active_enrollment['semester']); ?> Semester) and is protected from accidental overwrite.
                                </div>
                            </div>
                            <?php if ($can_edit) { ?>
                            <form method="post" class="m-0 p-0 bg-transparent border-0 shadow-none">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="toggle_lock_enrollment" value="1">
                                <input type="hidden" name="enrollment_id" value="<?php echo (int)$active_enrollment['id']; ?>">
                                <input type="hidden" name="student_id" value="<?php echo (int)$student_profile['id']; ?>">
                                <input type="hidden" name="new_lock" value="0">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-amber-200/80 hover:bg-amber-300 text-amber-950 font-bold text-[11px] border-0 cursor-pointer">
                                    <i class="fa-solid fa-unlock mr-1"></i> Unlock Term
                                </button>
                            </form>
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 px-4 py-3 text-xs text-emerald-900">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-bolt text-emerald-600 text-sm"></i>
                                <div>
                                    <strong>Active Term Enrollment:</strong>
                                    Subjects and load for AY <?php echo htmlspecialchars((string)$active_enrollment['academic_year']); ?> (<?php echo htmlspecialchars((string)$active_enrollment['semester']); ?> Semester) are currently open for management.
                                </div>
                            </div>
                            <?php if ($can_edit) { ?>
                            <form method="post" class="m-0 p-0 bg-transparent border-0 shadow-none">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="toggle_lock_enrollment" value="1">
                                <input type="hidden" name="enrollment_id" value="<?php echo (int)$active_enrollment['id']; ?>">
                                <input type="hidden" name="student_id" value="<?php echo (int)$student_profile['id']; ?>">
                                <input type="hidden" name="new_lock" value="1">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-white hover:bg-emerald-100 text-emerald-800 font-bold text-[11px] border border-emerald-300 cursor-pointer">
                                    <i class="fa-solid fa-lock mr-1"></i> Lock Term Archive
                                </button>
                            </form>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <!-- Term Snapshot Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-slate-100">
                        <div>
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <h2 class="text-xl font-extrabold text-[#59443F] m-0">
                                    AY <?php echo htmlspecialchars((string)$active_enrollment['academic_year']); ?> — <?php echo htmlspecialchars((string)$active_enrollment['semester']); ?> Semester
                                </h2>
                                <span class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-extrabold border border-indigo-200">
                                    <?php echo htmlspecialchars((string)$active_enrollment['year_level']); ?>
                                </span>
                                <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold">
                                    <?php echo htmlspecialchars((string)$active_enrollment['academic_standing']); ?>
                                </span>
                                <span class="px-2.5 py-1 rounded-lg text-xs font-bold <?php echo enrollment_status_badge_classes((string)$active_enrollment['enrollment_status']); ?>">
                                    <?php echo htmlspecialchars((string)$active_enrollment['enrollment_status']); ?>
                                </span>
                            </div>
                            <p class="text-xs text-[#8B7168] m-0 mt-1">
                                Enrollment ID #<?php echo (int)$active_enrollment['id']; ?> •
                                Program: <strong><?php echo htmlspecialchars((string)$active_enrollment['program']); ?></strong> •
                                Logged: <?php echo htmlspecialchars(substr((string)$active_enrollment['enrolled_at'], 0, 10)); ?>
                            </p>
                        </div>

                        <?php if ($is_registrar && !$is_locked) { ?>
                        <a
                            href="subject_enrollment.php?seminarian_id=<?php echo (int)$student_profile['id']; ?>&academic_year=<?php echo urlencode((string)$active_enrollment['academic_year']); ?>&tab=<?php echo urlencode((string)$active_enrollment['semester']); ?>"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-teal-700 text-white hover:bg-teal-800 transition no-underline shrink-0"
                        >
                            <i class="fa-solid fa-book-bookmark"></i>
                            <span>Manage Term Subjects</span>
                        </a>
                        <?php } ?>
                    </div>

                    <!-- Term KPI Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5">
                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Enlisted Load</span>
                            <div class="text-xl font-black text-[#59443F] mt-1">
                                <?php echo number_format((float)$active_enrollment['total_units'], 1); ?> Units
                            </div>
                            <span class="text-xs text-slate-500"><?php echo count($active_subjects); ?> Subjects in this term</span>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Assessed Term Fee</span>
                            <div class="text-xl font-black text-[#59443F] mt-1">
                                ₱<?php echo number_format((float)$active_enrollment['total_assessed_fee'], 2); ?>
                            </div>
                            <span class="text-xs text-emerald-700 font-semibold">
                                Paid: ₱<?php echo number_format((float)$active_enrollment['total_paid'], 2); ?>
                            </span>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Term Fee Balance</span>
                            <div class="text-xl font-black mt-1 <?php echo (float)$active_enrollment['fee_balance'] > 0 ? 'text-rose-600' : 'text-emerald-600'; ?>">
                                ₱<?php echo number_format((float)$active_enrollment['fee_balance'], 2); ?>
                            </div>
                            <span class="text-xs text-slate-500">
                                <?php echo (float)$active_enrollment['fee_balance'] > 0 ? 'Scoped to this semester' : 'Cleared for this semester'; ?>
                            </span>
                        </div>
                    </div>
                <?php } else { ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-slate-100">
                        <div>
                            <h2 class="text-xl font-extrabold text-[#59443F] m-0">
                                No Term Enrollment Record Yet
                            </h2>
                            <p class="text-xs text-[#8B7168] m-0 mt-1">
                                Use <strong>Enroll Student in Term</strong> above to enroll this seminarian in an Academic Year &amp; Semester.
                            </p>
                        </div>
                    </div>
                <?php } ?>

                    <!-- Enrolled Subjects Table for this Specific Term -->
                    <div class="mt-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-extrabold text-[#59443F] m-0">
                                <?php if ($active_enrollment) { ?>
                                    Enrolled Subjects for AY <?php echo htmlspecialchars((string)$active_enrollment['academic_year']); ?> (<?php echo htmlspecialchars((string)$active_enrollment['semester']); ?> Sem)
                                <?php } else { ?>
                                    Enrolled Subjects
                                <?php } ?>
                            </h3>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="min-w-full m-0 divide-y divide-slate-200 text-left text-sm">
                                <thead class="bg-slate-50 text-[11px] font-extrabold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">Subject Code</th>
                                        <th class="px-4 py-3">Descriptive Title</th>
                                        <th class="px-4 py-3 text-center">Units</th>
                                        <th class="px-4 py-3 text-center">Final Grade</th>
                                        <th class="px-4 py-3 text-right">Subject Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php if (empty($active_subjects)) { ?>
                                        <tr>
                                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                                                No enrollments found for this term.
                                            </td>
                                        </tr>
                                    <?php } else { ?>
                                        <?php foreach ($active_subjects as $sub) {
                                            $grade_val = $sub['sg_final_grade'] ?? $sub['final_computed_grade'] ?? $sub['final_grade'] ?? null;
                                            $sub_st = (string)($sub['subject_status'] ?: 'Enrolled');
                                            if (!empty($sub['sg_status']) && $sub['sg_status'] === 'Completed') {
                                                $sub_st = 'Completed';
                                            }
                                        ?>
                                        <tr class="hover:bg-slate-50/80">
                                            <td class="px-4 py-3 font-mono text-xs font-bold text-slate-800">
                                                <?php echo htmlspecialchars((string)$sub['subject_code']); ?>
                                            </td>
                                            <td class="px-4 py-3 font-semibold text-[#59443F]">
                                                <?php echo htmlspecialchars((string)$sub['subject_name']); ?>
                                            </td>
                                            <td class="px-4 py-3 text-center font-bold text-slate-600">
                                                <?php echo (float)($sub['units'] ?: $sub['catalog_units']); ?>
                                            </td>
                                            <td class="px-4 py-3 text-center font-bold text-slate-800">
                                                <?php echo $grade_val !== null && $grade_val !== '' ? htmlspecialchars((string)$grade_val) : '<span class="text-xs text-slate-400">In Progress</span>'; ?>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo $sub_st === 'Completed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-blue-50 text-blue-700 border border-blue-200'; ?>">
                                                    <?php echo htmlspecialchars($sub_st); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <!-- =================================================================== -->
        <!-- MASTER SEMESTER ENROLLMENT ROSTER VIEW                              -->
        <!-- =================================================================== -->
        <div class="w-full space-y-6">
            <div class="w-full bg-white rounded-2xl p-4 sm:p-5 border border-[#E8C7C5]/60 shadow-soft">
                <form id="rosterFilterForm" method="get" data-no-auto="1" onsubmit="event.preventDefault(); return false;" class="w-full grid grid-cols-1 md:grid-cols-3 items-end gap-4 m-0 p-0 bg-transparent border-0 shadow-none">
                    <div class="w-full">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">Search Student</label>
                        <input id="rosterFilterSearch" type="text" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Search by Student Name or ID..." class="w-full h-10 px-3.5 py-2 text-sm rounded-xl border border-slate-200 bg-white">
                    </div>

                    <div class="w-full">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">Semester</label>
                        <select id="rosterFilterSem" name="semester" data-no-auto="1" class="w-full h-10 px-3 py-2 text-sm rounded-xl border border-slate-200 bg-white">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo $filter_sem === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $filter_sem === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                            <option value="Summer" <?php echo $filter_sem === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                        </select>
                    </div>

                    <div class="w-full">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">Term Year Level</label>
                        <select id="rosterFilterYl" name="year_level" data-no-auto="1" class="w-full h-10 px-3 py-2 text-sm rounded-xl border border-slate-200 bg-white">
                            <option value="">All Year Levels</option>
                            <option value="Propaedeutic Stage" <?php echo $filter_yl === 'Propaedeutic Stage' ? 'selected' : ''; ?>>Propaedeutic Stage</option>
                            <option value="First Year" <?php echo $filter_yl === 'First Year' ? 'selected' : ''; ?>>First Year</option>
                            <option value="Second Year" <?php echo $filter_yl === 'Second Year' ? 'selected' : ''; ?>>Second Year</option>
                            <option value="Third Year" <?php echo $filter_yl === 'Third Year' ? 'selected' : ''; ?>>Third Year</option>
                            <option value="Fourth Year" <?php echo $filter_yl === 'Fourth Year' ? 'selected' : ''; ?>>Fourth Year</option>
                        </select>
                    </div>
                </form>
            </div>

            <div class="w-full bg-white rounded-2xl border border-[#E8C7C5]/60 shadow-soft overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2 bg-[#F8EEEE]/40">
                <div class="text-xs font-extrabold uppercase tracking-wider text-[#59443F]">
                    Semester Enrollment Records (<span id="rosterSnapshotCount"><?php echo (int)$roster_total_rows; ?></span> Term Snapshots)
                </div>
                <div class="text-xs text-[#8B7168]">
                    Each row represents a single student's enrollment in a specific Academic Year &amp; Semester.
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full m-0 divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-[11px] font-extrabold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3.5">Term (AY &amp; Sem)</th>
                            <th class="px-5 py-3.5">Student No. &amp; Name</th>
                            <th class="px-5 py-3.5">Term Year Level &amp; Program</th>
                            <th class="px-5 py-3.5 text-center">Enlisted Load</th>
                            <th class="px-5 py-3.5 text-right">Term Fee Balance</th>
                            <th class="px-5 py-3.5 text-center">Term Status</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rosterTableBody" class="divide-y divide-slate-100 bg-white">
                        <?php echo render_enrollment_roster_tbody_rows($roster_rows); ?>
                    </tbody>
                </table>
            </div>

            <?php
                $r_from = $roster_total_rows > 0 ? ($roster_offset + 1) : 0;
                $r_to = min($roster_total_rows, $roster_offset + $roster_per_page);
            ?>
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 border-t border-slate-200/80 bg-slate-50/60">
                <div class="flex items-center gap-3 flex-wrap text-xs font-semibold text-slate-600">
                    <span>
                        Showing <strong id="rosterFromCount" class="font-extrabold text-slate-900"><?php echo (int)$r_from; ?></strong>
                        to <strong id="rosterToCount" class="font-extrabold text-slate-900"><?php echo (int)$r_to; ?></strong>
                        of <strong id="rosterTotalCount" class="font-extrabold text-slate-900"><?php echo (int)$roster_total_rows; ?></strong> term enrollment records
                    </span>
                    <span class="text-slate-300">|</span>
                    <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                        <span>Rows per page:</span>
                        <select
                            onchange="window.location.href = this.value"
                            class="px-2 py-1 text-xs font-bold text-slate-800 bg-white border border-slate-200 rounded-lg cursor-pointer"
                        >
                            <?php foreach ($roster_per_page_opts as $rpp) { ?>
                                <option
                                    value="<?php echo htmlspecialchars(enrollment_build_url(['per_page' => $rpp, 'page' => 1])); ?>"
                                    <?php echo $roster_per_page === $rpp ? 'selected' : ''; ?>
                                >
                                    <?php echo (int)$rpp; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </label>
                </div>

                <div class="flex items-center gap-1.5 flex-wrap">
                    <?php if ($roster_page > 1) { ?>
                        <a href="<?php echo htmlspecialchars(enrollment_build_url(['page' => $roster_page - 1])); ?>" class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 no-underline">Prev</a>
                    <?php } else { ?>
                        <span class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">Prev</span>
                    <?php } ?>

                    <?php
                        $r_start = max(1, $roster_page - 2);
                        $r_end = min($roster_total_pages, $roster_page + 2);
                        for ($rp = $r_start; $rp <= $r_end; $rp++) {
                            if ($rp === $roster_page) {
                                echo '<span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-extrabold rounded-lg bg-indigo-600 text-white">' . (int)$rp . '</span>';
                            } else {
                                echo '<a href="' . htmlspecialchars(enrollment_build_url(['page' => $rp])) . '" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 no-underline">' . (int)$rp . '</a>';
                            }
                        }
                    ?>

                    <?php if ($roster_page < $roster_total_pages) { ?>
                        <a href="<?php echo htmlspecialchars(enrollment_build_url(['page' => $roster_page + 1])); ?>" class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 no-underline">Next</a>
                    <?php } else { ?>
                        <span class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">Next</span>
                    <?php } ?>
                </div>
            </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- MODAL: Enroll Student in New Semester -->
<div id="newTermEnrollmentModal" data-global-modal="1" class="<?php echo htmlspecialchars(global_modal_overlay_classes('hidden'), ENT_QUOTES, 'UTF-8'); ?>" role="dialog" aria-modal="true">
    <div class="max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-[#E8C7C5]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <span class="text-[10px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded bg-indigo-50 text-indigo-700">
                    New Term Record (`enrollments`)
                </span>
                <h3 class="text-lg font-extrabold text-[#59443F] m-0 mt-1">
                    Enroll Student in New Semester
                </h3>
            </div>
            <button type="button" onclick="closeNewTermModal()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 border-0 bg-transparent cursor-pointer text-lg">
                &times;
            </button>
        </div>

        <form method="post" class="mt-4 space-y-4 m-0 p-0 bg-transparent border-0 shadow-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="create_term_enrollment" value="1">
            <input type="hidden" name="return_student_id" value="<?php echo $student_profile ? (int)$student_profile['id'] : 0; ?>">

            <div class="p-3 rounded-xl bg-indigo-50/70 border border-indigo-200 text-xs text-indigo-950">
                <i class="fa-solid fa-clock-rotate-left text-indigo-600 mr-1"></i>
                <strong>Historical Preservation:</strong> Creating a new semester enrollment automatically locks all previous semesters in history so past records are never overwritten.
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Select Seminarian</label>
                <select name="student_id" id="nt_student_id" onchange="syncStudentProgressionPreview()" required class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                    <option value="">-- Choose Seminarian --</option>
                    <?php foreach ($all_students as $ast) { ?>
                        <option
                            value="<?php echo (int)$ast['id']; ?>"
                            data-last-ay="<?php echo htmlspecialchars((string)($ast['last_academic_year'] ?? '')); ?>"
                            data-last-sem="<?php echo htmlspecialchars((string)($ast['last_semester'] ?? '')); ?>"
                            data-last-yl="<?php echo htmlspecialchars((string)($ast['last_year_level'] ?? '')); ?>"
                            data-next-yl="<?php echo htmlspecialchars((string)($ast['next_year_level'] ?? '')); ?>"
                            data-prop-completed="<?php echo htmlspecialchars((string)($ast['prop_completed'] ?? '1')); ?>"
                            data-program="<?php echo htmlspecialchars((string)($ast['default_program'] ?? '')); ?>"
                            <?php echo ($student_profile && (int)$student_profile['id'] === (int)$ast['id']) ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($ast['full_name'] . ' (' . $ast['student_no'] . ')'); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div id="nt_history_banner" class="hidden p-3 rounded-xl bg-emerald-50/80 border border-emerald-200 text-xs text-emerald-950"></div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Academic Year</label>
                    <select name="academic_year" id="nt_academic_year" data-active-ay="<?php echo htmlspecialchars($current_ay); ?>" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <?php foreach ($ay_options as $ay) { ?>
                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                AY <?php echo htmlspecialchars($ay); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Semester</label>
                    <select name="semester" id="nt_semester" onchange="syncStudentProgressionPreview()" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <option value="1st">1st Semester</option>
                        <option value="2nd" <?php echo ($active_enrollment && $active_enrollment['semester'] === '1st') ? 'selected' : ''; ?>>2nd Semester</option>
                        <option value="Summer">Summer Term</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Year Level for Term</label>
                    <select name="year_level" id="nt_year_level" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <?php
                        $yl_choices = ['Propaedeutic Stage', 'First Year', 'Second Year', 'Third Year', 'Fourth Year', 'Completed'];
                        foreach ($yl_choices as $ylc) {
                        ?>
                            <option value="<?php echo htmlspecialchars($ylc); ?>" <?php echo $ylc === $suggested_next_yl ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ylc); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Academic Standing</label>
                    <select name="academic_standing" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <option value="Regular">Regular</option>
                        <option value="Irregular">Irregular</option>
                        <option value="Probationary">Probationary</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Program for Term</label>
                <select name="program" id="nt_program" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                    <option value="Bachelor of Arts in Philosophy" <?php echo ($student_profile && $student_profile['default_program'] === 'Bachelor of Arts in Philosophy') ? 'selected' : ''; ?>>Bachelor of Arts in Philosophy</option>
                    <option value="Propaedeutic" <?php echo ($student_profile && $student_profile['default_program'] === 'Propaedeutic') ? 'selected' : ''; ?>>Propaedeutic</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Term Remarks (Optional)</label>
                <input type="text" name="remarks" placeholder="e.g. Regular semester load" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeNewTermModal()" class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 border-0 cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl text-xs font-bold bg-indigo-600 text-white hover:bg-indigo-700 border-0 cursor-pointer shadow-sm">
                    <i class="fa-solid fa-check mr-1"></i> Create Semester Enrollment
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($is_registrar) { ?>
<!-- Migrated Add Seminarian Modal (with PSGC Cascading Address Selector) -->
<div class="<?php echo htmlspecialchars('modal ' . global_modal_overlay_classes(), ENT_QUOTES, 'UTF-8'); ?>" data-global-modal="1" id="addApplicantModal" aria-hidden="true">
    <div class="modal-panel add-seminarian-panel w-full max-w-4xl max-h-[90vh] flex flex-col bg-white rounded-xl shadow-lg overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="addApplicantTitle">
        <!-- Fixed Modal Header -->
        <div class="modal-header shrink-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between">
            <div class="modal-title text-lg font-extrabold text-[#59443F] flex items-center gap-2.5" id="addApplicantTitle">
                <i class="fas fa-plus-circle text-[#59443F]"></i>
                <span>Add Seminarian</span>
            </div>
            <button type="button" class="modal-close inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 transition cursor-pointer" data-modal-close aria-label="Close">&times;</button>
        </div>

        <!-- Flex Form Wrapper: Separates Scrollable Body from Sticky Footer -->
        <form method="post" class="flex-1 min-h-0 flex flex-col overflow-hidden !gap-0 m-0 p-0 bg-transparent border-0 shadow-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

            <?php
            $inputCls = 'w-full px-3.5 py-2.5 text-sm text-gray-900 bg-white border !border-gray-200 !rounded-md focus:outline-none focus:!ring-2 focus:!ring-blue-500 focus:!border-blue-500 transition';
            $disabledSelectCls = 'w-full px-3.5 py-2.5 text-sm text-gray-900 bg-white border !border-gray-200 !rounded-md focus:outline-none focus:!ring-2 focus:!ring-blue-500 focus:!border-blue-500 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed transition';
            $labelCls = 'block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5';
            $sectionCls = 'bg-white border border-gray-200/80 rounded-xl shadow-xs p-5';
            $sectionHeaderCls = 'flex items-center gap-2 text-xs font-extrabold text-slate-900 uppercase tracking-wider mb-4 pb-2.5 border-b border-gray-100';
            ?>

            <!-- Scrollable Body -->
            <div class="flex-1 min-h-0 overflow-y-auto p-6 space-y-6 bg-gray-50/70">
                <!-- 1. Academic Year Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-calendar-alt text-slate-700"></i>
                        <span>Academic Year</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="<?php echo $labelCls; ?>">Academic Year</label>
                            <select name="academic_year" class="<?php echo $inputCls; ?>">
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 2. Seminary Information Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-church text-slate-700"></i>
                        <span>Seminary Information</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="<?php echo $labelCls; ?>">Year Level</label>
                            <select name="year_level" required class="<?php echo $inputCls; ?>">
                                <option value="">Select</option>
                                <option value="Propaedeutic Stage">Propaedeutic Stage</option>
                                <option value="First Year">First Year</option>
                                <option value="Second Year">Second Year</option>
                                <option value="Third Year">Third Year</option>
                                <option value="Fourth Year">Fourth Year</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Program</label>
                            <select name="program" class="<?php echo $inputCls; ?>">
                                <option value="">Select</option>
                                <option value="Propaedeutic">Propaedeutic</option>
                                <option value="Bachelor of Arts in Philosophy">Bachelor of Arts in Philosophy</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Admission Date</label>
                            <input type="date" name="admission_date" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Semester</label>
                            <select name="enrollment_semester" required class="<?php echo $inputCls; ?>">
                                <option value="1st">First Semester</option>
                                <option value="2nd">Second Semester</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="<?php echo $labelCls; ?>">Formation Status</label>
                            <select name="formation_status" class="<?php echo $inputCls; ?>">
                                <option value="">Select</option>
                                <option value="Active" selected>Active</option>
                                <option value="Under Evaluation">Under Evaluation</option>
                                <option value="Graduated">Graduated</option>
                                <option value="Completed">Completed</option>
                                <option value="Dismissed">Dismissed</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 3. Personal Information Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-user text-slate-700"></i>
                        <span>Personal Information</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="<?php echo $labelCls; ?>">First Name</label>
                            <input type="text" name="first_name" placeholder="First Name" required class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Middle Name</label>
                            <input type="text" name="middle_name" placeholder="Middle Name" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Last Name</label>
                            <input type="text" name="last_name" placeholder="Last Name" required class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Suffix</label>
                            <input type="text" name="suffix" placeholder="Suffix (e.g. Jr., III)" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label for="add_dob" class="<?php echo $labelCls; ?>">Date of Birth</label>
                            <input type="date" id="add_dob" name="date_of_birth" required class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label for="add_age" class="<?php echo $labelCls; ?>">Age</label>
                            <input type="number" id="add_age" name="age" placeholder="Auto-calculated from Date of Birth" min="0" readonly class="w-full px-3.5 py-2.5 text-sm border !border-gray-200 !rounded-md !bg-gray-100 !text-gray-500 cursor-not-allowed focus:outline-none">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Place of Birth</label>
                            <input type="text" name="place_of_birth" placeholder="Place of Birth" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Civil Status</label>
                            <input type="text" name="civil_status" placeholder="Civil Status" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Nationality</label>
                            <input type="text" name="nationality" placeholder="Nationality" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Religion</label>
                            <input type="text" name="religion" placeholder="Religion" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Parish Priest Name</label>
                            <input type="text" name="parish_priest" placeholder="e.g. Rev. Fr. Juan dela Cruz" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Parish / Chaplaincy</label>
                            <input type="text" name="parish_chaplaincy" placeholder="e.g. St. Joseph Cathedral Parish" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Contact Number</label>
                            <input type="text" name="contact_number" placeholder="Contact Number" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Email Address</label>
                            <input type="email" name="email_address" placeholder="Email Address" class="<?php echo $inputCls; ?>">
                        </div>
                    </div>
                </div>

                <!-- 4. Address Information Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-map-marker-alt text-slate-700"></i>
                        <span>Address Information</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="<?php echo $labelCls; ?>">Permanent Address</label>
                            <textarea name="permanent_address" rows="2" placeholder="Permanent Address" class="<?php echo $inputCls; ?>"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="<?php echo $labelCls; ?>">Present Address</label>
                            <textarea name="present_address" rows="2" placeholder="Present Address" class="<?php echo $inputCls; ?>"></textarea>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Region</label>
                            <select name="region" id="psgc_region" class="<?php echo $inputCls; ?>">
                                <option value="">Loading regions...</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Province</label>
                            <select name="province" id="psgc_province" disabled class="<?php echo $disabledSelectCls; ?>">
                                <option value="">Select Region first</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">City / Municipality</label>
                            <select name="municipality" id="psgc_city" disabled class="<?php echo $disabledSelectCls; ?>">
                                <option value="">Select Province first</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Barangay</label>
                            <select name="barangay" id="psgc_barangay" disabled class="<?php echo $disabledSelectCls; ?>">
                                <option value="">Select City first</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">ZIP Code</label>
                            <input type="text" name="zip_code" placeholder="ZIP Code" class="<?php echo $inputCls; ?>">
                        </div>
                    </div>
                </div>

                <!-- 5. Family Information Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-users text-slate-700"></i>
                        <span>Family Information</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="<?php echo $labelCls; ?>">Father's Name</label>
                            <input type="text" name="father_name" placeholder="Father's Name" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Father's Occupation</label>
                            <input type="text" name="father_occupation" placeholder="Father's Occupation" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Mother's Name</label>
                            <input type="text" name="mother_name" placeholder="Mother's Name" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Mother's Occupation</label>
                            <input type="text" name="mother_occupation" placeholder="Mother's Occupation" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Guardian Name</label>
                            <input type="text" name="guardian_name" placeholder="Guardian Name" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Guardian Contact Number</label>
                            <input type="text" name="guardian_contact_number" placeholder="Guardian Contact Number" class="<?php echo $inputCls; ?>">
                        </div>
                    </div>
                </div>

                <!-- 6. Educational Information Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-graduation-cap text-slate-700"></i>
                        <span>Educational Information</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="<?php echo $labelCls; ?>">Previous School</label>
                            <input type="text" name="previous_school" placeholder="Previous School" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Educational Attainment</label>
                            <input type="text" name="educational_attainment" placeholder="Educational Attainment" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Year Graduated</label>
                            <input type="text" name="year_graduated" placeholder="Year Graduated" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Academic Records</label>
                            <input type="text" name="academic_records" placeholder="Academic Records" class="<?php echo $inputCls; ?>">
                        </div>
                    </div>
                </div>

                <!-- 7. Account Access Card -->
                <div class="<?php echo $sectionCls; ?>">
                    <div class="<?php echo $sectionHeaderCls; ?>">
                        <i class="fas fa-key text-slate-700"></i>
                        <span>Account Access</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="<?php echo $labelCls; ?>">Username (optional)</label>
                            <input type="text" name="username" placeholder="Auto-generate if empty" class="<?php echo $inputCls; ?>">
                        </div>
                        <div>
                            <label class="<?php echo $labelCls; ?>">Password (optional)</label>
                            <input type="text" name="password" placeholder="Default: pass" class="<?php echo $inputCls; ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sticky Action Bar Footer -->
            <div class="w-full bg-white border-t border-gray-100 p-4 flex justify-end items-center gap-3 shrink-0">
                <button type="button"
                        data-modal-close
                        class="px-4 py-2 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 transition cursor-pointer"
                        style="width: auto;">
                    Cancel
                </button>
                <button type="submit"
                        name="add_applicant"
                        class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-[#59443F] hover:bg-[#463430] rounded-md shadow-xs focus:outline-none focus:ring-2 focus:ring-blue-500 transition cursor-pointer"
                        style="width: auto;">
                    <i class="fas fa-check"></i> Save Seminarian
                </button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<script>
(function () {
    const openBtn = document.getElementById('openAddApplicantModal');
    const modal = document.getElementById('addApplicantModal');
    if (!openBtn || !modal) return;

    // Portal modal directly to document.body to escape parent stacking contexts (e.g. .capstone-animate-in / #appShell)
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    function openModal() {
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        document.body.classList.add('overflow-hidden');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        const first = modal.querySelector('input, select, textarea, button');
        if (first) first.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        openBtn.focus();
    }

    openBtn.addEventListener('click', openModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    // Automatic Age Calculation from Date of Birth
    const dobInput = document.getElementById('add_dob');
    const ageInput = document.getElementById('add_age');
    function syncAgeFromDob() {
        if (!dobInput || !ageInput) return;
        const val = (dobInput.value || '').trim();
        if (!val) {
            ageInput.value = '';
            return;
        }
        const parts = val.split('-');
        if (parts.length !== 3) {
            ageInput.value = '';
            return;
        }
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const d = parseInt(parts[2], 10);
        const birth = new Date(y, m, d);
        if (isNaN(birth.getTime())) {
            ageInput.value = '';
            return;
        }
        const today = new Date();
        if (birth > today) {
            ageInput.value = '';
            return;
        }
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
            age--;
        }
        ageInput.value = age >= 0 ? String(age) : '';
    }
    if (dobInput) {
        dobInput.addEventListener('change', syncAgeFromDob);
        dobInput.addEventListener('input', syncAgeFromDob);
        syncAgeFromDob();
    }
})();

// Cascading PSGC Philippine Address Selector (Region -> Province -> City/Municipality -> Barangay)
(function () {
    const regSelect = document.getElementById('psgc_region');
    const provSelect = document.getElementById('psgc_province');
    const citySelect = document.getElementById('psgc_city');
    const brgySelect = document.getElementById('psgc_barangay');
    if (!regSelect || !provSelect || !citySelect || !brgySelect) return;

    function resetSelect(selectEl, placeholder, disabled) {
        selectEl.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        selectEl.appendChild(opt);
        selectEl.disabled = !!disabled;
    }

    function populateSelect(selectEl, items, placeholder) {
        resetSelect(selectEl, placeholder, false);
        const sorted = (items || []).slice().sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        sorted.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.name;
            opt.setAttribute('data-code', item.code);
            if (item.isNcrVirtual) opt.setAttribute('data-ncr', '1');
            opt.textContent = item.name;
            selectEl.appendChild(opt);
        });
    }

    fetch('https://psgc.gitlab.io/api/regions')
        .then(r => r.json())
        .then(data => populateSelect(regSelect, data, 'Select Region'))
        .catch(() => resetSelect(regSelect, 'Unable to load regions', false));

    regSelect.addEventListener('change', function () {
        const selectedOpt = regSelect.options[regSelect.selectedIndex];
        const regionCode = selectedOpt ? selectedOpt.getAttribute('data-code') : '';

        resetSelect(provSelect, regionCode ? 'Loading provinces...' : 'Select Region first', true);
        resetSelect(citySelect, 'Select Province first', true);
        resetSelect(brgySelect, 'Select City first', true);

        if (!regionCode) return;

        fetch('https://psgc.gitlab.io/api/regions/' + encodeURIComponent(regionCode) + '/provinces')
            .then(r => r.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    populateSelect(provSelect, data, 'Select Province');
                } else {
                    populateSelect(provSelect, [{ code: regionCode, name: 'Metro Manila (NCR)', isNcrVirtual: true }], 'Select Province');
                    provSelect.selectedIndex = 1;
                    provSelect.dispatchEvent(new Event('change'));
                }
            })
            .catch(() => resetSelect(provSelect, 'Failed to load provinces', true));
    });

    provSelect.addEventListener('change', function () {
        const selectedOpt = provSelect.options[provSelect.selectedIndex];
        const provinceCode = selectedOpt ? selectedOpt.getAttribute('data-code') : '';
        const isNcr = selectedOpt && selectedOpt.getAttribute('data-ncr') === '1';

        resetSelect(citySelect, provinceCode ? 'Loading cities/municipalities...' : 'Select Province first', true);
        resetSelect(brgySelect, 'Select City first', true);

        if (!provinceCode) return;

        const url = isNcr
            ? ('https://psgc.gitlab.io/api/regions/' + encodeURIComponent(provinceCode) + '/cities-municipalities')
            : ('https://psgc.gitlab.io/api/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities');

        fetch(url)
            .then(r => r.json())
            .then(data => populateSelect(citySelect, data, 'Select City / Municipality'))
            .catch(() => resetSelect(citySelect, 'Failed to load cities', true));
    });

    citySelect.addEventListener('change', function () {
        const selectedOpt = citySelect.options[citySelect.selectedIndex];
        const cityCode = selectedOpt ? selectedOpt.getAttribute('data-code') : '';

        resetSelect(brgySelect, cityCode ? 'Loading barangays...' : 'Select City first', true);

        if (!cityCode) return;

        fetch('https://psgc.gitlab.io/api/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays')
            .then(r => r.json())
            .then(data => populateSelect(brgySelect, data, 'Select Barangay'))
            .catch(() => resetSelect(brgySelect, 'Failed to load barangays', true));
    });
})();

function syncStudentProgressionPreview() {
    const sel = document.getElementById('nt_student_id');
    const semSel = document.getElementById('nt_semester');
    const ylSel = document.getElementById('nt_year_level');
    const aySel = document.getElementById('nt_academic_year');
    const progSel = document.getElementById('nt_program');
    const banner = document.getElementById('nt_history_banner');
    if (!sel || !semSel || !ylSel || !banner) return;

    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        banner.classList.add('hidden');
        return;
    }

    const lastAy = opt.getAttribute('data-last-ay') || '';
    const lastSem = opt.getAttribute('data-last-sem') || '';
    const lastYl = opt.getAttribute('data-last-yl') || 'Propaedeutic Stage';
    const nextYl = opt.getAttribute('data-next-yl') || lastYl;
    const propCompleted = opt.getAttribute('data-prop-completed') === '1';
    const targetSem = semSel.value || '1st';

    if (aySel) {
        const activeAy = aySel.getAttribute('data-active-ay') || '';
        const lockFutureAy = (lastAy !== '' && lastAy === activeAy && lastSem === '1st');
        Array.from(aySel.options).forEach(function (ayOpt) {
            ayOpt.disabled = lockFutureAy && ayOpt.value > activeAy;
        });
        if (lockFutureAy && aySel.value > activeAy) {
            aySel.value = activeAy;
        }
    }

    let recommendedYl = lastYl;
    if (targetSem === '1st' && lastSem === '2nd') {
        if (lastYl === 'Propaedeutic Stage' && !propCompleted) {
            recommendedYl = 'Propaedeutic Stage';
        } else {
            recommendedYl = nextYl;
        }
    }
    ylSel.value = recommendedYl;

    // Disable advancing past Propaedeutic Stage until both 1st and 2nd Semesters of Propaedeutic Stage are completed
    Array.from(ylSel.options).forEach(function (ylOpt) {
        const val = ylOpt.value;
        const isPhilo = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'].indexOf(val) !== -1;
        ylOpt.disabled = (lastYl === 'Propaedeutic Stage' && !propCompleted && isPhilo);
    });

    if (progSel) {
        progSel.value = (recommendedYl === 'Propaedeutic Stage')
            ? 'Propaedeutic'
            : 'Bachelor of Arts in Philosophy';
    }

    banner.classList.remove('hidden');
    if (lastYl === 'Propaedeutic Stage' && !propCompleted && lastSem !== '2nd') {
        banner.innerHTML =
            '<div class="font-bold text-amber-900"><i class="fa-solid fa-lock mr-1"></i> Propaedeutic Stage In Progress:</div>' +
            '<div class="mt-0.5 text-amber-800">Last enrolled in <strong>AY ' + (lastAy || 'Current') + ' (' + (lastSem || '1st') + ' Semester) — Propaedeutic Stage</strong>. ' +
            'First Year (Philosophy I) is locked until both 1st and 2nd Semesters of Propaedeutic Stage are completed.</div>';
    } else if (lastAy) {
        banner.innerHTML =
            '<div class="font-bold text-emerald-900"><i class="fa-solid fa-shield-halved mr-1"></i> Previous Term Protected in History:</div>' +
            '<div class="mt-0.5 text-emerald-800">Last enrolled in <strong>AY ' + lastAy + ' (' + (lastSem || '1st') + ' Semester) — ' + lastYl + '</strong>. ' +
            'Submitting will append a new term record for <strong>' + recommendedYl + ' (' + targetSem + ' Sem)</strong> without overwriting past semester records.</div>';
    } else {
        banner.innerHTML =
            '<div class="font-bold text-emerald-900"><i class="fa-solid fa-sparkles mr-1"></i> First Term Enrollment:</div>' +
            '<div class="mt-0.5 text-emerald-800">Creates the initial semester record in <code>enrollments</code> for this seminarian.</div>';
    }
}

function openNewTermModal(studentId) {
    if (studentId && studentId > 0) {
        const sel = document.getElementById('nt_student_id');
        if (sel) sel.value = String(studentId);
    }
    syncStudentProgressionPreview();
    const modal = document.getElementById('newTermEnrollmentModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeNewTermModal() {
    const modal = document.getElementById('newTermEnrollmentModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('open_enroll') === '1') {
        const sid = parseInt(params.get('student_id') || '0', 10);
        openNewTermModal(sid);
    }

    // Dynamic AJAX (Fetch API) Roster Filtering
    const filterForm = document.getElementById('rosterFilterForm');
    const searchInput = document.getElementById('rosterFilterSearch');
    const semSelect = document.getElementById('rosterFilterSem');
    const ylSelect = document.getElementById('rosterFilterYl');
    const tableBody = document.getElementById('rosterTableBody');

    if (filterForm && tableBody) {
        let activeFetchController = null;

        function debounce(fn, delay) {
            let timerId = null;
            return function (...args) {
                if (timerId) {
                    clearTimeout(timerId);
                }
                timerId = setTimeout(function () {
                    fn.apply(this, args);
                }, delay);
            };
        }

        function fetchFilteredRoster() {
            const qVal = searchInput ? searchInput.value.trim() : '';
            const semVal = semSelect ? semSelect.value : '';
            const ylVal = ylSelect ? ylSelect.value : '';

            const currentUrlParams = new URLSearchParams(window.location.search);
            const perPage = currentUrlParams.get('per_page') || '';

            const queryParams = new URLSearchParams();
            if (qVal !== '') queryParams.set('q', qVal);
            if (semVal !== '') queryParams.set('semester', semVal);
            if (ylVal !== '') queryParams.set('year_level', ylVal);
            if (perPage !== '') queryParams.set('per_page', perPage);

            // Update URL query string cleanly without reloading the page
            const nextQueryString = queryParams.toString();
            const nextUrl = window.location.pathname + (nextQueryString ? '?' + nextQueryString : '');
            window.history.replaceState({}, '', nextUrl);

            // Add AJAX marker for PHP backend detection
            queryParams.set('ajax_filter', '1');

            if (activeFetchController) {
                activeFetchController.abort();
            }
            activeFetchController = new AbortController();

            fetch('enrollment_records.php?' + queryParams.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: activeFetchController.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }

                    const totalRows = response.headers.get('X-Total-Rows');
                    const fromRow = response.headers.get('X-From-Row');
                    const toRow = response.headers.get('X-To-Row');

                    if (totalRows !== null) {
                        const snapEl = document.getElementById('rosterSnapshotCount');
                        const totalEl = document.getElementById('rosterTotalCount');
                        if (snapEl) snapEl.textContent = totalRows;
                        if (totalEl) totalEl.textContent = totalRows;
                    }
                    if (fromRow !== null) {
                        const fromEl = document.getElementById('rosterFromCount');
                        if (fromEl) fromEl.textContent = fromRow;
                    }
                    if (toRow !== null) {
                        const toEl = document.getElementById('rosterToCount');
                        if (toEl) toEl.textContent = toRow;
                    }

                    return response.text();
                })
                .then(function (html) {
                    tableBody.innerHTML = html;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    console.error('Error fetching filtered roster:', err);
                });
        }

        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fetchFilteredRoster();
        });

        if (searchInput) {
            const debouncedSearch = debounce(fetchFilteredRoster, 300);
            searchInput.addEventListener('input', debouncedSearch);
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    fetchFilteredRoster();
                }
            });
        }

        [semSelect, ylSelect].forEach(function (selectEl) {
            if (selectEl) {
                selectEl.addEventListener('change', fetchFilteredRoster);
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>

