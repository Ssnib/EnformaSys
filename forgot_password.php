<?php
include 'includes/session_fix.php';

$success = '';

if (isset($_POST['request_reset'])) {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die("CSRF token validation failed.");
    }
    $reqUser = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 120);
    if ($reqUser !== '') {
        @include_once __DIR__ . '/includes/db.php';
        if (isset($conn) && $conn instanceof mysqli && function_exists('enformasys_audit_log')) {
            enformasys_audit_log($conn, null, "[SECURITY] Password reset requested for '{$reqUser}' from IP " . enformasys_client_ip(), 'Auth', null, 'password_reset_request', null, enformasys_client_ip());
        }
    }
    $success = 'If your account exists, please contact the registrar/administrator to reset your password.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - EnformaSys</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/motion.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="card" style="max-width: 520px;">
            <a href="login.php" class="back-link">Back to Login</a>
            <div class="login-brand">
                <i class="fas fa-church"></i>
                <h1>EnformaSys</h1>
            </div>
            <h2>Forgot Password</h2>
            <p class="subtitle">Enter your username. A password reset will be handled by the registrar/administrator.</p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="field">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" name="username" placeholder="Enter your username" maxlength="120" required autofocus autocomplete="username">
                </div>
                <button type="submit" name="request_reset" style="margin-top: 1rem;">
                    Submit <i class="fas fa-arrow-right" style="margin-left: 0.5rem;"></i>
                </button>
            </form>

            <?php if ($success) echo "<p class='success' style='margin-top: 1.5rem;'>" . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
        </div>
    </div>
</body>
</html>
