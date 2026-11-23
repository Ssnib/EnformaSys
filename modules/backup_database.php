<?php
require_once __DIR__ . '/../includes/session_fix.php';

require_login();
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['Dean', 'Registrar', 'Rector'], true)) {
    http_response_code(403);
    exit('Forbidden: Only Administrator, Rector, or Registrar roles may download database backups.');
}

$csrfToken = (string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ''));
if ($csrfToken === '' || !verify_csrf_token($csrfToken)) {
    http_response_code(403);
    exit('Invalid or missing security token. Please refresh the page and try again.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_helper.php';

@set_time_limit(180);

$tmpFile = tempnam(sys_get_temp_dir(), 'enformasys_sql_');
if ($tmpFile === false) {
    http_response_code(500);
    exit('Failed to allocate temporary file for database backup.');
}

$forcePhp = isset($_GET['mode']) && $_GET['mode'] === 'php';
$result = backup_generate_sql_file($conn, $tmpFile, [
    'host' => $servername ?? '127.0.0.1',
    'user' => $username ?? 'root',
    'pass' => $password ?? '',
    'name' => $dbname ?? 'seminary_db',
], $forcePhp);

if (!$result['success'] || !is_file($tmpFile)) {
    @unlink($tmpFile);
    http_response_code(500);
    exit('Backup generation failed: ' . htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8'));
}

$filename = 'enformasys_backup_' . date('Y-m-d_His') . '.sql';
$userId = (int)($_SESSION['user'] ?? ($_SESSION['user_id'] ?? 0));
if ($userId > 0 && function_exists('log_activity')) {
    try {
        log_activity($userId, "Downloaded full SQL database backup ({$filename}, method: {$result['method']})");
    } catch (\Throwable $e) {
        // Activity log failure must never block full database backup download.
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . (string)filesize($tmpFile));

readfile($tmpFile);
@unlink($tmpFile);
exit;
