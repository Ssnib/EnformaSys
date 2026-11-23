<?php
include 'includes/session_fix.php';

if (isset($_SESSION['user'])) {
    $uid = (int)$_SESSION['user'];
    @include_once 'includes/db.php';
    if (isset($conn) && $conn instanceof mysqli && function_exists('enformasys_audit_log')) {
        enformasys_audit_log($conn, $uid, 'User signed out', 'Auth', $uid, 'logout', null, enformasys_client_ip());
    }
}

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
header('Location: login.php');
exit;
