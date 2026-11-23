<?php
/**
 * Centralized Security Middleware for EnformaSys:
 * - HTTP Security Headers & Strict CORS Policy
 * - Secure Session Cookie Configuration
 * - Brute-Force Login Rate Limiting
 * - Application Security & Activity Audit Logging
 */
if (defined('CAPSTONE_SECURITY_LOADED')) {
    return;
}
define('CAPSTONE_SECURITY_LOADED', true);

require_once __DIR__ . '/env.php';

if (!function_exists('enformasys_is_https_request')) {
    function enformasys_is_https_request(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('enformasys_allowed_origins')) {
    /**
     * @return string[] Normalized scheme://host[:port] list
     */
    function enformasys_allowed_origins(): array
    {
        $origins = [];
        $rawList = (string)enformasys_env('ALLOWED_ORIGINS', '');
        $appUrl = (string)enformasys_env('APP_URL', '');
        $candidates = array_merge(
            array_map('trim', explode(',', $rawList)),
            [trim($appUrl)]
        );

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = enformasys_is_https_request() ? 'https' : 'http';
            $candidates[] = $scheme . '://' . $host;
        }

        foreach ($candidates as $cand) {
            if ($cand === '') {
                continue;
            }
            $parts = @parse_url($cand);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                continue;
            }
            $norm = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
            if (!empty($parts['port'])) {
                $norm .= ':' . (int)$parts['port'];
            }
            if (!in_array($norm, $origins, true)) {
                $origins[] = $norm;
            }
        }
        return $origins;
    }
}

if (!function_exists('enformasys_apply_security_headers')) {
    function enformasys_apply_security_headers(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('X-XSS-Protection: 0');

        if (enformasys_is_https_request() || enformasys_is_production()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Strict CORS validation when an Origin header is supplied
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($origin !== '') {
            $parts = @parse_url($origin);
            $normOrigin = (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']))
                ? (strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (!empty($parts['port']) ? ':' . (int)$parts['port'] : ''))
                : '';

            $allowed = enformasys_allowed_origins();
            if ($normOrigin !== '' && in_array($normOrigin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $normOrigin);
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
                header('Vary: Origin');
                if ($method === 'OPTIONS') {
                    http_response_code(204);
                    exit;
                }
            } else {
                // Reject cross-origin preflight or XHR/API calls from untrusted domains
                $isApi = $method === 'OPTIONS'
                    || (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'ajax.php') !== false)
                    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
                if ($isApi) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => 'Origin not allowed by CORS policy.']);
                    exit;
                }
            }
        } elseif ($method === 'OPTIONS') {
            http_response_code(405);
            exit;
        }
    }
}

if (!class_exists('EnformasysDbSessionHandler')) {
    /**
     * Stateless-friendly MySQL SessionHandler for Vercel serverless containers,
     * keeping sessions consistent across ephemeral Lambda instances.
     */
    class EnformasysDbSessionHandler implements SessionHandlerInterface
    {
        private ?mysqli $conn = null;
        private int $lifetime = 3600;
        private ?string $lastReadId = null;
        private ?string $lastReadHash = null;
        private int $lastReadAt = 0;

        public function __construct(int $lifetime = 3600)
        {
            $this->lifetime = max(300, $lifetime);
        }

        private function ensureTable(mysqli $c): void
        {
            @$c->query(
                "CREATE TABLE IF NOT EXISTS `php_sessions` (
                    `id` VARCHAR(128) NOT NULL,
                    `data` MEDIUMTEXT NOT NULL,
                    `last_accessed` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_php_sessions_last_accessed` (`last_accessed`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        private function connect(): ?mysqli
        {
            if ($this->conn instanceof mysqli) {
                return $this->conn;
            }
            $host = (string)enformasys_env('DB_HOST', '');
            $user = (string)enformasys_env('DB_USER', '');
            $pass = (string)enformasys_env('DB_PASS', enformasys_env('DB_PASSWORD', ''));
            $db   = (string)enformasys_env('DB_NAME', '');
            $port = (int)enformasys_env('DB_PORT', '3306');
            if ($host === '' || $user === '' || $db === '') {
                return null;
            }

            $isVercel = (enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== '');
            $usePersistent = $isVercel || strtolower(trim((string)enformasys_env('DB_PERSISTENT', 'false'))) === 'true';
            $connectHost = ($usePersistent && stripos($host, 'p:') !== 0) ? ('p:' . $host) : $host;

            $sslRaw = strtolower(trim((string)enformasys_env('DB_SSL', 'false')));
            $sslEnabled = in_array($sslRaw, ['1', 'true', 'yes', 'on', 'required'], true);
            $caRaw = trim((string)enformasys_env('DB_SSL_CA', ''));
            $caPath = null;
            if ($caRaw !== '') {
                $isAbs = (preg_match('/^([A-Za-z]:[\\\\\\/]|\\/)/', $caRaw) === 1);
                $cand = $isAbs
                    ? $caRaw
                    : (dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $caRaw), DIRECTORY_SEPARATOR));
                if (is_file($cand)) {
                    $caPath = $cand;
                }
            }

            try {
                $c = mysqli_init();
                if (!$c) {
                    return null;
                }
                $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
                $flags = 0;
                if ($sslEnabled || $caPath !== null) {
                    $c->ssl_set(null, null, $caPath, null, null);
                    $flags = MYSQLI_CLIENT_SSL;
                    if ($caPath !== null && defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                        $c->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
                    }
                }
                if (!@$c->real_connect($connectHost, $user, $pass, $db, $port > 0 ? $port : 3306, null, $flags)) {
                    return null;
                }
                $c->set_charset('utf8mb4');
                $this->conn = $c;
                return $this->conn;
            } catch (Throwable $e) {
                error_log('EnformasysDbSessionHandler connect failed: ' . $e->getMessage());
                return null;
            }
        }

        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            if ($this->conn instanceof mysqli) {
                @$this->conn->close();
                $this->conn = null;
            }
            return true;
        }

        public function read(string $id): string|false
        {
            $this->lastReadId = null;
            $this->lastReadHash = null;
            $this->lastReadAt = 0;

            $c = $this->connect();
            if (!$c) {
                return '';
            }
            $cutoff = time() - $this->lifetime;
            $stmt = @$c->prepare("SELECT data, last_accessed FROM php_sessions WHERE id = ? LIMIT 1");
            if (!$stmt && $c->errno === 1146) {
                $this->ensureTable($c);
                $stmt = @$c->prepare("SELECT data, last_accessed FROM php_sessions WHERE id = ? LIMIT 1");
            }
            if (!$stmt) {
                return '';
            }
            $stmt->bind_param('s', $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return '';
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || (int)$row['last_accessed'] < $cutoff) {
                return '';
            }
            $data = (string)$row['data'];
            $this->lastReadId = $id;
            $this->lastReadHash = hash('sha256', $data);
            $this->lastReadAt = (int)$row['last_accessed'];
            return $data;
        }

        public function write(string $id, string $data): bool
        {
            $now = time();
            $newHash = hash('sha256', $data);
            if (
                $this->lastReadId === $id
                && $this->lastReadHash === $newHash
                && ($now - $this->lastReadAt) < 60
            ) {
                return true;
            }

            $c = $this->connect();
            if (!$c) {
                return false;
            }
            $stmt = @$c->prepare(
                "INSERT INTO php_sessions (id, data, last_accessed) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_accessed = VALUES(last_accessed)"
            );
            if (!$stmt && $c->errno === 1146) {
                $this->ensureTable($c);
                $stmt = @$c->prepare(
                    "INSERT INTO php_sessions (id, data, last_accessed) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE data = VALUES(data), last_accessed = VALUES(last_accessed)"
                );
            }
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssi', $id, $data, $now);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                $this->lastReadId = $id;
                $this->lastReadHash = $newHash;
                $this->lastReadAt = $now;
            }
            return (bool)$ok;
        }

        public function destroy(string $id): bool
        {
            $this->lastReadId = null;
            $this->lastReadHash = null;
            $this->lastReadAt = 0;

            $c = $this->connect();
            if (!$c) {
                return true;
            }
            $stmt = @$c->prepare("DELETE FROM php_sessions WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('s', $id);
                @$stmt->execute();
                $stmt->close();
            }
            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            $c = $this->connect();
            if (!$c) {
                return 0;
            }
            $cutoff = time() - max($max_lifetime, $this->lifetime);
            $stmt = @$c->prepare("DELETE FROM php_sessions WHERE last_accessed < ?");
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $cutoff);
            @$stmt->execute();
            $deleted = max(0, (int)$stmt->affected_rows);
            $stmt->close();
            return $deleted;
        }
    }
}

if (!function_exists('enformasys_configure_session')) {
    function enformasys_configure_session(string $sessionsDir): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $isVercel = (enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== '');
        $tmpSessionsDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'enformasys_sessions';

        // On Vercel or read-only deployments, never try writing sessions into the bundle directory; use /tmp
        $targetDir = $sessionsDir;
        if ($isVercel) {
            $targetDir = $tmpSessionsDir;
        } else {
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0750, true);
            }
            if (!is_dir($targetDir) || !is_writable($targetDir)) {
                $targetDir = $tmpSessionsDir;
            }
        }
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0700, true);
        }
        if (is_dir($targetDir) && is_writable($targetDir)) {
            @session_save_path($targetDir);
        } else {
            @session_save_path(sys_get_temp_dir());
        }

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');

        $isSecure = enformasys_is_https_request() || enformasys_is_production() || $isVercel;
        @ini_set('session.cookie_secure', $isSecure ? '1' : '0');

        @session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        // Use MySQL-backed sessions on Vercel (or SESSION_DRIVER=database) so sessions persist across Lambdas
        $sessionDriver = strtolower(trim((string)enformasys_env('SESSION_DRIVER', $isVercel ? 'database' : 'files')));
        if (PHP_SAPI !== 'cli' && $sessionDriver === 'database' && enformasys_env('DB_HOST', '') !== '') {
            $lifetime = max(300, (int)enformasys_env('SESSION_LIFETIME', '3600'));
            @ini_set('session.gc_maxlifetime', (string)$lifetime);
            @session_set_save_handler(new EnformasysDbSessionHandler($lifetime), true);
        }
    }
}

if (!function_exists('enformasys_client_ip')) {
    function enformasys_client_ip(): string
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '127.0.0.1';
    }
}

if (!function_exists('enformasys_ensure_login_attempts_table')) {
    function enformasys_ensure_login_attempts_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL DEFAULT '',
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_user_ip (username, ip_address, attempted_at),
                INDEX idx_login_ip_time (ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }
}

if (!function_exists('enformasys_login_throttle_status')) {
    /**
     * Enforces max 5 failed login attempts per 15-minute window for (username + IP)
     * or max 15 failed attempts across all usernames from a single IP.
     *
     * @return array{blocked:bool, remaining_seconds:int, attempts:int}
     */
    function enformasys_login_throttle_status(mysqli $conn, string $username, string $ip): array
    {
        enformasys_ensure_login_attempts_table($conn);
        $normUser = strtolower(trim($username));
        $windowSec = 900; // 15 minutes
        $maxUserAttempts = 5;
        $maxIpAttempts = 15;

        // Clean stale entries older than 24h
        @$conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");

        $stmt = $conn->prepare(
            "SELECT
                SUM(CASE WHEN username = ? THEN 1 ELSE 0 END) AS user_cnt,
                COUNT(*) AS ip_cnt,
                MAX(UNIX_TIMESTAMP(attempted_at)) AS last_ts
             FROM login_attempts
             WHERE ip_address = ? AND attempted_at >= (NOW() - INTERVAL ? SECOND)"
        );
        if (!$stmt) {
            return ['blocked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
        }
        $stmt->bind_param('ssi', $normUser, $ip, $windowSec);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $userCnt = (int)($row['user_cnt'] ?? 0);
        $ipCnt = (int)($row['ip_cnt'] ?? 0);
        $lastTs = (int)($row['last_ts'] ?? 0);

        $isBlocked = ($normUser !== '' && $userCnt >= $maxUserAttempts) || ($ipCnt >= $maxIpAttempts);
        $remaining = 0;
        if ($isBlocked && $lastTs > 0) {
            $remaining = max(1, ($lastTs + $windowSec) - time());
        }
        return [
            'blocked' => $isBlocked,
            'remaining_seconds' => $remaining,
            'attempts' => max($userCnt, $ipCnt),
        ];
    }
}

if (!function_exists('enformasys_record_failed_login')) {
    function enformasys_record_failed_login(mysqli $conn, string $username, string $ip): void
    {
        enformasys_ensure_login_attempts_table($conn);
        $normUser = strtolower(trim($username));
        $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('ss', $normUser, $ip);
            $stmt->execute();
            $stmt->close();
        }

        // Look up target user ID (if account exists) for audit attribution without failing FK
        $targetUid = null;
        if ($normUser !== '') {
            $stmtU = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('s', $normUser);
                $stmtU->execute();
                $uRow = $stmtU->get_result()->fetch_assoc();
                if ($uRow && !empty($uRow['id'])) {
                    $targetUid = (int)$uRow['id'];
                }
                $stmtU->close();
            }
        }
        $safeUser = mb_substr($normUser !== '' ? $normUser : '(empty)', 0, 64);
        enformasys_audit_log(
            $conn,
            $targetUid,
            "[SECURITY] Failed login attempt for '{$safeUser}' from IP {$ip}",
            'Auth',
            $targetUid,
            'login_failed',
            null,
            $ip
        );
    }
}

if (!function_exists('enformasys_clear_login_attempts')) {
    function enformasys_clear_login_attempts(mysqli $conn, string $username, string $ip): void
    {
        enformasys_ensure_login_attempts_table($conn);
        $normUser = strtolower(trim($username));
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $normUser, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('enformasys_audit_log')) {
    /**
     * Records a structured security, financial, student-edit, or migration event in `activities`.
     */
    function enformasys_audit_log(
        mysqli $conn,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $fieldName = null,
        ?string $previousValue = null,
        ?string $newValue = null
    ): void {
        $uidNullable = ($userId !== null && $userId > 0) ? $userId : null;
        $actionTrim = mb_substr(trim($action), 0, 255);
        $stmt = @$conn->prepare(
            "INSERT INTO activities (user_id, action, entity_type, entity_id, field_name, previous_value, new_value)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param('ississs', $uidNullable, $actionTrim, $entityType, $entityId, $fieldName, $previousValue, $newValue);
            @$stmt->execute();
            $stmt->close();
        }
    }
}
