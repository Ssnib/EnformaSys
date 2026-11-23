<?php
/**
 * EnformaSys Database Connection & SSL Health Check
 *
 * Usage:
 *   php scripts/check_db.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once __DIR__ . '/../includes/env.php';

$host = (string)enformasys_env('DB_HOST', '');
$port = (string)enformasys_env('DB_PORT', '3306');
$db   = (string)enformasys_env('DB_NAME', '');
$user = (string)enformasys_env('DB_USER', '');
$ssl  = (string)enformasys_env('DB_SSL', 'false');
$ca   = (string)enformasys_env('DB_SSL_CA', '');

echo "Checking database connection...\n";
echo "  Host: {$host}:{$port}\n";
echo "  DB:   {$db}\n";
echo "  User: {$user}\n";
echo "  SSL:  {$ssl}" . ($ca !== '' ? " (CA: {$ca})" : '') . "\n\n";

require_once __DIR__ . '/../includes/db.php';

$info = $conn->query("SELECT DATABASE() AS db_name, VERSION() AS server_version, CURRENT_USER() AS cur_user")->fetch_assoc();
$sslCipher = $conn->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch_assoc();
$sslVersion = $conn->query("SHOW SESSION STATUS LIKE 'Ssl_version'")->fetch_assoc();
$tablesRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
$tableCount = $tablesRes ? $tablesRes->num_rows : 0;

$adminRow = null;
$stmt = @$conn->prepare("SELECT id, username, role FROM users WHERE username = 'admin' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $adminRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

echo "Connection: SUCCESS\n";
echo "  Active DB:      " . ($info['db_name'] ?? 'unknown') . "\n";
echo "  Server Version: " . ($info['server_version'] ?? 'unknown') . "\n";
echo "  Current User:   " . ($info['cur_user'] ?? 'unknown') . "\n";
echo "  SSL Cipher:     " . (($sslCipher['Value'] ?? '') !== '' ? $sslCipher['Value'] : 'NONE (unencrypted)') . "\n";
echo "  SSL Version:    " . (($sslVersion['Value'] ?? '') !== '' ? $sslVersion['Value'] : 'N/A') . "\n";
echo "  Base Tables:    {$tableCount}\n";
echo "  Admin Account:  " . ($adminRow ? "#{$adminRow['id']} {$adminRow['username']} ({$adminRow['role']})" : 'NOT FOUND') . "\n";
exit(0);
