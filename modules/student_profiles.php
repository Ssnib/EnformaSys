<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'Rector']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';

$role = (string)($_SESSION['role'] ?? '');
$is_registrar = ($role === 'Registrar');
$actor_id = (int)($_SESSION['user'] ?? 0);

// Sync decoupled tables only in local/non-serverless mode
$__sp_skip_sync = function_exists('enformasys_env')
    && (enformasys_is_production() || enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== '');
if (!$__sp_skip_sync) {
    registrar_sync_and_migrate($conn, false);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && function_exists('enformasys_invalidate_dashboard_cache')) {
    enformasys_invalidate_dashboard_cache();
}

$success = null;
$error = null;

/**
 * Helper to emit a JSON response for AJAX requests in student_profiles.php.
 */
function student_profiles_json_response(int $status_code, array $payload): void
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

// Handle AJAX: Generate / Reset Student User Account (Username = student_no, Password = "pass")
$is_generate_account_ajax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax']) && $_GET['ajax'] === 'generate_account');
if ($is_generate_account_ajax || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_account']))) {
    $raw_body = file_get_contents('php://input');
    $json_body = is_string($raw_body) && trim($raw_body) !== '' ? json_decode($raw_body, true) : null;
    $input = is_array($json_body) ? $json_body : $_POST;

    if (!$is_registrar && $role !== 'Dean') {
        if ($is_generate_account_ajax) {
            student_profiles_json_response(403, ['ok' => false, 'error' => 'Only the Registrar or Dean can generate student user accounts.']);
        }
        $error = 'Only the Registrar or Dean can generate student user accounts.';
    } elseif (!verify_csrf_token((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        if ($is_generate_account_ajax) {
            student_profiles_json_response(403, ['ok' => false, 'error' => 'Security validation failed (invalid CSRF token). Please refresh and try again.']);
        }
        $error = 'Security validation failed (invalid CSRF token). Please refresh and try again.';
    } else {
        $target_student_id = (int)($input['student_id'] ?? 0);
        if ($target_student_id <= 0) {
            if ($is_generate_account_ajax) {
                student_profiles_json_response(400, ['ok' => false, 'error' => 'Invalid student record selected.']);
            }
            $error = 'Invalid student record selected.';
        } else {
            $stmt_stu = $conn->prepare("SELECT id, user_id, student_no, full_name, first_name, last_name, contact_number, home_address, email FROM students WHERE id = ? LIMIT 1");
            $stmt_stu->bind_param('i', $target_student_id);
            $stmt_stu->execute();
            $student_row = $stmt_stu->get_result()->fetch_assoc();
            $stmt_stu->close();

            if (!$student_row) {
                if ($is_generate_account_ajax) {
                    student_profiles_json_response(404, ['ok' => false, 'error' => 'Student profile record was not found.']);
                }
                $error = 'Student profile record was not found.';
            } else {
                $target_username = trim((string)($student_row['student_no'] ?? ''));
                if ($target_username === '') {
                    if ($is_generate_account_ajax) {
                        student_profiles_json_response(400, ['ok' => false, 'error' => 'Cannot generate account: this student does not have a Student ID yet.']);
                    }
                    $error = 'Cannot generate account: this student does not have a Student ID yet.';
                } else {
                    $default_password = 'pass';
                    $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
                    $student_role = 'Student';
                    $existing_user_id = (int)($student_row['user_id'] ?? 0);

                    if ($existing_user_id > 0) {
                        $stmt_u_chk = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                        $stmt_u_chk->bind_param('i', $existing_user_id);
                        $stmt_u_chk->execute();
                        $u_exists = $stmt_u_chk->get_result()->fetch_assoc();
                        $stmt_u_chk->close();
                        if (!$u_exists) {
                            $existing_user_id = 0;
                        }
                    }

                    $conn->begin_transaction();
                    try {
                        $action_type = 'created';
                        $final_user_id = 0;

                        if ($existing_user_id > 0) {
                            $stmt_col = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                            $stmt_col->bind_param('si', $target_username, $existing_user_id);
                            $stmt_col->execute();
                            $col_row = $stmt_col->get_result()->fetch_assoc();
                            $stmt_col->close();

                            if ($col_row) {
                                throw new RuntimeException("Username '{$target_username}' is already assigned to another user account.", 409);
                            }

                            $stmt_u_upd = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?");
                            $stmt_u_upd->bind_param('sssi', $target_username, $hashed_password, $student_role, $existing_user_id);
                            if (!$stmt_u_upd->execute()) {
                                throw new RuntimeException('Failed to update existing user credentials.', 500);
                            }
                            $stmt_u_upd->close();
                            $final_user_id = $existing_user_id;
                            $action_type = 'reset';
                        } else {
                            $stmt_find_u = $conn->prepare("SELECT id, role FROM users WHERE username = ? LIMIT 1");
                            $stmt_find_u->bind_param('s', $target_username);
                            $stmt_find_u->execute();
                            $found_u = $stmt_find_u->get_result()->fetch_assoc();
                            $stmt_find_u->close();

                            if ($found_u) {
                                $cand_uid = (int)$found_u['id'];
                                $stmt_other_stu = $conn->prepare("SELECT id FROM students WHERE user_id = ? AND id <> ? LIMIT 1");
                                $stmt_other_stu->bind_param('ii', $cand_uid, $target_student_id);
                                $stmt_other_stu->execute();
                                $other_stu = $stmt_other_stu->get_result()->fetch_assoc();
                                $stmt_other_stu->close();

                                if ($other_stu || strcasecmp((string)$found_u['role'], 'Student') !== 0) {
                                    throw new RuntimeException("Username '{$target_username}' is already taken by another account.", 409);
                                }

                                $stmt_u_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                                $stmt_u_upd->bind_param('si', $hashed_password, $cand_uid);
                                if (!$stmt_u_upd->execute()) {
                                    throw new RuntimeException('Failed to link and update existing user credentials.', 500);
                                }
                                $stmt_u_upd->close();
                                $final_user_id = $cand_uid;
                                $action_type = 'reset';
                            } else {
                                $stmt_u_ins = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                                $stmt_u_ins->bind_param('sss', $target_username, $hashed_password, $student_role);
                                if (!$stmt_u_ins->execute()) {
                                    throw new RuntimeException('Failed to create user account.', 500);
                                }
                                $final_user_id = (int)$conn->insert_id;
                                $stmt_u_ins->close();
                                $action_type = 'created';
                            }
                        }

                        // Sync user_id on static `students` and legacy `seminarians` tables
                        $stmt_sync_stu = $conn->prepare("UPDATE students SET user_id = ? WHERE id = ?");
                        $stmt_sync_stu->bind_param('ii', $final_user_id, $target_student_id);
                        $stmt_sync_stu->execute();
                        $stmt_sync_stu->close();

                        $stmt_sync_sem = $conn->prepare("UPDATE seminarians SET user_id = ? WHERE id = ?");
                        if ($stmt_sync_sem) {
                            $stmt_sync_sem->bind_param('ii', $final_user_id, $target_student_id);
                            $stmt_sync_sem->execute();
                            $stmt_sync_sem->close();
                        }

                        // Ensure user_profiles record exists for the generated user_id
                        $stmt_up_chk = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ? LIMIT 1");
                        if ($stmt_up_chk) {
                            $stmt_up_chk->bind_param('i', $final_user_id);
                            $stmt_up_chk->execute();
                            $up_exists = $stmt_up_chk->get_result()->fetch_assoc();
                            $stmt_up_chk->close();

                            if (!$up_exists) {
                                $stmt_up_ins = $conn->prepare("INSERT INTO user_profiles (user_id, student_id, full_name, first_name, last_name, contact, address, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                if ($stmt_up_ins) {
                                    $up_full = (string)($student_row['full_name'] ?? '');
                                    $up_first = (string)($student_row['first_name'] ?? '');
                                    $up_last = (string)($student_row['last_name'] ?? '');
                                    $up_contact = (string)($student_row['contact_number'] ?? '');
                                    $up_addr = (string)($student_row['home_address'] ?? '');
                                    $up_email = (string)($student_row['email'] ?? '');
                                    $stmt_up_ins->bind_param('isssssss', $final_user_id, $target_username, $up_full, $up_first, $up_last, $up_contact, $up_addr, $up_email);
                                    $stmt_up_ins->execute();
                                    $stmt_up_ins->close();
                                }
                            }
                        }

                        $conn->commit();

                        if (function_exists('log_activity')) {
                            try {
                                log_activity($actor_id, ($action_type === 'reset' ? 'Reset' : 'Generated') . " student user account ({$target_username}) for student #{$target_student_id}");
                            } catch (\Throwable $e) {
                                // Non-blocking activity log
                            }
                        }

                        $msg = ($action_type === 'reset')
                            ? "Account credentials reset for {$student_row['full_name']} (Username: {$target_username})."
                            : "User account generated for {$student_row['full_name']} (Username: {$target_username}).";

                        if ($is_generate_account_ajax) {
                            student_profiles_json_response(200, [
                                'ok' => true,
                                'action' => $action_type,
                                'student_id' => $target_student_id,
                                'user_id' => $final_user_id,
                                'username' => $target_username,
                                'message' => $msg,
                            ]);
                        }
                        $success = $msg;
                    } catch (\Throwable $ex) {
                        $conn->rollback();
                        $code = ($ex->getCode() >= 400 && $ex->getCode() < 600) ? (int)$ex->getCode() : 500;
                        if ($is_generate_account_ajax) {
                            student_profiles_json_response($code, ['ok' => false, 'error' => $ex->getMessage()]);
                        }
                        $error = $ex->getMessage();
                    }
                }
            }
        }
    }
}

// Handle updating a static student profile (`students` table only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_static_profile'])) {
    if (!$is_registrar && $role !== 'Dean') {
        $error = 'Only the Registrar can edit static student profiles.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    } else {
        $target_student_id = (int)($_POST['student_id'] ?? 0);
        $new_student_no = trim((string)($_POST['student_no'] ?? ''));

        if ($target_student_id <= 0) {
            $error = 'Invalid student record selected.';
        } else {
            // Check duplicate student_no against other students
            $stmt_dup = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE student_no = ? AND id <> ?");
            $stmt_dup->bind_param('si', $new_student_no, $target_student_id);
            $stmt_dup->execute();
            $is_dup = (int)($stmt_dup->get_result()->fetch_assoc()['c'] ?? 0) > 0;

            if ($new_student_no === '' || $is_dup) {
                $error = $is_dup
                    ? "Student No. '{$new_student_no}' is already assigned to another student."
                    : 'Student No. cannot be empty.';
            } else {
                $ok = registrar_update_static_student_profile($conn, $target_student_id, $_POST, $actor_id);
                if ($ok) {
                    $success = 'Static Student Information updated successfully (enrollment history preserved).';
                } else {
                    $error = 'Failed to update static student profile.';
                }
            }
        }
    }
}

// Filters & Pagination
$search = trim((string)($_GET['q'] ?? ''));
$filter_program = trim((string)($_GET['program'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$whereSql = " WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
    $whereSql .= " AND (s.full_name LIKE ? OR s.student_no LIKE ? OR s.email LIKE ? OR s.contact_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($filter_program !== '') {
    $whereSql .= " AND s.default_program = ?";
    $params[] = $filter_program;
    $types .= 's';
}

if ($filter_status !== '') {
    if ($filter_status === 'Graduated') {
        $whereSql .= " AND (s.is_graduate = 1 OR LOWER(TRIM(COALESCE(s.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni'))";
    } else {
        $whereSql .= " AND s.formation_status = ? AND s.is_graduate = 0";
        $params[] = $filter_status;
        $types .= 's';
    }
}

$total_filtered = 0;
$stmtCount = $conn->prepare("SELECT COUNT(*) AS c FROM students s" . $whereSql);
if ($stmtCount) {
    if ($types !== '') {
        $bindC = [&$types];
        foreach ($params as $k => $v) {
            $bindC[] = &$params[$k];
        }
        call_user_func_array([$stmtCount, 'bind_param'], $bindC);
    }
    $stmtCount->execute();
    $total_filtered = (int)($stmtCount->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtCount->close();
}
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// Single JOIN query (avoids correlated subquery & view re-scan) with LIMIT/OFFSET pagination
$sql = "SELECT
            s.*,
            u.username AS linked_username,
            le.academic_year AS current_academic_year,
            le.semester AS current_semester,
            le.year_level AS latest_year_level,
            le.enrollment_status AS latest_enrollment_status,
            COALESCE(ec.total_terms_count, 0) AS total_terms_count
        FROM students s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN enrollments le ON le.id = (
            SELECT e2.id FROM enrollments e2
            WHERE e2.student_id = s.id
            ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC
            LIMIT 1
        )
        LEFT JOIN (
            SELECT student_id, COUNT(*) AS total_terms_count
            FROM enrollments
            GROUP BY student_id
        ) ec ON ec.student_id = s.id"
    . $whereSql
    . " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";

$students = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $listParams = $params;
    $listParams[] = $per_page;
    $listParams[] = $offset;
    $listTypes = $types . 'ii';
    $bind = [&$listTypes];
    foreach ($listParams as $k => $v) {
        $bind[] = &$listParams[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

// Single aggregate KPI query (replaces 4 separate round-trips)
$kpi_row = $conn->query("SELECT
    COUNT(*) AS kpi_total,
    COALESCE(SUM(CASE WHEN is_graduate = 0 AND formation_status = 'Active' THEN 1 ELSE 0 END), 0) AS kpi_active,
    COALESCE(SUM(CASE WHEN is_graduate = 0 AND default_program = 'Bachelor of Arts in Philosophy' THEN 1 ELSE 0 END), 0) AS kpi_philo,
    COALESCE(SUM(CASE WHEN is_graduate = 1 THEN 1 ELSE 0 END), 0) AS kpi_graduates
    FROM students")->fetch_assoc() ?: [];
$kpi_total = (int)($kpi_row['kpi_total'] ?? 0);
$kpi_active = (int)($kpi_row['kpi_active'] ?? 0);
$kpi_philo = (int)($kpi_row['kpi_philo'] ?? 0);
$kpi_graduates = (int)($kpi_row['kpi_graduates'] ?? 0);

$pageTitle = 'Student Information (Static Profiles)';
$activeNav = 'student_profiles';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>

<div class="space-y-6">
    <!-- Top Module Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-2xl p-6 border border-[#E8C7C5]/60 shadow-soft">
        <div>
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-teal-50 text-teal-700 border border-teal-200 text-[11px] font-extrabold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-database"></i> Decoupled Entity: <code>students</code> Table
            </div>
            <h1 class="text-2xl font-extrabold text-[#59443F] tracking-tight m-0 flex items-center gap-2.5">
                <i class="fa-solid fa-id-card text-teal-700"></i>
                Student Information Masterfile
            </h1>
            <p class="text-xs font-medium text-[#8B7168] mt-1 mb-0">
                Manages permanent student identity, contact, address, and family profiles independently of semester enrollments.
            </p>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <?php if ($is_registrar) { ?>
            <a href="enrollment_records.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-teal-700 hover:bg-teal-800 transition shadow-sm no-underline">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Go to Enrollment</span>
            </a>
            <a href="applicants.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5]/60 border border-[#E8C7C5] transition no-underline">
                <i class="fa-solid fa-user-plus"></i>
                <span>Admissions &amp; New Seminarian</span>
            </a>
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

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-[#E8C7C5]/60 shadow-soft flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] block">Total Masterfile Profiles</span>
                <div class="text-2xl font-black text-[#59443F] mt-1"><?php echo $kpi_total; ?></div>
                <span class="text-[11px] font-semibold text-teal-700">Permanent student records</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-lg">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-[#E8C7C5]/60 shadow-soft flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] block">Active in Formation</span>
                <div class="text-2xl font-black text-[#59443F] mt-1"><?php echo $kpi_active; ?></div>
                <span class="text-[11px] font-semibold text-emerald-600">Currently in residency</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-check"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-[#E8C7C5]/60 shadow-soft flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] block">AB Philosophy Base</span>
                <div class="text-2xl font-black text-[#59443F] mt-1"><?php echo $kpi_philo; ?></div>
                <span class="text-[11px] font-semibold text-indigo-600">Collegiate program</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-book-open"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-[#E8C7C5]/60 shadow-soft flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168] block">Graduates / Alumni</span>
                <div class="text-2xl font-black text-[#59443F] mt-1"><?php echo $kpi_graduates; ?></div>
                <span class="text-[11px] font-semibold text-amber-700">Completed formation</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
    </div>

    <!-- Master Student Profiles Table (with Integrated Header Search & Filters) -->
    <div class="bg-white rounded-2xl border border-[#E8C7C5]/60 shadow-soft overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-[#F8EEEE]/40 flex flex-col xl:flex-row xl:items-center justify-between gap-3">
            <div>
                <div class="text-xs font-extrabold uppercase tracking-wider text-[#59443F] flex items-center gap-2">
                    <span>Static Student Profiles</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#E8C7C5]/60 text-[#59443F]">
                        <?php echo (int)$total_filtered; ?> Found
                    </span>
                </div>
                <div class="text-[11px] text-[#8B7168] mt-0.5">
                    <i class="fa-solid fa-shield-halved text-teal-700 mr-1"></i>
                    Editing personal details here preserves semester enrollment records.
                </div>
            </div>

            <form method="get" class="flex !flex-row flex-wrap items-center justify-end !gap-2 m-0 p-0 bg-transparent border-0 shadow-none">
                <div class="relative w-full sm:w-60">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input type="text"
                           name="q"
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Search Name, ID, Email..."
                           aria-label="Search Student Profile"
                           class="w-full pl-8 pr-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-600 transition">
                </div>

                <select name="program"
                        aria-label="Base Program"
                        class="w-full sm:w-48 px-2.5 py-1.5 text-xs font-medium rounded-lg border border-slate-200 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-600 transition">
                    <option value="">All Programs</option>
                    <option value="Propaedeutic" <?php echo $filter_program === 'Propaedeutic' ? 'selected' : ''; ?>>Propaedeutic</option>
                    <option value="Bachelor of Arts in Philosophy" <?php echo $filter_program === 'Bachelor of Arts in Philosophy' ? 'selected' : ''; ?>>Bachelor of Arts in Philosophy</option>
                </select>

                <select name="status"
                        aria-label="Formation Status"
                        class="w-full sm:w-36 px-2.5 py-1.5 text-xs font-medium rounded-lg border border-slate-200 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-600 transition">
                    <option value="">All Statuses</option>
                    <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Under Evaluation" <?php echo $filter_status === 'Under Evaluation' ? 'selected' : ''; ?>>Under Evaluation</option>
                    <option value="Graduated" <?php echo $filter_status === 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                </select>

                <button type="submit"
                        style="width: auto;"
                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#59443F] text-white hover:bg-[#463430] transition cursor-pointer border-0 shadow-2xs">
                    <i class="fa-solid fa-filter text-[11px]"></i>
                    <span>Filter</span>
                </button>

                <?php if ($search !== '' || $filter_program !== '' || $filter_status !== '') { ?>
                    <a href="student_profiles.php"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-bold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 transition no-underline">
                        Reset
                    </a>
                <?php } ?>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-[11px] font-extrabold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-3.5">Student ID</th>
                        <th class="px-5 py-3.5">Full Name &amp; Demographics</th>
                        <th class="px-5 py-3.5">Contact &amp; Address</th>
                        <th class="px-5 py-3.5">Cohort &amp; Base Program</th>
                        <th class="px-5 py-3.5">Latest Term Snapshot</th>
                        <th class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <?php if (empty($students)) { ?>
                        <tr>
                            <td colSpan="6" class="px-6 py-10 text-center text-slate-400">
                                No student profiles matched your filter criteria.
                            </td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($students as $st) {
                            $sid = (int)$st['id'];
                            $is_grad = ((int)$st['is_graduate'] === 1) || registrar_is_graduated_status((string)($st['formation_status'] ?? ''));
                        ?>
                        <tr class="hover:bg-[#F8EEEE]/30 transition">
                            <td class="px-5 py-4 align-top">
                                <span class="inline-block font-mono text-xs font-bold px-2.5 py-1 rounded-lg bg-slate-100 text-slate-800 border border-slate-200">
                                    <?php echo htmlspecialchars((string)$st['student_no']); ?>
                                </span>
                                <div class="text-[11px] text-slate-400 mt-1">Record #<?php echo $sid; ?></div>
                            </td>

                            <td class="px-5 py-4 align-top">
                                <div class="font-bold text-[#59443F] text-sm">
                                    <?php echo htmlspecialchars((string)$st['full_name']); ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    <?php echo htmlspecialchars((string)($st['gender'] ?: 'Male')); ?>
                                    <?php if (!empty($st['date_of_birth'])) { ?>
                                        • DOB: <?php echo htmlspecialchars((string)$st['date_of_birth']); ?>
                                    <?php } ?>
                                </div>
                                <?php if (!empty($st['parish_chaplaincy'])) { ?>
                                    <div class="text-[11px] text-teal-700 font-medium mt-1">
                                        <i class="fa-solid fa-church mr-1"></i><?php echo htmlspecialchars((string)$st['parish_chaplaincy']); ?>
                                    </div>
                                <?php } ?>
                            </td>

                            <td class="px-5 py-4 align-top max-w-xs">
                                <div class="text-xs font-semibold text-slate-800">
                                    <i class="fa-solid fa-phone text-slate-400 mr-1"></i>
                                    <?php echo htmlspecialchars((string)($st['contact_number'] ?: '—')); ?>
                                </div>
                                <?php if (!empty($st['email'])) { ?>
                                    <div class="text-xs text-slate-500 truncate mt-0.5">
                                        <i class="fa-solid fa-envelope text-slate-400 mr-1"></i>
                                        <?php echo htmlspecialchars((string)$st['email']); ?>
                                    </div>
                                <?php } ?>
                                <div class="text-[11px] text-slate-500 mt-1 line-clamp-2">
                                    <?php echo htmlspecialchars((string)($st['home_address'] ?: 'No address recorded')); ?>
                                </div>
                            </td>

                            <td class="px-5 py-4 align-top">
                                <div class="text-xs font-bold text-slate-800">
                                    <?php echo htmlspecialchars((string)$st['default_program']); ?>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Admission Cohort: <strong>AY <?php echo htmlspecialchars((string)($st['admission_academic_year'] ?: '—')); ?></strong>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold mt-1.5 <?php echo $is_grad ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?>">
                                    <?php echo htmlspecialchars($is_grad ? 'Graduated / Alumni' : (string)$st['formation_status']); ?>
                                </span>
                            </td>

                            <td class="px-5 py-4 align-top">
                                <div class="text-xs font-bold text-slate-800">
                                    AY <?php echo htmlspecialchars((string)($st['current_academic_year'] ?: '—')); ?>
                                    (<?php echo htmlspecialchars((string)($st['current_semester'] ?: '1st')); ?> Sem)
                                </div>
                                <div class="text-xs text-slate-600 mt-0.5">
                                    <?php echo htmlspecialchars((string)($st['latest_year_level'] ?: '—')); ?>
                                </div>
                                <div class="text-[11px] font-semibold text-teal-700 mt-1">
                                    <i class="fa-solid fa-layer-group mr-1"></i>
                                    <?php echo (int)$st['total_terms_count']; ?> Semester Term(s) Logged
                                </div>
                            </td>

                            <td class="px-5 py-4 align-top text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5">
                                    <?php if ($is_registrar || $role === 'Dean') { ?>
                                    <button
                                        type="button"
                                        onclick='openEditProfileModal(<?php echo htmlspecialchars(json_encode($st), ENT_QUOTES, "UTF-8"); ?>)'
                                        class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition border border-slate-200 cursor-pointer"
                                        title="Edit Static Student Profile"
                                    >
                                        <i class="fa-solid fa-user-pen mr-1"></i> Edit Profile
                                    </button>
                                    <button
                                        type="button"
                                        id="genAccBtn_<?php echo $sid; ?>"
                                        data-student-id="<?php echo $sid; ?>"
                                        data-student-no="<?php echo htmlspecialchars((string)$st['student_no'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-full-name="<?php echo htmlspecialchars((string)$st['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-linked-username="<?php echo htmlspecialchars((string)($st['linked_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openGenerateAccountModalFromBtn(this)"
                                        class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-amber-50 text-amber-800 hover:bg-amber-100 transition border border-amber-200 cursor-pointer"
                                        title="<?php echo !empty($st['linked_username']) ? 'Reset or regenerate login credentials (' . htmlspecialchars((string)$st['linked_username'], ENT_QUOTES, 'UTF-8') . ')' : 'Generate Student Portal user credentials'; ?>"
                                    >
                                        <i class="fa-solid fa-key mr-1"></i> Generate Account
                                    </button>
                                    <?php } ?>

                                    <?php if ($is_registrar) { ?>
                                    <a
                                        href="enrollment_records.php?student_id=<?php echo $sid; ?>"
                                        class="px-2.5 py-1.5 rounded-lg text-xs font-bold bg-teal-700 text-white hover:bg-teal-800 transition no-underline"
                                        title="Manage Semester Enrollments &amp; History"
                                    >
                                        <i class="fa-solid fa-clock-rotate-left mr-1"></i> Enrollments (<?php echo (int)$st['total_terms_count']; ?>)
                                    </a>
                                    <?php } ?>

                                    <a
                                        href="seminarian_profile.php?seminarian_id=<?php echo $sid; ?>"
                                        class="p-1.5 rounded-lg text-xs font-bold bg-[#F8EEEE] text-[#59443F] hover:bg-[#E8C7C5] transition no-underline"
                                        title="View 360° Seminarian Overview"
                                    >
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="px-6 py-3 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-3 text-xs">
                <span class="text-slate-500 font-semibold">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></span>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1])), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 font-bold text-slate-700 hover:bg-slate-100 no-underline">Prev</a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1])), ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 font-bold text-slate-700 hover:bg-slate-100 no-underline">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: Edit Static Student Profile -->
<div id="editStaticProfileModal" data-global-modal="1" class="<?php echo htmlspecialchars(global_modal_overlay_classes('hidden'), ENT_QUOTES, 'UTF-8'); ?>" role="dialog" aria-modal="true">
    <div class="max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-[#E8C7C5]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <span class="text-[10px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded bg-teal-50 text-teal-700">
                    Static Student Entity (`students`)
                </span>
                <h3 class="text-lg font-extrabold text-[#59443F] m-0 mt-1">
                    Edit Student Personal Profile
                </h3>
            </div>
            <button type="button" onclick="closeEditProfileModal()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 border-0 bg-transparent cursor-pointer text-lg">
                &times;
            </button>
        </div>

        <form method="post" class="mt-4 space-y-4 m-0 p-0 bg-transparent border-0 shadow-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="update_static_profile" value="1">
            <input type="hidden" name="student_id" id="ep_student_id" value="">

            <div class="p-3 rounded-xl bg-amber-50/80 border border-amber-200 text-xs text-amber-900">
                <i class="fa-solid fa-lock text-amber-600 mr-1"></i>
                <strong>Decoupled Safeguard:</strong> Saving changes here updates only the student's permanent demographic profile. To enroll this student in a new semester or view past terms, use <strong>Enrollments</strong>.
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Student ID No.</label>
                    <input type="text" name="student_no" id="ep_student_no" required class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">First Name</label>
                    <input type="text" name="first_name" id="ep_first_name" required class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Middle Name</label>
                    <input type="text" name="middle_name" id="ep_middle_name" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Last Name</label>
                    <input type="text" name="last_name" id="ep_last_name" required class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Suffix</label>
                    <input type="text" name="suffix" id="ep_suffix" placeholder="Jr., III" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth" id="ep_dob" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Gender</label>
                    <select name="gender" id="ep_gender" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Contact Number</label>
                    <input type="text" name="contact_number" id="ep_contact" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email Address</label>
                    <input type="email" name="email" id="ep_email" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Parish Priest</label>
                    <input type="text" name="parish_priest" id="ep_parish_priest" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Parish / Chaplaincy</label>
                    <input type="text" name="parish_chaplaincy" id="ep_parish_chaplaincy" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Permanent / Home Address</label>
                <textarea name="home_address" id="ep_address" rows="2" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Guardian / Parent Name</label>
                    <input type="text" name="guardian_name" id="ep_guardian_name" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Guardian Contact</label>
                    <input type="text" name="guardian_contact" id="ep_guardian_contact" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Base Program</label>
                    <select name="default_program" id="ep_default_program" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <option value="Propaedeutic">Propaedeutic</option>
                        <option value="Bachelor of Arts in Philosophy">Bachelor of Arts in Philosophy</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Formation Status</label>
                    <select name="formation_status" id="ep_formation_status" class="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white">
                        <option value="Active">Active</option>
                        <option value="Under Evaluation">Under Evaluation</option>
                        <option value="Graduated">Graduated</option>
                        <option value="Completed">Completed</option>
                        <option value="Dismissed">Dismissed</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditProfileModal()" class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 border-0 cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl text-xs font-bold bg-teal-700 text-white hover:bg-teal-800 border-0 cursor-pointer shadow-sm">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Profile Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditProfileModal(student) {
    document.getElementById('ep_student_id').value = student.id || '';
    document.getElementById('ep_student_no').value = student.student_no || '';
    document.getElementById('ep_first_name').value = student.first_name || '';
    document.getElementById('ep_middle_name').value = student.middle_name || '';
    document.getElementById('ep_last_name').value = student.last_name || '';
    document.getElementById('ep_suffix').value = student.suffix || '';
    document.getElementById('ep_dob').value = student.date_of_birth || '';
    document.getElementById('ep_gender').value = student.gender || 'Male';
    document.getElementById('ep_contact').value = student.contact_number || '';
    document.getElementById('ep_email').value = student.email || '';
    document.getElementById('ep_parish_priest').value = student.parish_priest || '';
    document.getElementById('ep_parish_chaplaincy').value = student.parish_chaplaincy || '';
    document.getElementById('ep_address').value = student.home_address || '';
    document.getElementById('ep_guardian_name').value = student.guardian_name || student.father_name || '';
    document.getElementById('ep_guardian_contact').value = student.guardian_contact || '';
    document.getElementById('ep_default_program').value = student.default_program || 'Bachelor of Arts in Philosophy';
    document.getElementById('ep_formation_status').value = student.formation_status || 'Active';

    const modal = document.getElementById('editStaticProfileModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditProfileModal() {
    const modal = document.getElementById('editStaticProfileModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<!-- MODAL: Confirm Generate User Account -->
<div id="generateAccountModal" data-global-modal="1" class="<?php echo htmlspecialchars(global_modal_overlay_classes('hidden'), ENT_QUOTES, 'UTF-8'); ?>" role="dialog" aria-modal="true" aria-labelledby="genAccModalTitle">
    <div class="max-h-[90vh] overflow-y-auto bg-white rounded-xl max-w-md w-full shadow-2xl border border-[#E8C7C5]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-[#F8EEEE]/40">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-800 border border-amber-200 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-key text-sm"></i>
                </div>
                <div>
                    <h3 id="genAccModalTitle" class="text-base font-extrabold text-[#59443F] m-0 leading-tight">Generate User Account</h3>
                    <p class="text-[11px] font-medium text-[#8B7168] m-0">Seminarian Portal Credentials</p>
                </div>
            </div>
            <button type="button" onclick="closeGenerateAccountModal()" aria-label="Close modal" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 border-0 bg-transparent cursor-pointer text-lg flex items-center justify-center">
                &times;
            </button>
        </div>

        <div class="p-6 space-y-4">
            <p class="text-sm text-slate-700 m-0 leading-relaxed">
                Are you sure you want to generate login credentials for <strong id="ga_student_name" class="text-[#59443F]"></strong>?
            </p>

            <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 space-y-2.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold uppercase tracking-wider text-slate-500">Username</span>
                    <code id="ga_preview_username" class="px-2.5 py-1 rounded-md bg-white border border-slate-200 font-mono font-bold text-[#59443F]"></code>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold uppercase tracking-wider text-slate-500">Default Password</span>
                    <code class="px-2.5 py-1 rounded-md bg-white border border-slate-200 font-mono font-bold text-slate-800">pass</code>
                </div>
            </div>

            <div id="ga_overwrite_warning" class="hidden p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 leading-relaxed">
                <i class="fa-solid fa-triangle-exclamation text-amber-600 mr-1"></i>
                <strong>Existing Account Detected:</strong> This student is currently linked to username <strong id="ga_existing_username"></strong>. Proceeding will reset their username to match their Student ID and reset their password to <code>"pass"</code>.
            </div>

            <div id="ga_new_info" class="hidden p-3.5 rounded-xl bg-teal-50 border border-teal-200 text-xs text-teal-900 leading-relaxed">
                <i class="fa-solid fa-circle-info text-teal-600 mr-1"></i>
                A new <strong>Student</strong> login account will be created and linked to this profile.
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2.5">
            <button type="button" onclick="closeGenerateAccountModal()" class="px-4 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-100 border border-slate-200 cursor-pointer transition">
                Cancel
            </button>
            <button type="button" id="ga_confirm_btn" onclick="submitGenerateAccount()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-[#59443F] text-white hover:bg-[#463430] border-0 cursor-pointer shadow-sm transition disabled:opacity-60 disabled:cursor-not-allowed">
                <i id="ga_confirm_spinner" class="fa-solid fa-circle-notch fa-spin hidden"></i>
                <i id="ga_confirm_icon" class="fa-solid fa-key"></i>
                <span id="ga_confirm_text">Confirm &amp; Generate</span>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const CSRF_TOKEN = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;
    let activeStudentTarget = null;

    function notifyUser(message, isError) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, isError ? 'error' : 'success', 4000);
            return;
        }
        alert(message);
    }

    window.openGenerateAccountModalFromBtn = function (btnEl) {
        if (!btnEl) return;
        const studentId = parseInt(btnEl.getAttribute('data-student-id') || '0', 10);
        const studentNo = (btnEl.getAttribute('data-student-no') || '').trim();
        const fullName = (btnEl.getAttribute('data-full-name') || '').trim();
        const linkedUsername = (btnEl.getAttribute('data-linked-username') || '').trim();

        if (!studentId || !studentNo) {
            notifyUser('Student ID is missing for this profile.', true);
            return;
        }

        activeStudentTarget = {
            id: studentId,
            studentNo: studentNo,
            fullName: fullName,
            linkedUsername: linkedUsername,
            btnEl: btnEl
        };

        document.getElementById('ga_student_name').textContent = fullName || studentNo;
        document.getElementById('ga_preview_username').textContent = studentNo;

        const warnBox = document.getElementById('ga_overwrite_warning');
        const infoBox = document.getElementById('ga_new_info');
        const existingSpan = document.getElementById('ga_existing_username');

        if (linkedUsername) {
            existingSpan.textContent = linkedUsername;
            warnBox.classList.remove('hidden');
            infoBox.classList.add('hidden');
        } else {
            warnBox.classList.add('hidden');
            infoBox.classList.remove('hidden');
        }

        const modal = document.getElementById('generateAccountModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    window.closeGenerateAccountModal = function () {
        const modal = document.getElementById('generateAccountModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        activeStudentTarget = null;
    };

    window.submitGenerateAccount = async function () {
        if (!activeStudentTarget) return;

        const confirmBtn = document.getElementById('ga_confirm_btn');
        const spinner = document.getElementById('ga_confirm_spinner');
        const icon = document.getElementById('ga_confirm_icon');
        const btnText = document.getElementById('ga_confirm_text');

        confirmBtn.disabled = true;
        spinner.classList.remove('hidden');
        icon.classList.add('hidden');
        btnText.textContent = 'Generating...';

        try {
            const res = await fetch('student_profiles.php?ajax=generate_account', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    csrf_token: CSRF_TOKEN,
                    student_id: activeStudentTarget.id
                })
            });

            const data = await res.json();
            if (!res.ok || !data || data.ok !== true) {
                throw new Error((data && data.error) ? data.error : 'Failed to generate user account.');
            }

            if (activeStudentTarget.btnEl && data.username) {
                activeStudentTarget.btnEl.setAttribute('data-linked-username', data.username);
                activeStudentTarget.btnEl.setAttribute('title', 'Reset or regenerate login credentials (' + data.username + ')');
            }

            const msg = data.message || ('Account generated for ' + data.username + ' (Default password: pass)');
            closeGenerateAccountModal();
            notifyUser(msg, false);
        } catch (err) {
            notifyUser(err && err.message ? err.message : 'Unable to generate user account.', true);
        } finally {
            confirmBtn.disabled = false;
            spinner.classList.add('hidden');
            icon.classList.remove('hidden');
            btnText.textContent = 'Confirm & Generate';
        }
    };

    const gaModal = document.getElementById('generateAccountModal');
    if (gaModal) {
        gaModal.addEventListener('click', function (e) {
            if (e.target === gaModal) {
                window.closeGenerateAccountModal();
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && gaModal && !gaModal.classList.contains('hidden')) {
            window.closeGenerateAccountModal();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>

