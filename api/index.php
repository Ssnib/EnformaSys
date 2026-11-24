<?php
/**
 * Vercel PHP Single-Function Front Controller (`vercel-php@0.7.4`)
 *
 * Routes all public PHP pages and modules through one serverless entrypoint
 * while preserving original working directory, `PHP_SELF`, and `SCRIPT_NAME`.
 */

$projectRoot = realpath(dirname(__DIR__));
if ($projectRoot === false) {
    http_response_code(500);
    exit('Application root could not be resolved.');
}

$rawUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$reqPath = (string)(parse_url($rawUri, PHP_URL_PATH) ?? '/');
$reqPath = str_replace('\\', '/', $reqPath);

if ($reqPath === '' || $reqPath === '/') {
    $reqPath = '/index.php';
}

// Reject null bytes and directory traversal attempts
if (strpos($reqPath, "\0") !== false || strpos($reqPath, '..') !== false) {
    http_response_code(400);
    exit('Bad Request.');
}

// Normalize trailing slash on extensionless paths if any
if (substr($reqPath, -1) === '/') {
    $reqPath = rtrim($reqPath, '/');
    if ($reqPath === '') {
        $reqPath = '/index.php';
    }
}

$allowedRootRoutes = [
    '/index.php',
    '/login.php',
    '/logout.php',
    '/forgot_password.php',
    '/apply.php',
    '/migrate.php',
    '/setup.php',
];

$explicitAllowedModuleRoutes = [
    '/modules/ajax.php',
    '/modules/cashier_soa.php',
    '/modules/cashier_payments.php',
    '/modules/cashier_accounts.php',
    '/modules/cashier_receipt.php',
    '/modules/cashier_fees.php',
    '/modules/cashier_reports.php',
    '/modules/cashier_requests.php',
    '/modules/cashier_dashboard.php',
    '/modules/student_soa.php',
    '/modules/student_view.php',
];

$target = null;

if (in_array($reqPath, $allowedRootRoutes, true)) {
    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($reqPath, '/'));
    if ($candidate !== false && is_file($candidate)) {
        $target = $candidate;
    }
} elseif (in_array($reqPath, $explicitAllowedModuleRoutes, true) || preg_match('#^/modules/([a-zA-Z0-9_]+\.php)$#', $reqPath, $m)) {
    $moduleRel = ltrim($reqPath, '/');
    $modulesDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'modules');
    $candidate = $modulesDir !== false ? realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $moduleRel)) : false;
    if (
        $modulesDir !== false
        && $candidate !== false
        && strpos($candidate, $modulesDir . DIRECTORY_SEPARATOR) === 0
        && is_file($candidate)
    ) {
        $target = $candidate;
    }
} elseif (preg_match('#^/(includes|scripts|sql|backups|sessions|scratch|api)/#i', $reqPath)
    || preg_match('#^/(\.env|\.env\.example|\.gitignore|\.vercelignore|ca\.pem|setup\.sql|vercel\.json)$#i', $reqPath)
) {
    http_response_code(403);
    exit('Forbidden.');
}

if ($target === null) {
    http_response_code(404);
    exit('Not Found.');
}

$_SERVER['PHP_SELF'] = $reqPath;
$_SERVER['SCRIPT_NAME'] = $reqPath;
$_SERVER['SCRIPT_FILENAME'] = $target;

$__enformasys_req_start = microtime(true);
$GLOBALS['ENFORMASYS_TIMINGS'] = [
    'session_ms' => 0.0,
    'db_connect_ms' => 0.0,
    'req_start' => $__enformasys_req_start,
];

if (function_exists('header_register_callback')) {
    header_register_callback(static function () use ($__enformasys_req_start): void {
        if (headers_sent()) {
            return;
        }
        $timings = $GLOBALS['ENFORMASYS_TIMINGS'] ?? [];
        $sessionMs = (float)($timings['session_ms'] ?? 0.0);
        $dbMs = (float)($timings['db_connect_ms'] ?? 0.0);
        $totalMs = max(0.0, (microtime(true) - $__enformasys_req_start) * 1000.0);
        $renderMs = max(0.0, $totalMs - $sessionMs - $dbMs);
        header(sprintf(
            'Server-Timing: session_start;dur=%.2f, db_connect;dur=%.2f, page_render;dur=%.2f, total;dur=%.2f',
            $sessionMs,
            $dbMs,
            $renderMs,
            $totalMs
        ));
    });
}

chdir(dirname($target));
require $target;
