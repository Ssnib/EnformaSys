<?php
require_once __DIR__ . '/includes/session_fix.php';
require_once __DIR__ . '/includes/env.php';

if (enformasys_is_production()) {
    http_response_code(403);
    exit('Forbidden: setup.php is disabled in production.');
}

require_roles(['Dean', 'Rector', 'Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed: Execute database setup via POST with a valid CSRF token.');
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('CSRF token validation failed.');
}

$servername = (string)enformasys_env('DB_HOST', '127.0.0.1');
$port = (int)enformasys_env('DB_PORT', '3306');
$username = (string)enformasys_env('DB_USER', 'root');
$password = (string)enformasys_env('DB_PASS', enformasys_env('DB_PASSWORD', ''));

$conn = new mysqli($servername, $username, $password, '', $port > 0 ? $port : 3306);
if ($conn->connect_error) {
    http_response_code(500);
    die('Connection failed.');
}

$sqlPath = __DIR__ . '/setup.sql';
$sql = is_file($sqlPath) ? file_get_contents($sqlPath) : '';
if ($sql === false || trim($sql) === '') {
    http_response_code(500);
    exit('setup.sql not found.');
}

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "Database setup complete.";
} else {
    http_response_code(500);
    echo "Database setup failed.";
}

$conn->close();
