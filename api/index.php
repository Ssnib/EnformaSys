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

$target = null;

if (in_array($reqPath, $allowedRootRoutes, true)) {
    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($reqPath, '/'));
    if ($candidate !== false && is_file($candidate)) {
        $target = $candidate;
    }
} elseif (preg_match('#^/modules/([a-zA-Z0-9_]+\.php)$#', $reqPath, $m)) {
    $modulesDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'modules');
    $candidate = $modulesDir !== false ? realpath($modulesDir . DIRECTORY_SEPARATOR . $m[1]) : false;
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

chdir(dirname($target));
require $target;
