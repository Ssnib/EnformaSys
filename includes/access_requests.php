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

    if ($hasTerm && $assignment_type === 'Academic' && $cleanAy !== '' && !empty($semVars)) {
        $ph = implode(',', array_fill(0, count($semVars), '?'));
        $types = 'isss' . str_repeat('s', count($semVars));
        // Note: first bind is seminarian_id (i), assignment_type (s), cleanAy (s) => actually 'iss' + count($semVars) 's'
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
 * strictly scoped to the given (seminarian_id, academic_year, semester).
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
    if ($seminarian_id <= 0 || !capstone_access_has_term_columns($conn)) {
        return $map;
    }

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
    if (!$stmt) {
        return $map;
    }
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
    if ($assignment_type === 'Academic') {
        $activeTerm = capstone_access_resolve_active_term($conn);
        if ($reqAy === '') {
            $reqAy = $activeTerm['academic_year'];
        }
        if (!in_array($reqSem, ['1st', '2nd', 'Summer'], true)) {
            $reqSem = $activeTerm['semester'];
        }
    } else {
        if ($reqSem === '') {
            $reqSem = null;
        }
        if ($reqAy === '') {
            $reqAy = null;
        }
    }

    $pending = capstone_access_request_get_pending(
        $conn,
        $seminarian_id,
        $assignment_type,
        $assignment_type === 'Academic' ? $reqAy : null,
        $assignment_type === 'Academic' ? $reqSem : null
    );
    if ($pending) {
        $termDesc = ($assignment_type === 'Academic' && $reqAy && $reqSem)
            ? " for AY {$reqAy} ({$reqSem} Semester)"
            : '';
        return ['ok' => false, 'error' => 'You already have a pending request' . $termDesc . '.'];
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
    return ['ok' => true, 'academic_year' => $reqAy, 'semester' => $reqSem];
}

