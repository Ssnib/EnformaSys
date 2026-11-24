<?php
require_once __DIR__ . '/../includes/session_fix.php';
if (!enformasys_is_production()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

require_roles(['Registrar', 'Dean', 'Rector']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && function_exists('enformasys_invalidate_dashboard_cache')) {
    enformasys_invalidate_dashboard_cache();
}
$role = (string)($_SESSION['role'] ?? '');
$is_registrar = $role === 'Registrar';
$show_archived = isset($_GET['archived']) && $_GET['archived'] === '1' && $is_registrar;

$current_ay = get_current_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$enroll_students = [];
$res_enroll_students = $conn->query(
    "SELECT s.id AS seminarian_id, u.username, COALESCE(up.full_name, u.username) AS full_name,
            s.program, s.year_level, s.academic_status,
            le.year_level AS last_enr_year_level, le.academic_year AS last_enr_ay, le.semester AS last_enr_sem
     FROM seminarians s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN enrollments le ON le.id = (
         SELECT e2.id FROM enrollments e2
         WHERE e2.student_id = s.id
         ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC
         LIMIT 1
     )
     ORDER BY full_name ASC
     LIMIT 500"
);
if ($res_enroll_students) {
    while ($r = $res_enroll_students->fetch_assoc()) {
        $last_yl = !empty($r['last_enr_year_level']) ? (string)$r['last_enr_year_level'] : (string)($r['year_level'] ?? '');
        $norm_last_yl = normalize_year_level($last_yl);
        $next_yl = get_next_year_level($norm_last_yl);

        $r['last_year_level'] = $norm_last_yl;
        $r['last_academic_year'] = (string)($r['last_enr_ay'] ?? '');
        $r['last_semester'] = (string)($r['last_enr_sem'] ?? '');
        $r['next_year_level'] = $next_yl;
        $r['next_year_level_display'] = get_year_level_display($next_yl);
        $r['last_year_level_display'] = get_year_level_display($norm_last_yl);

        $enroll_students[] = $r;
    }
}

// Fetch total count for badge
$total_applicants = $conn->query("SELECT COUNT(*) as count FROM applicants")->fetch_assoc()['count'];

if (isset($_POST['enroll_existing_seminarian'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can enroll seminarians.";
    } else {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die("CSRF token validation failed.");
        }

        $sid = (int)($_POST['existing_student_id'] ?? 0);
        $ay = trim((string)($_POST['academic_year'] ?? ''));
        $sem = trim((string)($_POST['semester'] ?? ''));
        $target_yl_raw = trim((string)($_POST['target_year_level'] ?? ''));

        if ($sid <= 0) {
            $error = "Please select a seminarian.";
        } elseif ($ay === '' || !in_array($ay, $ay_options, true)) {
            $error = "Invalid Academic Year.";
        } elseif (!in_array($sem, ['1st', '2nd'], true)) {
            $error = "Invalid semester.";
        } else {
            $stmt_st = $conn->prepare("SELECT s.id as student_id, s.program, s.year_level, s.academic_status FROM seminarians s WHERE s.id = ? LIMIT 1");
            $stmt_st->bind_param('i', $sid);
            $stmt_st->execute();
            $st = $stmt_st->get_result()->fetch_assoc();

            if (!$st) {
                $error = "Seminarian not found.";
            } else {
                // Fetch student's most recent enrollment record to find last completed year level & semester
                $last_rec = get_student_last_enrollment_record($sid);
                $last_yl = !empty($last_rec['year_level']) ? $last_rec['year_level'] : (string)($st['year_level'] ?? '');
                $last_sem = trim((string)($last_rec['semester'] ?? ''));
                $norm_last_yl = normalize_year_level($last_yl);

                // Semester-aware auto-promotion calculation:
                // 1. If enrolling for 2nd Semester: Target Year Level MUST remain exactly the same as current stage
                // 2. If enrolling for 1st Semester: Only auto-promote if completed 2nd Semester of previous year
                $calculated_next_yl = calculate_target_year_level($norm_last_yl, $last_sem, $sem);

                // Auto-determine target year level if empty, or normalize submitted value
                if ($sem === '2nd') {
                    $target_yl = $norm_last_yl;
                } else {
                    $target_yl = ($target_yl_raw === '') ? $calculated_next_yl : normalize_year_level($target_yl_raw);
                }

                $allowed_year_levels = [
                    'Propaedeutic Stage',
                    'First Year',
                    'Second Year',
                    'Third Year',
                    'Fourth Year',
                    'Completed',
                ];

                if (!in_array($target_yl, $allowed_year_levels, true)) {
                    $error = "Invalid target year level.";
                } elseif ($target_yl === 'Completed') {
                    // Seminarian completed 4th year and is being marked as Completed / Graduated
                    $stmt_grad = $conn->prepare("
                        UPDATE seminarians 
                        SET year_level = 'Completed',
                            formation_stage = 'Completed',
                            enrollment_status = 'Graduated',
                            formation_status = 'Graduated',
                            is_graduate = 1,
                            completed_at = CURDATE(),
                            academic_year_completed = ?,
                            program_completed = COALESCE(NULLIF(program, ''), 'Bachelor of Arts in Philosophy')
                        WHERE id = ?
                    ");
                    $stmt_grad->bind_param('si', $ay, $sid);
                    if ($stmt_grad->execute()) {
                        $conn->query("UPDATE students SET is_graduate = 1, graduated_at = COALESCE(graduated_at, CURDATE()), formation_status = 'Graduated' WHERE id = " . (int)$sid);
                        if (function_exists('registrar_sync_and_migrate')) {
                            $conn->query("UPDATE enrollments SET is_locked = 1, enrollment_status = 'Graduated' WHERE student_id = " . (int)$sid . " AND academic_year = '" . $conn->real_escape_string($ay) . "'");
                            registrar_sync_and_migrate($conn, true);
                        }
                        $success = "Seminarian successfully completed formation and marked as Graduated for AY " . htmlspecialchars($ay) . ".";
                        log_activity((int)($_SESSION['user'] ?? 0), "Promoted seminarian ID " . $sid . " to Completed/Graduated (" . $ay . ")");
                    } else {
                        $error = "Failed to update completion status.";
                    }
                } else {
                    $p = trim((string)($st['program'] ?? ''));
                    $status = trim((string)($st['academic_status'] ?? 'Regular'));

                    // Auto-transition program: Propaedeutic Stage -> Bachelor of Arts in Philosophy
                    if ($target_yl !== 'Propaedeutic Stage' && ($p === 'Propaedeutic' || $p === '')) {
                        $p = 'Bachelor of Arts in Philosophy';
                    } elseif ($target_yl === 'Propaedeutic Stage') {
                        $p = 'Propaedeutic';
                    }

                    $guard_err = function_exists('registrar_validate_enrollment_guardrails')
                        ? registrar_validate_enrollment_guardrails($conn, $sid, $ay, $sem, $target_yl, $p)
                        : null;
                    if ($guard_err !== null) {
                        $error = $guard_err;
                    } else {

                    // Look up curriculum version matching program and term
                    $stmt_cv = $conn->prepare("
                        SELECT cv.id 
                        FROM curriculum_versions cv
                        LEFT JOIN academic_years ay ON ay.id = cv.academic_year_id
                        WHERE cv.program = ? 
                          AND cv.is_active = 1 
                          AND cv.archived_at IS NULL
                        ORDER BY (ay.label = ?) DESC, cv.is_current DESC, cv.id DESC 
                        LIMIT 1
                    ");
                    $stmt_cv->bind_param('ss', $p, $ay);
                    $stmt_cv->execute();
                    $cv_row = $stmt_cv->get_result()->fetch_assoc();
                    $curriculum_version_id = (int)($cv_row['id'] ?? 0);

                    if ($curriculum_version_id <= 0) {
                        $stmt_cv_fb = $conn->prepare("SELECT id FROM curriculum_versions WHERE program = ? AND is_active = 1 AND archived_at IS NULL ORDER BY is_current DESC, id DESC LIMIT 1");
                        $stmt_cv_fb->bind_param('s', $p);
                        $stmt_cv_fb->execute();
                        $cv_fb_row = $stmt_cv_fb->get_result()->fetch_assoc();
                        $curriculum_version_id = (int)($cv_fb_row['id'] ?? 0);
                    }

                    if ($curriculum_version_id <= 0) {
                        $error = "No active curriculum version found for " . htmlspecialchars($p) . ".";
                    } else {
                        $items = [];
                        $stmt_items = $conn->prepare(
                            "SELECT cs.subject_id\n"
                            . "FROM curriculum_subjects cs\n"
                            . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
                            . "WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = ? AND cs.is_active = 1 AND s.archived_at IS NULL"
                        );
                        $stmt_items->bind_param('iss', $curriculum_version_id, $target_yl, $sem);
                        $stmt_items->execute();
                        $res = $stmt_items->get_result();
                        while ($r = $res->fetch_assoc()) {
                            $items[] = (int)($r['subject_id'] ?? 0);
                        }
                        $items = array_values(array_filter($items, static fn ($v) => $v > 0));

                        if (count($items) === 0) {
                            $error = "No curriculum subjects found for " . htmlspecialchars($target_yl) . " (" . htmlspecialchars($sem) . " Semester).";
                        } else {
                            $selected = $items;
                            if ($status === 'Irregular') {
                                $picked = array_map('intval', (array)($_POST['subject_ids'] ?? []));
                                $picked = array_values(array_filter($picked, static fn ($v) => $v > 0));
                                if (count($picked) > 0) {
                                    $selected = array_values(array_intersect($picked, $items));
                                }
                            }

                            if (count($selected) === 0) {
                                $error = "No subjects selected.";
                            } else {
                                $conn->begin_transaction();
                                try {
                                    $stmt_before = $conn->prepare("SELECT COUNT(*) as c FROM student_enrolled_subjects WHERE student_id = ? AND academic_year = ? AND semester = ?");
                                    $stmt_before->bind_param('iss', $sid, $ay, $sem);
                                    $stmt_before->execute();
                                    $before_count = (int)($stmt_before->get_result()->fetch_assoc()['c'] ?? 0);

                                    $stmt_update = $conn->prepare("UPDATE seminarians SET current_academic_year = ?, year_level = ?, program = ?, enrollment_status = 'Enrolled' WHERE id = ?");
                                    $stmt_update->bind_param('sssi', $ay, $target_yl, $p, $sid);
                                    $stmt_update->execute();

                                    $stmt_sem_sub = $conn->prepare("INSERT IGNORE INTO semester_subjects (subject_id, semester, academic_year, year_level) VALUES (?, ?, ?, ?)");
                                    $stmt_enr = $conn->prepare("INSERT IGNORE INTO student_enrolled_subjects (student_id, subject_id, curriculum_version_id, program, year_level, semester, academic_year, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Enrolled')");

                                    foreach ($selected as $sub_id) {
                                        $stmt_sem_sub->bind_param('isss', $sub_id, $sem, $ay, $target_yl);
                                        $stmt_sem_sub->execute();

                                        $stmt_enr->bind_param('iiissss', $sid, $sub_id, $curriculum_version_id, $p, $target_yl, $sem, $ay);
                                        $stmt_enr->execute();
                                    }

                                    $stmt_after = $conn->prepare("SELECT COUNT(*) as c FROM student_enrolled_subjects WHERE student_id = ? AND academic_year = ? AND semester = ?");
                                    $stmt_after->bind_param('iss', $sid, $ay, $sem);
                                    $stmt_after->execute();
                                    $after_count = (int)($stmt_after->get_result()->fetch_assoc()['c'] ?? 0);
                                    $added = max(0, $after_count - $before_count);

                                    $conn->commit();
                                    if (function_exists('registrar_sync_and_migrate')) {
                                        $conn->query("UPDATE enrollments SET is_locked = 1, enrollment_status = IF(enrollment_status = 'Enrolled', 'Completed', enrollment_status) WHERE student_id = " . (int)$sid . " AND NOT (academic_year = '" . $conn->real_escape_string($ay) . "' AND semester = '" . $conn->real_escape_string($sem) . "')");
                                        registrar_sync_and_migrate($conn, true);
                                    }
                                    $success = "Enrollment updated. Seminarian promoted to " . htmlspecialchars(get_year_level_display($target_yl)) . ". Added subjects: " . (int)$added . " • Total this term: " . (int)$after_count;
                                    log_activity((int)($_SESSION['user'] ?? 0), "Enrolled existing seminarian ID " . $sid . " (" . $ay . " " . $sem . ", " . $target_yl . ")");
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $error = "Failed to enroll subjects: " . $e->getMessage();
                                }
                            }
                        }
                    }
                    }
                }
            }
        }
    }
}

if (isset($_POST['enroll_applicant'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can enroll seminarians.";
    } else {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    try {
        $applicant_id = $_POST['applicant_id'];
        
        // Get applicant details
        $stmt = $conn->prepare("SELECT full_name, status, contact, address FROM applicants WHERE id = ?");
        $stmt->bind_param("i", $applicant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $applicant = $res->fetch_assoc();
        
        if ($applicant && ($applicant['status'] == 'Pending' || empty($applicant['status']))) {
            $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $applicant['full_name']));
            if (empty($username)) {
                $username = "user" . rand(1000, 9999);
            }
            $password = 'pass'; // Default password
            $role_student = 'Student';

            // Check if username already exists
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt_check->bind_param("s", $username);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();
            
            if ($res_check->num_rows > 0) {
                $username .= rand(10, 99); // Simple collision resolution
            }

            // Create user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt_user = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt_user->bind_param("sss", $username, $hashed_password, $role_student);
            
            if ($stmt_user->execute()) {
                $user_id = $conn->insert_id;

                // Create seminarian
                $stmt_sem = $conn->prepare("INSERT INTO seminarians (user_id) VALUES (?)");
                $stmt_sem->bind_param("i", $user_id);
                if ($stmt_sem->execute()) {
                    $seminarian_id = (int)$conn->insert_id;
                    if ($seminarian_id > 0) {
                        create_default_seminarian_assignments($conn, $seminarian_id, (int)($_SESSION['user'] ?? 0));
                    }
                    // Update applicant status + link to created user
                    $stmt_upd = $conn->prepare("UPDATE applicants SET status = 'Enrolled', user_id = ? WHERE id = ?");
                    $stmt_upd->bind_param("ii", $user_id, $applicant_id);
                    $stmt_upd->execute();

                    $app_contact = (string)($applicant['contact'] ?? '');
                    $app_address = (string)($applicant['address'] ?? '');
                    $stmt_prof = $conn->prepare("INSERT IGNORE INTO user_profiles (user_id, full_name, contact, address) VALUES (?, ?, ?, ?)");
                    $stmt_prof->bind_param("isss", $user_id, $applicant['full_name'], $app_contact, $app_address);
                    $stmt_prof->execute();
                    
                    // Log Activity
                    log_activity($_SESSION['user'], "Enrolled applicant: " . $applicant['full_name']);
                    if (function_exists('registrar_sync_and_migrate')) {
                        registrar_sync_and_migrate($conn, true);
                    }

                    $success = "Applicant " . $applicant['full_name'] . " enrolled successfully as " . $username;
                } else {
                    $error = "Failed to create seminarian record: " . $stmt_sem->error;
                }
            } else {
                $error = "Failed to create user: " . $stmt_user->error;
            }
        } else {
            $error = $applicant ? "Applicant already enrolled." : "Applicant not found.";
        }
    } catch (Exception $e) {
        $error = "An error occurred during enrollment: " . $e->getMessage();
    }
    }
}

if (isset($_POST['archive_applicant'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can archive applicants.";
    } else {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $applicant_id = (int)($_POST['applicant_id'] ?? 0);
    if ($applicant_id > 0) {
        $stmt = $conn->prepare("SELECT full_name, status FROM applicants WHERE id = ?");
        $stmt->bind_param('i', $applicant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $applicant = $res->fetch_assoc();

        if (!$applicant) {
            $error = "Applicant not found.";
        } elseif ($applicant['status'] === 'Enrolled') {
            $error = "Cannot archive an enrolled student.";
        } else {
            $stmt_arc = $conn->prepare("UPDATE applicants SET archived_at = NOW() WHERE id = ?");
            $stmt_arc->bind_param('i', $applicant_id);
            $stmt_arc->execute();
            log_activity($_SESSION['user'], "Archived applicant: " . $applicant['full_name']);
            $success = "Applicant archived successfully.";
        }
    }
    }
}

if (isset($_POST['restore_applicant'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can restore applicants.";
    } else {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $applicant_id = (int)($_POST['applicant_id'] ?? 0);
    if ($applicant_id > 0) {
        $stmt = $conn->prepare("UPDATE applicants SET archived_at = NULL WHERE id = ?");
        $stmt->bind_param('i', $applicant_id);
        $stmt->execute();
        log_activity($_SESSION['user'], "Restored applicant ID: " . $applicant_id);
        $success = "Applicant restored successfully.";
    }
    }
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
if (($filter === 'pending' || $filter === 'enrolled') && !$is_registrar) {
    $filter = '';
}
if ($filter === 'pending' || $filter === 'enrolled') {
    $show_archived = false;
}

$list_filter_ay = trim((string)($_GET['academic_year'] ?? ''));
$list_filter_year_level = trim((string)($_GET['year_level'] ?? ''));
$allowed_year_levels = [
    'Propaedeutic Stage',
    'First Year',
    'Second Year',
    'Third Year',
    'Fourth Year',
    'Completed',
];
if ($show_archived || ($filter !== '' && $filter !== 'enrolled')) {
    $list_filter_ay = '';
    $list_filter_year_level = '';
} else {
    if ($list_filter_ay !== '' && !in_array($list_filter_ay, $ay_options, true)) {
        $list_filter_ay = '';
    }
    if ($list_filter_year_level !== '' && !in_array($list_filter_year_level, $allowed_year_levels, true)) {
        $list_filter_year_level = '';
    }
    if ($filter === 'enrolled' && !$is_registrar) {
        $list_filter_ay = '';
        $list_filter_year_level = '';
    }
}

$query = "SELECT a.*, u.username AS current_username, p.full_name AS profile_full_name, p.contact AS profile_contact, p.address AS profile_address,
                 p.parish_priest, p.parish_chaplaincy,
                 sem.id AS seminarian_id, sem.year_level AS sem_year_level, sem.is_graduate AS sem_is_graduate
          FROM applicants a
          LEFT JOIN users u ON (a.user_id = u.id OR (a.user_id IS NULL AND a.status = 'Enrolled' AND u.username = a.full_name))
          LEFT JOIN user_profiles p ON p.user_id = u.id
          LEFT JOIN seminarians sem ON sem.user_id = u.id";

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$allowed_per_page = [5, 10, 20, 50];
$per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowed_per_page, true)
    ? (int)$_GET['per_page']
    : 10;
$offset = ($page - 1) * $per_page;

if ($show_archived) {
    $query .= " WHERE a.archived_at IS NOT NULL";
} else {
    $query .= " WHERE a.archived_at IS NULL";
}

if ($filter == 'pending' && !$show_archived) {
    $query .= " AND (a.status = 'Pending' OR a.status IS NULL)";
}
if ($filter == 'enrolled' && !$show_archived) {
    $query .= " AND a.status = 'Enrolled'";
}
if (!$show_archived && ($filter === '' || $filter === 'enrolled') && $list_filter_ay !== '') {
    $query .= " AND a.academic_year = '" . $conn->real_escape_string($list_filter_ay) . "'";
}
if (!$show_archived && ($filter === '' || $filter === 'enrolled') && $list_filter_year_level !== '') {
    $query .= " AND sem.year_level = '" . $conn->real_escape_string($list_filter_year_level) . "'";
}

$count_sql = "SELECT COUNT(*) as c FROM applicants a";
if (!$show_archived && ($filter === '' || $filter === 'enrolled') && $list_filter_year_level !== '') {
    $count_sql .= " LEFT JOIN users u ON (a.user_id = u.id OR (a.user_id IS NULL AND a.status = 'Enrolled' AND u.username = a.full_name))";
    $count_sql .= " LEFT JOIN seminarians sem ON sem.user_id = u.id";
}
if ($show_archived) {
    $count_sql .= " WHERE a.archived_at IS NOT NULL";
} else {
    $count_sql .= " WHERE a.archived_at IS NULL";
}
if ($filter == 'pending' && !$show_archived) {
    $count_sql .= " AND (a.status = 'Pending' OR a.status IS NULL)";
}
if ($filter == 'enrolled' && !$show_archived) {
    $count_sql .= " AND a.status = 'Enrolled'";
}
if (!$show_archived && ($filter === '' || $filter === 'enrolled') && $list_filter_ay !== '') {
    $count_sql .= " AND a.academic_year = '" . $conn->real_escape_string($list_filter_ay) . "'";
}
if (!$show_archived && ($filter === '' || $filter === 'enrolled') && $list_filter_year_level !== '') {
    $count_sql .= " AND sem.year_level = '" . $conn->real_escape_string($list_filter_year_level) . "'";
}
$total_rows = (int)($conn->query($count_sql)->fetch_assoc()['c'] ?? 0);
$total_pages = (int)max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$query .= " ORDER BY a.id DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$result = $conn->query($query);

function applicants_build_url(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return 'applicants.php' . ($qs ? ('?' . $qs) : '');
}
?>
<?php
$pageTitle = ($filter === 'pending' || $filter === 'enrolled') ? 'Enrollment' : 'Manage Seminarian';
$activeNav = ($filter === 'pending' || $filter === 'enrolled') ? 'enrollment' : 'applicants';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <!-- Page Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin: 0; font-size: 1.5rem; font-weight: 800;">
                        <i class="fas <?php echo ($filter === 'pending' || $filter === 'enrolled') ? 'fa-check-circle' : 'fa-users'; ?>" style="color: #0f766e; margin-right: 0.35rem;"></i>
                        <?php echo ($role === 'Rector') ? 'Seminarians' : (($filter === 'pending' || $filter === 'enrolled') ? 'Enroll Seminarians' : 'Manage Seminarians'); ?>
                    </h1>
                    <div style="color: #64748b; font-size: 0.85rem; font-weight: 600;">
                        <?php echo ($role === 'Rector') ? 'Seminarian directory, records, and profile details (Read-Only).' : 'Admissions, enrollment records, and profile management.'; ?>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <span class="status-badge status-info" id="enrollmentTotalBadge" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                        Total: <?php echo (int)$total_rows; ?> <?php echo $filter === 'pending' ? 'pending' : ($filter === 'enrolled' ? 'enrolled' : ''); ?> seminarians
                    </span>
                    <?php if (!$show_archived && $is_registrar) { ?>
                        <a href="enrollment_records.php?open_enroll=1" class="action-btn-secondary" style="padding: 0.45rem 0.9rem; font-size: 0.825rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
                            <i class="fas fa-user-check"></i> Go to Enrollment
                        </a>
                    <?php } ?>
                </div>
            </div>

            <?php if (isset($success)) { ?>
                <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>
            <?php if (isset($error)) { ?>
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-red-600"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php } ?>

            <!-- Minimalist Toolbar & Filter Card -->
            <div class="minimal-card">
                <div class="minimal-toolbar">
                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="applicantSearch" placeholder="Quick search seminarians..." autocomplete="off">
                        <kbd class="search-kbd">/</kbd>
                    </div>

                    <?php if ($filter === '' && !$show_archived) { ?>
                        <form method="get" id="manageSeminarianFilterForm" class="capstone-filter-bar" style="display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0; background: none; border: none; box-shadow: none;">
                            <input type="hidden" name="per_page" value="<?php echo (int)$per_page; ?>">
                            <select name="year_level" class="minimal-select" title="Year Level">
                                <option value="">All Year Levels</option>
                                <?php foreach ($allowed_year_levels as $yl) { ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $yl === $list_filter_year_level ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yl); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>
                    <?php } ?>

                    <?php if ($is_registrar && $filter === 'enrolled') { ?>
                        <form method="get" id="enrolledFilterForm" class="capstone-filter-bar" style="display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0; background: none; border: none; box-shadow: none;">
                            <input type="hidden" name="filter" value="enrolled">
                            <select name="year_level" class="minimal-select" title="Year Level">
                                <option value="">All Year Levels</option>
                                <?php foreach ($allowed_year_levels as $yl) { ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $yl === $list_filter_year_level ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yl); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>
                    <?php } ?>
                </div>

                <div style="overflow-x: auto;">
                    <table id="applicantTable" class="minimal-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Contact</th>
                                <th>Background</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="enrollmentTbody">
                            <?php while ($row = $result->fetch_assoc()) {
                                $display_name = trim((string)($row['profile_full_name'] ?? ''));
                                if ($display_name === '') {
                                    $display_name = trim((string)($row['current_username'] ?? ''));
                                }
                                if ($display_name === '') {
                                    $display_name = (string)($row['full_name'] ?? '');
                                }
                                $display_contact = trim((string)($row['profile_contact'] ?? ''));
                                if ($display_contact === '') {
                                    $display_contact = (string)($row['contact'] ?? '');
                                }
                                $display_address = trim((string)($row['profile_address'] ?? ''));
                                if ($display_address === '') {
                                    $display_address = (string)($row['address'] ?? '');
                                }
                            ?>
                            <tr class="applicant-row" style="<?php echo $show_archived ? 'opacity: 0.65;' : ''; ?>">
                                <td data-label="ID"><?php echo $row['id']; ?></td>
                                <td data-label="Full Name" class="applicant-name">
                                    <strong><?php echo htmlspecialchars($display_name); ?></strong><br>
                                    <small style="color: var(--text-secondary);"><?php echo htmlspecialchars($row['gender']); ?> • <?php echo htmlspecialchars($row['age']); ?> yrs</small>
                                </td>
                                <td data-label="Contact">
                                    <span style="color: var(--primary);"><?php echo htmlspecialchars($display_contact); ?></span><br>
                                    <small style="color: var(--text-secondary);"><?php echo htmlspecialchars($display_address); ?></small>
                                </td>
                                <td data-label="Background">
                                    <div style="font-size: 0.8rem; max-width: 200px;">
                                        <strong>Edu:</strong> <?php echo htmlspecialchars($row['academic_history']); ?><br>
                                        <strong>Parent:</strong> <?php echo htmlspecialchars($row['parent_info']); ?>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <?php
                                        $is_graduate = ((int)($row['sem_is_graduate'] ?? 0) === 1) || (trim((string)($row['sem_year_level'] ?? '')) === 'Completed');
                                        $status_text = $row['status'] ?: 'Pending';
                                        $badge_class = $status_text === 'Enrolled' ? 'badge-success' : 'badge-warning';
                                        if ($is_graduate) {
                                            $status_text = 'Graduated';
                                            $badge_class = 'badge-primary';
                                        }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                                </td>
                                <td data-label="Actions">
                                    <div style="display: flex; gap: 0.5rem;">
                                        <?php if ($is_registrar && $filter == 'pending' && ($row['status'] == 'Pending' || empty($row['status']))) { ?>
                                        <form method="post" style="box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="applicant_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="enroll_applicant" title="Enroll Student" style="padding: 0.4rem; width: 32px; background: var(--accent);"><i class="fas fa-user-plus"></i></button>
                                        </form>
                                        <?php } ?>
                                        <?php if ((int)($row['seminarian_id'] ?? 0) > 0) { ?>
                                            <a href="seminarian_profile.php?seminarian_id=<?php echo (int)$row['seminarian_id']; ?>" title="View Profile" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem; width: 32px; border-radius: 0.375rem; background: var(--primary); color: #fff; text-decoration: none;">
                                                <i class="fas fa-id-card"></i>
                                            </a>
                                            <?php if ($is_registrar) { ?>
                                            <a href="enrollment_records.php?student_id=<?php echo (int)$row['seminarian_id']; ?>" title="Enrollment History & Split View" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem; width: 32px; border-radius: 0.375rem; background: #4f46e5; color: #fff; text-decoration: none;" onmouseover="this.style.background='#4338ca'" onmouseout="this.style.background='#4f46e5'">
                                                <i class="fas fa-layer-group"></i>
                                            </a>
                                            <a href="subject_enrollment.php?seminarian_id=<?php echo (int)$row['seminarian_id']; ?>" title="Enroll Subjects" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem; width: 32px; border-radius: 0.375rem; background: #0f766e; color: #fff; text-decoration: none;" onmouseover="this.style.background='#0d9488'" onmouseout="this.style.background='#0f766e'">
                                                <i class="fas fa-book-bookmark"></i>
                                            </a>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <button type="button" class="viewApplicantBtn" title="View Details"
                                                data-id="<?php echo (int)$row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($display_name); ?>"
                                                data-dob="<?php echo htmlspecialchars($row['dob']); ?>"
                                                data-age="<?php echo htmlspecialchars($row['age']); ?>"
                                                data-gender="<?php echo htmlspecialchars($row['gender']); ?>"
                                                data-contact="<?php echo htmlspecialchars($display_contact); ?>"
                                                data-address="<?php echo htmlspecialchars($display_address); ?>"
                                                data-academic="<?php echo htmlspecialchars($row['academic_history']); ?>"
                                                data-parent="<?php echo htmlspecialchars($row['parent_info']); ?>"
                                                data-parish-priest="<?php echo htmlspecialchars($row['parish_priest'] ?? ''); ?>"
                                                data-parish-chaplaincy="<?php echo htmlspecialchars($row['parish_chaplaincy'] ?? ''); ?>"
                                                data-status="<?php echo htmlspecialchars($row['status'] ?: 'Pending'); ?>"
                                                style="padding: 0.4rem; width: 32px; background: var(--primary);">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        <?php } ?>

                                        <?php if ($is_registrar && $show_archived) { ?>
                                            <form method="post" style="box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="applicant_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" name="restore_applicant" title="Restore" style="padding: 0.4rem; width: 32px; background: var(--accent);" onclick="return confirm('Restore this applicant?');"><i class="fas fa-undo"></i></button>
                                            </form>
                                        <?php } elseif ($is_registrar) { ?>
                                            <?php if ($row['status'] === 'Enrolled') { ?>
                                                <button type="button" title="Cannot archive enrolled" disabled style="padding: 0.4rem; width: 32px; background: #cbd5e1; cursor: not-allowed;"><i class="fas fa-archive"></i></button>
                                            <?php } else { ?>
                                                <form method="post" style="box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="applicant_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" name="archive_applicant" title="Archive" style="padding: 0.4rem; width: 32px; background: var(--danger);" onclick="return confirm('Archive this applicant?');"><i class="fas fa-archive"></i></button>
                                                </form>
                                            <?php } ?>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php
                    $showing_from = $total_rows > 0 ? ($offset + 1) : 0;
                    $showing_to = min($total_rows, $offset + $per_page);
                ?>
                <div id="enrollmentPagination" class="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 border-t border-slate-200/80 bg-slate-50/60">
                    <div class="flex items-center gap-3 flex-wrap text-xs font-semibold text-slate-600">
                        <span id="paginationSummaryText">
                            Showing <strong class="font-extrabold text-slate-900"><?php echo (int)$showing_from; ?></strong>
                            to <strong class="font-extrabold text-slate-900"><?php echo (int)$showing_to; ?></strong>
                            of <strong class="font-extrabold text-slate-900"><?php echo (int)$total_rows; ?></strong> seminarians
                        </span>
                        <span class="text-slate-300">|</span>
                        <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                            <span>Rows per page:</span>
                            <select
                                onchange="window.location.href = this.value"
                                class="px-2 py-1 text-xs font-bold text-slate-800 bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500/20 cursor-pointer"
                            >
                                <?php foreach ($allowed_per_page as $pp_opt) { ?>
                                    <option
                                        value="<?php echo htmlspecialchars(applicants_build_url(['per_page' => $pp_opt, 'page' => 1])); ?>"
                                        <?php echo $per_page === $pp_opt ? 'selected' : ''; ?>
                                    >
                                        <?php echo (int)$pp_opt; ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </label>
                    </div>

                    <div class="flex items-center gap-1.5 flex-wrap" aria-label="Pagination">
                        <?php if ($page > 1) { ?>
                            <a
                                href="<?php echo htmlspecialchars(applicants_build_url(['page' => 1])); ?>"
                                class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline"
                                title="First Page"
                            >
                                <i class="fa-solid fa-angles-left"></i>
                            </a>
                            <a
                                href="<?php echo htmlspecialchars(applicants_build_url(['page' => $page - 1])); ?>"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline"
                            >
                                <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                <span>Prev</span>
                            </a>
                        <?php } else { ?>
                            <span class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">
                                <i class="fa-solid fa-angles-left"></i>
                            </span>
                            <span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">
                                <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                <span>Prev</span>
                            </span>
                        <?php } ?>

                        <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            if ($start > 1) {
                                echo '<a href="' . htmlspecialchars(applicants_build_url(['page' => 1])) . '" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline">1</a>';
                                if ($start > 2) {
                                    echo '<span class="px-1 text-xs font-bold text-slate-400">&hellip;</span>';
                                }
                            }
                            for ($p = $start; $p <= $end; $p++) {
                                $is_active = ($p === $page);
                                if ($is_active) {
                                    echo '<span aria-current="page" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-extrabold rounded-lg bg-teal-700 text-white shadow-xs">' . (int)$p . '</span>';
                                } else {
                                    echo '<a href="' . htmlspecialchars(applicants_build_url(['page' => $p])) . '" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline">' . (int)$p . '</a>';
                                }
                            }
                            if ($end < $total_pages) {
                                if ($end < $total_pages - 1) {
                                    echo '<span class="px-1 text-xs font-bold text-slate-400">&hellip;</span>';
                                }
                                echo '<a href="' . htmlspecialchars(applicants_build_url(['page' => $total_pages])) . '" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline">' . (int)$total_pages . '</a>';
                            }
                        ?>

                        <?php if ($page < $total_pages) { ?>
                            <a
                                href="<?php echo htmlspecialchars(applicants_build_url(['page' => $page + 1])); ?>"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline"
                            >
                                <span>Next</span>
                                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                            </a>
                            <a
                                href="<?php echo htmlspecialchars(applicants_build_url(['page' => $total_pages])); ?>"
                                class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 transition no-underline"
                                title="Last Page"
                            >
                                <i class="fa-solid fa-angles-right"></i>
                            </a>
                        <?php } else { ?>
                            <span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">
                                <span>Next</span>
                                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                            </span>
                            <span class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200/60 bg-slate-100 text-slate-400 cursor-not-allowed">
                                <i class="fa-solid fa-angles-right"></i>
                            </span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <?php if (!$show_archived && $is_registrar) { ?>
            <div class="modal" id="enrollExistingModal" aria-hidden="true">
                <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="enrollExistingTitle">
                    <div class="modal-header">
                        <div class="modal-title" id="enrollExistingTitle"><i class="fas fa-user-check"></i> Enroll Existing Seminarian</div>
                        <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" id="enrollExistingForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="existing_student_id" id="existing_student_id" value="">

                            <div class="field">
                                <label>Seminarian (search)</label>
                                <input type="search" id="existing_student_search" inputmode="search" autocomplete="off" placeholder="Type student name...">
                                <div id="existing_student_results" style="display:none; position:relative;"></div>
                            </div>

                            <!-- Dynamic Year-Level Progression Banner -->
                            <div id="existing_progression_card" style="display:none; margin-bottom: 1.1rem; padding: 0.85rem 1rem; background: rgba(37,99,235,0.06); border: 1px solid rgba(37,99,235,0.2); border-radius: 10px;">
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.35rem;">
                                    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--primary, #2563eb);">
                                        <i class="fas fa-layer-group"></i> Auto-Promotion Sequence
                                    </span>
                                    <span id="existing_progression_badge" style="font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:12px; background:#2563eb; color:#fff;">
                                        Auto-Promoted
                                    </span>
                                </div>
                                <div id="existing_progression_text" style="font-size:0.875rem; color:#1e293b; line-height:1.45;">
                                </div>
                            </div>

                            <div class="form-grid two">
                                <div class="field">
                                    <label>Academic Year</label>
                                    <select name="academic_year" required>
                                        <?php foreach ($ay_options as $ay) { ?>
                                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($ay); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Semester</label>
                                    <select name="semester" id="existing_semester" required>
                                        <option value="1st">First Semester</option>
                                        <option value="2nd">Second Semester</option>
                                    </select>
                                </div>
                            </div>

                            <div class="field">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                                    <label style="margin:0;">Target Year Level</label>
                                    <span id="auto_promo_hint" style="display:none; font-size:0.75rem; font-weight:600; color:#16a34a;">
                                        <i class="fas fa-check-circle"></i> Auto-selected next level
                                    </span>
                                </div>
                                <select name="target_year_level" id="existing_target_year_level" required>
                                    <option value="">Select Target Year Level</option>
                                    <option value="Propaedeutic Stage">Propaedeutic Stage</option>
                                    <option value="First Year">1st Year (First Year)</option>
                                    <option value="Second Year">2nd Year (Second Year)</option>
                                    <option value="Third Year">3rd Year (Third Year)</option>
                                    <option value="Fourth Year">4th Year (Fourth Year)</option>
                                    <option value="Completed">Completed / Graduated</option>
                                </select>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="modal-close" data-modal-close>Cancel</button>
                                <button type="submit" name="enroll_existing_seminarian">Enroll</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php } ?>

    <div class="modal" id="applicantViewModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="applicantViewTitle">
            <div class="modal-header">
                <div class="modal-title" id="applicantViewTitle"><i class="fas fa-eye"></i> Applicant Details</div>
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Full Name</div>
                        <div id="viewApplicantName" style="font-size: 1.15rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Status</div>
                        <div id="viewApplicantStatus" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">DOB</div>
                        <div id="viewApplicantDob" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Age / Gender</div>
                        <div id="viewApplicantAgeGender" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Contact</div>
                        <div id="viewApplicantContact" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Address</div>
                        <div id="viewApplicantAddress" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Parish / Chaplaincy</div>
                        <div id="viewApplicantParish" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Parish Priest</div>
                        <div id="viewApplicantPriest" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Academic History</div>
                        <div id="viewApplicantAcademic" style="font-size: 0.95rem; font-weight: 800; color: #0f172a; white-space: pre-wrap;"></div>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Parent/Guardian Info</div>
                        <div id="viewApplicantParent" style="font-size: 0.95rem; font-weight: 800; color: #0f172a; white-space: pre-wrap;"></div>
                    </div>
                </div>

                <div class="modal-actions" style="margin-top: 1.25rem;">
                    <button type="button" class="modal-close" data-modal-close>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const modal = document.getElementById('applicantViewModal');
        if (!modal) return;

        const nameEl = document.getElementById('viewApplicantName');
        const statusEl = document.getElementById('viewApplicantStatus');
        const dobEl = document.getElementById('viewApplicantDob');
        const ageGenderEl = document.getElementById('viewApplicantAgeGender');
        const contactEl = document.getElementById('viewApplicantContact');
        const addressEl = document.getElementById('viewApplicantAddress');
        const parishEl = document.getElementById('viewApplicantParish');
        const priestEl = document.getElementById('viewApplicantPriest');
        const academicEl = document.getElementById('viewApplicantAcademic');
        const parentEl = document.getElementById('viewApplicantParent');

        function openModal(btn) {
            nameEl.textContent = btn.getAttribute('data-name') || '';
            statusEl.textContent = btn.getAttribute('data-status') || '';
            dobEl.textContent = btn.getAttribute('data-dob') || '';
            const age = btn.getAttribute('data-age') || '';
            const gender = btn.getAttribute('data-gender') || '';
            ageGenderEl.textContent = (age ? age + ' yrs' : '') + (age && gender ? ' • ' : '') + gender;
            contactEl.textContent = btn.getAttribute('data-contact') || '';
            addressEl.textContent = btn.getAttribute('data-address') || '';
            if (parishEl) parishEl.textContent = btn.getAttribute('data-parish-chaplaincy') || '—';
            if (priestEl) priestEl.textContent = btn.getAttribute('data-parish-priest') || '—';
            academicEl.textContent = btn.getAttribute('data-academic') || '';
            parentEl.textContent = btn.getAttribute('data-parent') || '';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            const closeBtn = modal.querySelector('[data-modal-close]');
            if (closeBtn) closeBtn.focus();
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.viewApplicantBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn);
            });
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });
    })();

    (function () {
        const openBtn = document.getElementById('openEnrollExistingModal');
        const modal = document.getElementById('enrollExistingModal');
        const input = document.getElementById('existing_student_search');
        const resultsHost = document.getElementById('existing_student_results');
        const idInput = document.getElementById('existing_student_id');
        const semesterSelect = document.getElementById('existing_semester');
        const yearLevelSelect = document.getElementById('existing_target_year_level');
        const form = document.getElementById('enrollExistingForm');
        const progCard = document.getElementById('existing_progression_card');
        const progBadge = document.getElementById('existing_progression_badge');
        const progText = document.getElementById('existing_progression_text');
        const autoHint = document.getElementById('auto_promo_hint');
        if (!openBtn || !modal || !input || !resultsHost || !idInput || !semesterSelect || !yearLevelSelect || !form) return;

        const students = <?php echo json_encode(array_map(static function ($s) {
            $id = (int)($s['seminarian_id'] ?? 0);
            $name = (string)($s['full_name'] ?? $s['username'] ?? '');
            $program = (string)($s['program'] ?? '');
            $currentYl = (string)($s['year_level'] ?? '');
            $lastYl = (string)($s['last_year_level'] ?? $currentYl);
            $nextYl = (string)($s['next_year_level'] ?? '');
            $lastYlDisplay = (string)($s['last_year_level_display'] ?? $lastYl);
            $nextYlDisplay = (string)($s['next_year_level_display'] ?? $nextYl);
            $meta = trim($program) . ' • Current: ' . trim($currentYl);
            $display = trim($name) === '' ? '' : ($name . ' (' . $meta . ')');
            return [
                'id' => $id,
                'name' => $name,
                'display' => $display,
                'current_year_level' => $currentYl,
                'last_year_level' => $lastYl,
                'last_year_level_display' => $lastYlDisplay,
                'next_year_level' => $nextYl,
                'next_year_level_display' => $nextYlDisplay,
                'last_academic_year' => (string)($s['last_academic_year'] ?? ''),
                'last_semester' => (string)($s['last_semester'] ?? ''),
            ];
        }, $enroll_students)); ?>;

        const progressionMap = {
            'Propaedeutic Stage': 'First Year',
            'First Year': 'Second Year',
            'Second Year': 'Third Year',
            'Third Year': 'Fourth Year',
            'Fourth Year': 'Completed',
            'Completed': 'Completed'
        };

        const yearLevelLabels = {
            'Propaedeutic Stage': 'Propaedeutic Stage',
            'First Year': '1st Year (First Year)',
            'Second Year': '2nd Year (Second Year)',
            'Third Year': '3rd Year (Third Year)',
            'Fourth Year': '4th Year (Fourth Year)',
            'Completed': 'Completed / Graduated'
        };

        let currentSelectedStudent = null;

        function clearResults() {
            resultsHost.innerHTML = '';
            resultsHost.style.display = 'none';
        }

        function applySemesterAwareProgression(st) {
            if (!st) return;
            const selectedSem = (semesterSelect.value || '1st').trim();
            const fromLevel = st.last_year_level || st.current_year_level || 'Propaedeutic Stage';
            const fromLabel = st.last_year_level_display || yearLevelLabels[fromLevel] || fromLevel;
            const lastSem = (st.last_semester || '').trim();

            let toLevel = fromLevel;
            let isPromotion = false;

            // Conditional check on selected 'Semester' dropdown:
            // 1. If 2nd Semester: Target Year Level MUST remain exactly the same as current stage/year level.
            // 2. If 1st Semester: ONLY auto-promote if completed 2nd Semester of previous year.
            if (selectedSem === '2nd') {
                toLevel = fromLevel;
                isPromotion = false;
            } else if (selectedSem === '1st') {
                if (lastSem === '2nd') {
                    toLevel = st.next_year_level || progressionMap[fromLevel] || 'First Year';
                    isPromotion = true;
                } else {
                    toLevel = fromLevel;
                    isPromotion = false;
                }
            }

            // Automatically pre-populate target year level dropdown
            yearLevelSelect.value = toLevel;

            // Display dynamic auto-promotion notification card
            if (progCard && progText) {
                progCard.style.display = 'block';

                if (selectedSem === '2nd') {
                    if (autoHint) {
                        autoHint.style.display = 'inline';
                        autoHint.innerHTML = '<i class="fas fa-arrow-right"></i> Same Level (2nd Semester)';
                        autoHint.style.color = '#0284c7';
                    }
                    if (progBadge) {
                        progBadge.textContent = 'Continuing Stage (2nd Sem)';
                        progBadge.style.background = '#0284c7';
                    }
                    let info = `<div><strong>${st.name}</strong> is enrolling for <strong>2nd Semester</strong>. Target Year Level remains <strong>${fromLabel}</strong>.</div>`;
                    if (st.last_academic_year) {
                        info += `<div style="font-size:0.75rem; color:#64748b; margin-top:3px;"><i class="fas fa-history"></i> Current enrollment: AY ${st.last_academic_year} (${lastSem ? lastSem + ' Semester' : '1st Semester'})</div>`;
                    }
                    progText.innerHTML = info;
                } else if (isPromotion) {
                    const toLabel = st.next_year_level_display || yearLevelLabels[toLevel] || toLevel;
                    if (toLevel === 'Completed') {
                        if (autoHint) {
                            autoHint.style.display = 'inline';
                            autoHint.innerHTML = '<i class="fas fa-graduation-cap"></i> Graduation';
                            autoHint.style.color = '#059669';
                        }
                        if (progBadge) {
                            progBadge.textContent = 'Completed / Graduation';
                            progBadge.style.background = '#059669';
                        }
                        progText.innerHTML = `<div><strong>${st.name}</strong> completed 2nd Semester of <strong>${fromLabel}</strong>. Advancing to <strong>Completed / Graduated</strong> will record their formal graduation.</div>`;
                    } else {
                        if (autoHint) {
                            autoHint.style.display = 'inline';
                            autoHint.innerHTML = '<i class="fas fa-check-circle"></i> Auto-selected next level';
                            autoHint.style.color = '#16a34a';
                        }
                        if (progBadge) {
                            progBadge.textContent = 'Auto-Promoted';
                            progBadge.style.background = '#2563eb';
                        }
                        let info = `<div><strong>${st.name}</strong> completed 2nd Semester of <strong>${fromLabel}</strong> &rarr; automatically advanced to <strong>${toLabel}</strong> for 1st Semester.</div>`;
                        if (st.last_academic_year) {
                            info += `<div style="font-size:0.75rem; color:#64748b; margin-top:3px;"><i class="fas fa-history"></i> Previous enrollment: AY ${st.last_academic_year} (${lastSem} Semester)</div>`;
                        }
                        progText.innerHTML = info;
                    }
                } else {
                    if (autoHint) {
                        autoHint.style.display = 'inline';
                        autoHint.innerHTML = '<i class="fas fa-info-circle"></i> Retained level';
                        autoHint.style.color = '#475569';
                    }
                    if (progBadge) {
                        progBadge.textContent = 'Current Level (1st Sem)';
                        progBadge.style.background = '#475569';
                    }
                    let info = `<div><strong>${st.name}</strong> has not completed 2nd Semester (last recorded: ${lastSem ? '<strong>' + lastSem + ' Semester</strong>' : '<em>None</em>'}). Target Year Level remains <strong>${fromLabel}</strong>.</div>`;
                    if (st.last_academic_year) {
                        info += `<div style="font-size:0.75rem; color:#64748b; margin-top:3px;"><i class="fas fa-history"></i> Last enrollment record: AY ${st.last_academic_year} (${lastSem || '1st'} Semester)</div>`;
                    }
                    progText.innerHTML = info;
                }
            }
        }

        function renderResults(items) {
            if (items.length === 0) {
                clearResults();
                return;
            }

            const box = document.createElement('div');
            box.style.position = 'absolute';
            box.style.left = '0';
            box.style.right = '0';
            box.style.top = '0.25rem';
            box.style.background = 'var(--card)';
            box.style.border = '1px solid rgba(255,255,255,0.12)';
            box.style.borderRadius = '12px';
            box.style.boxShadow = '0 10px 30px rgba(0,0,0,0.35)';
            box.style.zIndex = '60';
            box.style.maxHeight = '260px';
            box.style.overflowY = 'auto';

            items.slice(0, 12).forEach((st) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.style.display = 'block';
                btn.style.width = '100%';
                btn.style.textAlign = 'left';
                btn.style.padding = '10px 12px';
                btn.style.border = 'none';
                btn.style.background = 'transparent';
                btn.style.color = 'inherit';
                btn.style.cursor = 'pointer';
                btn.textContent = st.display;
                btn.addEventListener('click', () => {
                    currentSelectedStudent = st;
                    input.value = st.display;
                    idInput.value = String(st.id);
                    applySemesterAwareProgression(st);
                    clearResults();
                });
                box.appendChild(btn);
            });

            resultsHost.innerHTML = '';
            resultsHost.appendChild(box);
            resultsHost.style.display = 'block';
        }

        function getFilteredStudents(query) {
            const q = (query || '').trim().toLowerCase();
            return students.filter((st) => {
                if (!st || !st.display) return false;
                if (q === '') return false;
                return st.display.toLowerCase().includes(q);
            });
        }

        function openModal() {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            currentSelectedStudent = null;
            idInput.value = '';
            input.value = '';
            yearLevelSelect.value = '';
            if (progCard) progCard.style.display = 'none';
            if (autoHint) autoHint.style.display = 'none';
            clearResults();
            input.focus();
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            currentSelectedStudent = null;
            clearResults();
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

        input.addEventListener('input', () => {
            currentSelectedStudent = null;
            idInput.value = '';
            if (progCard) progCard.style.display = 'none';
            if (autoHint) autoHint.style.display = 'none';
            renderResults(getFilteredStudents(input.value));
        });
        input.addEventListener('focus', () => {
            if (!idInput.value) renderResults(getFilteredStudents(input.value));
        });
        semesterSelect.addEventListener('change', () => {
            if (currentSelectedStudent) {
                applySemesterAwareProgression(currentSelectedStudent);
            }
        });
        document.addEventListener('click', (e) => {
            if (e.target === input || resultsHost.contains(e.target)) return;
            clearResults();
        });

        form.addEventListener('submit', (e) => {
            if (!idInput.value) {
                e.preventDefault();
                alert('Please select a seminarian from the search results.');
            }
        });
    })();

    (function () {
        const isEnrolledView = <?php echo $filter === 'enrolled' ? 'true' : 'false'; ?>;
        const isRegistrar = <?php echo $is_registrar ? 'true' : 'false'; ?>;
        if (!isEnrolledView || !isRegistrar || !window.CapstoneAjax) return;

        const form = document.getElementById('enrolledFilterForm');
        const tbody = document.getElementById('enrollmentTbody');
        const badge = document.getElementById('enrollmentTotalBadge');
        const pager = document.getElementById('enrollmentPagination');
        if (!form || !tbody || !badge) return;

        function buildUrl(params) {
            const sp = new URLSearchParams();
            sp.set('filter', 'enrolled');
            if (params.academic_year) sp.set('academic_year', params.academic_year);
            if (params.year_level) sp.set('year_level', params.year_level);
            if (params.page) sp.set('page', params.page);
            return 'applicants.php?' + sp.toString();
        }

        function renderRows(rows) {
            tbody.innerHTML = '';
            if (!rows || rows.length === 0) {
                const tr = document.createElement('tr');
                tr.className = 'applicant-row';
                const td = document.createElement('td');
                td.colSpan = 6;
                td.style.color = 'var(--text-secondary)';
                td.textContent = 'No enrolled seminarians found.';
                tr.appendChild(td);
                tbody.appendChild(tr);
                return;
            }
            rows.forEach(function (r) {
                const tr = document.createElement('tr');
                tr.className = 'applicant-row';
                const profileLink = r.seminarian_id > 0
                    ? ('<a href="seminarian_profile.php?seminarian_id=' + r.seminarian_id + '" title="View Profile" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem; width: 32px; border-radius: 0.375rem; background: var(--primary); color: #fff; text-decoration: none;"><i class="fas fa-id-card"></i></a>'<?php if ($is_registrar) { ?> + '<a href="subject_enrollment.php?seminarian_id=' + r.seminarian_id + '" title="Enroll Subjects" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem; width: 32px; border-radius: 0.375rem; background: #0f766e; color: #fff; text-decoration: none;"><i class="fas fa-book-bookmark"></i></a>'<?php } ?>)
                    : ('<button type="button" title="No profile" disabled style="padding: 0.4rem; width: 32px; background: #cbd5e1; cursor: not-allowed;"><i class="fas fa-id-card"></i></button>');
                tr.innerHTML =
                    '<td data-label="ID">' + (r.id || '') + '</td>' +
                    '<td data-label="Full Name" class="applicant-name"><strong>' + (r.display_name || '') + '</strong><br><small style="color: var(--text-secondary);">' + (r.gender || '') + ' • ' + (r.age || '') + ' yrs</small></td>' +
                    '<td data-label="Contact"><span style="color: var(--primary);">' + (r.contact || '') + '</span><br><small style="color: var(--text-secondary);">' + (r.address || '') + '</small></td>' +
                    '<td data-label="Background"><div style="font-size: 0.8rem; max-width: 200px;"><strong>Edu:</strong> ' + (r.academic_history || '') + '<br><strong>Parent:</strong> ' + (r.parent_info || '') + '</div></td>' +
                    '<td data-label="Status"><span class="badge ' + (r.badge_class || 'badge-success') + '">' + (r.status_text || 'Enrolled') + '</span></td>' +
                    '<td data-label="Actions"><div style="display: flex; gap: 0.5rem;">' + profileLink + '</div></td>';
                tbody.appendChild(tr);
            });
        }

        function renderPagination(params, page, totalPages) {
            if (!pager) return;
            if (!totalPages || totalPages <= 1) {
                pager.style.display = 'none';
                return;
            }
            pager.style.display = 'flex';

            const left = pager.children[0];
            const right = pager.children[1];
            if (left) left.textContent = 'Page ' + page + ' of ' + totalPages;

            if (right) {
                right.innerHTML = '';

                function addLink(label, p) {
                    const a = document.createElement('a');
                    a.className = 'back-link';
                    a.style.margin = '0';
                    a.href = buildUrl({ academic_year: params.academic_year, year_level: params.year_level, page: p });
                    a.textContent = label;
                    right.appendChild(a);
                }

                if (page > 1) addLink('Prev', page - 1);

                const start = Math.max(1, page - 2);
                const end = Math.min(totalPages, page + 2);
                for (let p = start; p <= end; p++) {
                    if (p === page) {
                        const s = document.createElement('span');
                        s.className = 'badge badge-primary';
                        s.style.padding = '0.5rem 0.8rem';
                        s.textContent = String(p);
                        right.appendChild(s);
                    } else {
                        addLink(String(p), p);
                    }
                }

                if (page < totalPages) addLink('Next', page + 1);
            }
        }

        async function load(params) {
            const data = await window.CapstoneAjax.get('ajax.php', {
                action: 'enrollment_enrolled_list',
                academic_year: params.academic_year || '',
                year_level: params.year_level || '',
                page: params.page || 1
            });
            badge.textContent = 'Total: ' + (data.total_rows || 0) + ' enrolled applicants';
            renderRows(data.rows || []);
            renderPagination(params, data.page || 1, data.total_pages || 1);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(form);
            load({ academic_year: fd.get('academic_year') || '', year_level: fd.get('year_level') || '', page: 1 });
        });

        if (pager) {
            pager.addEventListener('click', function (e) {
                const a = e.target && e.target.closest ? e.target.closest('a') : null;
                if (!a) return;
                const url = new URL(a.href, window.location.href);
                const p = parseInt(url.searchParams.get('page') || '1', 10);
                const fd = new FormData(form);
                e.preventDefault();
                load({ academic_year: fd.get('academic_year') || '', year_level: fd.get('year_level') || '', page: p });
            });
        }
    })();

    document.getElementById('applicantSearch').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('.applicant-row');
        
        rows.forEach(row => {
            const name = row.querySelector('.applicant-name').textContent.toLowerCase();
            if (name.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
