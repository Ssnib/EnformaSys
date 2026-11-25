<?php

/**
 * Normalizes any semester string ('1st', '1st Semester', '2nd', '2nd Semester', etc.)
 * into canonical DB representation ('1st', '2nd', or 'Summer').
 */
function capstone_access_normalize_semester(?string $semester): string
{
    $raw = strtolower(trim((string)($semester ?? '')));
    if ($raw === '') {
        return '';
    }
    if (function_exists('enformasys_normalize_semester')) {
        $norm = enformasys_normalize_semester((string)$semester);
        if (in_array($norm, ['1st', '2nd', 'Summer'], true)) {
            return $norm;
        }
    }
    if (in_array($raw, ['1', '1st', '1st sem', '1st semester', 'first', 'first semester'], true) || strpos($raw, '1st') === 0 || strpos($raw, 'first') === 0) {
        return '1st';
    }
    if (in_array($raw, ['2', '2nd', '2nd sem', '2nd semester', 'second', 'second semester'], true) || strpos($raw, '2nd') === 0 || strpos($raw, 'second') === 0) {
        return '2nd';
    }
    if (strpos($raw, 'sum') !== false || strpos($raw, 'mid') !== false) {
        return 'Summer';
    }
    return trim((string)$semester);
}

/**
 * Returns all synonymous string variants for a semester so DB lookups match
 * both canonical ('1st', '2nd', 'Summer') and descriptive ('1st Semester', '2nd Semester') labels.
 *
 * @return string[]
 */
function capstone_access_semester_variants(?string $semester): array
{
    $norm = capstone_access_normalize_semester($semester);
    if ($norm === '1st') {
        return ['1st', '1st Semester', 'First Semester'];
    }
    if ($norm === '2nd') {
        return ['2nd', '2nd Semester', 'Second Semester'];
    }
    if ($norm === 'Summer') {
        return ['Summer', 'Summer Term', 'Midyear'];
    }
    return $norm !== '' ? [$norm] : [];
}

/**
 * Resolves the authoritative current Academic Year and normalized Semester.
 *
 * @return array{academic_year:string,semester:string}
 */
function capstone_access_resolve_active_term(mysqli $conn): array
{
    $ay = function_exists('get_active_academic_year')
        ? trim((string)get_active_academic_year())
        : (function_exists('get_current_academic_year') ? trim((string)get_current_academic_year()) : (date('Y') . '-' . (date('Y') + 1)));
    $sem_raw = function_exists('get_active_semester') ? (string)get_active_semester() : '1st';
    $sem = capstone_access_normalize_semester($sem_raw);
    if (!in_array($sem, ['1st', '2nd', 'Summer'], true)) {
        $sem = '1st';
    }
    return ['academic_year' => $ay, 'semester' => $sem];
}

/**
 * Ensures `academic_year` and `semester` columns exist on `seminarian_access_requests`
 * (cached per request and guarded on production/Vercel where DDL sync is disabled).
 */
function capstone_access_has_term_columns(mysqli $conn): bool
{
    static $hasCols = null;
    if ($hasCols !== null) {
        return $hasCols;
    }
    if (isset($_SESSION['cached_sar_has_term_cols'])) {
        $hasCols = (bool)$_SESSION['cached_sar_has_term_cols'];
        return $hasCols;
    }

    $cols = [];
    $res = @$conn->query("SHOW COLUMNS FROM seminarian_access_requests");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[(string)$row['Field']] = true;
        }
    }
    $ready = !empty($cols['academic_year']) && !empty($cols['semester']);
    if (!$ready && (defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC') || (!getenv('VERCEL') && !getenv('VERCEL_ENV')))) {
        if (empty($cols['academic_year'])) {
            @$conn->query("ALTER TABLE seminarian_access_requests ADD COLUMN academic_year VARCHAR(20) NULL AFTER assignment_type");
        }
        if (empty($cols['semester'])) {
            @$conn->query("ALTER TABLE seminarian_access_requests ADD COLUMN semester VARCHAR(20) NULL AFTER academic_year");
        }
        $ready = true;
    }
    $hasCols = $ready;
    if ($ready && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['cached_sar_has_term_cols'] = true;
    }
    return $hasCols;
}

/**
 * Ensures the `seminarian_eval_access` table exists for per-term evaluation/grade visibility.
 */
function capstone_access_ensure_eval_access_table(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!empty($_SESSION['cached_sea_table_ready'])) {
        $ready = true;
        return true;
    }

    $tbl = @$conn->query("SHOW TABLES LIKE 'seminarian_eval_access'");
    if ($tbl && $tbl->num_rows > 0) {
        $ready = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['cached_sea_table_ready'] = true;
        }
        return true;
    }

    if (defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC') || (!getenv('VERCEL') && !getenv('VERCEL_ENV'))) {
        $ok = @$conn->query(
            "CREATE TABLE IF NOT EXISTS `seminarian_eval_access` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `seminarian_id` int(11) NOT NULL,
              `formator_type` enum('Academic','Human','Pastoral','Spiritual') NOT NULL,
              `academic_year` varchar(20) NOT NULL,
              `semester` varchar(20) NOT NULL,
              `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
              `updated_by` int(11) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_sea_term` (`seminarian_id`, `formator_type`, `academic_year`, `semester`),
              KEY `idx_sea_type_term` (`formator_type`, `academic_year`, `semester`, `is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $ready = (bool)$ok;
        if ($ready && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['cached_sea_table_ready'] = true;
        }
        return $ready;
    }

    $ready = false;
    return false;
}

/**
 * Sets evaluation/report visibility strictly for (seminarian_id, formator_type, academic_year, semester).
 */
function capstone_access_set_term_eval_access(
    mysqli $conn,
    int $seminarian_id,
    string $formator_type,
    string $academic_year,
    string $semester,
    bool $enabled,
    ?int $actor_user_id = null
): bool {
    if ($seminarian_id <= 0 || !in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return false;
    }
    $cleanAy = trim($academic_year);
    $normSem = capstone_access_normalize_semester($semester);
    if ($cleanAy === '' || !in_array($normSem, ['1st', '2nd', 'Summer'], true)) {
        return false;
    }
    if (!capstone_access_ensure_eval_access_table($conn)) {
        return false;
    }
    $flag = $enabled ? 1 : 0;
    $actor = $actor_user_id !== null && $actor_user_id > 0 ? $actor_user_id : null;
    $stmt = $conn->prepare(
        "INSERT INTO seminarian_eval_access (seminarian_id, formator_type, academic_year, semester, is_enabled, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by), updated_at = NOW()"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isssii', $seminarian_id, $formator_type, $cleanAy, $normSem, $flag, $actor);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

/**
 * Returns [seminarian_id => bool] indicating whether evaluation access is enabled for
 * (formator_type, academic_year, semester). Explicit toggles in `seminarian_eval_access`
 * override approved requests for that same term.
 *
 * @return array<int,bool>
 */
function capstone_access_get_term_eval_access_map(
    mysqli $conn,
    string $formator_type,
    string $academic_year,
    string $semester
): array {
    $map = [];
    if (!in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return $map;
    }
    $cleanAy = trim($academic_year);
    $semVars = capstone_access_semester_variants($semester);
    if ($cleanAy === '' || empty($semVars)) {
        return $map;
    }

    if (capstone_access_has_term_columns($conn)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'ss' . str_repeat('s', count($semVars));
        $stmtSar = $conn->prepare(
            "SELECT DISTINCT seminarian_id
             FROM seminarian_access_requests
             WHERE assignment_type = ?
               AND status = 'Approved'
               AND TRIM(COALESCE(academic_year, '')) = ?
               AND semester IN ($ph)
               AND COALESCE(request_scope, 'whole') = 'whole'"
        );
        if ($stmtSar) {
            $params = array_merge([$formator_type, $cleanAy], $semVars);
            $stmtSar->bind_param($types, ...$params);
            if ($stmtSar->execute()) {
                $res = $stmtSar->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $sid = (int)($r['seminarian_id'] ?? 0);
                    if ($sid > 0) {
                        $map[$sid] = true;
                    }
                }
            }
            $stmtSar->close();
        }
    }

    if (capstone_access_ensure_eval_access_table($conn)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'ss' . str_repeat('s', count($semVars));
        $stmtSea = $conn->prepare(
            "SELECT seminarian_id, is_enabled
             FROM seminarian_eval_access
             WHERE formator_type = ?
               AND TRIM(academic_year) = ?
               AND semester IN ($ph)"
        );
        if ($stmtSea) {
            $params = array_merge([$formator_type, $cleanAy], $semVars);
            $stmtSea->bind_param($types, ...$params);
            if ($stmtSea->execute()) {
                $res = $stmtSea->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $sid = (int)($r['seminarian_id'] ?? 0);
                    if ($sid > 0) {
                        $map[$sid] = (int)($r['is_enabled'] ?? 0) === 1;
                    }
                }
            }
            $stmtSea->close();
        }
    }

    return $map;
}

/**
 * Checks strictly per (seminarian_id, formator_type, academic_year, semester) whether
 * a seminarian can view formation evaluation reports for that term.
 */
function capstone_access_can_view_evaluation(
    mysqli $conn,
    int $seminarian_id,
    string $formator_type,
    string $academic_year,
    string $semester
): bool {
    if ($seminarian_id <= 0 || !in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return false;
    }
    $cleanAy = trim($academic_year);
    $semVars = capstone_access_semester_variants($semester);
    if ($cleanAy === '' || empty($semVars)) {
        return false;
    }

    if (capstone_access_ensure_eval_access_table($conn)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'iss' . str_repeat('s', count($semVars));
        $stmtSea = $conn->prepare(
            "SELECT is_enabled
             FROM seminarian_eval_access
             WHERE seminarian_id = ?
               AND formator_type = ?
               AND TRIM(academic_year) = ?
               AND semester IN ($ph)
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        if ($stmtSea) {
            $params = array_merge([$seminarian_id, $formator_type, $cleanAy], $semVars);
            $stmtSea->bind_param($types, ...$params);
            if ($stmtSea->execute()) {
                $row = $stmtSea->get_result()->fetch_assoc();
                $stmtSea->close();
                if ($row !== null) {
                    return (int)($row['is_enabled'] ?? 0) === 1;
                }
            } else {
                $stmtSea->close();
            }
        }
    }

    if (!capstone_access_has_term_columns($conn)) {
        return false;
    }

    $ph = implode(',', array_fill(0, count($semVars), '?'));
    $types = 'iss' . str_repeat('s', count($semVars));
    $stmtSar = $conn->prepare(
        "SELECT id
         FROM seminarian_access_requests
         WHERE seminarian_id = ?
           AND assignment_type = ?
           AND status = 'Approved'
           AND TRIM(COALESCE(academic_year, '')) = ?
           AND semester IN ($ph)
         LIMIT 1"
    );
    if ($stmtSar) {
        $params = array_merge([$seminarian_id, $formator_type, $cleanAy], $semVars);
        $stmtSar->bind_param($types, ...$params);
        $ok = $stmtSar->execute() && ($stmtSar->get_result()->num_rows > 0);
        $stmtSar->close();
        return $ok;
    }
    return false;
}

/**
 * Returns ["AY|NormSem" => true] for all terms where the seminarian is permitted to
 * view evaluations for the given formator_type.
 *
 * @return array<string,bool>
 */
function capstone_access_get_approved_eval_terms(
    mysqli $conn,
    int $seminarian_id,
    string $formator_type
): array {
    $map = [];
    if ($seminarian_id <= 0 || !in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return $map;
    }

    if (capstone_access_has_term_columns($conn)) {
        $stmtSar = $conn->prepare(
            "SELECT DISTINCT academic_year, semester
             FROM seminarian_access_requests
             WHERE seminarian_id = ?
               AND assignment_type = ?
               AND status = 'Approved'
               AND academic_year IS NOT NULL AND TRIM(academic_year) <> ''
               AND semester IS NOT NULL AND TRIM(semester) <> ''"
        );
        if ($stmtSar) {
            $stmtSar->bind_param('is', $seminarian_id, $formator_type);
            if ($stmtSar->execute()) {
                $res = $stmtSar->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $ay = trim((string)($r['academic_year'] ?? ''));
                    $sem = capstone_access_normalize_semester((string)($r['semester'] ?? ''));
                    if ($ay !== '' && $sem !== '') {
                        $map[$ay . '|' . $sem] = true;
                    }
                }
            }
            $stmtSar->close();
        }
    }

    if (capstone_access_ensure_eval_access_table($conn)) {
        $stmtSea = $conn->prepare(
            "SELECT academic_year, semester, is_enabled
             FROM seminarian_eval_access
             WHERE seminarian_id = ? AND formator_type = ?"
        );
        if ($stmtSea) {
            $stmtSea->bind_param('is', $seminarian_id, $formator_type);
            if ($stmtSea->execute()) {
                $res = $stmtSea->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $ay = trim((string)($r['academic_year'] ?? ''));
                    $sem = capstone_access_normalize_semester((string)($r['semester'] ?? ''));
                    if ($ay !== '' && $sem !== '') {
                        if ((int)($r['is_enabled'] ?? 0) === 1) {
                            $map[$ay . '|' . $sem] = true;
                        } else {
                            unset($map[$ay . '|' . $sem]);
                        }
                    }
                }
            }
            $stmtSea->close();
        }
    }

    return $map;
}

function capstone_access_request_get_pending(
    mysqli $conn,
    int $seminarian_id,
    string $assignment_type,
    ?string $academic_year = null,
    ?string $semester = null
): ?array {
    $hasTerm = capstone_access_has_term_columns($conn);
    $cleanAy = trim((string)($academic_year ?? ''));
    $semVars = capstone_access_semester_variants($semester);

    if ($hasTerm && $cleanAy !== '' && !empty($semVars)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'iss' . str_repeat('s', count($semVars));
        $stmt = $conn->prepare(
            "SELECT id, status, requested_at, request_scope, requested_subjects, academic_year, semester, message
             FROM seminarian_access_requests
             WHERE seminarian_id = ?
               AND assignment_type = ?
               AND status = 'Pending'
               AND TRIM(COALESCE(academic_year, '')) = ?
               AND semester IN ($ph)
             ORDER BY requested_at DESC, id DESC
             LIMIT 1"
        );
        $params = array_merge([$seminarian_id, $assignment_type, $cleanAy], $semVars);
        $stmt->bind_param($types, ...$params);
    } else {
        $colsSel = $hasTerm
            ? 'id, status, requested_at, request_scope, requested_subjects, academic_year, semester, message'
            : 'id, status, requested_at, request_scope, requested_subjects, message';
        $stmt = $conn->prepare("SELECT {$colsSel}
            FROM seminarian_access_requests
            WHERE seminarian_id = ? AND assignment_type = ? AND status = 'Pending'
            ORDER BY requested_at DESC, id DESC
            LIMIT 1");
        $stmt->bind_param('is', $seminarian_id, $assignment_type);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Checks whether a seminarian has an Approved Academic grade-viewing request
 * or active Eval Access toggle strictly scoped to (seminarian_id, academic_year, semester).
 *
 * @return array{can_view_any:bool,is_whole_semester:bool,approved_subject_ids:array<int,bool>}
 */
function capstone_access_get_grade_view_permission(
    mysqli $conn,
    int $seminarian_id,
    string $academic_year,
    string $semester
): array {
    $result = [
        'can_view_any' => false,
        'is_whole_semester' => false,
        'approved_subject_ids' => [],
    ];
    if ($seminarian_id <= 0) {
        return $result;
    }

    $cleanAy = trim($academic_year);
    $semVars = capstone_access_semester_variants($semester);
    if ($cleanAy === '' || empty($semVars)) {
        return $result;
    }

    $explicitToggle = null;
    if (capstone_access_ensure_eval_access_table($conn)) {
        $phSea = implode(',', array_fill(0, count($semVars), '?'));
        $typesSea = 'is' . str_repeat('s', count($semVars));
        $stmtSea = $conn->prepare(
            "SELECT is_enabled
             FROM seminarian_eval_access
             WHERE seminarian_id = ?
               AND formator_type = 'Academic'
               AND TRIM(academic_year) = ?
               AND semester IN ($phSea)
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        if ($stmtSea) {
            $paramsSea = array_merge([$seminarian_id, $cleanAy], $semVars);
            $stmtSea->bind_param($typesSea, ...$paramsSea);
            if ($stmtSea->execute()) {
                $rSea = $stmtSea->get_result()->fetch_assoc();
                if ($rSea !== null) {
                    $explicitToggle = ((int)($rSea['is_enabled'] ?? 0) === 1);
                }
            }
            $stmtSea->close();
        }
    }

    if ($explicitToggle === false) {
        return $result;
    }
    if ($explicitToggle === true) {
        $result['is_whole_semester'] = true;
        $result['can_view_any'] = true;
    }

    if (!capstone_access_has_term_columns($conn)) {
        return $result;
    }

    $ph = implode(',', array_fill(0, count($semVars), '?'));
    $types = 'is' . str_repeat('s', count($semVars));
    $stmt = $conn->prepare(
        "SELECT id, request_scope, requested_subjects
         FROM seminarian_access_requests
         WHERE seminarian_id = ?
           AND assignment_type = 'Academic'
           AND status = 'Approved'
           AND TRIM(COALESCE(academic_year, '')) = ?
           AND semester IN ($ph)
         ORDER BY resolved_at DESC, id DESC"
    );
    if (!$stmt) {
        return $result;
    }
    $params = array_merge([$seminarian_id, $cleanAy], $semVars);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $scope = trim((string)($row['request_scope'] ?? 'whole'));
            if ($scope === 'selected' && !empty($row['requested_subjects'])) {
                $decoded = json_decode((string)$row['requested_subjects'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $subItem) {
                        $sid = is_array($subItem) ? (int)($subItem['subject_id'] ?? 0) : (int)$subItem;
                        if ($sid > 0) {
                            $result['approved_subject_ids'][$sid] = true;
                            $result['can_view_any'] = true;
                        }
                    }
                }
            } else {
                $result['is_whole_semester'] = true;
                $result['can_view_any'] = true;
            }
        }
    }
    $stmt->close();

    return $result;
}

/**
 * Returns a lookup map of all terms ("AY|NormSem") approved for the seminarian's
 * Academic grade/evaluation viewing.
 *
 * @return array<string,array{is_whole:bool,subject_ids:array<int,bool>}>
 */
function capstone_access_get_approved_grade_terms(mysqli $conn, int $seminarian_id): array
{
    $map = [];
    if ($seminarian_id <= 0) {
        return $map;
    }

    if (capstone_access_has_term_columns($conn)) {
        $stmt = $conn->prepare(
            "SELECT academic_year, semester, request_scope, requested_subjects
             FROM seminarian_access_requests
             WHERE seminarian_id = ?
               AND assignment_type = 'Academic'
               AND status = 'Approved'
               AND academic_year IS NOT NULL
               AND TRIM(academic_year) <> ''
               AND semester IS NOT NULL
               AND TRIM(semester) <> ''"
        );
        if ($stmt) {
            $stmt->bind_param('i', $seminarian_id);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $ay = trim((string)($row['academic_year'] ?? ''));
                    $sem = capstone_access_normalize_semester((string)($row['semester'] ?? ''));
                    if ($ay === '' || $sem === '') {
                        continue;
                    }
                    $k = $ay . '|' . $sem;
                    if (!isset($map[$k])) {
                        $map[$k] = ['is_whole' => false, 'subject_ids' => []];
                    }
                    $scope = trim((string)($row['request_scope'] ?? 'whole'));
                    if ($scope === 'selected' && !empty($row['requested_subjects'])) {
                        $decoded = json_decode((string)$row['requested_subjects'], true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $subItem) {
                                $sid = is_array($subItem) ? (int)($subItem['subject_id'] ?? 0) : (int)$subItem;
                                if ($sid > 0) {
                                    $map[$k]['subject_ids'][$sid] = true;
                                }
                            }
                        }
                    } else {
                        $map[$k]['is_whole'] = true;
                    }
                }
            }
            $stmt->close();
        }
    }

    if (capstone_access_ensure_eval_access_table($conn)) {
        $stmtSea = $conn->prepare(
            "SELECT academic_year, semester, is_enabled
             FROM seminarian_eval_access
             WHERE seminarian_id = ? AND formator_type = 'Academic'"
        );
        if ($stmtSea) {
            $stmtSea->bind_param('i', $seminarian_id);
            if ($stmtSea->execute()) {
                $resSea = $stmtSea->get_result();
                while ($resSea && ($rSea = $resSea->fetch_assoc())) {
                    $ay = trim((string)($rSea['academic_year'] ?? ''));
                    $sem = capstone_access_normalize_semester((string)($rSea['semester'] ?? ''));
                    if ($ay === '' || $sem === '') {
                        continue;
                    }
                    $k = $ay . '|' . $sem;
                    if ((int)($rSea['is_enabled'] ?? 0) === 1) {
                        if (!isset($map[$k])) {
                            $map[$k] = ['is_whole' => true, 'subject_ids' => []];
                        } else {
                            $map[$k]['is_whole'] = true;
                        }
                    } else {
                        unset($map[$k]);
                    }
                }
            }
            $stmtSea->close();
        }
    }

    return $map;
}

function capstone_access_request_create(
    mysqli $conn,
    int $seminarian_id,
    string $assignment_type,
    ?int $requested_by_user_id = null,
    ?string $message = null,
    string $request_scope = 'whole',
    $requested_subjects = null,
    ?string $academic_year = null,
    ?string $semester = null
): array {
    $assignment_type = trim($assignment_type);
    if (!in_array($assignment_type, ['Academic', 'Spiritual', 'Human', 'Pastoral'], true)) {
        return ['ok' => false, 'error' => 'Invalid request type.'];
    }

    $clean_message = trim((string)($message ?? ''));
    $hasTerm = capstone_access_has_term_columns($conn);

    $reqAy = trim((string)($academic_year ?? ''));
    $reqSem = capstone_access_normalize_semester($semester);
    $activeTerm = capstone_access_resolve_active_term($conn);
    if ($reqAy === '') {
        $reqAy = $activeTerm['academic_year'];
    }
    if (!in_array($reqSem, ['1st', '2nd', 'Summer'], true)) {
        $reqSem = $activeTerm['semester'];
    }

    $pending = capstone_access_request_get_pending(
        $conn,
        $seminarian_id,
        $assignment_type,
        $reqAy,
        $reqSem
    );
    if ($pending) {
        return ['ok' => false, 'error' => "You already have a pending request for AY {$reqAy} ({$reqSem} Semester)."];
    }

    $request_scope = in_array($request_scope, ['whole', 'selected'], true) ? $request_scope : 'whole';
    $subjects_json = null;
    if ($request_scope === 'selected') {
        if (is_array($requested_subjects) && !empty($requested_subjects)) {
            $subjects_json = json_encode(array_values($requested_subjects));
        } else {
            return ['ok' => false, 'error' => 'Please select at least one subject for the request.'];
        }
    }

    $assignee_user_id = 0;
    $stmt_as = $conn->prepare("SELECT assigned_user_id
        FROM seminarian_assignments
        WHERE seminarian_id = ? AND assignment_type = ? AND active = 1
        LIMIT 1");
    $stmt_as->bind_param('is', $seminarian_id, $assignment_type);
    $stmt_as->execute();
    $as = $stmt_as->get_result()->fetch_assoc();
    if ($as && $as['assigned_user_id'] !== null) {
        $assignee_user_id = (int)$as['assigned_user_id'];
    }

    if ($hasTerm) {
        $stmt = $conn->prepare("INSERT INTO seminarian_access_requests (seminarian_id, assignment_type, academic_year, semester, assigned_user_id, status, requested_by, message, request_scope, requested_subjects, requested_at)
            VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?,0), 'Pending', ?, NULLIF(?,''), ?, ?, NOW())");
        $ayBind = (string)($reqAy ?? '');
        $semBind = (string)($reqSem ?? '');
        $stmt->bind_param('isssiisss', $seminarian_id, $assignment_type, $ayBind, $semBind, $assignee_user_id, $requested_by_user_id, $clean_message, $request_scope, $subjects_json);
    } else {
        $stmt = $conn->prepare("INSERT INTO seminarian_access_requests (seminarian_id, assignment_type, assigned_user_id, status, requested_by, message, request_scope, requested_subjects, requested_at)
            VALUES (?, ?, NULLIF(?,0), 'Pending', ?, NULLIF(?,''), ?, ?, NOW())");
        $stmt->bind_param('isiisss', $seminarian_id, $assignment_type, $assignee_user_id, $requested_by_user_id, $clean_message, $request_scope, $subjects_json);
    }
    $ok = $stmt->execute();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Failed to submit request: ' . $stmt->error];
    }
    if (function_exists('enformasys_invalidate_dashboard_cache')) {
        enformasys_invalidate_dashboard_cache();
    }
    return ['ok' => true, 'academic_year' => $reqAy, 'semester' => $reqSem];
}

/**
 * Counts pending evaluation/report viewing requests for a specific pillar
 * (`Academic`, `Spiritual`, `Human`, or `Pastoral`) and active Academic Year & Semester.
 */
function capstone_access_count_pending_eval_requests(
    mysqli $conn,
    string $formator_type,
    string $academic_year = '',
    string $semester = ''
): int {
    $pillar = trim($formator_type);
    if (!in_array($pillar, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return 0;
    }

    $cleanAy = trim($academic_year);
    $semVars = capstone_access_semester_variants($semester);
    $hasTerm = capstone_access_has_term_columns($conn);

    if ($hasTerm && $cleanAy !== '' && !empty($semVars)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'ss' . str_repeat('s', count($semVars));
        $stmt = @$conn->prepare(
            "SELECT COUNT(*) AS c
             FROM seminarian_access_requests
             WHERE assignment_type = ?
               AND LOWER(status) = 'pending'
               AND TRIM(COALESCE(academic_year, '')) = ?
               AND semester IN ($ph)"
        );
        if (!$stmt) {
            return 0;
        }
        $params = array_merge([$pillar, $cleanAy], $semVars);
        $stmt->bind_param($types, ...$params);
        $cnt = 0;
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $cnt = (int)($row['c'] ?? 0);
        }
        $stmt->close();
        return max(0, $cnt);
    }

    $stmt = @$conn->prepare(
        "SELECT COUNT(*) AS c
         FROM seminarian_access_requests
         WHERE assignment_type = ?
           AND LOWER(status) = 'pending'"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $pillar);
    $cnt = 0;
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        $cnt = (int)($row['c'] ?? 0);
    }
    $stmt->close();
    return max(0, $cnt);
}

/**
 * Builds a term-scoped per-seminarian evaluation viewing status map combining
 * `seminarian_access_requests` and explicit overrides in `seminarian_eval_access`.
 *
 * @return array<int,array{status_key:string,enabled:bool,has_pending:bool,request_id:int,requested_at:?string,message:?string}>
 */
function capstone_access_get_term_eval_request_state_map(
    mysqli $conn,
    string $formator_type,
    string $academic_year,
    string $semester
): array {
    $state = [];
    if (!in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return $state;
    }

    $cleanAy = trim($academic_year);
    $semVars = capstone_access_semester_variants($semester);
    if ($cleanAy === '' || empty($semVars)) {
        return $state;
    }

    $reqBySid = [];
    if (capstone_access_has_term_columns($conn)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'ss' . str_repeat('s', count($semVars));
        $stmtSar = @$conn->prepare(
            "SELECT id, seminarian_id, status, requested_at, resolved_at, message
             FROM seminarian_access_requests
             WHERE assignment_type = ?
               AND TRIM(COALESCE(academic_year, '')) = ?
               AND semester IN ($ph)
               AND COALESCE(request_scope, 'whole') = 'whole'
             ORDER BY requested_at DESC, id DESC"
        );
        if ($stmtSar) {
            $params = array_merge([$formator_type, $cleanAy], $semVars);
            $stmtSar->bind_param($types, ...$params);
            if ($stmtSar->execute()) {
                $res = $stmtSar->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $sid = (int)($r['seminarian_id'] ?? 0);
                    if ($sid <= 0 || isset($reqBySid[$sid])) {
                        continue;
                    }
                    $reqBySid[$sid] = $r;
                }
            }
            $stmtSar->close();
        }
    }

    $explicitBySid = [];
    if (capstone_access_ensure_eval_access_table($conn)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'ss' . str_repeat('s', count($semVars));
        $stmtSea = @$conn->prepare(
            "SELECT seminarian_id, is_enabled, updated_at
             FROM seminarian_eval_access
             WHERE formator_type = ?
               AND TRIM(academic_year) = ?
               AND semester IN ($ph)"
        );
        if ($stmtSea) {
            $params = array_merge([$formator_type, $cleanAy], $semVars);
            $stmtSea->bind_param($types, ...$params);
            if ($stmtSea->execute()) {
                $res = $stmtSea->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $sid = (int)($r['seminarian_id'] ?? 0);
                    if ($sid > 0) {
                        $explicitBySid[$sid] = $r;
                    }
                }
            }
            $stmtSea->close();
        }
    }

    $allSids = array_unique(array_merge(array_keys($reqBySid), array_keys($explicitBySid)));
    foreach ($allSids as $sid) {
        $req = $reqBySid[$sid] ?? null;
        $exp = $explicitBySid[$sid] ?? null;

        $reqStatus = $req ? strtolower(trim((string)($req['status'] ?? ''))) : '';
        $hasExplicit = ($exp !== null);
        $explicitEnabled = $hasExplicit ? ((int)($exp['is_enabled'] ?? 0) === 1) : false;

        $statusKey = 'none';
        $enabled = false;
        $hasPending = false;

        if ($reqStatus === 'pending') {
            $reqTs = !empty($req['requested_at']) ? strtotime((string)$req['requested_at']) : 0;
            $expTs = ($exp && !empty($exp['updated_at'])) ? strtotime((string)$exp['updated_at']) : 0;
            if (!$hasExplicit || $reqTs >= $expTs) {
                $statusKey = 'pending';
                $hasPending = true;
                $enabled = $explicitEnabled;
            } else {
                $statusKey = $explicitEnabled ? 'approved' : 'declined';
                $enabled = $explicitEnabled;
            }
        } elseif ($hasExplicit) {
            $statusKey = $explicitEnabled ? 'approved' : 'declined';
            $enabled = $explicitEnabled;
        } elseif ($reqStatus === 'approved') {
            $statusKey = 'approved';
            $enabled = true;
        } elseif ($reqStatus === 'rejected') {
            $statusKey = 'declined';
            $enabled = false;
        }

        $state[$sid] = [
            'status_key' => $statusKey,
            'enabled' => $enabled,
            'has_pending' => $hasPending,
            'request_id' => $req ? (int)($req['id'] ?? 0) : 0,
            'requested_at' => $req ? ($req['requested_at'] ?? null) : null,
            'message' => $req ? ($req['message'] ?? null) : null,
        ];
    }

    return $state;
}

/**
 * Accepts (approves) or declines (rejects/revokes) evaluation report viewing access
 * strictly for (seminarian_id, formator_type, academic_year, semester) across both
 * `seminarian_eval_access` and matching `seminarian_access_requests` records.
 */
function capstone_access_resolve_eval_request_decision(
    mysqli $conn,
    int $seminarian_id,
    string $formator_type,
    string $academic_year,
    string $semester,
    bool $accept,
    ?int $actor_user_id = null
): bool {
    if ($seminarian_id <= 0 || !in_array($formator_type, ['Academic', 'Human', 'Pastoral', 'Spiritual'], true)) {
        return false;
    }

    $cleanAy = trim($academic_year);
    $normSem = capstone_access_normalize_semester($semester);
    $semVars = capstone_access_semester_variants($normSem);
    if ($cleanAy === '' || !in_array($normSem, ['1st', '2nd', 'Summer'], true) || empty($semVars)) {
        return false;
    }

    $saved = capstone_access_set_term_eval_access(
        $conn,
        $seminarian_id,
        $formator_type,
        $cleanAy,
        $normSem,
        $accept,
        $actor_user_id
    );
    if (!$saved) {
        return false;
    }

    if (capstone_access_has_term_columns($conn)) {
        $actor = ($actor_user_id !== null && $actor_user_id > 0) ? $actor_user_id : null;
        $newStatus = $accept ? 'Approved' : 'Rejected';
        $targetStatuses = $accept ? "'Pending'" : "'Pending', 'Approved'";
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $stmt = @$conn->prepare("UPDATE seminarian_access_requests
                SET status = ?, is_read = 0, resolved_by = ?, resolved_at = NOW()
                WHERE seminarian_id = ?
                  AND assignment_type = ?
                  AND TRIM(COALESCE(academic_year, '')) = ?
                  AND semester IN ($ph)
                  AND COALESCE(request_scope, 'whole') = 'whole'
                  AND status IN ({$targetStatuses})");
        if ($stmt) {
            $typesBind = 'siiss' . str_repeat('s', count($semVars));
            $params = array_merge([$newStatus, $actor, $seminarian_id, $formator_type, $cleanAy], $semVars);
            $stmt->bind_param($typesBind, ...$params);
            @$stmt->execute();
            $stmt->close();
        }
    }

    if (function_exists('enformasys_invalidate_dashboard_cache')) {
        enformasys_invalidate_dashboard_cache();
    }
    return true;
}

