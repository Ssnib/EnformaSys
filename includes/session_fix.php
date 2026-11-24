<?php
if (defined('CAPSTONE_SESSION_FIX_LOADED')) {
    return;
}
define('CAPSTONE_SESSION_FIX_LOADED', true);

require_once __DIR__ . '/security.php';
enformasys_apply_security_headers();

$sessions_dir = __DIR__ . '/../sessions';
enformasys_configure_session($sessions_dir);

if (!isset($GLOBALS['ENFORMASYS_TIMINGS']) || !is_array($GLOBALS['ENFORMASYS_TIMINGS'])) {
    $GLOBALS['ENFORMASYS_TIMINGS'] = ['session_ms' => 0.0, 'db_connect_ms' => 0.0];
}

if (session_status() === PHP_SESSION_NONE) {
    $__tSess0 = microtime(true);
    $__dbBefore = (float)($GLOBALS['ENFORMASYS_TIMINGS']['db_connect_ms'] ?? 0.0);
    session_start();
    $__dbDuringSess = max(0.0, (float)($GLOBALS['ENFORMASYS_TIMINGS']['db_connect_ms'] ?? 0.0) - $__dbBefore);
    $GLOBALS['ENFORMASYS_TIMINGS']['session_ms'] += max(0.0, ((microtime(true) - $__tSess0) * 1000.0) - $__dbDuringSess);
}

// Enforce idle session timeout when authenticated
if (isset($_SESSION['user'])) {
    $sessionLifetime = max(300, (int)enformasys_env('SESSION_LIFETIME', '3600'));
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $sessionLifetime) {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            @setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        @session_destroy();
        @session_start();
    } else {
        $nowTs = time();
        if ($lastActivity === 0 || ($nowTs - $lastActivity) >= 60) {
            $_SESSION['last_activity'] = $nowTs;
        }
    }
}

if (isset($_SESSION['user']) && (string)($_SESSION['role'] ?? '') === 'Shrine') {
    $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    if ($script !== 'timetable.php' && $script !== 'logout.php') {
        $target = (strpos((string)($_SERVER['PHP_SELF'] ?? ''), '/modules/') !== false) ? 'timetable.php' : 'modules/timetable.php';
        header('Location: ' . $target);
        exit;
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('capstone_redirect')) {
    function capstone_redirect(string $path): void {
        $in_modules = strpos((string)($_SERVER['PHP_SELF'] ?? ''), '/modules/') !== false;
        $prefix = $in_modules ? '../' : '';
        header('Location: ' . $prefix . ltrim($path, '/'));
        exit;
    }
}

if (!function_exists('capstone_is_api_request')) {
    function capstone_is_api_request(): bool {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $acceptJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        return $xhr || $acceptJson || strpos($script, 'ajax.php') !== false || (isset($_GET['ajax']) && $_GET['ajax'] === '1');
    }
}

if (!function_exists('require_login')) {
    function require_login(): void {
        if (!isset($_SESSION['user'])) {
            if (capstone_is_api_request()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
                exit;
            }
            capstone_redirect('login.php');
        }
    }
}

if (!function_exists('require_roles')) {
    function require_roles(array $roles): void {
        require_login();
        $role = (string)($_SESSION['role'] ?? '');
        $allowed = in_array($role, $roles, true);
        if (!$allowed && in_array($role, ['Rector', 'Dean', 'Admin'], true)) {
            if (in_array('Dean', $roles, true) || in_array('Rector', $roles, true) || in_array('Admin', $roles, true)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            if (capstone_is_api_request()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Forbidden: insufficient role permissions.']);
                exit;
            }
            capstone_redirect('index.php');
        }
    }
}

// Auto-persist active academic term in session so users don't need to re-select term across modules
if (isset($_GET['academic_year']) && trim((string)$_GET['academic_year']) !== '') {
    $__ayParam = trim((string)$_GET['academic_year']);
    if ((string)($_SESSION['active_academic_year'] ?? '') !== $__ayParam) {
        $_SESSION['active_academic_year'] = $__ayParam;
    }
}
if (isset($_GET['semester']) && trim((string)$_GET['semester']) !== '') {
    $__semParam = trim((string)$_GET['semester']);
    if ((string)($_SESSION['active_semester'] ?? '') !== $__semParam) {
        $_SESSION['active_semester'] = $__semParam;
    }
}

if (!function_exists('enformasys_invalidate_dashboard_cache')) {
    function enformasys_invalidate_dashboard_cache(): void {
        unset($_SESSION['cached_dashboard_summary']);
    }
}

if (!function_exists('enformasys_invalidate_ay_cache')) {
    function enformasys_invalidate_ay_cache(): void {
        unset(
            $_SESSION['cached_current_ay'],
            $_SESSION['cached_active_ays'],
            $_SESSION['cached_all_ays'],
            $_SESSION['cached_ay_ts'],
            $_SESSION['cached_dashboard_summary']
        );
    }
}

if (!function_exists('enformasys_refresh_ay_cache')) {
    function enformasys_refresh_ay_cache(?mysqli $conn = null): void {
        if (!$conn instanceof mysqli) {
            global $conn;
        }
        if (!($conn instanceof mysqli)) {
            return;
        }
        $cur = '';
        $active = [];
        $all = [];
        $res = @$conn->query("SELECT label, is_active, is_current, archived_at FROM academic_years ORDER BY start_year DESC, end_year DESC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $lbl = trim((string)($row['label'] ?? ''));
                if ($lbl === '') {
                    continue;
                }
                $all[] = ['label' => $lbl, 'archived' => $row['archived_at'] !== null];
                if ($row['archived_at'] === null) {
                    if ((int)($row['is_active'] ?? 0) === 1) {
                        $active[] = $lbl;
                    }
                    if ($cur === '' && (int)($row['is_current'] ?? 0) === 1) {
                        $cur = $lbl;
                    }
                }
            }
        }
        if ($cur === '') {
            $y = (int)date('Y');
            $m = (int)date('n');
            $start = $m >= 6 ? $y : ($y - 1);
            $cur = $start . '-' . ($start + 1);
        }
        if (empty($active)) {
            $active[] = $cur;
        }
        $allUnarchived = [];
        $allIncludingArchived = [];
        foreach ($all as $item) {
            $allIncludingArchived[] = $item['label'];
            if (!$item['archived']) {
                $allUnarchived[] = $item['label'];
            }
        }
        if (empty($allUnarchived)) {
            $allUnarchived = [$cur];
        }
        if (empty($allIncludingArchived)) {
            $allIncludingArchived = [$cur];
        }

        $_SESSION['cached_current_ay'] = $cur;
        $_SESSION['cached_active_ays'] = $active;
        $_SESSION['cached_all_ays'] = [
            'active_only' => $allUnarchived,
            'all' => $allIncludingArchived,
        ];
        $_SESSION['cached_ay_ts'] = time();
    }
}

if (!function_exists('enformasys_warm_session_cache')) {
    /**
     * Preloads academic-year lookups and role/student profile attributes into $_SESSION
     * upon login so shared layout components (header/sidebar) avoid repeated queries.
     */
    function enformasys_warm_session_cache(mysqli $conn, int $uid, string $username, string $role): void {
        enformasys_refresh_ay_cache($conn);

        $profile = [
            'id' => $uid,
            'username' => $username,
            'role' => $role,
            'label' => get_header_user_label(),
            'seminarian_id' => 0,
            'avatar_url' => null,
        ];

        if ($role === 'Student' && $uid > 0) {
            $stmt = @$conn->prepare("SELECT id, profile_picture FROM seminarians WHERE user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $profile['seminarian_id'] = (int)($row['id'] ?? 0);
                        $_SESSION['seminarian_id'] = $profile['seminarian_id'];
                        if (!function_exists('get_seminarian_avatar_url')) {
                            @require_once __DIR__ . '/seminarian_profile_helper.php';
                        }
                        if (function_exists('get_seminarian_avatar_url')) {
                            $profile['avatar_url'] = get_seminarian_avatar_url($row['profile_picture'] ?? null);
                        }
                    }
                }
                $stmt->close();
            }
        }

        $_SESSION['cached_user_profile'] = $profile;
    }
}

function get_active_academic_year(): string {
    if (!empty($_SESSION['active_academic_year'])) {
        return (string)$_SESSION['active_academic_year'];
    }
    return get_current_academic_year();
}

function get_active_semester(): string {
    if (!empty($_SESSION['active_semester'])) {
        return (string)$_SESSION['active_semester'];
    }
    return '1st';
}

function get_current_academic_year(): string {
    $cacheTs = (int)($_SESSION['cached_ay_ts'] ?? 0);
    if (!empty($_SESSION['cached_current_ay']) && $cacheTs > 0 && (time() - $cacheTs) < 300) {
        return (string)$_SESSION['cached_current_ay'];
    }

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        enformasys_refresh_ay_cache($conn);
        if (!empty($_SESSION['cached_current_ay'])) {
            return (string)$_SESSION['cached_current_ay'];
        }
    }

    $y = (int)date('Y');
    $m = (int)date('n');
    $start = $m >= 6 ? $y : ($y - 1);
    return $start . '-' . ($start + 1);
}

function get_active_academic_years(): array {
    $cacheTs = (int)($_SESSION['cached_ay_ts'] ?? 0);
    if (!empty($_SESSION['cached_active_ays']) && is_array($_SESSION['cached_active_ays']) && $cacheTs > 0 && (time() - $cacheTs) < 300) {
        return $_SESSION['cached_active_ays'];
    }

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        enformasys_refresh_ay_cache($conn);
        if (!empty($_SESSION['cached_active_ays']) && is_array($_SESSION['cached_active_ays'])) {
            return $_SESSION['cached_active_ays'];
        }
    }
    return [get_current_academic_year()];
}

function get_all_academic_years(bool $include_archived = true): array {
    $key = $include_archived ? 'all' : 'active_only';
    $cacheTs = (int)($_SESSION['cached_ay_ts'] ?? 0);
    if (
        !empty($_SESSION['cached_all_ays'][$key])
        && is_array($_SESSION['cached_all_ays'][$key])
        && $cacheTs > 0
        && (time() - $cacheTs) < 300
    ) {
        return $_SESSION['cached_all_ays'][$key];
    }

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        enformasys_refresh_ay_cache($conn);
        if (!empty($_SESSION['cached_all_ays'][$key]) && is_array($_SESSION['cached_all_ays'][$key])) {
            return $_SESSION['cached_all_ays'][$key];
        }
    }
    return [get_current_academic_year()];
}

if (!function_exists('capstone_format_units')) {
    /**
     * Format decimal subject/load units cleanly without truncating .5 values.
     */
    function capstone_format_units($units): string {
        $val = round((float)$units, 1);
        return abs($val - round($val)) < 0.0001
            ? (string)(int)round($val)
            : number_format($val, 1);
    }
}

/**
 * Normalizes year level strings and aliases to standard canonical format.
 */
function normalize_year_level(?string $yl): string {
    $clean = trim((string)($yl ?? ''));
    if ($clean === '') {
        return '';
    }
    $lower = strtolower($clean);

    if (strpos($lower, 'propaedeutic') !== false || strpos($lower, 'prep') !== false) {
        return 'Propaedeutic Stage';
    }
    if (strpos($lower, 'first') !== false || strpos($lower, '1st') !== false || $lower === '1') {
        return 'First Year';
    }
    if (strpos($lower, 'second') !== false || strpos($lower, '2nd') !== false || $lower === '2') {
        return 'Second Year';
    }
    if (strpos($lower, 'third') !== false || strpos($lower, '3rd') !== false || $lower === '3') {
        return 'Third Year';
    }
    if (strpos($lower, 'fourth') !== false || strpos($lower, '4th') !== false || $lower === '4') {
        return 'Fourth Year';
    }
    if (strpos($lower, 'complete') !== false || strpos($lower, 'graduate') !== false) {
        return 'Completed';
    }
    return $clean;
}

/**
 * Returns formatted display label for year level.
 */
function get_year_level_display(?string $yl): string {
    $norm = normalize_year_level($yl);
    if ($norm === '') {
        return '';
    }
    $labels = [
        'Propaedeutic Stage' => 'Propaedeutic Stage',
        'First Year'         => '1st Year (First Year)',
        'Second Year'        => '2nd Year (Second Year)',
        'Third Year'         => '3rd Year (Third Year)',
        'Fourth Year'        => '4th Year (Fourth Year)',
        'Completed'          => 'Completed / Graduated',
    ];
    return $labels[$norm] ?? $norm;
}

/**
 * Computes next year level using defined progression sequence:
 * Propaedeutic Stage -> 1st Year -> 2nd Year -> 3rd Year -> 4th Year -> Completed/Graduated.
 */
function get_next_year_level(?string $current_yl): string {
    $norm = normalize_year_level($current_yl);
    if ($norm === '') {
        return 'First Year';
    }

    $progression_map = [
        'Propaedeutic Stage' => 'First Year',
        'First Year'         => 'Second Year',
        'Second Year'        => 'Third Year',
        'Third Year'         => 'Fourth Year',
        'Fourth Year'        => 'Completed',
        'Completed'          => 'Completed',
    ];

    return $progression_map[$norm] ?? 'First Year';
}

/**
 * Calculates semester-aware target year level:
 * - If enrolling for 2nd Semester, target year level MUST remain exactly the same as current stage/year level.
 * - Auto-promotion to the next year level ONLY occurs if student completed 2nd Semester of previous year
 *   and is now enrolling for 1st Semester of a new academic year.
 */
function calculate_target_year_level(?string $current_yl, ?string $last_sem, string $enrolling_sem): string {
    $norm = normalize_year_level($current_yl);
    if ($norm === '') {
        return 'First Year';
    }
    // If enrolling for 2nd Semester, target year level MUST remain exactly the same
    if ($enrolling_sem === '2nd') {
        return $norm;
    }
    // Only promote if student completed 2nd Semester of previous year and is now enrolling for 1st Semester
    if ($enrolling_sem === '1st' && trim((string)$last_sem) === '2nd') {
        return get_next_year_level($norm);
    }
    // Otherwise (e.g. enrolling for 1st Semester but last completed was 1st or none), remain at current stage
    return $norm;
}

/**
 * Queries student's most recent enrollment record to find their last completed year_level or stage.
 */
function get_student_last_enrollment_record(int $student_id): array {
    global $conn;
    $record = [
        'year_level' => '',
        'semester' => '',
        'academic_year' => '',
        'program' => ''
    ];
    if ($student_id <= 0 || !($conn instanceof mysqli)) return $record;

    // 0. Check most recent record in decoupled `enrollments` table first
    $stmt_enr = @$conn->prepare(
        "SELECT year_level, semester, academic_year, program\n"
        . "FROM enrollments\n"
        . "WHERE student_id = ?\n"
        . "ORDER BY academic_year DESC, (CASE semester WHEN 'Summer' THEN 3 WHEN '2nd' THEN 2 WHEN '1st' THEN 1 ELSE 0 END) DESC, id DESC\n"
        . "LIMIT 1"
    );
    if ($stmt_enr) {
        $stmt_enr->bind_param('i', $student_id);
        $stmt_enr->execute();
        $row_enr = $stmt_enr->get_result()->fetch_assoc();
        if ($row_enr && !empty(trim((string)($row_enr['year_level'] ?? '')))) {
            return [
                'year_level' => trim((string)$row_enr['year_level']),
                'semester' => trim((string)($row_enr['semester'] ?? '')),
                'academic_year' => trim((string)($row_enr['academic_year'] ?? '')),
                'program' => trim((string)($row_enr['program'] ?? ''))
            ];
        }
    }

    // 1. Check most recent record in student_enrolled_subjects (chronologically sorted)
    $stmt = $conn->prepare(
        "SELECT year_level, semester, academic_year, program\n"
        . "FROM student_enrolled_subjects\n"
        . "WHERE student_id = ?\n"
        . "ORDER BY academic_year DESC, (CASE semester WHEN 'Summer' THEN 3 WHEN '2nd' THEN 2 WHEN '1st' THEN 1 ELSE 0 END) DESC, id DESC\n"
        . "LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && !empty(trim((string)($row['year_level'] ?? '')))) {
            return [
                'year_level' => trim((string)$row['year_level']),
                'semester' => trim((string)($row['semester'] ?? '')),
                'academic_year' => trim((string)($row['academic_year'] ?? '')),
                'program' => trim((string)($row['program'] ?? ''))
            ];
        }
    }

    // 2. Check student_grades joined with curriculum_subjects
    $stmt_g = $conn->prepare(
        "SELECT cs.year_level, g.semester, g.academic_year\n"
        . "FROM student_grades g\n"
        . "LEFT JOIN curriculum_subjects cs ON cs.subject_id = g.subject_id\n"
        . "WHERE g.student_id = ? AND cs.year_level IS NOT NULL AND cs.year_level != ''\n"
        . "ORDER BY g.academic_year DESC, (CASE g.semester WHEN 'Summer' THEN 3 WHEN '2nd' THEN 2 WHEN '1st' THEN 1 ELSE 0 END) DESC, g.grade_id DESC\n"
        . "LIMIT 1"
    );
    if ($stmt_g) {
        $stmt_g->bind_param('i', $student_id);
        $stmt_g->execute();
        $row_g = $stmt_g->get_result()->fetch_assoc();
        if ($row_g && !empty(trim((string)($row_g['year_level'] ?? '')))) {
            return [
                'year_level' => trim((string)$row_g['year_level']),
                'semester' => trim((string)($row_g['semester'] ?? '')),
                'academic_year' => trim((string)($row_g['academic_year'] ?? '')),
                'program' => ''
            ];
        }
    }

    // 3. Fallback to seminarians table
    $stmt_s = $conn->prepare("SELECT year_level, program, current_academic_year FROM seminarians WHERE id = ? LIMIT 1");
    if ($stmt_s) {
        $stmt_s->bind_param('i', $student_id);
        $stmt_s->execute();
        $row_s = $stmt_s->get_result()->fetch_assoc();
        if ($row_s) {
            return [
                'year_level' => trim((string)($row_s['year_level'] ?? '')),
                'semester' => '',
                'academic_year' => trim((string)($row_s['current_academic_year'] ?? '')),
                'program' => trim((string)($row_s['program'] ?? ''))
            ];
        }
    }

    return $record;
}

function get_performance_label($score) {
    $score = round($score);
    switch ($score) {
        case 1: return "Deficient";
        case 2: return "Needs Growth";
        case 3: return "Solidly Developing";
        case 4: return "Often Demonstrated";
        case 5: return "Consistently Demonstrated";
        default: return "Not Evaluated";
    }
}

function render_performance_bar($score) {
    $score = round($score);
    if ($score < 1) $score = 1;
    if ($score > 5) $score = 5;
    $width = ($score / 5) * 100;
    $label = get_performance_label($score);
    
    return "
    <div class='performance-container'>
        <span class='performance-label label-$score'>$label</span>
        <div class='performance-bar-bg'>
            <div class='performance-bar-fill perf-$score' style='width: $width%;'></div>
        </div>
    </div>";
}

function render_rating_badge($score) {
    if ($score === null || $score === '' || !is_numeric($score)) {
        return "<span class='badge badge-warning'>Not rated</span>";
    }

    $score = (float)$score;
    if ($score < 1) $score = 1;
    if ($score > 5) $score = 5;

    $label = get_performance_label($score);
    $score_text = rtrim(rtrim(number_format($score, 1), '0'), '.');

    return "<span class='badge badge-primary'>{$score_text}/5</span> <span style='color: #64748b; font-size: 0.85rem; font-weight: 600;'>{$label}</span>";
}



function render_pie_chart($percent, $size = 120) {
    $p = (float)$percent;
    if ($p < 0) $p = 0;
    if ($p > 100) $p = 100;
    
    $radius = 50;
    $center = 60;
    $circumference = 2 * pi() * $radius;
    $offset = $circumference - ($p / 100) * $circumference;
    
    $color = '#3b82f6';
    if ($p < 60) $color = '#ef4444';
    elseif ($p < 80) $color = '#f59e0b';
    else $color = '#10b981';

    return "
    <div style='position: relative; width: {$size}px; height: {$size}px;'>
        <svg width='{$size}' height='{$size}' viewBox='0 0 120 120' style='transform: rotate(-90deg);'>
            <circle cx='{$center}' cy='{$center}' r='{$radius}' fill='transparent' stroke='#f1f5f9' stroke-width='12' />
            <circle cx='{$center}' cy='{$center}' r='{$radius}' fill='transparent' stroke='{$color}' stroke-width='12' 
                stroke-dasharray='{$circumference}' stroke-dashoffset='{$offset}' stroke-linecap='round' />
        </svg>
        <div style='position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center;'>
            <div style='font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1;'>" . (int)$p . "</div>
            <div style='font-size: 0.75rem; font-weight: 700; color: #64748b;'>%</div>
        </div>
    </div>";
}

function render_category_pie_chart($categories, $overallPercent = null, $size = 220) {
    $labels = array_keys($categories);
    $values = array_values($categories);
    $n = count($labels);
    
    if ($n === 0) return '';
    
    $validValues = [];
    $validLabels = [];
    $validColors = [];
    $palette = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b'];
    for ($i = 0; $i < $n; $i++) {
        $v = is_numeric($values[$i]) ? max(0.0, (float)$values[$i]) : 0.0;
        $validValues[] = $v;
        $validLabels[] = $labels[$i];
        $validColors[] = $palette[$i % count($palette)];
    }
    
    $validCount = count($validValues);
    if ($validCount === 0) {
        return "<div style='width: {$size}px; height: {$size}px; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 0.85rem;'>No data</div>";
    }

    $radius = 80;
    $center = 110;
    $circumference = 2 * pi() * $radius;
    
    $slices = '';
    $currentAngle = -pi() / 2;
    $totalVal = array_sum($validValues);
    if ($totalVal <= 0) $totalVal = 1;

    for ($i = 0; $i < $validCount; $i++) {
        $proportion = $validValues[$i] / $totalVal;
        $dashLen = $circumference * $proportion;
        $gapLen = $circumference - $dashLen;
        $color = $validColors[$i];

        $slices .= "<circle cx='{$center}' cy='{$center}' r='{$radius}' fill='transparent' stroke='{$color}' stroke-width='28' 
            stroke-dasharray='{$dashLen} {$gapLen}' stroke-dashoffset='0' 
            style='transform: rotate(" . rad2deg($currentAngle) . "deg); transform-origin: {$center}px {$center}px;' />";
        
        $currentAngle += 2 * pi() * $proportion;
    }

    $centerLabel = '';
    if ($overallPercent !== null && is_numeric($overallPercent)) {
        $op = (int)$overallPercent;
        $label = get_overall_percent_label($op);
        $centerLabel = "
        <text x='{$center}' y='" . ($center - 8) . "' text-anchor='middle' font-size='1.75rem' font-weight='800' fill='#1e293b' dominant-baseline='middle'>{$op}</text>
        <text x='{$center}' y='" . ($center + 12) . "' text-anchor='middle' font-size='0.7rem' font-weight='700' fill='#64748b' dominant-baseline='middle'>{$label}</text>";
    }

    $svg = "<svg width='{$size}' height='{$size}' viewBox='0 0 220 220' style='overflow: visible;'>"
        . "<circle cx='{$center}' cy='{$center}' r='" . ($radius - 14) . "' fill='rgba(255,255,255,0.85)' />"
        . $slices
        . $centerLabel
        . "</svg>";

    $legend = '';
    for ($i = 0; $i < $validCount; $i++) {
        $color = $validColors[$i];
        $lbl = htmlspecialchars($validLabels[$i]);
        $legend .= "<div style='display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 700; color: #475569;'>
            <div style='width: 12px; height: 12px; border-radius: 3px; background: {$color}; flex-shrink: 0;'></div>
            {$lbl}
        </div>";
    }

    return "
    <div style='display: flex; flex-direction: column; align-items: center; gap: 1rem;'>
        <div>{$svg}</div>
        <div style='display: flex; flex-direction: column; gap: 0.35rem;'>{$legend}</div>
    </div>";
}

function log_activity($user_id, $action) {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }
    $uid = (is_numeric($user_id) && (int)$user_id > 0) ? (int)$user_id : null;
    $act = mb_substr(trim((string)$action), 0, 255);
    $stmt = @$conn->prepare("INSERT INTO activities (user_id, action) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("is", $uid, $act);
        @$stmt->execute();
        $stmt->close();
    }
}

function log_field_change($user_id, string $entity_type, int $entity_id, string $field_name, $previous_value, $new_value): void {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }
    $uid = (is_numeric($user_id) && (int)$user_id > 0) ? (int)$user_id : null;
    $prev = is_scalar($previous_value) || $previous_value === null ? (string)($previous_value ?? '') : json_encode($previous_value);
    $next = is_scalar($new_value) || $new_value === null ? (string)($new_value ?? '') : json_encode($new_value);
    $action = mb_substr($entity_type . " updated: " . $field_name, 0, 255);

    $stmt = @$conn->prepare("INSERT INTO activities (user_id, action, entity_type, entity_id, field_name, previous_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ississs", $uid, $action, $entity_type, $entity_id, $field_name, $prev, $next);
        @$stmt->execute();
        $stmt->close();
        return;
    }

    log_activity($uid, $action . " (" . $prev . " -> " . $next . ")");
}

function get_header_user_label() {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'Student') {
        return 'Student';
    }
    if ($role === 'Registrar') {
        return 'Registrar';
    }
    if ($role === 'Dean' || $role === 'Rector') {
        return 'Rector';
    }
    if ($role === 'AcademicFormator') {
        return 'Academic Formator';
    }
    if ($role === 'SpiritualDirector') {
        return 'Spiritual Director';
    }
    if ($role === 'HumanFormator') {
        return 'Human Formator';
    }
    if ($role === 'PastoralFormator') {
        return 'Pastoral Formator';
    }
    if ($role === 'Cashier') {
        return 'Cashier';
    }
    if ($role === 'Shrine') {
        return 'Shrine';
    }
    return 'User';
}

function get_overall_percent_label($percent) {
    $p = (int)$percent;
    if ($p <= 0) {
        return 'No performance';
    }
    if ($p < 60) {
        return 'Unsuitable';
    }
    if ($p <= 69) {
        return 'Needs Improvement';
    }
    if ($p <= 79) {
        return 'Developing';
    }
    if ($p <= 89) {
        return 'Very Good';
    }
    return 'Outstanding';
}

function academic_percent_to_rating($percent) {
    if ($percent === null || $percent === '' || !is_numeric($percent)) {
        return null;
    }
    $p = (float)$percent;
    if ($p <= 0) {
        return null;
    }
    if ($p < 60) return 1;
    if ($p <= 69) return 2;
    if ($p <= 79) return 3;
    if ($p <= 89) return 4;
    return 5;
}

if (!function_exists('get_logged_in_user_id')) {
    /**
     * Resolve the authenticated user's numeric database ID regardless of session key shape.
     */
    function get_logged_in_user_id(?mysqli $conn = null): int {
        if (isset($_SESSION['user']) && is_numeric($_SESSION['user']) && (int)$_SESSION['user'] > 0) {
            return (int)$_SESSION['user'];
        }
        if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            return (int)$_SESSION['user_id'];
        }
        if ($conn !== null) {
            $uname = trim((string)($_SESSION['username'] ?? ($_SESSION['user'] ?? '')));
            if ($uname !== '') {
                $stmt = @$conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $uname);
                    $stmt->execute();
                    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
                    $stmt->close();
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }
        return 0;
    }
}

if (!function_exists('set_toast')) {
    /**
     * Set a flash toast notification to be displayed on next page load
     * @param string $message
     * @param 'success'|'error'|'info' $type
     */
    function set_toast(string $message, string $type = 'success'): void {
        $allowed = ['success', 'error', 'info'];
        $type = in_array(strtolower($type), $allowed, true) ? strtolower($type) : 'info';
        $_SESSION['toast_' . $type] = $message;
    }
}
