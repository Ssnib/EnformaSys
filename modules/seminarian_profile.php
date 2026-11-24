<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'Rector']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';
require_once __DIR__ . '/../includes/lodging.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$role = (string)($_SESSION['role'] ?? '');
$is_registrar = $role === 'Registrar';

$seminarian_id = isset($_GET['seminarian_id']) ? (int)$_GET['seminarian_id'] : 0;
if ($seminarian_id <= 0) {
    header('Location: applicants.php');
    exit;
}

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') {
    $filter_academic_year = $current_ay;
}

$filter_semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if ($filter_semester === '' || !in_array($filter_semester, ['all', '1st', '2nd', 'Summer'], true)) {
    $filter_semester = 'all';
}

$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

// Ensure seminarian_documents table exists
$conn->query("CREATE TABLE IF NOT EXISTS seminarian_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seminarian_id (seminarian_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle Document Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    if (!$is_registrar && !in_array($role, ['Registrar', 'Dean', 'Admin'], true)) {
        $error = "Only authorized administrators can upload documents.";
        $_GET['tab'] = 'documents';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    } else {
        $doc_type = trim((string)($_POST['document_type'] ?? ''));
        $valid_doc_types = [
            'Baptismal Certificate',
            'Confirmation Certificate',
            'Birth Certificate',
            'Good Moral',
            'Transcript of Records',
            'Others'
        ];

        if (!in_array($doc_type, $valid_doc_types, true)) {
            $error = "Please select a valid Document Type from the list.";
            $_GET['tab'] = 'documents';
        } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = "Please select a document file to upload.";
            $_GET['tab'] = 'documents';
        } elseif ($_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "File upload failed with error code: " . (int)$_FILES['document_file']['error'];
            $_GET['tab'] = 'documents';
        } else {
            $file = $_FILES['document_file'];
            $max_size = 5 * 1024 * 1024; // 5 MB

            if ($file['size'] > $max_size) {
                $error = "File size exceeds the 5MB limit. Please upload a file smaller than 5MB.";
                $_GET['tab'] = 'documents';
            } else {
                $orig_name = basename($file['name']);
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

                if (!in_array($ext, $allowed_exts, true)) {
                    $error = "Invalid file type (." . htmlspecialchars($ext) . "). Only PDF, JPG, and PNG files are allowed.";
                    $_GET['tab'] = 'documents';
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];

                    if (!in_array($mime_type, $allowed_mimes, true)) {
                        $error = "Security validation failed: File MIME type ({$mime_type}) is not allowed.";
                        $_GET['tab'] = 'documents';
                    } else {
                        $upload_dir = __DIR__ . '/../uploads/documents/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                            @file_put_contents($upload_dir . 'index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body>Directory listing forbidden.</body></html>');
                        }

                        $safe_unique_name = 'doc_' . $seminarian_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dest_file = $upload_dir . $safe_unique_name;

                        if (move_uploaded_file($file['tmp_name'], $dest_file)) {
                            $rel_path = 'uploads/documents/' . $safe_unique_name;
                            $file_size = (int)$file['size'];
                            $uploaded_by = (int)($_SESSION['user'] ?? 0);

                            $stmt_doc_ins = $conn->prepare("INSERT INTO seminarian_documents (seminarian_id, document_type, file_name, file_path, file_size, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            if ($stmt_doc_ins) {
                                $stmt_doc_ins->bind_param('isssii', $seminarian_id, $doc_type, $orig_name, $rel_path, $file_size, $uploaded_by);
                                if ($stmt_doc_ins->execute()) {
                                    $success = "Document '" . htmlspecialchars($orig_name) . "' uploaded successfully.";
                                    $_GET['tab'] = 'documents';
                                } else {
                                    @unlink($dest_file);
                                    $error = "Database failed to record document: " . $conn->error;
                                    $_GET['tab'] = 'documents';
                                }
                            } else {
                                @unlink($dest_file);
                                $error = "Failed to prepare database statement: " . $conn->error;
                                $_GET['tab'] = 'documents';
                            }
                        } else {
                            $error = "Failed to save the uploaded file to disk.";
                            $_GET['tab'] = 'documents';
                        }
                    }
                }
            }
        }
    }
}

// Handle Document Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_document') {
    if (!$is_registrar && !in_array($role, ['Registrar', 'Dean', 'Admin'], true)) {
        $error = "Only authorized administrators can delete documents.";
        $_GET['tab'] = 'documents';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    } else {
        $doc_id = (int)($_POST['document_id'] ?? 0);
        $stmt_find = $conn->prepare("SELECT file_name, file_path FROM seminarian_documents WHERE id = ? AND seminarian_id = ?");
        $stmt_find->bind_param('ii', $doc_id, $seminarian_id);
        $stmt_find->execute();
        $found_doc = $stmt_find->get_result()->fetch_assoc();

        if ($found_doc) {
            $real_file = __DIR__ . '/../' . ltrim($found_doc['file_path'], '/\\');
            if (file_exists($real_file) && is_file($real_file)) {
                @unlink($real_file);
            }
            $stmt_del = $conn->prepare("DELETE FROM seminarian_documents WHERE id = ? AND seminarian_id = ?");
            $stmt_del->bind_param('ii', $doc_id, $seminarian_id);
            $stmt_del->execute();
            $success = "Document '" . htmlspecialchars($found_doc['file_name']) . "' was permanently deleted.";
            $_GET['tab'] = 'documents';
        } else {
            $error = "Document record not found or already deleted.";
            $_GET['tab'] = 'documents';
        }
    }
}

if (isset($_POST['update_seminary_info'])) {
    if (!$is_registrar) {
        $error = "Only the Registrar can update Seminary Information.";
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    } else {
        $new_ay = trim((string)($_POST['current_academic_year'] ?? ''));
        $new_year_level = trim((string)($_POST['year_level'] ?? ''));
        $new_program = trim((string)($_POST['program'] ?? ''));
        $new_formation_status = trim((string)($_POST['formation_status'] ?? ''));

        $active_ays = get_active_academic_years();
        if ($new_ay === '' || !in_array($new_ay, $active_ays, true)) {
            $new_ay = $current_ay;
        }

        $stmt_cur = $conn->prepare("SELECT current_academic_year, year_level, program, formation_status, is_graduate, completed_at, academic_year_completed, program_completed, user_id FROM seminarians WHERE id = ? LIMIT 1");
        $stmt_cur->bind_param('i', $seminarian_id);
        $stmt_cur->execute();
        $cur = $stmt_cur->get_result()->fetch_assoc();

        if (!$cur) {
            $error = "Seminarian not found.";
        } else {
            $prev_ay = (string)($cur['current_academic_year'] ?? '');
            $prev_year_level = (string)($cur['year_level'] ?? '');
            $prev_program = (string)($cur['program'] ?? '');
            $prev_formation_status = (string)($cur['formation_status'] ?? '');
            $prev_grad = (int)($cur['is_graduate'] ?? 0);
            $user_id = (int)($cur['user_id'] ?? 0);

            $status_is_grad = function_exists('registrar_is_graduated_status')
                ? registrar_is_graduated_status($new_formation_status)
                : in_array(strtolower($new_formation_status), ['graduated', 'graduate', 'completed', 'complete', 'alumni'], true);
            $is_completed = ($new_year_level === 'Completed') || $status_is_grad;
            $is_graduate = $is_completed ? 1 : 0;
            $existing_completed_at = trim((string)($cur['completed_at'] ?? ''));
            $completed_at = $is_completed
                ? (($existing_completed_at !== '' && $existing_completed_at !== '0000-00-00') ? $existing_completed_at : date('Y-m-d'))
                : null;
            $ay_completed = $is_completed
                ? ($new_ay !== '' ? $new_ay : (trim((string)($cur['academic_year_completed'] ?? '')) ?: $current_ay))
                : null;
            $program_completed = $is_completed
                ? ($new_program !== '' ? $new_program : (trim((string)($cur['program_completed'] ?? '')) ?: 'Bachelor of Arts in Philosophy'))
                : null;
            if ($is_completed && $new_year_level === '') {
                $new_year_level = 'Completed';
            }
            if ($is_completed && $new_formation_status === '') {
                $new_formation_status = 'Graduated';
            }
            if (!$is_completed && $status_is_grad) {
                $new_formation_status = 'Active';
            }

            $stmt_upd = $conn->prepare("UPDATE seminarians SET current_academic_year = ?, year_level = ?, program = ?, formation_status = ?, is_graduate = ?, completed_at = ?, academic_year_completed = ?, program_completed = ?, enrollment_status = CASE WHEN ? = 1 THEN 'Graduated' ELSE enrollment_status END WHERE id = ?");
            $stmt_upd->bind_param('ssssisssii', $new_ay, $new_year_level, $new_program, $new_formation_status, $is_graduate, $completed_at, $ay_completed, $program_completed, $is_graduate, $seminarian_id);
            if ($stmt_upd->execute()) {
                if (function_exists('registrar_sync_graduation_metadata')) {
                    registrar_sync_graduation_metadata($conn, $seminarian_id, $is_completed, $ay_completed, $program_completed, $completed_at, $new_formation_status);
                }
                $actor = (int)($_SESSION['user'] ?? 0);
                if ($prev_ay !== $new_ay) log_field_change($actor, 'Seminarian', $seminarian_id, 'current_academic_year', $prev_ay, $new_ay);
                if ($prev_year_level !== $new_year_level) log_field_change($actor, 'Seminarian', $seminarian_id, 'year_level', $prev_year_level, $new_year_level);
                if ($prev_program !== $new_program) log_field_change($actor, 'Seminarian', $seminarian_id, 'program', $prev_program, $new_program);
                if ($prev_formation_status !== $new_formation_status) log_field_change($actor, 'Seminarian', $seminarian_id, 'formation_status', $prev_formation_status, $new_formation_status);
                if ((int)$prev_grad !== (int)$is_graduate) log_field_change($actor, 'Seminarian', $seminarian_id, 'is_graduate', $prev_grad, $is_graduate);

                $stmt_app = $conn->prepare("SELECT id, academic_year FROM applicants WHERE user_id = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
                $stmt_app->bind_param('i', $user_id);
                $stmt_app->execute();
                $app = $stmt_app->get_result()->fetch_assoc();
                if ($app) {
                    $app_id = (int)($app['id'] ?? 0);
                    $app_prev_ay = (string)($app['academic_year'] ?? '');
                    if ($app_id > 0 && $app_prev_ay !== $new_ay) {
                        $stmt_app_upd = $conn->prepare("UPDATE applicants SET academic_year = ? WHERE id = ?");
                        $stmt_app_upd->bind_param('si', $new_ay, $app_id);
                        $stmt_app_upd->execute();
                        log_field_change($actor, 'Applicant', $app_id, 'academic_year', $app_prev_ay, $new_ay);
                    }
                }

                if (function_exists('registrar_sync_and_migrate')) {
                    registrar_sync_and_migrate($conn, true);
                }

                $success = "Seminary Information updated.";
            } else {
                $error = "Failed to update Seminary Information.";
            }
        }
    }
}

$stmt = $conn->prepare("SELECT s.id as seminarian_id, s.current_academic_year, s.year_level, s.program, s.formation_status, s.is_graduate, s.completed_at, s.academic_year_completed, s.program_completed, s.profile_picture, s.last_profile_update,
        u.id as user_id, u.username, 
        up.full_name, up.first_name, up.middle_name, up.last_name, up.suffix, up.email, up.email_address, up.contact, up.contact_number, up.address, up.present_address, up.permanent_address,
        up.parish_priest, up.parish_chaplaincy, up.religion,
        up.father_name, up.father_occupation, up.mother_name, up.mother_occupation, up.guardian_name, up.guardian_contact_number,
        up.previous_school, up.educational_attainment, up.year_graduated,
        a.id as applicant_id, a.status as applicant_status, a.academic_year as applicant_academic_year, a.dob, a.age, a.gender, a.academic_history, a.parent_info
    FROM seminarians s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN applicants a ON a.user_id = u.id AND a.archived_at IS NULL
    WHERE s.id = ?
    ORDER BY a.id DESC
    LIMIT 1");
$stmt->bind_param('i', $seminarian_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
if (!$profile) {
    header('Location: applicants.php');
    exit;
}

$display_name = trim((string)($profile['full_name'] ?? ''));
if ($display_name === '') {
    $display_name = (string)($profile['username'] ?? '');
}

$seminarian_avatar_url = function_exists('get_seminarian_avatar_url') ? get_seminarian_avatar_url($profile['profile_picture'] ?? null) : null;
$seminarian_cooldown = function_exists('get_seminarian_cooldown_status') ? get_seminarian_cooldown_status($profile['last_profile_update'] ?? null) : null;
$first_letter = mb_substr(trim((string)($profile['full_name'] ?? $display_name)), 0, 1);
$words = preg_split('/\s+/', trim((string)($profile['full_name'] ?? $display_name)));
$second_letter = (count($words) > 1) ? mb_substr($words[count($words) - 1], 0, 1) : mb_substr($display_name, 1, 1);
$seminarian_initials = strtoupper($first_letter . $second_letter);
if (mb_strlen($seminarian_initials) < 2) {
    $seminarian_initials = 'SM';
}

// Resolved Contact & Parish Affiliation
$contact_display = trim((string)($profile['contact_number'] ?: ($profile['contact'] ?: '')));
$parish_chaplaincy_display = trim((string)($profile['parish_chaplaincy'] ?? ''));
$parish_priest_display = trim((string)($profile['parish_priest'] ?? ''));
$address_display = trim((string)($profile['present_address'] ?: ($profile['address'] ?: ($profile['permanent_address'] ?: ''))));

// Helper for parsing Parent / Guardian Info (structured from user_profiles or parsed from applicants.parent_info)
if (!function_exists('seminarian_parse_parent_role')) {
    function seminarian_parse_parent_role(?string $raw, string $role): array {
        if (empty($raw)) return ['name' => '', 'detail' => ''];
        if (preg_match('/' . preg_quote($role, '/') . '\s*:\s*([^|\n\r()]+)(?:\s*\(([^)]+)\))?/i', $raw, $m)) {
            return [
                'name' => trim($m[1] ?? ''),
                'detail' => trim($m[2] ?? '')
            ];
        }
        return ['name' => '', 'detail' => ''];
    }
}

$raw_parents = (string)($profile['parent_info'] ?? '');
$parsed_father = seminarian_parse_parent_role($raw_parents, 'Father');
$parsed_mother = seminarian_parse_parent_role($raw_parents, 'Mother');
$parsed_guardian = seminarian_parse_parent_role($raw_parents, 'Guardian');

$father_name_disp = trim((string)($profile['father_name'] ?: $parsed_father['name']));
$father_detail_disp = trim((string)($profile['father_occupation'] ?: $parsed_father['detail']));

$mother_name_disp = trim((string)($profile['mother_name'] ?: $parsed_mother['name']));
$mother_detail_disp = trim((string)($profile['mother_occupation'] ?: $parsed_mother['detail']));

$guardian_name_disp = trim((string)($profile['guardian_name'] ?: $parsed_guardian['name']));
$guardian_detail_disp = trim((string)($profile['guardian_contact_number'] ?: $parsed_guardian['detail']));

// Resolved Academic History Items (structured from user_profiles or parsed from applicants.academic_history)
$academic_history_items = [];
if (!empty($profile['previous_school'])) {
    $academic_history_items[] = [
        'school' => trim((string)$profile['previous_school']),
        'level' => trim((string)($profile['educational_attainment'] ?? '')),
        'year' => trim((string)($profile['year_graduated'] ?? '')),
    ];
}

$raw_academic = trim((string)($profile['academic_history'] ?? ''));
if ($raw_academic !== '') {
    $acad_lines = preg_split('/[\r\n]+/', $raw_academic);
    foreach ($acad_lines as $aline) {
        $aline = trim($aline);
        if ($aline === '') continue;
        if (strpos($aline, '|') !== false) {
            $p = array_map('trim', explode('|', $aline));
            $s_name = $p[0] ?? '';
            $s_lvl = $p[1] ?? '';
            $s_yr = $p[2] ?? '';
            if (!empty($academic_history_items) && strcasecmp($academic_history_items[0]['school'], $s_name) === 0) {
                if (empty($academic_history_items[0]['level'])) $academic_history_items[0]['level'] = $s_lvl;
                if (empty($academic_history_items[0]['year'])) $academic_history_items[0]['year'] = $s_yr;
                continue;
            }
            $academic_history_items[] = ['school' => $s_name, 'level' => $s_lvl, 'year' => $s_yr];
        } else {
            if (empty($academic_history_items) || strcasecmp($academic_history_items[0]['school'], $aline) !== 0) {
                $academic_history_items[] = ['school' => $aline, 'level' => '', 'year' => ''];
            }
        }
    }
}

$grade_params = [$seminarian_id];
$grade_types = 'i';
$grade_where = 'seminarian_id = ? AND archived_at IS NULL';
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $grade_where .= ' AND school_year = ?';
    $grade_params[] = $filter_academic_year;
    $grade_types .= 's';
}

$grades = [];
$stmt_grades = $conn->prepare("SELECT subject, units, grade, grade_percent, term, school_year, date FROM grades WHERE {$grade_where} ORDER BY date DESC, id DESC LIMIT 20");
$bind = [];
$bind[] = $grade_types;
foreach ($grade_params as $k => $v) {
    $bind[] = &$grade_params[$k];
}
call_user_func_array([$stmt_grades, 'bind_param'], $bind);
$stmt_grades->execute();
$res_grades = $stmt_grades->get_result();
while ($row = $res_grades->fetch_assoc()) {
    $grades[] = $row;
}

$stmt_acad_avg = $conn->prepare("SELECT AVG(grade) as avg_grade FROM grades WHERE {$grade_where}");
$bind2 = [];
$bind2[] = $grade_types;
foreach ($grade_params as $k => $v) {
    $bind2[] = &$grade_params[$k];
}
call_user_func_array([$stmt_acad_avg, 'bind_param'], $bind2);
$stmt_acad_avg->execute();
$academic_avg = (float)($stmt_acad_avg->get_result()->fetch_assoc()['avg_grade'] ?? 0);

if (!function_exists('formator_score_to_label')) {
    function formator_score_to_label(float $score): string {
        return (string)(getFormationInterpretation($score)['label'] ?? 'Pending');
    }
}

$eval_details = [
    'Academic' => [
        'score' => (float)$academic_avg,
        'label' => '',
        'eval_id' => 0,
        'sub_metric' => '',
        'trend' => '',
        'evaluation_date' => '',
        'evaluator_name' => '',
    ],
    'Pastoral' => [
        'score' => 0.0,
        'label' => '',
        'eval_id' => 0,
        'sub_metric' => '',
        'trend' => '',
        'evaluation_date' => '',
        'evaluator_name' => '',
    ],
    'Spiritual' => [
        'score' => 0.0,
        'label' => '',
        'eval_id' => 0,
        'sub_metric' => '',
        'trend' => '',
        'evaluation_date' => '',
        'evaluator_name' => '',
    ],
    'Human' => [
        'score' => 0.0,
        'label' => '',
        'eval_id' => 0,
        'sub_metric' => '',
        'trend' => '',
        'evaluation_date' => '',
        'evaluator_name' => '',
    ],
];

// 1. Fetch Spiritual Formator Evaluation
$sql_se = "SELECT r.average_rating, r.interpretation, r.prayer_life_avg, r.sacramental_participation_avg, r.spiritual_discipline_avg, 
                  e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
                  up.full_name as formator_name
           FROM spiritual_evaluations e 
           JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id 
           LEFT JOIN users u ON u.id = e.spiritual_director_id
           LEFT JOIN user_profiles up ON up.user_id = u.id
           WHERE e.seminarian_id = ? AND e.archived_at IS NULL";
$params_se = [$seminarian_id];
$types_se = 'i';
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $sql_se .= " AND e.academic_year = ?";
    $params_se[] = $filter_academic_year;
    $types_se .= 's';
}
if ($filter_semester !== '' && $filter_semester !== 'all') {
    $sql_se .= " AND e.semester = ?";
    $params_se[] = $filter_semester;
    $types_se .= 's';
}
$sql_se .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";

$stmt_se = $conn->prepare($sql_se);
$bind_se = [$types_se];
foreach ($params_se as $k => $v) { $bind_se[] = &$params_se[$k]; }
call_user_func_array([$stmt_se, 'bind_param'], $bind_se);
$stmt_se->execute();
if ($r_se = $stmt_se->get_result()->fetch_assoc()) {
    $eval_details['Spiritual']['score'] = (float)$r_se['average_rating'];
    $sp_interp = getFormationInterpretation($eval_details['Spiritual']['score']);
    $eval_details['Spiritual']['label'] = (string)$sp_interp['label'];
    $eval_details['Spiritual']['badge_class'] = (string)$sp_interp['badge_class'];
    $eval_details['Spiritual']['eval_id'] = (int)$r_se['evaluation_id'];
    $eval_details['Spiritual']['evaluation_date'] = (string)$r_se['evaluation_date'];
    $eval_details['Spiritual']['evaluator_name'] = (string)($r_se['formator_name'] ?? '');
    if ((float)($r_se['prayer_life_avg'] ?? 0) > 0) {
        $eval_details['Spiritual']['sub_metric'] = 'Prayer Life: ' . number_format((float)$r_se['prayer_life_avg'], 2) . ' / 5.0';
    } else {
        $eval_details['Spiritual']['sub_metric'] = 'Spiritual Direction Active';
    }
}

// 2. Fetch Human Formator Evaluation
$sql_he = "SELECT r.average_rating, r.interpretation, r.emotional_maturity_avg, r.responsibility_avg, r.interpersonal_avg, 
                  e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
                  up.full_name as formator_name
           FROM human_evaluations e 
           JOIN human_results r ON r.evaluation_id = e.evaluation_id 
           LEFT JOIN users u ON u.id = e.formator_id
           LEFT JOIN user_profiles up ON up.user_id = u.id
           WHERE e.seminarian_id = ? AND e.archived_at IS NULL";
$params_he = [$seminarian_id];
$types_he = 'i';
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $sql_he .= " AND e.academic_year = ?";
    $params_he[] = $filter_academic_year;
    $types_he .= 's';
}
if ($filter_semester !== '' && $filter_semester !== 'all') {
    $sql_he .= " AND e.semester = ?";
    $params_he[] = $filter_semester;
    $types_he .= 's';
}
$sql_he .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";

$stmt_he = $conn->prepare($sql_he);
$bind_he = [$types_he];
foreach ($params_he as $k => $v) { $bind_he[] = &$params_he[$k]; }
call_user_func_array([$stmt_he, 'bind_param'], $bind_he);
$stmt_he->execute();
if ($r_he = $stmt_he->get_result()->fetch_assoc()) {
    $eval_details['Human']['score'] = (float)$r_he['average_rating'];
    $hu_interp = getFormationInterpretation($eval_details['Human']['score']);
    $eval_details['Human']['label'] = (string)$hu_interp['label'];
    $eval_details['Human']['badge_class'] = (string)$hu_interp['badge_class'];
    $eval_details['Human']['eval_id'] = (int)$r_he['evaluation_id'];
    $eval_details['Human']['evaluation_date'] = (string)$r_he['evaluation_date'];
    $eval_details['Human']['evaluator_name'] = (string)($r_he['formator_name'] ?? '');
    if ((float)($r_he['emotional_maturity_avg'] ?? 0) > 0) {
        $eval_details['Human']['sub_metric'] = 'Maturity: ' . number_format((float)$r_he['emotional_maturity_avg'], 2) . ' / 5.0';
    } else {
        $eval_details['Human']['sub_metric'] = 'Discipline & Conduct';
    }
}

// 3. Fetch Pastoral Formator Evaluation
$sql_pe = "SELECT r.average_rating, r.interpretation, r.pastoral_ministry_avg, r.leadership_avg, r.community_engagement_avg, 
                  e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
                  up.full_name as formator_name
           FROM pastoral_evaluations e 
           JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id 
           LEFT JOIN users u ON u.id = e.formator_id
           LEFT JOIN user_profiles up ON up.user_id = u.id
           WHERE e.seminarian_id = ? AND e.archived_at IS NULL";
$params_pe = [$seminarian_id];
$types_pe = 'i';
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $sql_pe .= " AND e.academic_year = ?";
    $params_pe[] = $filter_academic_year;
    $types_pe .= 's';
}
if ($filter_semester !== '' && $filter_semester !== 'all') {
    $sql_pe .= " AND e.semester = ?";
    $params_pe[] = $filter_semester;
    $types_pe .= 's';
}
$sql_pe .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";

$stmt_pe = $conn->prepare($sql_pe);
$bind_pe = [$types_pe];
foreach ($params_pe as $k => $v) { $bind_pe[] = &$params_pe[$k]; }
call_user_func_array([$stmt_pe, 'bind_param'], $bind_pe);
$stmt_pe->execute();
if ($r_pe = $stmt_pe->get_result()->fetch_assoc()) {
    $eval_details['Pastoral']['score'] = (float)$r_pe['average_rating'];
    $pa_interp = getFormationInterpretation($eval_details['Pastoral']['score']);
    $eval_details['Pastoral']['label'] = (string)$pa_interp['label'];
    $eval_details['Pastoral']['badge_class'] = (string)$pa_interp['badge_class'];
    $eval_details['Pastoral']['eval_id'] = (int)$r_pe['evaluation_id'];
    $eval_details['Pastoral']['evaluation_date'] = (string)$r_pe['evaluation_date'];
    $eval_details['Pastoral']['evaluator_name'] = (string)($r_pe['formator_name'] ?? '');
    if ((float)($r_pe['pastoral_ministry_avg'] ?? 0) > 0) {
        $eval_details['Pastoral']['sub_metric'] = 'Apostolate: ' . number_format((float)$r_pe['pastoral_ministry_avg'], 2) . ' / 5.0';
    } else {
        $eval_details['Pastoral']['sub_metric'] = 'Parish Ministry Active';
    }
}

// 4. Fetch Academic Formator Evaluation
$sql_ae = "SELECT r.average_score, r.rating_label, r.recommendation, e.id, e.academic_year, e.semester, e.evaluation_date, e.gpa, e.remarks,
                  up.full_name as formator_name
           FROM academic_evaluations e 
           JOIN academic_evaluation_results r ON r.evaluation_id = e.id 
           LEFT JOIN users u ON u.id = e.formator_id
           LEFT JOIN user_profiles up ON up.user_id = u.id
           WHERE e.student_id = ? AND e.archived_at IS NULL";
$params_ae = [$seminarian_id];
$types_ae = 'i';
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $sql_ae .= " AND e.academic_year = ?";
    $params_ae[] = $filter_academic_year;
    $types_ae .= 's';
}
if ($filter_semester !== '' && $filter_semester !== 'all') {
    $sql_ae .= " AND e.semester = ?";
    $params_ae[] = $filter_semester;
    $types_ae .= 's';
}
$sql_ae .= " ORDER BY e.evaluation_date DESC, e.id DESC LIMIT 1";

$stmt_ae = $conn->prepare($sql_ae);
$bind_ae = [$types_ae];
foreach ($params_ae as $k => $v) { $bind_ae[] = &$params_ae[$k]; }
call_user_func_array([$stmt_ae, 'bind_param'], $bind_ae);
$stmt_ae->execute();
if ($r_ae = $stmt_ae->get_result()->fetch_assoc()) {
    $eval_details['Academic']['score'] = (float)$r_ae['average_score'];
    $ac_interp = getFormationInterpretation($eval_details['Academic']['score']);
    $eval_details['Academic']['label'] = (string)$ac_interp['label'];
    $eval_details['Academic']['badge_class'] = (string)$ac_interp['badge_class'];
    $eval_details['Academic']['eval_id'] = (int)$r_ae['id'];
    $eval_details['Academic']['evaluation_date'] = (string)$r_ae['evaluation_date'];
    $eval_details['Academic']['evaluator_name'] = (string)($r_ae['formator_name'] ?? '');
    if (!empty($r_ae['gpa'])) {
        $eval_details['Academic']['sub_metric'] = 'GPA: ' . number_format((float)$r_ae['gpa'], 2) . ' (' . ($r_ae['recommendation'] ?: 'Standing') . ')';
    } else {
        $eval_details['Academic']['sub_metric'] = (string)($r_ae['recommendation'] ?: 'Intellectual Progress');
    }
} else {
    // Fallback: Check gpa_records for Academic Formator standing
    $sql_gpa = "SELECT semester_gpa, cumulative_gpa, performance_category FROM gpa_records WHERE student_id = ?";
    $params_gpa = [$seminarian_id];
    $types_gpa = 'i';
    if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
        $sql_gpa .= " AND academic_year = ?";
        $params_gpa[] = $filter_academic_year;
        $types_gpa .= 's';
    }
    if ($filter_semester !== '' && $filter_semester !== 'all') {
        $sql_gpa .= " AND semester = ?";
        $params_gpa[] = $filter_semester;
        $types_gpa .= 's';
    }
    $sql_gpa .= " ORDER BY academic_year DESC, computed_at DESC LIMIT 1";
    $stmt_gpa = $conn->prepare($sql_gpa);
    $bind_gpa = [$types_gpa];
    foreach ($params_gpa as $k => $v) { $bind_gpa[] = &$params_gpa[$k]; }
    call_user_func_array([$stmt_gpa, 'bind_param'], $bind_gpa);
    $stmt_gpa->execute();
    if ($r_gpa = $stmt_gpa->get_result()->fetch_assoc()) {
        $sgpa = (float)($r_gpa['semester_gpa'] ?? $r_gpa['cumulative_gpa'] ?? 0);
        if ($sgpa > 0) {
            $calc_score = round(max(1.0, min(5.0, 6.00 - $sgpa)), 2);
            $eval_details['Academic']['score'] = $calc_score;
            $eval_details['Academic']['label'] = (string)($r_gpa['performance_category'] ?: formator_score_to_label($calc_score));
            $eval_details['Academic']['sub_metric'] = 'GPA: ' . number_format($sgpa, 2) . ' • ' . ($r_gpa['performance_category'] ?: 'Regular');
        }
    }
}

// Legacy evaluations fallback if any
$stmt_eval_avg = $conn->prepare("SELECT type, AVG(score) as avg_score FROM evaluations WHERE seminarian_id = ? AND archived_at IS NULL GROUP BY type");
$stmt_eval_avg->bind_param('i', $seminarian_id);
$stmt_eval_avg->execute();
$eval_avg_res = $stmt_eval_avg->get_result();
while ($row = $eval_avg_res->fetch_assoc()) {
    $t = (string)$row['type'];
    if (isset($eval_details[$t]) && $eval_details[$t]['score'] <= 0) {
        $eval_details[$t]['score'] = (float)$row['avg_score'];
        if (empty($eval_details[$t]['label'])) {
            $eval_details[$t]['label'] = formator_score_to_label((float)$row['avg_score']);
        }
    }
}

$pillar_scores = [
    'Academic' => ($eval_details['Academic']['score'] > 0 ? (float)$eval_details['Academic']['score'] : (float)$academic_avg),
    'Pastoral' => (float)($eval_details['Pastoral']['score']),
    'Spiritual' => (float)($eval_details['Spiritual']['score']),
    'Human' => (float)($eval_details['Human']['score']),
];

$overall_components = [];
foreach ($pillar_scores as $v) {
    if (is_numeric($v) && (float)$v > 0) {
        $overall_components[] = (float)$v;
    }
}
$overall_avg = 0.0;
$overall_is_complete = (count($overall_components) === 4);
if ($overall_is_complete) {
    $overall_avg = round(array_sum($overall_components) / 4, 2, PHP_ROUND_HALF_UP);
} elseif (count($overall_components) > 0) {
    $overall_avg = round(array_sum($overall_components) / count($overall_components), 2, PHP_ROUND_HALF_UP);
}
$overall_percent = 0;
if ($overall_is_complete && $overall_avg > 0) {
    $overall_percent = (int)round(($overall_avg / 5) * 100);
    if ($overall_percent < 0) $overall_percent = 0;
    if ($overall_percent > 100) $overall_percent = 100;
}

// Consolidated Formation Status computation using unified 5-point scale
$overall_interp = $overall_is_complete ? getFormationInterpretation($overall_avg) : getFormationInterpretation(null);
$formation_progress_status = (string)$overall_interp['key'];

$term_context_badge = ($filter_semester !== 'all' ? $filter_semester . ' Semester • ' : '') . 
                      ($filter_academic_year === 'all' ? 'All Academic Years' : 'A.Y. ' . $filter_academic_year);

$total_billed = 0.0;
$total_paid_allocated = 0.0;
$balance = 0.0;

if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $stmt_billed = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
        FROM lodging_invoices i
        JOIN lodging_invoice_items it ON it.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL
          AND i.status IN ('issued', 'partially_paid', 'paid')
          AND i.academic_year = ?");
    $stmt_billed->bind_param('is', $seminarian_id, $filter_academic_year);
    $stmt_billed->execute();
    $total_billed = (float)($stmt_billed->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(pa.amount),0) as total
        FROM lodging_invoices i
        JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL
          AND i.academic_year = ?");
    $stmt_paid->bind_param('is', $seminarian_id, $filter_academic_year);
    $stmt_paid->execute();
    $total_paid_allocated = (float)($stmt_paid->get_result()->fetch_assoc()['total'] ?? 0);
} else {
    $stmt_billed = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
        FROM lodging_invoices i
        JOIN lodging_invoice_items it ON it.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL
          AND i.status IN ('issued', 'partially_paid', 'paid')");
    $stmt_billed->bind_param('i', $seminarian_id);
    $stmt_billed->execute();
    $total_billed = (float)($stmt_billed->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(pa.amount),0) as total
        FROM lodging_invoices i
        JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL");
    $stmt_paid->bind_param('i', $seminarian_id);
    $stmt_paid->execute();
    $total_paid_allocated = (float)($stmt_paid->get_result()->fetch_assoc()['total'] ?? 0);
}

$balance = $total_billed - $total_paid_allocated;
if ($balance < 0) $balance = 0.0;

$from_date = date('Y-m-01');
$to_date = date('Y-m-d');

$profile_url_params = 'seminarian_id=' . (int)$seminarian_id . '&academic_year=' . urlencode($filter_academic_year);

$active_tab = 'overview';
if (isset($_GET['tab'])) {
    if ($_GET['tab'] === 'enrollment') {
        $active_tab = 'enrollment';
    } elseif ($_GET['tab'] === 'documents') {
        $active_tab = 'documents';
    }
}

// Fetch physical documents for this seminarian
$seminarian_documents = [];
$stmt_get_docs = $conn->prepare("SELECT id, seminarian_id, document_type, file_name, file_path, file_size, uploaded_by, uploaded_at FROM seminarian_documents WHERE seminarian_id = ? ORDER BY uploaded_at DESC, id DESC");
if ($stmt_get_docs) {
    $stmt_get_docs->bind_param('i', $seminarian_id);
    $stmt_get_docs->execute();
    $res_get_docs = $stmt_get_docs->get_result();
    while ($doc_row = $res_get_docs->fetch_assoc()) {
        $seminarian_documents[] = $doc_row;
    }
}

if (!function_exists('format_file_size_human')) {
    function format_file_size_human($bytes) {
        $bytes = (float)$bytes;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes) . ' B';
    }
}

if (!function_exists('normalize_stage')) {
    function normalize_stage($program, $year_level) {
        if (stripos((string)$program, 'Propaedeutic') !== false || stripos((string)$year_level, 'Propaedeutic') !== false) {
            return 'Propaedeutic Stage';
        }
        return 'Bachelor of Arts in Philosophy';
    }
}

if (!function_exists('normalize_year_level')) {
    function normalize_year_level($yl) {
        $yl = trim((string)$yl);
        if (stripos($yl, 'Propaedeutic') !== false) return 'Propaedeutic Stage';
        if (stripos($yl, 'First') !== false || stripos($yl, '1st') !== false) return 'First Year';
        if (stripos($yl, 'Second') !== false || stripos($yl, '2nd') !== false) return 'Second Year';
        if (stripos($yl, 'Third') !== false || stripos($yl, '3rd') !== false) return 'Third Year';
        if (stripos($yl, 'Fourth') !== false || stripos($yl, '4th') !== false) return 'Fourth Year';
        return $yl ?: 'First Year';
    }
}

if (!function_exists('normalize_semester')) {
    function normalize_semester($sem) {
        $sem = trim((string)$sem);
        if (stripos($sem, '1') !== false || stripos($sem, 'first') !== false) return '1st';
        if (stripos($sem, '2') !== false || stripos($sem, 'second') !== false) return '2nd';
        if (stripos($sem, 'summer') !== false) return 'Summer';
        return '1st';
    }
}

if (!function_exists('get_seminarian_enrollment_tree')) {
    function get_seminarian_enrollment_tree($conn, $student_id) {
        $sem_state = null;
        $stmt_cur_sem = $conn->prepare("SELECT year_level, program, current_academic_year FROM seminarians WHERE id = ? LIMIT 1");
        if ($stmt_cur_sem) {
            $stmt_cur_sem->bind_param('i', $student_id);
            $stmt_cur_sem->execute();
            $sem_state = $stmt_cur_sem->get_result()->fetch_assoc();
        }
        $cur_sem_yl_norm = normalize_year_level((string)($sem_state['year_level'] ?? ''));
        $restrict_to_prop = ($cur_sem_yl_norm === 'Propaedeutic Stage')
            && function_exists('registrar_has_completed_propaedeutic')
            && !registrar_has_completed_propaedeutic($conn, (int)$student_id);

        // Query enrollments with grades joined
        $sql1 = "SELECT 
                    es.id as enrollment_id,
                    es.student_id,
                    es.subject_id,
                    es.academic_year,
                    es.semester,
                    COALESCE(es.program, cv.program, s_info.program, 'Bachelor of Arts in Philosophy') as raw_program,
                    COALESCE(es.year_level, cs.year_level, s_info.year_level, 'First Year') as raw_year_level,
                    es.status as enrollment_status,
                    s.subject_code,
                    s.subject_name,
                    s.units,
                    sg.grade_id,
                    sg.final_grade,
                    sg.final_computed_grade,
                    sg.status as grade_status,
                    sg.remarks
                FROM student_enrolled_subjects es
                JOIN subjects s ON s.subject_id = es.subject_id
                JOIN seminarians s_info ON s_info.id = es.student_id
                LEFT JOIN student_grades sg ON (sg.student_id = es.student_id 
                                            AND sg.subject_id = es.subject_id 
                                            AND sg.academic_year = es.academic_year 
                                            AND sg.semester = es.semester)
                LEFT JOIN curriculum_subjects cs ON cs.subject_id = es.subject_id
                LEFT JOIN curriculum_versions cv ON cv.id = cs.curriculum_version_id
                WHERE es.student_id = ?
                GROUP BY es.id";

        // Query historical/standalone grades without enrollment rows
        $sql2 = "SELECT 
                    NULL as enrollment_id,
                    sg.student_id,
                    sg.subject_id,
                    sg.academic_year,
                    sg.semester,
                    COALESCE(cv.program, s_info.program, 'Bachelor of Arts in Philosophy') as raw_program,
                    COALESCE(cs.year_level, s_info.year_level, 'First Year') as raw_year_level,
                    COALESCE(sg.status, 'Completed') as enrollment_status,
                    s.subject_code,
                    s.subject_name,
                    s.units,
                    sg.grade_id,
                    sg.final_grade,
                    sg.final_computed_grade,
                    sg.status as grade_status,
                    sg.remarks
                FROM student_grades sg
                JOIN subjects s ON s.subject_id = sg.subject_id
                JOIN seminarians s_info ON s_info.id = sg.student_id
                LEFT JOIN student_enrolled_subjects es ON (es.student_id = sg.student_id 
                                                      AND es.subject_id = sg.subject_id 
                                                      AND es.academic_year = sg.academic_year 
                                                      AND es.semester = sg.semester)
                LEFT JOIN curriculum_subjects cs ON cs.subject_id = sg.subject_id
                LEFT JOIN curriculum_versions cv ON cv.id = cs.curriculum_version_id
                WHERE sg.student_id = ? AND es.id IS NULL
                GROUP BY sg.grade_id";

        $raw_records = [];

        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param('i', $student_id);
        $stmt1->execute();
        $res1 = $stmt1->get_result();
        while ($r = $res1->fetch_assoc()) {
            $raw_records[] = $r;
        }

        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param('i', $student_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($r = $res2->fetch_assoc()) {
            $raw_records[] = $r;
        }

        // Canonical 3-level tree structure:
        // Level 1: "Propaedeutic Stage" and "Bachelor of Arts in Philosophy"
        // Level 2: Year Levels (Propaedeutic Year, First Year, Second Year, Third Year, Fourth Year)
        // Level 3: Semester (1st Semester, 2nd Semester)
        $tree = [
            'Propaedeutic Stage' => [
                'key' => 'Propaedeutic Stage',
                'title' => 'Propaedeutic Stage',
                'subtitle' => 'Foundational Spiritual, Human & Academic Formation',
                'icon' => 'fa-seedling',
                'color' => '#059669',
                'bg_light' => '#ecfdf5',
                'border_color' => '#a7f3d0',
                'has_records' => false,
                'total_subjects' => 0,
                'total_units' => 0,
                'total_passed_units' => 0,
                'year_levels' => [
                    'Propaedeutic Stage' => [
                        'key' => 'Propaedeutic Stage',
                        'title' => 'Propaedeutic Year',
                        'has_records' => false,
                        'academic_years' => [],
                        'total_subjects' => 0,
                        'total_units' => 0,
                        'semesters' => [
                            '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                            '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                        ]
                    ]
                ]
            ],
            'Bachelor of Arts in Philosophy' => [
                'key' => 'Bachelor of Arts in Philosophy',
                'title' => 'Bachelor of Arts in Philosophy',
                'subtitle' => 'Canonical Four-Year Collegiate Philosophy Curriculum',
                'icon' => 'fa-graduation-cap',
                'color' => '#4f46e5',
                'bg_light' => '#eef2ff',
                'border_color' => '#c7d2fe',
                'has_records' => false,
                'total_subjects' => 0,
                'total_units' => 0,
                'total_passed_units' => 0,
                'year_levels' => [
                    'First Year' => [
                        'key' => 'First Year',
                        'title' => 'First Year (Philosophy I)',
                        'has_records' => false,
                        'academic_years' => [],
                        'total_subjects' => 0,
                        'total_units' => 0,
                        'semesters' => [
                            '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                            '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                        ]
                    ],
                    'Second Year' => [
                        'key' => 'Second Year',
                        'title' => 'Second Year (Philosophy II)',
                        'has_records' => false,
                        'academic_years' => [],
                        'total_subjects' => 0,
                        'total_units' => 0,
                        'semesters' => [
                            '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                            '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                        ]
                    ],
                    'Third Year' => [
                        'key' => 'Third Year',
                        'title' => 'Third Year (Philosophy III)',
                        'has_records' => false,
                        'academic_years' => [],
                        'total_subjects' => 0,
                        'total_units' => 0,
                        'semesters' => [
                            '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                            '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                        ]
                    ],
                    'Fourth Year' => [
                        'key' => 'Fourth Year',
                        'title' => 'Fourth Year (Philosophy IV)',
                        'has_records' => false,
                        'academic_years' => [],
                        'total_subjects' => 0,
                        'total_units' => 0,
                        'semesters' => [
                            '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                            '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                        ]
                    ]
                ]
            ]
        ];

        $overall_stats = [
            'total_subjects' => 0,
            'total_units' => 0,
            'passed_units' => 0,
            'in_progress_units' => 0,
            'failed_units' => 0,
            'incomplete_units' => 0
        ];

        foreach ($raw_records as $rec) {
            $stage = normalize_stage($rec['raw_program'], $rec['raw_year_level']);
            $yl = normalize_year_level($rec['raw_year_level']);
            $sem = normalize_semester($rec['semester']);
            $ay = trim((string)($rec['academic_year'] ?? ''));

            if ($restrict_to_prop && ($stage !== 'Propaedeutic Stage' || $yl !== 'Propaedeutic Stage')) {
                continue;
            }

            if (!isset($tree[$stage])) {
                $tree[$stage] = [
                    'key' => $stage,
                    'title' => $stage,
                    'subtitle' => 'Academic Formation Stage',
                    'icon' => 'fa-book',
                    'color' => '#0284c7',
                    'bg_light' => '#f0f9ff',
                    'border_color' => '#bae6fd',
                    'has_records' => false,
                    'total_subjects' => 0,
                    'total_units' => 0,
                    'total_passed_units' => 0,
                    'year_levels' => []
                ];
            }

            if (!isset($tree[$stage]['year_levels'][$yl])) {
                $tree[$stage]['year_levels'][$yl] = [
                    'key' => $yl,
                    'title' => $yl,
                    'has_records' => false,
                    'academic_years' => [],
                    'total_subjects' => 0,
                    'total_units' => 0,
                    'semesters' => [
                        '1st' => ['name' => '1st Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0],
                        '2nd' => ['name' => '2nd Semester', 'subjects' => [], 'total_units' => 0, 'passed_units' => 0]
                    ]
                ];
            }

            if (!isset($tree[$stage]['year_levels'][$yl]['semesters'][$sem])) {
                $tree[$stage]['year_levels'][$yl]['semesters'][$sem] = [
                    'name' => ($sem === 'Summer' ? 'Summer Term' : $sem . ' Semester'),
                    'subjects' => [],
                    'total_units' => 0,
                    'passed_units' => 0
                ];
            }

            $units = (float)($rec['units'] ?? 0);
            $final_grade = $rec['final_grade'];
            $grade_status = $rec['grade_status'];
            $enrollment_status = $rec['enrollment_status'];

            $is_passed = false;
            $is_failed = false;
            $is_incomplete = false;

            if ($final_grade !== null && $final_grade !== '') {
                $num_grade = (float)$final_grade;
                if ($num_grade >= 75.0) {
                    $is_passed = true;
                    $badge_class = 'acad-badge-pass';
                    $badge_icon = 'fa-circle-check';
                    $display_status = 'Passed (' . round($num_grade) . ')';
                } elseif ($num_grade > 0.0) {
                    $is_failed = true;
                    $badge_class = 'acad-badge-fail';
                    $badge_icon = 'fa-circle-xmark';
                    $display_status = 'Failed (' . round($num_grade) . ')';
                } else {
                    if ($grade_status === 'Incomplete') {
                        $is_incomplete = true;
                        $badge_class = 'acad-badge-inc';
                        $badge_icon = 'fa-triangle-exclamation';
                        $display_status = 'Incomplete';
                    } else {
                        $badge_class = 'acad-badge-zero';
                        $badge_icon = 'fa-circle-question';
                        $display_status = 'No Grade';
                    }
                }
            } elseif ($enrollment_status === 'Completed') {
                $is_passed = true;
                $badge_class = 'acad-badge-pass';
                $badge_icon = 'fa-circle-check';
                $display_status = 'Completed';
            } elseif ($enrollment_status === 'Dropped') {
                $is_failed = true;
                $badge_class = 'acad-badge-drop';
                $badge_icon = 'fa-ban';
                $display_status = 'Dropped';
            } else {
                $badge_class = 'acad-badge-enrolled';
                $badge_icon = 'fa-book-open';
                $display_status = 'Enrolled';
            }

            $rec['display_status'] = $display_status;
            $rec['badge_class'] = $badge_class;
            $rec['badge_icon'] = $badge_icon;
            $rec['is_passed'] = $is_passed;

            $tree[$stage]['has_records'] = true;
            $tree[$stage]['total_subjects']++;
            $tree[$stage]['total_units'] += $units;

            $tree[$stage]['year_levels'][$yl]['has_records'] = true;
            $tree[$stage]['year_levels'][$yl]['total_subjects']++;
            $tree[$stage]['year_levels'][$yl]['total_units'] += $units;
            if ($ay !== '' && !in_array($ay, $tree[$stage]['year_levels'][$yl]['academic_years'], true)) {
                $tree[$stage]['year_levels'][$yl]['academic_years'][] = $ay;
            }

            $tree[$stage]['year_levels'][$yl]['semesters'][$sem]['total_units'] += $units;
            if ($is_passed) {
                $tree[$stage]['total_passed_units'] += $units;
                $tree[$stage]['year_levels'][$yl]['semesters'][$sem]['passed_units'] += $units;
                $overall_stats['passed_units'] += $units;
            } elseif ($is_failed) {
                $overall_stats['failed_units'] += $units;
            } elseif ($is_incomplete) {
                $overall_stats['incomplete_units'] += $units;
            } else {
                $overall_stats['in_progress_units'] += $units;
            }

            $overall_stats['total_subjects']++;
            $overall_stats['total_units'] += $units;

            $tree[$stage]['year_levels'][$yl]['semesters'][$sem]['subjects'][] = $rec;
        }

        // Sort subjects in each semester block alphabetically by subject_code
        foreach ($tree as $s_key => &$stg) {
            foreach ($stg['year_levels'] as $y_key => &$y_data) {
                foreach ($y_data['semesters'] as $sem_key => &$sem_data) {
                    usort($sem_data['subjects'], function($a, $b) {
                        return strnatcasecmp((string)($a['subject_code'] ?? ''), (string)($b['subject_code'] ?? ''));
                    });
                }
            }
        }
        unset($stg, $y_data, $sem_data);

        return [$tree, $overall_stats];
    }
}

list($enrollment_tree, $enrollment_stats) = get_seminarian_enrollment_tree($conn, $seminarian_id);
?>
<?php
$pageTitle = 'Seminarian Profile';
$activeNav = 'applicants';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <a href="applicants.php" style="color: var(--primary); text-decoration: none;">Manage Seminarian</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Seminarian Profile</span>
            </nav>

            <!-- Profile Header -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6 pb-4 border-b border-slate-200/80">
                <div class="flex items-center gap-3.5">
                    <?php if (!empty($seminarian_avatar_url)): ?>
                        <div class="w-14 h-14 rounded-2xl overflow-hidden border-2 border-[#E8C7C5] shadow-sm flex-shrink-0 bg-[#E8C7C5]">
                            <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover">
                        </div>
                    <?php else: ?>
                        <div class="w-14 h-14 rounded-2xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shadow-sm flex-shrink-0 text-lg font-black border-2 border-[#E8C7C5]/80">
                            <?php echo htmlspecialchars($seminarian_initials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight m-0">
                                <?php echo htmlspecialchars($display_name); ?>
                            </h1>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                                <?php echo htmlspecialchars((string)($profile['applicant_status'] ?? 'Enrolled')); ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-3 mt-1 text-xs font-medium text-slate-500">
                            <span class="inline-flex items-center gap-1.5">
                                <i class="fas fa-user text-slate-400"></i>
                                <span class="font-semibold text-slate-700"><?php echo htmlspecialchars((string)($profile['username'] ?? '')); ?></span>
                            </span>
                            <span class="text-slate-300">•</span>
                            <span class="inline-flex items-center gap-1 font-mono text-slate-500">
                                ID #<?php echo (int)$seminarian_id; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-end gap-3 flex-wrap">
                    <div>
                        <label for="academicYearSelect" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Academic Year</label>
                        <div class="relative min-w-[175px]">
                            <select id="academicYearSelect" class="w-full h-10 pl-3 pr-8 text-sm font-semibold text-slate-800 bg-white border border-slate-200 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500 transition cursor-pointer appearance-none">
                                <option value="all" <?php echo $filter_academic_year === 'all' ? 'selected' : ''; ?>>All Academic Years</option>
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $filter_academic_year === $ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-slate-400 text-xs">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="semesterSelect" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Semester</label>
                        <div class="relative min-w-[150px]">
                            <select id="semesterSelect" class="w-full h-10 pl-3 pr-8 text-sm font-semibold text-slate-800 bg-white border border-slate-200 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/30 focus:border-teal-500 transition cursor-pointer appearance-none">
                                <option value="all" <?php echo $filter_semester === 'all' ? 'selected' : ''; ?>>All Semesters</option>
                                <option value="1st" <?php echo $filter_semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd" <?php echo $filter_semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                                <option value="Summer" <?php echo $filter_semester === 'Summer' ? 'selected' : ''; ?>>Summer Term</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-slate-400 text-xs">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    <?php if ($is_registrar) { ?>
                    <a href="enrollment_records.php?student_id=<?php echo (int)$seminarian_id; ?>" class="h-10 inline-flex items-center gap-2 px-4 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-[0.98] shadow-sm hover:shadow transition text-decoration-none">
                        <i class="fas fa-layer-group"></i>
                        <span>Enrollment History</span>
                    </a>
                    <a href="subject_enrollment.php?seminarian_id=<?php echo (int)$seminarian_id; ?>" class="h-10 inline-flex items-center gap-2 px-4 rounded-xl text-sm font-semibold text-white bg-teal-700 hover:bg-teal-800 active:scale-[0.98] shadow-sm hover:shadow transition text-decoration-none">
                        <i class="fas fa-book-bookmark"></i>
                        <span>Enroll Subjects</span>
                    </a>
                    <?php } ?>
                    <button type="button" onclick="switchProfileTab('documents')" class="h-10 inline-flex items-center gap-2 px-4 rounded-xl text-sm font-semibold text-white bg-[#59443F] hover:bg-[#463430] active:scale-[0.98] shadow-sm hover:shadow transition border-0 cursor-pointer">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>Documents (<?php echo count($seminarian_documents); ?>)</span>
                    </button>
                </div>
            </div>

            <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

            <!-- Modern Stat Cards (Top Row) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <!-- Status Card -->
                <div class="group bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold tracking-wider uppercase text-slate-400 block mb-1">Status</span>
                        <div class="text-2xl font-black text-slate-900 tracking-tight">
                            <?php echo htmlspecialchars((string)($profile['applicant_status'] ?? 'Enrolled')); ?>
                        </div>
                        <span class="inline-flex items-center text-[11px] font-semibold text-emerald-600 mt-1">
                            <i class="fas fa-circle-check mr-1 text-[10px]"></i> Active Formation
                        </span>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg flex-shrink-0 group-hover:scale-110 transition-transform duration-200 shadow-sm">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>

                <!-- Overall Performance Card -->
                <div class="group bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold tracking-wider uppercase text-slate-400 block mb-1">Overall Performance</span>
                        <div class="text-2xl font-black text-slate-900 tracking-tight flex items-baseline gap-1">
                            <?php echo (int)$overall_percent; ?><span class="text-base font-bold text-slate-400">%</span>
                        </div>
                        <span class="inline-flex items-center text-[11px] font-semibold text-blue-600 mt-1">
                            <i class="fas fa-trophy mr-1 text-[10px]"></i> <?php echo htmlspecialchars(get_overall_percent_label((int)$overall_percent)); ?>
                        </span>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-lg flex-shrink-0 group-hover:scale-110 transition-transform duration-200 shadow-sm">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>

                <!-- Remaining Balance Card -->
                <div class="group bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold tracking-wider uppercase text-slate-400 block mb-1">Remaining Balance</span>
                        <div class="text-2xl font-black text-slate-900 tracking-tight">
                            ₱<?php echo htmlspecialchars(number_format($balance, 2)); ?>
                        </div>
                        <span class="inline-flex items-center text-[11px] font-semibold <?php echo $balance > 0 ? 'text-amber-600' : 'text-emerald-600'; ?> mt-1">
                            <i class="fas <?php echo $balance > 0 ? 'fa-clock' : 'fa-check'; ?> mr-1 text-[10px]"></i>
                            <?php echo $balance > 0 ? 'Pending Payment' : 'Fully Settled'; ?>
                        </span>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-lg flex-shrink-0 group-hover:scale-110 transition-transform duration-200 shadow-sm">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>

                <!-- Total Subjects Card -->
                <div class="group bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold tracking-wider uppercase text-slate-400 block mb-1">Total Subjects</span>
                        <div class="text-2xl font-black text-slate-900 tracking-tight flex items-baseline gap-1.5">
                            <?php echo (int)$enrollment_stats['total_subjects']; ?>
                            <span class="text-xs font-bold text-slate-400">(<?php echo (int)$enrollment_stats['total_units']; ?> units)</span>
                        </div>
                        <span class="inline-flex items-center text-[11px] font-semibold text-indigo-600 mt-1">
                            <i class="fas fa-book-open mr-1 text-[10px]"></i> Enrolled Subjects
                        </span>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg flex-shrink-0 group-hover:scale-110 transition-transform duration-200 shadow-sm">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                </div>
            </div>


            <style>
            .profile-tabs-nav {
                display: inline-flex;
                gap: 0.5rem;
                background: #f1f5f9;
                padding: 0.35rem;
                border-radius: 12px;
                margin-bottom: 1.5rem;
                border: 1px solid #e2e8f0;
                width: fit-content;
                flex-wrap: wrap;
            }
            .profile-tab-btn {
                display: inline-flex;
                align-items: center;
                gap: 0.6rem;
                padding: 0.65rem 1.25rem;
                font-size: 0.88rem;
                font-weight: 700;
                color: #64748b;
                background: transparent;
                border: none;
                border-radius: 9px;
                cursor: pointer;
                transition: all 0.2s ease;
                text-decoration: none;
            }
            .profile-tab-btn:hover {
                color: #0f172a;
                background: rgba(255,255,255,0.7);
            }
            .profile-tab-btn.active {
                color: #0f766e;
                background: #ffffff;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            }
            .tab-badge {
                display: inline-block;
                padding: 0.15rem 0.55rem;
                font-size: 0.72rem;
                font-weight: 800;
                border-radius: 9999px;
                background: #e2e8f0;
                color: #64748b;
            }
            .tab-badge.badge-has-records {
                background: #ccfbf1;
                color: #0f766e;
            }
            .acad-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.82rem;
            }
            .acad-table th {
                background: #f8fafc;
                color: #64748b;
                font-weight: 700;
                font-size: 0.72rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                padding: 0.65rem 0.85rem;
                border-bottom: 1px solid #e2e8f0;
                text-align: left;
            }
            .acad-table td {
                padding: 0.65rem 0.85rem;
                border-bottom: 1px solid #f1f5f9;
                color: #334155;
                vertical-align: middle;
            }
            .acad-table tbody tr:hover {
                background: #f8fafc;
            }
            .acad-table tfoot td {
                padding: 0.65rem 0.85rem;
                background: #f8fafc;
                font-weight: 700;
                border-top: 1px solid #e2e8f0;
                color: #0f172a;
            }
            .acad-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.25rem 0.65rem;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 700;
                white-space: nowrap;
            }
            .acad-badge-pass {
                background: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            .acad-badge-fail {
                background: #fef2f2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            .acad-badge-inc {
                background: #fffbeb;
                color: #92400e;
                border: 1px solid #fde68a;
            }
            .acad-badge-enrolled {
                background: #f0fdfa;
                color: #0f766e;
                border: 1px solid #99f6e4;
            }
            .acad-badge-drop {
                background: #f8fafc;
                color: #64748b;
                border: 1px solid #cbd5e1;
            }
            .acad-badge-zero {
                background: #f1f5f9;
                color: #475569;
                border: 1px solid #e2e8f0;
            }
            @media (max-width: 900px) {
                .semesters-grid {
                    grid-template-columns: 1fr !important;
                }
            }
            @media print {
                .main-nav, .sidebar, .sidebar-sub-box, .breadcrumb, .profile-tabs-nav, .pill-btn, #academicYearSelect, #semesterSelect, .action-btn-primary, button, .no-print {
                    display: none !important;
                }
                .profile-tab-pane {
                    display: block !important;
                }
                #pane-overview {
                    display: none !important;
                }
                #pane-enrollment {
                    display: block !important;
                }
                .print-only-header {
                    display: block !important;
                    margin-bottom: 2rem;
                    text-align: center;
                }
                .semesters-grid {
                    display: grid !important;
                    grid-template-columns: 1fr 1fr !important;
                    gap: 1rem !important;
                    page-break-inside: avoid;
                }
                .stage-section-card, .year-level-card {
                    page-break-inside: avoid;
                    box-shadow: none !important;
                    border: 1px solid #cbd5e1 !important;
                    margin-bottom: 1.5rem !important;
                }
            }
            </style>

            <div class="profile-tabs-nav no-print">
                <button type="button" class="profile-tab-btn <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" data-tab="overview" onclick="switchProfileTab('overview')">
                    <i class="fas fa-id-card"></i> Profile Overview
                </button>
                <button type="button" class="profile-tab-btn <?php echo $active_tab === 'enrollment' ? 'active' : ''; ?>" data-tab="enrollment" onclick="switchProfileTab('enrollment')">
                    <i class="fas fa-graduation-cap"></i> Enrollment Records
                    <span class="tab-badge <?php echo $enrollment_stats['total_subjects'] > 0 ? 'badge-has-records' : ''; ?>">
                        <?php echo (int)$enrollment_stats['total_subjects']; ?>
                    </span>
                </button>
                <button type="button" class="profile-tab-btn <?php echo $active_tab === 'documents' ? 'active' : ''; ?>" data-tab="documents" onclick="switchProfileTab('documents')">
                    <i class="fa-solid fa-folder-open"></i> Physical Documents
                    <span class="tab-badge <?php echo count($seminarian_documents) > 0 ? 'badge-has-records' : ''; ?>">
                        <?php echo count($seminarian_documents); ?>
                    </span>
                </button>
            </div>

            <!-- PANE 1: Profile Overview -->
            <div id="pane-overview" class="profile-tab-pane" style="<?php echo $active_tab === 'overview' ? 'display: block;' : 'display: none;'; ?>">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- Information Card (7 Columns) -->
                <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200/90 shadow-sm p-6">
                    <div class="flex items-center justify-between pb-4 mb-5 border-b border-slate-100">
                        <h3 class="text-base font-bold text-slate-900 flex items-center gap-2.5 m-0">
                            <span class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-sm shadow-sm border border-teal-100/80">
                                <i class="fas fa-address-card"></i>
                            </span>
                            Seminarian Information
                        </h3>
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600">
                            Profile Details
                        </span>
                    </div>

                    <!-- Seminarian Photo & Last Profile Update Status -->
                    <div class="flex items-center gap-4 p-4 rounded-2xl bg-[#F8EEEE]/80 border border-[#E8C7C5]/60 mb-5">
                        <div class="w-16 h-16 rounded-2xl overflow-hidden border-2 border-white shadow-soft bg-[#E8C7C5] flex items-center justify-center shrink-0">
                            <?php if (!empty($seminarian_avatar_url)): ?>
                                <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <span class="font-heading font-black text-xl text-[#59443F]"><?php echo htmlspecialchars($seminarian_initials, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h4 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] truncate m-0"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></h4>
                            <div class="text-xs text-[#8B7168] mt-1 flex items-center gap-2 flex-wrap">
                                <?php if (!empty($profile['last_profile_update'])): ?>
                                    <span>Last updated: <strong><?php echo date('M d, Y h:i A', strtotime($profile['last_profile_update'])); ?></strong></span>
                                    <?php if ($seminarian_cooldown && $seminarian_cooldown['is_active']): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                            <i class="fa-solid fa-lock text-[8px]"></i> 7d Cooldown Active
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span>No student profile updates recorded yet.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                        <!-- Full Name -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Full Name</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($display_name); ?></div>
                        </div>

                        <!-- Current Academic Year -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Current Academic Year</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['current_academic_year'] ?? $profile['applicant_academic_year'] ?? '—')); ?></div>
                        </div>

                        <!-- Year Level -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Year Level</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['year_level'] ?? '—')); ?></div>
                        </div>

                        <!-- Program -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Program</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['program'] ?? '—')); ?></div>
                        </div>

                        <!-- Formation Status -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Formation Status</div>
                            <div class="text-sm font-semibold text-slate-900">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-800">
                                    <?php echo htmlspecialchars((string)($profile['formation_status'] ?? '—')); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Email</div>
                            <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars((string)($profile['email'] ?? '—') ?: '—'); ?></div>
                        </div>

                        <?php if ((int)($profile['is_graduate'] ?? 0) === 1) { ?>
                        <!-- Date Completed -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Date Completed</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['completed_at'] ?? '—')); ?></div>
                        </div>

                        <!-- Academic Year Completed -->
                        <div class="space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Academic Year Completed</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['academic_year_completed'] ?? '—')); ?></div>
                        </div>

                        <!-- Program Completed -->
                        <div class="sm:col-span-2 space-y-1">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Program Completed</div>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($profile['program_completed'] ?? '—')); ?></div>
                        </div>
                        <?php } ?>

                        <!-- Contact & Parish Details (2-Column x 2-Row Structured Grid) -->
                        <div class="sm:col-span-2 pt-4 border-t border-[#E8C7C5]/40">
                            <div class="text-xs font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-2 mb-3">
                                <i class="fa-solid fa-address-book text-xs text-[#8B7168]"></i>
                                Contact & Parish Details
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                                <!-- Contact Number -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left transition-colors">
                                    <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                        <i class="fa-solid fa-phone text-[10px] text-[#8B7168]"></i>
                                        Contact Number
                                    </div>
                                    <?php if ($contact_display !== '' && $contact_display !== '—'): ?>
                                        <div class="text-sm font-semibold text-[#59443F] mt-1 truncate">
                                            <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact_display)); ?>" class="hover:underline hover:text-[#3d2f2b]">
                                                <?php echo htmlspecialchars($contact_display); ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Parish / Chaplaincy -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left transition-colors">
                                    <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                        <i class="fa-solid fa-church text-[10px] text-[#8B7168]"></i>
                                        Parish / Chaplaincy
                                    </div>
                                    <?php if ($parish_chaplaincy_display !== '' && $parish_chaplaincy_display !== '—'): ?>
                                        <div class="text-sm font-semibold text-[#59443F] mt-1 truncate" title="<?php echo htmlspecialchars($parish_chaplaincy_display); ?>">
                                            <?php echo htmlspecialchars($parish_chaplaincy_display); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Parish Priest -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left transition-colors">
                                    <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                        <i class="fa-solid fa-cross text-[10px] text-[#8B7168]"></i>
                                        Parish Priest
                                    </div>
                                    <?php if ($parish_priest_display !== '' && $parish_priest_display !== '—'): ?>
                                        <div class="text-sm font-semibold text-[#59443F] mt-1 truncate" title="<?php echo htmlspecialchars($parish_priest_display); ?>">
                                            <?php echo htmlspecialchars($parish_priest_display); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Address -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left transition-colors">
                                    <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                        <i class="fa-solid fa-location-dot text-[10px] text-[#8B7168]"></i>
                                        Address
                                    </div>
                                    <?php if ($address_display !== '' && $address_display !== '—'): ?>
                                        <div class="text-sm font-semibold text-[#59443F] mt-1 truncate" title="<?php echo htmlspecialchars($address_display); ?>">
                                            <?php echo htmlspecialchars($address_display); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Academic History (Structured Left-Aligned Mini-Cards) -->
                        <div class="sm:col-span-2 pt-4 border-t border-[#E8C7C5]/40">
                            <div class="text-xs font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-2 mb-3">
                                <i class="fa-solid fa-school text-xs text-[#8B7168]"></i>
                                Academic History
                            </div>
                            <?php if (!empty($academic_history_items)): ?>
                                <div class="space-y-2.5">
                                    <?php foreach ($academic_history_items as $acad_item): ?>
                                        <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-[#59443F] flex items-center gap-2">
                                                    <i class="fa-solid fa-graduation-cap text-[#8B7168] text-xs shrink-0"></i>
                                                    <span class="truncate"><?php echo htmlspecialchars($acad_item['school']); ?></span>
                                                </div>
                                                <?php if (!empty($acad_item['level']) || !empty($acad_item['year'])): ?>
                                                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                                        <?php if (!empty($acad_item['level'])): ?>
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                                                <?php echo htmlspecialchars($acad_item['level']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($acad_item['level']) && !empty($acad_item['year'])): ?>
                                                            <span class="text-slate-300">•</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($acad_item['year'])): ?>
                                                            <span class="text-xs text-[#8B7168] font-medium flex items-center gap-1">
                                                                <i class="fa-regular fa-calendar-check text-[11px]"></i>
                                                                Class of <?php echo htmlspecialchars($acad_item['year']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left">
                                    <span class="italic text-slate-400 text-xs font-normal">No previous academic history specified</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Parent / Guardian Information (3-Column Sub-Grid) -->
                        <div class="sm:col-span-2 pt-4 border-t border-[#E8C7C5]/40">
                            <div class="text-xs font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-2 mb-3">
                                <i class="fa-solid fa-users text-xs text-[#8B7168]"></i>
                                Parent / Guardian Information
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                                <!-- Father -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left flex flex-col justify-between">
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                            <i class="fa-solid fa-user-tie text-[10px] text-[#8B7168]"></i>
                                            Father
                                        </div>
                                        <?php if ($father_name_disp !== '' && $father_name_disp !== '—'): ?>
                                            <div class="text-sm font-bold text-[#59443F] mt-1"><?php echo htmlspecialchars($father_name_disp); ?></div>
                                        <?php else: ?>
                                            <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($father_detail_disp !== '' && $father_detail_disp !== '—'): ?>
                                        <div class="text-xs text-[#8B7168] mt-2 pt-2 border-t border-[#E8C7C5]/30 flex items-center gap-1.5">
                                            <i class="fa-solid fa-briefcase text-[10px] text-[#8B7168]"></i>
                                            <span class="truncate"><?php echo htmlspecialchars($father_detail_disp); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Mother -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left flex flex-col justify-between">
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                            <i class="fa-solid fa-person-dress text-[10px] text-[#8B7168]"></i>
                                            Mother
                                        </div>
                                        <?php if ($mother_name_disp !== '' && $mother_name_disp !== '—'): ?>
                                            <div class="text-sm font-bold text-[#59443F] mt-1"><?php echo htmlspecialchars($mother_name_disp); ?></div>
                                        <?php else: ?>
                                            <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($mother_detail_disp !== '' && $mother_detail_disp !== '—'): ?>
                                        <div class="text-xs text-[#8B7168] mt-2 pt-2 border-t border-[#E8C7C5]/30 flex items-center gap-1.5">
                                            <i class="fa-solid fa-briefcase text-[10px] text-[#8B7168]"></i>
                                            <span class="truncate"><?php echo htmlspecialchars($mother_detail_disp); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Guardian -->
                                <div class="bg-[#FAF8F6] rounded-xl p-3.5 border border-[#E8C7C5]/40 text-left flex flex-col justify-between">
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168] flex items-center gap-1.5">
                                            <i class="fa-solid fa-user-shield text-[10px] text-[#8B7168]"></i>
                                            Guardian
                                        </div>
                                        <?php if ($guardian_name_disp !== '' && $guardian_name_disp !== '—'): ?>
                                            <div class="text-sm font-bold text-[#59443F] mt-1"><?php echo htmlspecialchars($guardian_name_disp); ?></div>
                                        <?php else: ?>
                                            <div class="text-xs italic text-slate-400 font-normal mt-1">Not specified</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($guardian_detail_disp !== '' && $guardian_detail_disp !== '—'): ?>
                                        <div class="text-xs text-[#8B7168] mt-2 pt-2 border-t border-[#E8C7C5]/30 flex items-center gap-1.5">
                                            <?php if (preg_match('/^[0-9+\-\s()]+$/', $guardian_detail_disp)): ?>
                                                <i class="fa-solid fa-phone text-[10px] text-[#8B7168]"></i>
                                                <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $guardian_detail_disp)); ?>" class="text-[#8B7168] hover:text-[#59443F] hover:underline font-medium truncate">
                                                    <?php echo htmlspecialchars($guardian_detail_disp); ?>
                                                </a>
                                            <?php else: ?>
                                                <i class="fa-solid fa-id-badge text-[10px] text-[#8B7168]"></i>
                                                <span class="truncate"><?php echo htmlspecialchars($guardian_detail_disp); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formators Analytics Overview Card (5 Columns) -->
                <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/90 shadow-sm p-6">
                    <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100">
                        <div>
                            <h3 class="text-base font-bold text-slate-900 flex items-center gap-2.5 m-0">
                                <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center text-sm shadow-sm border border-purple-100/80">
                                    <i class="fas fa-chart-pie"></i>
                                </span>
                                Formators Analytics Overview
                            </h3>
                            <p class="text-xs text-[#8B7168] mt-1 mb-0">
                                4-pillar formative assessment for <?php echo htmlspecialchars($display_name); ?>
                            </p>
                        </div>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-lg bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center gap-1.5 shrink-0" title="Selected filter term">
                            <i class="fa-solid fa-filter text-[10px] text-[#8B7168]"></i>
                            <span><?php echo htmlspecialchars($term_context_badge); ?></span>
                        </span>
                    </div>

                    <!-- Consolidated Formation Status Banner -->
                    <?php if ($overall_is_complete): ?>
                        <?php
                        $banner_needs_attention = in_array($formation_progress_status, ['needs_improvement', 'poor'], true);
                        $banner_wrap = $banner_needs_attention
                            ? 'bg-gradient-to-r from-amber-50/90 to-rose-50/90 border-amber-200/80'
                            : 'bg-gradient-to-r from-emerald-50/90 to-teal-50/90 border-emerald-200/80';
                        $icon_wrap = $banner_needs_attention ? 'bg-amber-500 text-white' : 'bg-emerald-500 text-white';
                        $title_text = $banner_needs_attention ? 'text-amber-950' : 'text-emerald-950';
                        $sub_text = $banner_needs_attention ? 'text-amber-800/80' : 'text-emerald-800/80';
                        ?>
                        <div class="p-4 rounded-2xl <?php echo $banner_wrap; ?> border shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl <?php echo $icon_wrap; ?> flex items-center justify-center text-lg shadow-sm shrink-0">
                                    <i class="fa-solid <?php echo htmlspecialchars((string)$overall_interp['badge_icon']); ?>"></i>
                                </div>
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-wider <?php echo $banner_needs_attention ? 'text-amber-700' : 'text-emerald-700'; ?>">Consolidated Formation Status</div>
                                    <div class="text-sm font-extrabold <?php echo $title_text; ?> flex items-center gap-1.5 flex-wrap">
                                        <span>Overall Formative Progress:</span>
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider <?php echo htmlspecialchars((string)$overall_interp['badge_class']); ?>">
                                            <?php echo htmlspecialchars((string)$overall_interp['label']); ?>
                                        </span>
                                    </div>
                                    <div class="text-xs <?php echo $sub_text; ?> mt-0.5">
                                        Avg: <strong><?php echo number_format($overall_avg, 2); ?> / 5.00</strong> (<?php echo $overall_percent; ?>%)
                                    </div>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-white/90 text-slate-700 border border-slate-200 shadow-xs">
                                    <i class="fa-solid fa-shield-halved text-[10px]"></i> 4 of 4 Evaluated
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gray-200 text-gray-600 flex items-center justify-center text-lg shadow-sm shrink-0">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                </div>
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Consolidated Formation Status</div>
                                    <div class="text-sm font-extrabold text-slate-800 flex items-center gap-1.5 flex-wrap">
                                        <span>Overall Formative Progress:</span>
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-600 uppercase tracking-wider">Pending</span>
                                    </div>
                                    <div class="text-xs text-slate-500 mt-0.5">
                                        Waiting for all 4 pillar evaluations (Academic, Spiritual, Human, Pastoral).
                                    </div>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-white text-gray-600 border border-gray-200 shadow-xs">
                                    <?php echo count($overall_components); ?> of 4 Evaluated
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    $has_eval_data = ($overall_percent > 0) || 
                                     ($pillar_scores['Academic'] > 0) || 
                                     ($pillar_scores['Pastoral'] > 0) || 
                                     ($pillar_scores['Spiritual'] > 0) || 
                                     ($pillar_scores['Human'] > 0);
                    ?>

                    <?php if ($has_eval_data) { ?>
                    <div class="flex flex-col items-center">
                        <!-- Chart Container with Central Overall Percentage -->
                        <div class="relative w-44 h-44 my-2">
                            <canvas id="formationChart" width="176" height="176"></canvas>
                            <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                <span class="text-3xl font-black text-slate-900 tracking-tight leading-none">
                                    <?php echo (int)$overall_percent; ?><span class="text-lg text-slate-400">%</span>
                                </span>
                                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mt-1.5">
                                    <?php echo htmlspecialchars(get_overall_percent_label((int)$overall_percent)); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Styled Flex Row Legend with Colored Dots -->
                        <div class="flex items-center justify-center gap-3 sm:gap-4 flex-wrap text-xs font-semibold text-slate-600 pt-1 pb-1">
                            <div class="inline-flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm ring-2 ring-blue-100"></span>
                                <span>Academic</span>
                            </div>
                            <div class="inline-flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm ring-2 ring-emerald-100"></span>
                                <span>Pastoral</span>
                            </div>
                            <div class="inline-flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-purple-500 shadow-sm ring-2 ring-purple-100"></span>
                                <span>Spiritual</span>
                            </div>
                            <div class="inline-flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shadow-sm ring-2 ring-amber-100"></span>
                                <span>Human</span>
                            </div>
                        </div>
                    </div>
                    <?php } else { ?>
                    <!-- Empty State UI -->
                    <div class="py-6 px-4 flex flex-col items-center justify-center text-center">
                        <div class="w-14 h-14 rounded-full bg-slate-50 border border-slate-200/80 flex items-center justify-center text-slate-300 text-xl mb-2 shadow-inner">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="text-sm font-bold text-slate-800">No Analytics for Selected Term</div>
                        <p class="text-xs text-slate-500 max-w-xs mt-1 mb-0 leading-relaxed">
                            Formator evaluations for Academic, Pastoral, Spiritual, and Human dimensions will populate here once recorded.
                        </p>
                    </div>
                    <?php } ?>

                    <!-- Four-Dimension Analytics Summary Grid (2x2 Grid) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 mt-4 pt-4 border-t border-slate-100">
                        <!-- 1. Academic Formator (Blue) -->
                        <div class="p-3.5 rounded-2xl bg-white border border-blue-200/80 hover:border-blue-400 hover:shadow-soft transition-all duration-200 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-xs shadow-xs shrink-0">
                                            <i class="fa-solid fa-graduation-cap"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-xs font-extrabold text-[#59443F] truncate">Academic Formator</div>
                                            <div class="text-[10px] font-semibold text-[#8B7168] truncate">Grades, GPA &amp; Progress</div>
                                        </div>
                                    </div>
                                    <?php if ($pillar_scores['Academic'] > 0): ?>
                                        <div class="text-right shrink-0">
                                            <span class="text-sm font-black text-blue-900"><?php echo number_format($pillar_scores['Academic'], 2); ?></span>
                                            <span class="text-[10px] text-slate-400">/ 5.0</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200 shrink-0">Pending</span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between text-xs mt-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Rating Status</span>
                                    <?php if ($pillar_scores['Academic'] > 0): $ac_i = getFormationInterpretation($pillar_scores['Academic']); ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo htmlspecialchars((string)$ac_i['badge_class']); ?> truncate max-w-[140px]" title="<?php echo htmlspecialchars((string)$ac_i['label']); ?>">
                                            <?php echo htmlspecialchars((string)$ac_i['label']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] italic text-slate-400 font-normal">Not evaluated</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Progress Bar -->
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-2 mb-1.5">
                                    <div class="bg-blue-500 h-full rounded-full transition-all duration-500" style="width: <?php echo min(100, round(($pillar_scores['Academic'] / 5.0) * 100)); ?>%;"></div>
                                </div>

                                <!-- Mini-trend / Sub-metric -->
                                <div class="text-[10px] font-medium text-slate-500 flex items-center justify-between pt-0.5">
                                    <span class="truncate"><?php echo htmlspecialchars($eval_details['Academic']['sub_metric'] ?: 'Intellectual Standing'); ?></span>
                                    <span class="text-[10px] font-bold text-blue-700 shrink-0"><?php echo min(100, round(($pillar_scores['Academic'] / 5.0) * 100)); ?>%</span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-3 pt-2 border-t border-slate-100">
                                <?php if (!empty($eval_details['Academic']['eval_id'])): ?>
                                    <a href="afe_report.php?evaluation_id=<?php echo (int)$eval_details['Academic']['eval_id']; ?>" class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 active:scale-[0.98] transition-all shadow-xs text-decoration-none">
                                        <span>View Details</span>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="afms_student_record.php?student_id=<?php echo (int)$seminarian_id; ?>" class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200/60 active:scale-[0.98] transition-all text-decoration-none">
                                        <span>Academic Record</span>
                                        <i class="fas fa-arrow-right text-[10px]"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. Pastoral Formator (Green / Emerald) -->
                        <div class="p-3.5 rounded-2xl bg-white border border-emerald-200/80 hover:border-emerald-400 hover:shadow-soft transition-all duration-200 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-xs shadow-xs shrink-0">
                                            <i class="fa-solid fa-hands-holding-circle"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-xs font-extrabold text-[#59443F] truncate">Pastoral Formator</div>
                                            <div class="text-[10px] font-semibold text-[#8B7168] truncate">Parish &amp; Apostolate</div>
                                        </div>
                                    </div>
                                    <?php if ($pillar_scores['Pastoral'] > 0): ?>
                                        <div class="text-right shrink-0">
                                            <span class="text-sm font-black text-emerald-900"><?php echo number_format($pillar_scores['Pastoral'], 2); ?></span>
                                            <span class="text-[10px] text-slate-400">/ 5.0</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200 shrink-0">Pending</span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between text-xs mt-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Rating Status</span>
                                    <?php if ($pillar_scores['Pastoral'] > 0): $pa_i = getFormationInterpretation($pillar_scores['Pastoral']); ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo htmlspecialchars((string)$pa_i['badge_class']); ?> truncate max-w-[140px]" title="<?php echo htmlspecialchars((string)$pa_i['label']); ?>">
                                            <?php echo htmlspecialchars((string)$pa_i['label']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] italic text-slate-400 font-normal">Not evaluated</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Progress Bar -->
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-2 mb-1.5">
                                    <div class="bg-emerald-500 h-full rounded-full transition-all duration-500" style="width: <?php echo min(100, round(($pillar_scores['Pastoral'] / 5.0) * 100)); ?>%;"></div>
                                </div>

                                <!-- Mini-trend / Sub-metric -->
                                <div class="text-[10px] font-medium text-slate-500 flex items-center justify-between pt-0.5">
                                    <span class="truncate"><?php echo htmlspecialchars($eval_details['Pastoral']['sub_metric'] ?: 'Community Service'); ?></span>
                                    <span class="text-[10px] font-bold text-emerald-700 shrink-0"><?php echo min(100, round(($pillar_scores['Pastoral'] / 5.0) * 100)); ?>%</span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-3 pt-2 border-t border-slate-100">
                                <?php if (!empty($eval_details['Pastoral']['eval_id'])): ?>
                                    <a href="pfe_report.php?evaluation_id=<?php echo (int)$eval_details['Pastoral']['eval_id']; ?>" class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] transition-all shadow-xs text-decoration-none">
                                        <span>View Details</span>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-semibold text-slate-400 bg-slate-50 border border-slate-200/70 cursor-not-allowed">
                                        <i class="fa-regular fa-clock text-[10px]"></i>
                                        <span>Awaiting Report</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 3. Spiritual Formator (Purple) -->
                        <div class="p-3.5 rounded-2xl bg-white border border-purple-200/80 hover:border-purple-400 hover:shadow-soft transition-all duration-200 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 border border-purple-100 flex items-center justify-center text-xs shadow-xs shrink-0">
                                            <i class="fa-solid fa-cross"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-xs font-extrabold text-[#59443F] truncate">Spiritual Formator</div>
                                            <div class="text-[10px] font-semibold text-[#8B7168] truncate">Prayer &amp; Direction</div>
                                        </div>
                                    </div>
                                    <?php if ($pillar_scores['Spiritual'] > 0): ?>
                                        <div class="text-right shrink-0">
                                            <span class="text-sm font-black text-purple-900"><?php echo number_format($pillar_scores['Spiritual'], 2); ?></span>
                                            <span class="text-[10px] text-slate-400">/ 5.0</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200 shrink-0">Pending</span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between text-xs mt-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Rating Status</span>
                                    <?php if ($pillar_scores['Spiritual'] > 0): $sp_i = getFormationInterpretation($pillar_scores['Spiritual']); ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo htmlspecialchars((string)$sp_i['badge_class']); ?> truncate max-w-[140px]" title="<?php echo htmlspecialchars((string)$sp_i['label']); ?>">
                                            <?php echo htmlspecialchars((string)$sp_i['label']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] italic text-slate-400 font-normal">Not evaluated</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Progress Bar -->
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-2 mb-1.5">
                                    <div class="bg-purple-500 h-full rounded-full transition-all duration-500" style="width: <?php echo min(100, round(($pillar_scores['Spiritual'] / 5.0) * 100)); ?>%;"></div>
                                </div>

                                <!-- Mini-trend / Sub-metric -->
                                <div class="text-[10px] font-medium text-slate-500 flex items-center justify-between pt-0.5">
                                    <span class="truncate"><?php echo htmlspecialchars($eval_details['Spiritual']['sub_metric'] ?: 'Spiritual Life'); ?></span>
                                    <span class="text-[10px] font-bold text-purple-700 shrink-0"><?php echo min(100, round(($pillar_scores['Spiritual'] / 5.0) * 100)); ?>%</span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-3 pt-2 border-t border-slate-100">
                                <?php if (!empty($eval_details['Spiritual']['eval_id'])): ?>
                                    <a href="sfe_report.php?evaluation_id=<?php echo (int)$eval_details['Spiritual']['eval_id']; ?>" class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 active:scale-[0.98] transition-all shadow-xs text-decoration-none">
                                        <span>View Details</span>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-semibold text-slate-400 bg-slate-50 border border-slate-200/70 cursor-not-allowed">
                                        <i class="fa-regular fa-clock text-[10px]"></i>
                                        <span>Awaiting Report</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 4. Human Formator (Amber / Orange) -->
                        <div class="p-3.5 rounded-2xl bg-white border border-amber-200/80 hover:border-amber-400 hover:shadow-soft transition-all duration-200 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center text-xs shadow-xs shrink-0">
                                            <i class="fa-solid fa-user-group"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-xs font-extrabold text-[#59443F] truncate">Human Formator</div>
                                            <div class="text-[10px] font-semibold text-[#8B7168] truncate">Discipline &amp; Maturity</div>
                                        </div>
                                    </div>
                                    <?php if ($pillar_scores['Human'] > 0): ?>
                                        <div class="text-right shrink-0">
                                            <span class="text-sm font-black text-amber-900"><?php echo number_format($pillar_scores['Human'], 2); ?></span>
                                            <span class="text-[10px] text-slate-400">/ 5.0</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200 shrink-0">Pending</span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between text-xs mt-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Rating Status</span>
                                    <?php if ($pillar_scores['Human'] > 0): $hu_i = getFormationInterpretation($pillar_scores['Human']); ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo htmlspecialchars((string)$hu_i['badge_class']); ?> truncate max-w-[140px]" title="<?php echo htmlspecialchars((string)$hu_i['label']); ?>">
                                            <?php echo htmlspecialchars((string)$hu_i['label']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] italic text-slate-400 font-normal">Not evaluated</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Progress Bar -->
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-2 mb-1.5">
                                    <div class="bg-amber-500 h-full rounded-full transition-all duration-500" style="width: <?php echo min(100, round(($pillar_scores['Human'] / 5.0) * 100)); ?>%;"></div>
                                </div>

                                <!-- Mini-trend / Sub-metric -->
                                <div class="text-[10px] font-medium text-slate-500 flex items-center justify-between pt-0.5">
                                    <span class="truncate"><?php echo htmlspecialchars($eval_details['Human']['sub_metric'] ?: 'Emotional Maturity'); ?></span>
                                    <span class="text-[10px] font-bold text-amber-700 shrink-0"><?php echo min(100, round(($pillar_scores['Human'] / 5.0) * 100)); ?>%</span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-3 pt-2 border-t border-slate-100">
                                <?php if (!empty($eval_details['Human']['eval_id'])): ?>
                                    <a href="hfe_report.php?evaluation_id=<?php echo (int)$eval_details['Human']['eval_id']; ?>" class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 active:scale-[0.98] transition-all shadow-xs text-decoration-none">
                                        <span>View Details</span>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="w-full h-8 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-semibold text-slate-400 bg-slate-50 border border-slate-200/70 cursor-not-allowed">
                                        <i class="fa-regular fa-clock text-[10px]"></i>
                                        <span>Awaiting Report</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overview Quick Documents Card -->
            <div class="mt-6 bg-white rounded-3xl border border-[#E8C7C5]/60 shadow-lg p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5] flex items-center justify-center text-xl shrink-0 shadow-xs">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-extrabold text-[#59443F] m-0">Physical Documents &amp; Certificates</h4>
                        <p class="text-xs font-semibold text-[#8B7168] m-0 mt-0.5">
                            <?php echo count($seminarian_documents); ?> physical document(s) archived on file for <?php echo htmlspecialchars($display_name); ?>.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="switchProfileTab('documents')" class="h-10 px-4 inline-flex items-center gap-2 rounded-xl text-xs font-bold uppercase tracking-wider bg-[#59443F] hover:bg-[#463430] text-white shadow-sm transition border-0 cursor-pointer">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Upload &amp; Manage Documents</span>
                    </button>
                </div>
            </div>

            </div><!-- /#pane-overview -->

            <!-- PANE 2: Enrollment Records (Academic History) -->
            <div id="pane-enrollment" class="profile-tab-pane" style="<?php echo $active_tab === 'enrollment' ? 'display: block;' : 'display: none;'; ?>">
                
                <!-- Print-Only Official Letterhead Header -->
                <div class="print-only-header" style="display: none;">
                    <div style="font-size: 1.35rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase;">Littleway College Seminary</div>
                    <div style="font-size: 0.85rem; color: #475569; margin-top: 2px;">Office of the Registrar • Official Academic History &amp; Enrollment Record</div>
                    <div style="margin-top: 1rem; display: flex; justify-content: space-between; font-size: 0.85rem; border-top: 1.5px solid #0f172a; border-bottom: 1px solid #cbd5e1; padding: 0.6rem 0; text-align: left;">
                        <div>
                            <strong>Seminarian:</strong> <?php echo htmlspecialchars($display_name); ?><br>
                            <strong>Username / ID:</strong> <?php echo htmlspecialchars((string)($profile['username'] ?? '')); ?> (ID: <?php echo (int)$seminarian_id; ?>)
                        </div>
                        <div>
                            <strong>Program:</strong> <?php echo htmlspecialchars((string)($profile['program'] ?? '')); ?><br>
                            <strong>Year Level:</strong> <?php echo htmlspecialchars((string)($profile['year_level'] ?? '')); ?>
                        </div>
                        <div style="text-align: right;">
                            <strong>Academic Year:</strong> <?php echo htmlspecialchars((string)($profile['current_academic_year'] ?? $current_ay)); ?><br>
                            <strong>Date Issued:</strong> <?php echo date('F d, Y'); ?>
                        </div>
                    </div>
                </div>

                <!-- Academic History Summary Banner (On Screen) -->
                <div class="minimal-card no-print" style="margin: 0 0 1.5rem 0; padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #e2e8f0; border-radius: 14px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                        <div>
                            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-folder-open" style="color: #0f766e;"></i> Academic History &amp; Enrollment Records
                            </h2>
                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.35rem;">
                                Complete hierarchical academic progression across all stages, year levels, and semesters.
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                            <div class="stat-pill" style="padding: 0.4rem 0.85rem; background: #ffffff;">
                                <div class="stat-pill-label" style="font-size: 0.7rem;">Total Subjects</div>
                                <div class="stat-pill-value" style="font-size: 1.05rem;"><?php echo (int)$enrollment_stats['total_subjects']; ?></div>
                            </div>
                            <div class="stat-pill" style="padding: 0.4rem 0.85rem; background: #ffffff;">
                                <div class="stat-pill-label" style="font-size: 0.7rem;">Total Units</div>
                                <div class="stat-pill-value" style="font-size: 1.05rem;"><?php echo htmlspecialchars(capstone_format_units($enrollment_stats['total_units'] ?? 0)); ?></div>
                            </div>
                            <div class="stat-pill" style="padding: 0.4rem 0.85rem; background: #ffffff;">
                                <div class="stat-pill-label" style="font-size: 0.7rem;">Passed Units</div>
                                <div class="stat-pill-value" style="font-size: 1.05rem; color: #166534;"><?php echo htmlspecialchars(capstone_format_units($enrollment_stats['passed_units'] ?? 0)); ?></div>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button type="button" onclick="window.print()" class="pill-btn" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-size: 0.85rem;">
                                    <i class="fas fa-print"></i> Print Transcript
                                </button>
                                <?php if ($is_registrar) { ?>
                                <a href="subject_enrollment.php?seminarian_id=<?php echo (int)$seminarian_id; ?>" class="pill-btn" style="background: #0f766e; color: #ffffff; font-size: 0.85rem; text-decoration: none;">
                                    <i class="fas fa-plus-circle"></i> Enroll Subjects
                                </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- LEVEL 1: Program Stages (Only display stages legitimately enrolled in or completed) -->
                <?php
                $has_any_stage_record = false;
                foreach ($enrollment_tree as $st_chk) {
                    if (!empty($st_chk['has_records'])) {
                        $has_any_stage_record = true;
                        break;
                    }
                }
                $active_stage_key = normalize_stage((string)($profile['program'] ?? ''), (string)($profile['year_level'] ?? ''));
                foreach ($enrollment_tree as $stage_key => $stage) {
                    if (empty($stage['has_records']) && ($has_any_stage_record || $stage_key !== $active_stage_key)) {
                        continue;
                    }
                ?>
                    <div class="stage-section-card" style="margin-bottom: 2rem; background: #ffffff; border: 1px solid <?php echo $stage['border_color']; ?>; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
                        <!-- Stage Header Banner -->
                        <div class="stage-header-banner" style="background: <?php echo $stage['bg_light']; ?>; border-bottom: 1px solid <?php echo $stage['border_color']; ?>; padding: 1.15rem 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 0.85rem;">
                                <div style="width: 44px; height: 44px; border-radius: 10px; background: <?php echo $stage['color']; ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;">
                                    <i class="fas <?php echo $stage['icon']; ?>"></i>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                        <span style="font-size: 1.18rem; font-weight: 800; color: #0f172a;"><?php echo htmlspecialchars($stage['title']); ?></span>
                                        <span class="badge" style="background: #ffffff; border: 1px solid <?php echo $stage['border_color']; ?>; color: <?php echo $stage['color']; ?>; font-weight: 700; font-size: 0.72rem;">
                                            Stage Level
                                        </span>
                                    </div>
                                    <div style="font-size: 0.82rem; color: #64748b; margin-top: 2px;">
                                        <?php echo htmlspecialchars($stage['subtitle']); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                                <span class="badge" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-weight: 700; font-size: 0.78rem;">
                                    <i class="fas fa-book"></i> <?php echo (int)$stage['total_subjects']; ?> Subjects
                                </span>
                                <span class="badge" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-weight: 700; font-size: 0.78rem;">
                                    <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars(capstone_format_units($stage['total_units'] ?? 0)); ?> Units
                                </span>
                                <?php if ($stage['total_passed_units'] > 0) { ?>
                                <span class="badge" style="background: #dcfce7; border: 1px solid #86efac; color: #166534; font-weight: 700; font-size: 0.78rem;">
                                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars(capstone_format_units($stage['total_passed_units'] ?? 0)); ?> Units Passed
                                </span>
                                <?php } ?>
                            </div>
                        </div>

                        <!-- Stage Content Body -->
                        <div style="padding: 1.5rem;">
                            <?php if (!$stage['has_records']) { ?>
                                <!-- Clean Empty State for Stage with no records -->
                                <div style="padding: 2.5rem 1.5rem; text-align: center; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
                                    <i class="fas fa-folder-open" style="font-size: 2.25rem; color: #94a3b8; margin-bottom: 0.75rem; display: block;"></i>
                                    <div style="font-size: 1rem; font-weight: 700; color: #334155;">No Enrollment Records on File for <?php echo htmlspecialchars($stage['title']); ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem; max-width: 480px; margin-left: auto; margin-right: auto;">
                                        There are currently no recorded subjects or historical grades for this formation stage.
                                    </div>
                                    <?php if ($is_registrar && stripos((string)($profile['program'] ?? ''), (string)$stage['title']) !== false) { ?>
                                    <div style="margin-top: 1rem;">
                                        <a href="subject_enrollment.php?seminarian_id=<?php echo (int)$seminarian_id; ?>" class="pill-btn" style="background: #0f766e; color: #ffffff; text-decoration: none; font-size: 0.82rem;">
                                            <i class="fas fa-plus"></i> Enroll Subjects for this Stage
                                        </a>
                                    </div>
                                    <?php } ?>
                                </div>
                            <?php } else { ?>
                                <!-- LEVEL 2: Year Levels inside this Program Stage -->
                                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                                    <?php foreach ($stage['year_levels'] as $yl_key => $yl) { ?>
                                        <?php if ($yl['has_records']) { ?>
                                            <div class="year-level-card" style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                                <!-- Year Level Header -->
                                                <div style="padding: 0.9rem 1.25rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; background: #e0f2fe; color: #0369a1; font-size: 0.85rem;">
                                                            <i class="fas fa-layer-group"></i>
                                                        </span>
                                                        <div>
                                                            <span style="font-weight: 800; font-size: 0.98rem; color: #0f172a;"><?php echo htmlspecialchars($yl['title']); ?></span>
                                                            <?php if (!empty($yl['academic_years'])) { ?>
                                                            <span style="font-size: 0.78rem; font-weight: 700; color: #64748b; margin-left: 0.4rem;">
                                                                (Academic Year: <?php echo htmlspecialchars(implode(', ', $yl['academic_years'])); ?>)
                                                            </span>
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                        <span class="badge" style="background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-weight: 700; font-size: 0.78rem;">
                                                            <?php echo (int)$yl['total_subjects']; ?> Subjects • <?php echo htmlspecialchars(capstone_format_units($yl['total_units'] ?? 0)); ?> Units
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- LEVEL 3: Semester Separation - Side-by-Side ("tinlo lantawon") -->
                                                <div style="padding: 1.25rem;">
                                                    <div class="semesters-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.25rem; align-items: stretch;">
                                                        
                                                        <!-- 1st Semester Column Card -->
                                                        <?php $sem1 = $yl['semesters']['1st'] ?? ['subjects' => [], 'total_units' => 0]; ?>
                                                        <div class="semester-col" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column;">
                                                            <div style="padding: 0.75rem 1rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                                                                <div style="font-weight: 800; font-size: 0.9rem; color: #0f172a; display: flex; align-items: center; gap: 0.45rem;">
                                                                    <i class="fas fa-calendar-day" style="color: #0f766e;"></i> 1st Semester
                                                                </div>
                                                                <span class="badge" style="background: #ccfbf1; color: #0f766e; font-weight: 700; font-size: 0.75rem;">
                                                                    <?php echo count($sem1['subjects']); ?> Subjects • <?php echo htmlspecialchars(capstone_format_units($sem1['total_units'] ?? 0)); ?> Units
                                                                </span>
                                                            </div>
                                                            <div style="flex: 1;">
                                                                <?php if (empty($sem1['subjects'])) { ?>
                                                                    <div style="padding: 2.25rem 1rem; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                                                                        <i class="fas fa-inbox" style="font-size: 1.75rem; margin-bottom: 0.5rem; display: block; color: #cbd5e1;"></i>
                                                                        No subjects enrolled for 1st Semester.
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <div style="overflow-x: auto;">
                                                                        <table class="acad-table">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th style="width: 25%;">Subject Code</th>
                                                                                    <th style="width: 45%;">Description</th>
                                                                                    <th style="width: 12%; text-align: center;">Units</th>
                                                                                    <th style="width: 18%; text-align: right;">Status / Grade</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php foreach ($sem1['subjects'] as $sub) { ?>
                                                                                    <tr>
                                                                                        <td><strong style="color: #0f172a; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($sub['subject_code']); ?></strong></td>
                                                                                        <td style="font-weight: 500; color: #1e293b;"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                                                        <td style="text-align: center;"><span style="font-weight: 700; color: #475569;"><?php echo htmlspecialchars(capstone_format_units($sub['units'] ?? 0)); ?></span></td>
                                                                                        <td style="text-align: right;">
                                                                                            <span class="acad-badge <?php echo $sub['badge_class']; ?>">
                                                                                                <i class="fas <?php echo $sub['badge_icon']; ?>"></i> <?php echo htmlspecialchars($sub['display_status']); ?>
                                                                                            </span>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php } ?>
                                                                            </tbody>
                                                                            <tfoot>
                                                                                <tr>
                                                                                    <td colspan="2" style="font-weight: 700; color: #64748b;">1st Semester Total</td>
                                                                                    <td style="text-align: center; font-weight: 800; color: #0f172a;"><?php echo htmlspecialchars(capstone_format_units($sem1['total_units'] ?? 0)); ?></td>
                                                                                    <td style="text-align: right; font-weight: 700; color: #0f766e;"><?php echo count($sem1['subjects']); ?> enrolled</td>
                                                                                </tr>
                                                                            </tfoot>
                                                                        </table>
                                                                    </div>
                                                                <?php } ?>
                                                            </div>
                                                        </div>

                                                        <!-- 2nd Semester Column Card -->
                                                        <?php $sem2 = $yl['semesters']['2nd'] ?? ['subjects' => [], 'total_units' => 0]; ?>
                                                        <div class="semester-col" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column;">
                                                            <div style="padding: 0.75rem 1rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                                                                <div style="font-weight: 800; font-size: 0.9rem; color: #0f172a; display: flex; align-items: center; gap: 0.45rem;">
                                                                    <i class="fas fa-calendar-alt" style="color: #2563eb;"></i> 2nd Semester
                                                                </div>
                                                                <span class="badge" style="background: #dbeafe; color: #1e40af; font-weight: 700; font-size: 0.75rem;">
                                                                    <?php echo count($sem2['subjects']); ?> Subjects • <?php echo htmlspecialchars(capstone_format_units($sem2['total_units'] ?? 0)); ?> Units
                                                                </span>
                                                            </div>
                                                            <div style="flex: 1;">
                                                                <?php if (empty($sem2['subjects'])) { ?>
                                                                    <div style="padding: 2.25rem 1rem; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                                                                        <i class="fas fa-inbox" style="font-size: 1.75rem; margin-bottom: 0.5rem; display: block; color: #cbd5e1;"></i>
                                                                        No subjects enrolled for 2nd Semester.
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <div style="overflow-x: auto;">
                                                                        <table class="acad-table">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th style="width: 25%;">Subject Code</th>
                                                                                    <th style="width: 45%;">Description</th>
                                                                                    <th style="width: 12%; text-align: center;">Units</th>
                                                                                    <th style="width: 18%; text-align: right;">Status / Grade</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php foreach ($sem2['subjects'] as $sub) { ?>
                                                                                    <tr>
                                                                                        <td><strong style="color: #0f172a; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($sub['subject_code']); ?></strong></td>
                                                                                        <td style="font-weight: 500; color: #1e293b;"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                                                        <td style="text-align: center;"><span style="font-weight: 700; color: #475569;"><?php echo htmlspecialchars(capstone_format_units($sub['units'] ?? 0)); ?></span></td>
                                                                                        <td style="text-align: right;">
                                                                                            <span class="acad-badge <?php echo $sub['badge_class']; ?>">
                                                                                                <i class="fas <?php echo $sub['badge_icon']; ?>"></i> <?php echo htmlspecialchars($sub['display_status']); ?>
                                                                                            </span>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php } ?>
                                                                            </tbody>
                                                                            <tfoot>
                                                                                <tr>
                                                                                    <td colspan="2" style="font-weight: 700; color: #64748b;">2nd Semester Total</td>
                                                                                    <td style="text-align: center; font-weight: 800; color: #0f172a;"><?php echo htmlspecialchars(capstone_format_units($sem2['total_units'] ?? 0)); ?></td>
                                                                                    <td style="text-align: right; font-weight: 700; color: #2563eb;"><?php echo count($sem2['subjects']); ?> enrolled</td>
                                                                                </tr>
                                                                            </tfoot>
                                                                        </table>
                                                                    </div>
                                                                <?php } ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                    <!-- Summer Term if any subjects exist for this year level -->
                                                    <?php if (isset($yl['semesters']['Summer']) && !empty($yl['semesters']['Summer']['subjects'])) { 
                                                        $sem_sum = $yl['semesters']['Summer'];
                                                    ?>
                                                        <div class="semester-col" style="margin-top: 1.25rem; background: #ffffff; border: 1px solid #fde68a; border-radius: 10px; overflow: hidden;">
                                                            <div style="padding: 0.75rem 1rem; background: #fffbeb; border-bottom: 1px solid #fde68a; display: flex; align-items: center; justify-content: space-between;">
                                                                <div style="font-weight: 800; font-size: 0.9rem; color: #92400e; display: flex; align-items: center; gap: 0.4rem;">
                                                                    <i class="fas fa-sun" style="color: #d97706;"></i> Summer Term
                                                                </div>
                                                                <span class="badge" style="background: #fef3c7; color: #92400e; font-weight: 700; font-size: 0.75rem;">
                                                                    <?php echo count($sem_sum['subjects']); ?> Subjects • <?php echo htmlspecialchars(capstone_format_units($sem_sum['total_units'] ?? 0)); ?> Units
                                                                </span>
                                                            </div>
                                                            <div style="overflow-x: auto;">
                                                                <table class="acad-table">
                                                                    <thead>
                                                                        <tr>
                                                                            <th style="width: 25%;">Subject Code</th>
                                                                            <th style="width: 45%;">Description</th>
                                                                            <th style="width: 12%; text-align: center;">Units</th>
                                                                            <th style="width: 18%; text-align: right;">Status / Grade</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($sem_sum['subjects'] as $sub) { ?>
                                                                            <tr>
                                                                                <td><strong style="color: #0f172a; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($sub['subject_code']); ?></strong></td>
                                                                                <td style="font-weight: 500; color: #1e293b;"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                                                <td style="text-align: center;"><span style="font-weight: 700; color: #475569;"><?php echo htmlspecialchars(capstone_format_units($sub['units'] ?? 0)); ?></span></td>
                                                                                <td style="text-align: right;">
                                                                                    <span class="acad-badge <?php echo $sub['badge_class']; ?>">
                                                                                        <i class="fas <?php echo $sub['badge_icon']; ?>"></i> <?php echo htmlspecialchars($sub['display_status']); ?>
                                                                                    </span>
                                                                                </td>
                                                                            </tr>
                                                                        <?php } ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    <?php } ?>

                                                </div>
                                            </div>
                                        <?php } ?>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>

            </div><!-- /#pane-enrollment -->

            <!-- =============================================================== -->
            <!-- PANE 3: Physical Documents Archive & Upload                     -->
            <!-- =============================================================== -->
            <div id="pane-documents" class="profile-tab-pane" style="<?php echo $active_tab === 'documents' ? 'display: block;' : 'display: none;'; ?>">
                <div class="bg-white rounded-3xl sm:rounded-[2rem] border border-[#E8C7C5]/60 shadow-lg p-6 sm:p-8 space-y-8">
                    
                    <!-- Header Banner -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-[#E8C7C5]/60">
                        <div class="flex items-center gap-3.5">
                            <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5] flex items-center justify-center text-xl shrink-0 shadow-xs">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <div>
                                <h2 class="text-xl sm:text-2xl font-extrabold text-[#59443F] tracking-tight m-0">
                                    Physical Documents &amp; Records Archive
                                </h2>
                                <p class="text-xs sm:text-sm font-semibold text-[#8B7168] mt-1 mb-0">
                                    Upload, archive, and manage physical documents, certificates, and credentials for this seminarian.
                                </p>
                            </div>
                        </div>
                        <div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]">
                                <i class="fa-solid fa-shield-halved text-[#8B7168]"></i>
                                <span><?php echo count($seminarian_documents); ?> Total Document(s)</span>
                            </span>
                        </div>
                    </div>

                    <!-- Upload Form Container -->
                    <div class="bg-[#F8EEEE]/30 rounded-2xl p-5 sm:p-6 border border-[#E8C7C5]/60">
                        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-[#59443F] m-0 flex items-center gap-2">
                                <i class="fa-solid fa-cloud-arrow-up text-[#8B7168]"></i>
                                <span>Upload New Document</span>
                            </h3>
                            <span class="text-[11px] font-semibold text-[#8B7168]">
                                Formats: PDF, JPG, PNG &bull; Maximum File Size: 5MB
                            </span>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="w-full grid grid-cols-1 md:grid-cols-12 gap-4 items-end m-0 p-0 bg-transparent border-0 shadow-none">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                            <input type="hidden" name="action" value="upload_document">
                            <input type="hidden" name="tab" value="documents">

                            <!-- Document Type Dropdown -->
                            <div class="w-full md:col-span-4">
                                <label for="document_type_select" class="block text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">
                                    Document Type <span class="text-rose-500">*</span>
                                </label>
                                <select id="document_type_select" name="document_type" required class="w-full h-11 px-3.5 py-2 text-sm font-semibold rounded-xl border border-slate-200 bg-white text-[#59443F] focus:border-[#59443F] focus:ring-2 focus:ring-[#59443F]/20 transition outline-none cursor-pointer">
                                    <option value="" disabled selected>Select Document Type...</option>
                                    <option value="Baptismal Certificate">Baptismal Certificate</option>
                                    <option value="Confirmation Certificate">Confirmation Certificate</option>
                                    <option value="Birth Certificate">Birth Certificate</option>
                                    <option value="Good Moral">Good Moral</option>
                                    <option value="Transcript of Records">Transcript of Records</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>

                            <!-- File Input -->
                            <div class="w-full md:col-span-5">
                                <label for="document_file_input" class="block text-[11px] font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">
                                    Select File <span class="text-rose-500">*</span>
                                </label>
                                <input type="file" id="document_file_input" name="document_file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full h-11 px-3 py-2 text-xs font-semibold rounded-xl border border-slate-200 bg-white text-slate-600 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-[#59443F] file:text-white hover:file:bg-[#463430] file:cursor-pointer cursor-pointer transition">
                            </div>

                            <!-- Submit Button -->
                            <div class="w-full md:col-span-3">
                                <button type="submit" class="w-full h-11 inline-flex items-center justify-center gap-2 px-5 rounded-xl text-xs font-bold uppercase tracking-wider bg-[#59443F] hover:bg-[#463430] text-white shadow-sm hover:shadow transition active:scale-[0.98] cursor-pointer border-0 whitespace-nowrap">
                                    <i class="fa-solid fa-cloud-arrow-up text-sm"></i>
                                    <span>Upload Document</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Documents List Section -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div>
                                <h3 class="text-sm font-extrabold uppercase tracking-wider text-[#59443F] m-0">
                                    Seminarian Documents on File
                                </h3>
                                <p class="text-xs font-semibold text-[#8B7168] mt-0.5 mb-0">
                                    Physical documents and credentials archived for ID #<?php echo (int)$seminarian_id; ?>.
                                </p>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-[#E8C7C5]/60 overflow-hidden bg-white shadow-xs">
                            <table class="min-w-full divide-y divide-[#E8C7C5]/40 text-left text-sm m-0">
                                <thead class="bg-[#F8EEEE]/50 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                    <tr>
                                        <th class="px-5 py-3.5">Document Type</th>
                                        <th class="px-5 py-3.5">File Name</th>
                                        <th class="px-5 py-3.5">Date Uploaded</th>
                                        <th class="px-5 py-3.5 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php if (empty($seminarian_documents)) { ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-12 text-center">
                                                <div class="flex flex-col items-center justify-center text-center">
                                                    <div class="w-14 h-14 rounded-2xl bg-[#F8EEEE] text-[#8B7168] flex items-center justify-center text-2xl mb-3 shadow-inner border border-[#E8C7C5]">
                                                        <i class="fa-solid fa-folder-open"></i>
                                                    </div>
                                                    <div class="text-sm font-bold text-[#59443F]">No Documents Uploaded Yet</div>
                                                    <p class="text-xs text-[#8B7168] max-w-sm mt-1 mb-0 leading-relaxed">
                                                        No physical files or certificates have been archived for this seminarian yet. Use the upload form above to add a document.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php } else { ?>
                                        <?php foreach ($seminarian_documents as $doc) {
                                            $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                            $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <tr class="hover:bg-[#F8EEEE]/30 transition">
                                            <td class="px-5 py-4 align-middle whitespace-nowrap">
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]">
                                                    <i class="fa-solid <?php
                                                        switch ($doc['document_type']) {
                                                            case 'Baptismal Certificate': echo 'fa-scroll'; break;
                                                            case 'Confirmation Certificate': echo 'fa-award'; break;
                                                            case 'Birth Certificate': echo 'fa-id-card'; break;
                                                            case 'Good Moral': echo 'fa-stamp'; break;
                                                            case 'Transcript of Records': echo 'fa-graduation-cap'; break;
                                                            default: echo 'fa-file-lines'; break;
                                                        }
                                                    ?> text-[#8B7168]"></i>
                                                    <span><?php echo htmlspecialchars($doc['document_type']); ?></span>
                                                </span>
                                            </td>

                                            <td class="px-5 py-4 align-middle">
                                                <div class="font-bold text-[#59443F] flex items-center gap-2">
                                                    <i class="fa-solid <?php echo $is_pdf ? 'fa-file-pdf text-rose-500 text-base' : 'fa-file-image text-indigo-500 text-base'; ?>"></i>
                                                    <span class="break-all"><?php echo htmlspecialchars($doc['file_name']); ?></span>
                                                </div>
                                                <div class="text-[11px] text-[#8B7168] mt-0.5">
                                                    <?php echo format_file_size_human((int)$doc['file_size']); ?> &bull; <?php echo strtoupper($ext); ?>
                                                </div>
                                            </td>

                                            <td class="px-5 py-4 align-middle whitespace-nowrap">
                                                <div class="font-bold text-slate-800 text-xs">
                                                    <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                                </div>
                                                <div class="text-[11px] text-[#8B7168] mt-0.5">
                                                    <?php echo date('h:i A', strtotime($doc['uploaded_at'])); ?>
                                                </div>
                                            </td>

                                            <td class="px-5 py-4 align-middle text-right whitespace-nowrap">
                                                <div class="inline-flex items-center justify-end gap-2">
                                                    <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#59443F] hover:bg-[#E8C7C5]/80 border border-[#E8C7C5] transition no-underline" title="Open and View Document in New Tab">
                                                        <i class="fa-solid fa-eye"></i>
                                                        <span>View</span>
                                                    </a>
                                                    <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" download="<?php echo htmlspecialchars($doc['file_name']); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200 transition no-underline" title="Download Document to Device">
                                                        <i class="fa-solid fa-download"></i>
                                                        <span>Download</span>
                                                    </a>
                                                    <?php if ($is_registrar || in_array($role, ['Registrar', 'Dean', 'Admin'], true)) { ?>
                                                    <form method="post" class="inline m-0 p-0" data-confirm="Permanently delete this document: <?php echo htmlspecialchars($doc['file_name']); ?>?" data-confirm-title="Delete Document" data-confirm-text="Delete Document" data-confirm-variant="danger" data-confirm-loading="Deleting...">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                                        <input type="hidden" name="action" value="delete_document">
                                                        <input type="hidden" name="document_id" value="<?php echo (int)$doc['id']; ?>">
                                                        <input type="hidden" name="tab" value="documents">
                                                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 transition cursor-pointer" title="Delete Document">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                            <span>Delete</span>
                                                        </button>
                                                    </form>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div><!-- /#pane-documents -->

        </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    let formationChartInstance = null;
    function initFormationChart() {
        const chartCanvas = document.getElementById('formationChart');
        if (!chartCanvas || typeof Chart === 'undefined') return;
        if (formationChartInstance) {
            formationChartInstance.resize();
            return;
        }
        formationChartInstance = new Chart(chartCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Academic', 'Pastoral', 'Spiritual', 'Human'],
                datasets: [{
                    data: [
                        <?php echo max(0.0, (float)$pillar_scores['Academic']); ?>,
                        <?php echo max(0.0, (float)$pillar_scores['Pastoral']); ?>,
                        <?php echo max(0.0, (float)$pillar_scores['Spiritual']); ?>,
                        <?php echo max(0.0, (float)$pillar_scores['Human']); ?>
                    ],
                    backgroundColor: ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: 'Inter', size: 12, weight: 'bold' },
                        bodyFont: { family: 'Inter', size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.label + ': ' + Number(ctx.raw).toFixed(2);
                            }
                        }
                    }
                },
                animation: {
                    duration: 600
                }
            }
        });
    }

    function switchProfileTab(tabName) {
        document.querySelectorAll('.profile-tab-btn').forEach(btn => {
            const isActive = (btn.dataset.tab === tabName);
            btn.classList.toggle('active', isActive);
        });
        document.querySelectorAll('.profile-tab-pane').forEach(pane => {
            pane.style.display = (pane.id === 'pane-' + tabName) ? 'block' : 'none';
        });
        const params = new URLSearchParams(window.location.search);
        params.set('tab', tabName);
        window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
        if (tabName === 'overview') {
            setTimeout(initFormationChart, 50);
        }
    }

    document.addEventListener('DOMContentLoaded', initFormationChart);

    (function () {
        const aySel = document.getElementById('academicYearSelect');
        const semSel = document.getElementById('semesterSelect');
        function handleFilterChange() {
            const params = new URLSearchParams(window.location.search);
            params.set('seminarian_id', '<?php echo (int)$seminarian_id; ?>');
            if (aySel) params.set('academic_year', aySel.value || '<?php echo htmlspecialchars($current_ay); ?>');
            if (semSel) params.set('semester', semSel.value || 'all');
            const activeTab = document.querySelector('.profile-tab-btn.active')?.dataset.tab || 'overview';
            params.set('tab', activeTab);
            window.location.search = params.toString();
        }
        if (aySel) aySel.addEventListener('change', handleFilterChange);
        if (semSel) semSel.addEventListener('change', handleFilterChange);
    })();
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
