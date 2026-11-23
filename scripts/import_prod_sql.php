<?php
/**
 * EnformaSys Production SQL Importer (Aiven / TLS Ready)
 *
 * Imports a clean SQL dump (supporting `DELIMITER ;;` trigger blocks)
 * over the configured SSL/TLS MySQL connection into DB_NAME (`defaultdb`).
 *
 * Usage:
 *   php scripts/import_prod_sql.php --file=sql/prod_setup.sql
 *   php scripts/import_prod_sql.php --file=sql/prod_setup.sql --drop-existing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once __DIR__ . '/../includes/env.php';

$args = array_slice($argv ?? [], 1);
$sqlFile = 'sql/prod_setup.sql';
$dropExisting = in_array('--drop-existing', $args, true);

foreach ($args as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $sqlFile = substr($arg, strlen('--file='));
    }
}

$fullPath = preg_match('/^([A-Za-z]:[\\\\\\/]|\\/)/', $sqlFile) === 1
    ? $sqlFile
    : (dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sqlFile));

if (!is_file($fullPath)) {
    fwrite(STDERR, "ERROR: SQL file not found: {$fullPath}\n");
    exit(1);
}

$host = (string)enformasys_env('DB_HOST', '');
$port = (int)enformasys_env('DB_PORT', '3306');
$db   = (string)enformasys_env('DB_NAME', '');
$user = (string)enformasys_env('DB_USER', '');
$pass = (string)enformasys_env('DB_PASS', enformasys_env('DB_PASSWORD', ''));
$sslRaw = strtolower(trim((string)enformasys_env('DB_SSL', 'false')));
$sslEnabled = in_array($sslRaw, ['1', 'true', 'yes', 'on', 'required'], true);
$caRaw = trim((string)enformasys_env('DB_SSL_CA', ''));

$caPath = null;
if ($caRaw !== '') {
    $caPath = (preg_match('/^([A-Za-z]:[\\\\\\/]|\\/)/', $caRaw) === 1)
        ? $caRaw
        : (dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $caRaw), DIRECTORY_SEPARATOR));
    if (!is_file($caPath)) {
        fwrite(STDERR, "ERROR: CA certificate not found: {$caPath}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_init();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
$flags = 0;
if ($sslEnabled || $caPath !== null) {
    $conn->ssl_set(null, null, $caPath, null, null);
    $flags = MYSQLI_CLIENT_SSL;
    if ($caPath !== null && defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
        $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    }
}
if (!$conn->real_connect($host, $user, $pass, $db, $port > 0 ? $port : 3306, null, $flags)) {
    fwrite(STDERR, "ERROR: Connection failed: " . $conn->connect_error . "\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

echo "Connected to {$user}@{$host}:{$port}/{$db} (SSL: " . ($conn->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch_assoc()['Value'] ?? 'NONE') . ")\n";

if ($dropExisting) {
    echo "Dropping existing tables & views in {$db}...\n";
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $viewsRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    while ($v = $viewsRes->fetch_row()) {
        $conn->query("DROP VIEW IF EXISTS `{$v[0]}`");
    }
    $tblsRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($t = $tblsRes->fetch_row()) {
        $conn->query("DROP TABLE IF EXISTS `{$t[0]}`");
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Parse a SQL dump into executable statements honoring DELIMITER directives and quotes.
 *
 * @return array<int, string>
 */
function parse_sql_statements(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $lines = explode("\n", $sql);
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (trim($buffer) === '' && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
            continue;
        }
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', trim($line), $m)) {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
                $buffer = '';
            }
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "\n";
        $delimLen = strlen($delimiter);
        $rtrimBuf = rtrim($buffer);
        if ($delimLen > 0 && strlen($rtrimBuf) >= $delimLen && substr($rtrimBuf, -$delimLen) === $delimiter) {
            $stmt = trim(substr($rtrimBuf, 0, -$delimLen));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

$rawSql = file_get_contents($fullPath);
// Strip any remaining MySQL/MariaDB conditional DEFINER fragments just in case
$rawSql = preg_replace('/\/\*!\d+\s+DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*\*\//i', '', $rawSql);
$rawSql = preg_replace('/\/\*!\d+\s+SQL\s+SECURITY\s+DEFINER\s*\*\//i', '', $rawSql);

$statements = parse_sql_statements($rawSql);
echo "Parsed " . count($statements) . " SQL statements from {$sqlFile}...\n";

$executed = 0;
foreach ($statements as $idx => $stmt) {
    // Skip empty /*!50003 */ wrapper noise if left behind
    $cleanCheck = preg_replace('/\/\*!?\d*\s*\*\/|\s+/', '', $stmt);
    if ($cleanCheck === '') {
        continue;
    }
    // Strip /*!50003 CREATE*/ comment wrappers around triggers so `CREATE TRIGGER ...` executes cleanly
    if (stripos($stmt, 'TRIGGER') !== false) {
        $stmt = preg_replace('/\/\*!50003\s+(CREATE)\s*\*\//i', '$1 ', $stmt);
        $stmt = preg_replace('/\/\*!50003\s+(TRIGGER\b)/i', '$1', $stmt);
        $stmt = preg_replace('/\s+END\s*\*\/$/i', ' END', $stmt);
    }
    try {
        $conn->query($stmt);
        $executed++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED at statement #" . ($idx + 1) . ": " . $e->getMessage() . "\n");
        fwrite(STDERR, substr($stmt, 0, 500) . "\n");
        exit(1);
    }
}

$baseTables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->num_rows;
$views = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->num_rows;
$triggers = $conn->query("SHOW TRIGGERS")->num_rows;

echo "Import complete! Executed {$executed} statements.\n";
echo "Verified {$db}: {$baseTables} base tables, {$views} view(s), {$triggers} trigger(s).\n";
exit(0);
