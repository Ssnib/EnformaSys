<?php
include 'includes/session_fix.php';
if (!enformasys_is_production()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

/**
 * Ensures the password_reset_otps table exists (idempotent across Aiven & InfinityFree).
 */
function fp_ensure_otp_table(mysqli $conn): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS password_reset_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pro_user_active (user_id, used_at, expires_at),
        KEY idx_pro_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $ok = (bool)@$conn->query($sql);
    if ($ok) {
        $checked = true;
    }
    return $ok;
}

/**
 * Looks up a user by username or linked email address in user_profiles.
 *
 * @return array{id:int,username:string,role:string,display_name:string,linked_email:string}|null
 */
function fp_find_user_by_identifier(mysqli $conn, string $identifier): ?array
{
    $clean = trim($identifier);
    if ($clean === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT u.id, u.username, u.role,
            COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(CONCAT_WS(' ', up.first_name, up.last_name)), ''), u.username) AS display_name,
            COALESCE(NULLIF(TRIM(up.email_address), ''), NULLIF(TRIM(up.email), ''), '') AS linked_email
        FROM users u
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE LOWER(u.username) = LOWER(?)
           OR LOWER(TRIM(up.email_address)) = LOWER(?)
           OR LOWER(TRIM(up.email)) = LOWER(?)
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('sss', $clean, $clean, $clean);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => (string)$row['role'],
        'display_name' => trim((string)($row['display_name'] ?? '')) !== '' ? (string)$row['display_name'] : (string)$row['username'],
        'linked_email' => trim((string)($row['linked_email'] ?? '')),
    ];
}

/**
 * Issues a fresh 6-digit OTP, persists its hash + 10-min expiry, and emails it via Gmail SMTP.
 *
 * @return array{ok:bool,error:string,otp_id:int,masked_email:string,smtp_unconfigured:bool}
 */
function fp_issue_and_send_otp(mysqli $conn, array $user): array
{
    $uid = (int)$user['id'];
    $email = trim((string)$user['linked_email']);
    $displayName = (string)$user['display_name'];
    $masked = enformasys_mask_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'No valid email address is linked to this account.', 'otp_id' => 0, 'masked_email' => '', 'smtp_unconfigured' => false];
    }

    if (!enformasys_smtp_is_configured()) {
        return [
            'ok' => false,
            'error' => 'Automated email verification is not configured on this server yet. Please submit a manual reset request to the Registrar/Administrator below.',
            'otp_id' => 0,
            'masked_email' => $masked,
            'smtp_unconfigured' => true,
        ];
    }

    $otpCode = (string)random_int(100000, 999999);
    $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
    $ip = function_exists('enformasys_client_ip') ? enformasys_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $otpId = 0;
    if (fp_ensure_otp_table($conn)) {
        // Invalidate any previous unused OTPs for this user
        $stInv = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        if ($stInv) {
            $stInv->bind_param('i', $uid);
            $stInv->execute();
            $stInv->close();
        }
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $stIns = $conn->prepare("INSERT INTO password_reset_otps (user_id, email, otp_hash, attempts, expires_at, ip_address) VALUES (?, ?, ?, 0, ?, ?)");
        if ($stIns) {
            $stIns->bind_param('issss', $uid, $email, $otpHash, $expiresAt, $ip);
            $stIns->execute();
            $otpId = (int)$conn->insert_id;
            $stIns->close();
        }
    }

    $htmlBody = enformasys_build_otp_email_html($displayName, $otpCode, 10);
    $textBody = "Hello {$displayName},\n\nYour EnformaSys password reset verification code is: {$otpCode}\n\nThis code expires in 10 minutes. If you did not request a password reset, please ignore this email.\n\n- EnformaSys Seminary Portal";
    $mailRes = enformasys_send_smtp_mail($email, $displayName, 'EnformaSys Password Reset Verification Code', $htmlBody, $textBody);

    if (empty($mailRes['ok'])) {
        if ($otpId > 0) {
            $stFail = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?");
            if ($stFail) {
                $stFail->bind_param('i', $otpId);
                $stFail->execute();
                $stFail->close();
            }
        }
        error_log('EnformaSys OTP SMTP send failed for user #' . $uid . ': ' . ($mailRes['error'] ?? 'Unknown error'));
        return [
            'ok' => false,
            'error' => 'We could not deliver the verification email right now. Please try again shortly or submit a manual reset request to the Registrar/Administrator.',
            'otp_id' => 0,
            'masked_email' => $masked,
            'smtp_unconfigured' => false,
        ];
    }

    $_SESSION['fp_reset'] = [
        'user_id' => $uid,
        'username' => (string)$user['username'],
        'display_name' => $displayName,
        'email' => $email,
        'masked_email' => $masked,
        'otp_id' => $otpId,
        'otp_hash_fallback' => $otpId > 0 ? '' : $otpHash,
        'expires_ts' => time() + 600,
        'sent_at' => time(),
        'verified' => false,
    ];

    if (function_exists('enformasys_audit_log')) {
        enformasys_audit_log($conn, $uid, "[SECURITY] Password reset OTP emailed to {$masked}", 'Auth', $uid, 'password_reset_otp_sent', null, $ip);
    }

    return ['ok' => true, 'error' => '', 'otp_id' => $otpId, 'masked_email' => $masked, 'smtp_unconfigured' => false];
}

// Handle "Start Over" query action cleanly
if (isset($_GET['restart']) && (string)$_GET['restart'] === '1') {
    unset($_SESSION['fp_reset']);
    header('Location: forgot_password.php');
    exit;
}

$error = '';
$success = '';
$infoNotice = '';
$showManualFallback = false;
$manualIdentifier = '';

// Determine current step from session state
$fpState = isset($_SESSION['fp_reset']) && is_array($_SESSION['fp_reset']) ? $_SESSION['fp_reset'] : null;
if ($fpState && time() > (int)($fpState['expires_ts'] ?? 0) + 300) {
    unset($_SESSION['fp_reset']);
    $fpState = null;
}

$step = 1;
if ($fpState) {
    $step = !empty($fpState['verified']) ? 3 : 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('CSRF token validation failed.');
    }

    $action = trim((string)($_POST['fp_action'] ?? ''));

    // Manual Reset Fallback Request (for accounts without linked email or SMTP fallback)
    if ($action === 'manual_reset') {
        $reqId = mb_substr(trim((string)($_POST['manual_identifier'] ?? ($_POST['identifier'] ?? ''))), 0, 120);
        if ($reqId === '') {
            $error = 'Please enter your username so we can notify the Registrar/Administrator.';
        } else {
            $ip = function_exists('enformasys_client_ip') ? enformasys_client_ip() : '';
            if (function_exists('enformasys_audit_log')) {
                enformasys_audit_log($conn, null, "[SECURITY] Manual password reset requested for '{$reqId}' from IP {$ip}", 'Auth', null, 'password_reset_manual_request', null, $ip);
            }
            unset($_SESSION['fp_reset']);
            $fpState = null;
            $step = 1;
            $success = 'Your manual password reset request has been logged for the Registrar/Administrator. Please follow up with the Seminary Office for assistance.';
        }
    }

    // Step 1: Account Lookup & Send 6-Digit OTP
    elseif ($action === 'lookup_account') {
        $identifier = mb_substr(trim((string)($_POST['identifier'] ?? '')), 0, 120);
        $manualIdentifier = $identifier;

        if ($identifier === '') {
            $error = 'Please enter your username or linked Gmail address.';
        } else {
            $user = fp_find_user_by_identifier($conn, $identifier);
            if (!$user) {
                $error = 'We could not find an EnformaSys account matching that username or email address.';
                $showManualFallback = true;
            } elseif ($user['linked_email'] === '' || !filter_var($user['linked_email'], FILTER_VALIDATE_EMAIL)) {
                $infoNotice = 'No Gmail or email address is currently linked to this account. You can submit a manual password reset request to the Registrar/Administrator below.';
                $showManualFallback = true;
                $manualIdentifier = $user['username'];
            } else {
                $res = fp_issue_and_send_otp($conn, $user);
                if ($res['ok']) {
                    $fpState = $_SESSION['fp_reset'];
                    $step = 2;
                    $success = 'A 6-digit verification code has been sent to ' . $res['masked_email'] . '.';
                } else {
                    $error = $res['error'];
                    $showManualFallback = true;
                    $manualIdentifier = $user['username'];
                }
            }
        }
    }

    // Step 2a: Resend 6-Digit OTP (60s Cooldown)
    elseif ($action === 'resend_otp') {
        if (!$fpState || empty($fpState['user_id'])) {
            $step = 1;
            $error = 'Your verification session expired. Please look up your account again.';
        } else {
            $elapsed = time() - (int)($fpState['sent_at'] ?? 0);
            if ($elapsed < 60) {
                $wait = 60 - $elapsed;
                $error = "Please wait {$wait} second" . ($wait === 1 ? '' : 's') . " before requesting a new code.";
                $step = 2;
            } else {
                $user = [
                    'id' => (int)$fpState['user_id'],
                    'username' => (string)$fpState['username'],
                    'display_name' => (string)$fpState['display_name'],
                    'linked_email' => (string)$fpState['email'],
                ];
                $res = fp_issue_and_send_otp($conn, $user);
                if ($res['ok']) {
                    $fpState = $_SESSION['fp_reset'];
                    $step = 2;
                    $success = 'A new 6-digit verification code was sent to ' . $res['masked_email'] . '.';
                } else {
                    $error = $res['error'];
                    $step = 2;
                }
            }
        }
    }

    // Step 2b: Verify 6-Digit OTP
    elseif ($action === 'verify_otp') {
        if (!$fpState || empty($fpState['user_id'])) {
            $step = 1;
            $error = 'Your verification session expired. Please start again.';
        } else {
            $otpInput = preg_replace('/\D/', '', (string)($_POST['otp_code'] ?? ''));
            if (strlen($otpInput) !== 6) {
                $error = 'Please enter the complete 6-digit verification code.';
                $step = 2;
            } else {
                $verified = false;
                $otpId = (int)($fpState['otp_id'] ?? 0);
                if ($otpId > 0 && fp_ensure_otp_table($conn)) {
                    $stOtp = $conn->prepare("SELECT id, otp_hash, attempts, expires_at, used_at FROM password_reset_otps WHERE id = ? AND user_id = ? LIMIT 1");
                    $uid = (int)$fpState['user_id'];
                    $stOtp->bind_param('ii', $otpId, $uid);
                    $stOtp->execute();
                    $otpRow = $stOtp->get_result()->fetch_assoc();
                    $stOtp->close();

                    if (!$otpRow || $otpRow['used_at'] !== null) {
                        $error = 'This verification code is no longer valid. Please request a new code.';
                    } elseif (strtotime((string)$otpRow['expires_at']) < time()) {
                        $error = 'This verification code has expired. Please click "Resend Code" to get a new one.';
                    } elseif ((int)$otpRow['attempts'] >= 5) {
                        $error = 'Too many incorrect attempts. Please click "Resend Code" for a fresh verification code.';
                    } elseif (password_verify($otpInput, (string)$otpRow['otp_hash'])) {
                        $verified = true;
                    } else {
                        $stInc = $conn->prepare("UPDATE password_reset_otps SET attempts = attempts + 1 WHERE id = ?");
                        if ($stInc) {
                            $stInc->bind_param('i', $otpId);
                            $stInc->execute();
                            $stInc->close();
                        }
                        $remaining = max(0, 4 - (int)$otpRow['attempts']);
                        $error = $remaining > 0
                            ? "Incorrect verification code. You have {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining."
                            : 'Too many incorrect attempts. Please request a new verification code.';
                    }
                } elseif (!empty($fpState['otp_hash_fallback'])) {
                    if (time() > (int)($fpState['expires_ts'] ?? 0)) {
                        $error = 'This verification code has expired. Please request a new one.';
                    } elseif (password_verify($otpInput, (string)$fpState['otp_hash_fallback'])) {
                        $verified = true;
                    } else {
                        $error = 'Incorrect verification code. Please check your email and try again.';
                    }
                }

                if ($verified) {
                    $_SESSION['fp_reset']['verified'] = true;
                    $fpState = $_SESSION['fp_reset'];
                    $step = 3;
                    $success = 'Email verified! You may now set your new password.';
                } else {
                    $step = 2;
                }
            }
        }
    }

    // Step 3: Set New Password
    elseif ($action === 'set_new_password') {
        if (!$fpState || empty($fpState['user_id']) || empty($fpState['verified'])) {
            $step = 1;
            $error = 'Please verify your email code before setting a new password.';
        } else {
            $newPass = (string)($_POST['new_password'] ?? '');
            $confirmPass = (string)($_POST['confirm_password'] ?? '');

            if (strlen($newPass) < 8) {
                $error = 'Your new password must be at least 8 characters long.';
                $step = 3;
            } elseif ($newPass !== $confirmPass) {
                $error = 'Password confirmation does not match. Please re-enter both fields.';
                $step = 3;
            } else {
                $uid = (int)$fpState['user_id'];
                $otpId = (int)($fpState['otp_id'] ?? 0);
                $stillValid = true;

                if ($otpId > 0 && fp_ensure_otp_table($conn)) {
                    $stChk = $conn->prepare("SELECT id, expires_at, used_at FROM password_reset_otps WHERE id = ? AND user_id = ? LIMIT 1");
                    $stChk->bind_param('ii', $otpId, $uid);
                    $stChk->execute();
                    $chkRow = $stChk->get_result()->fetch_assoc();
                    $stChk->close();
                    if (!$chkRow || $chkRow['used_at'] !== null || strtotime((string)$chkRow['expires_at']) < time()) {
                        $stillValid = false;
                    }
                }

                if (!$stillValid) {
                    unset($_SESSION['fp_reset']);
                    $fpState = null;
                    $step = 1;
                    $error = 'Your reset session expired. Please request a new verification code.';
                } else {
                    $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                    $stUpd = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                    if (!$stUpd) {
                        $error = 'Unable to update password right now. Please try again.';
                        $step = 3;
                    } else {
                        $stUpd->bind_param('si', $hashed, $uid);
                        $stUpd->execute();
                        $stUpd->close();

                        // Invalidate OTP immediately
                        if ($otpId > 0 && fp_ensure_otp_table($conn)) {
                            $stUse = $conn->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                            if ($stUse) {
                                $stUse->bind_param('i', $uid);
                                $stUse->execute();
                                $stUse->close();
                            }
                        }

                        $ip = function_exists('enformasys_client_ip') ? enformasys_client_ip() : '';
                        if (function_exists('enformasys_clear_login_attempts')) {
                            enformasys_clear_login_attempts($conn, (string)$fpState['username'], $ip);
                        }
                        if (function_exists('enformasys_audit_log')) {
                            enformasys_audit_log($conn, $uid, '[SECURITY] Password reset completed via Email OTP', 'Auth', $uid, 'password_reset_completed', null, $ip);
                        }

                        unset($_SESSION['fp_reset']);
                        $_SESSION['flash_success'] = 'Password updated successfully! Sign in with your new password.';
                        header('Location: login.php');
                        exit;
                    }
                }
            }
        }
    }
}

$resendCooldown = 0;
if ($step === 2 && $fpState) {
    $elapsed = time() - (int)($fpState['sent_at'] ?? 0);
    $resendCooldown = max(0, 60 - $elapsed);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <script>
    (function(){try{if(localStorage.getItem('enformasys_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');var m=document.querySelector('meta[name="color-scheme"]');if(m)m.setAttribute('content','dark');}}catch(e){}})();
    </script>
    <title>Reset Password - EnformaSys | Littleway College Seminary</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/style.css'); ?>">
    <link rel="stylesheet" href="css/motion.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/motion.css'); ?>">
    <link rel="stylesheet" href="css/floating_theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/floating_theme.css'); ?>">
    <link id="enformasys-live-css-link" rel="stylesheet" href="css/custom_live.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/custom_live.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .font-serif-seminary { font-family: 'Lora', Georgia, Cambria, serif; }
        .font-ui { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .font-display { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; }
        .fp-card {
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.72),
                0 24px 48px -12px rgba(74, 55, 49, 0.12),
                0 10px 24px -6px rgba(74, 55, 49, 0.06);
        }
        html[data-theme="dark"] .fp-card {
            background-color: #1b1817 !important;
            border-color: rgba(245, 240, 236, 0.11) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 28px 56px -12px rgba(0, 0, 0, 0.65) !important;
        }
        .otp-digit-input {
            width: 2.85rem;
            height: 3.35rem;
            text-align: center;
            font-family: 'Plus Jakarta Sans', ui-monospace, monospace;
            font-size: 1.35rem;
            font-weight: 800;
            border-radius: 12px;
            border: 1.5px solid #E2C9C8;
            background: #FCF9F7;
            color: #4A3731;
            transition: border-color 160ms ease, box-shadow 160ms ease, transform 140ms ease, background-color 160ms ease;
        }
        .otp-digit-input:focus {
            outline: none;
            border-color: #5c3a31;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(92, 58, 49, 0.15);
            transform: translateY(-1px);
        }
        html[data-theme="dark"] .otp-digit-input {
            background: #231f1d !important;
            border-color: rgba(245, 240, 236, 0.14) !important;
            color: #f5f0ec !important;
        }
        html[data-theme="dark"] .otp-digit-input:focus {
            background: #2a2422 !important;
            border-color: #c98a6b !important;
            box-shadow: 0 0 0 3px rgba(201, 138, 107, 0.26) !important;
        }
        @media (max-width: 420px) {
            .otp-digit-input {
                width: 2.35rem;
                height: 2.95rem;
                font-size: 1.15rem;
            }
        }
    </style>
</head>
<body class="login-page font-ui bg-[#FAF6F4] min-h-screen text-[#4A3731] antialiased flex items-center justify-center p-4 sm:p-8 relative overflow-x-hidden">
    <!-- Global Non-Blocking Progress Bar & Branded Preloader -->
    <div id="enformasys-top-progress" aria-hidden="true"></div>
    <div id="enformasys-preloader" role="status" aria-live="polite" aria-hidden="true">
        <div class="enformasys-preloader-ring">
            <div class="enformasys-preloader-spinner" aria-hidden="true"></div>
            <img src="assets/LOGO%20(2).jpg" alt="EnformaSys Crest" class="enformasys-preloader-crest" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
            <span class="enformasys-preloader-fallback" style="display:none;" aria-hidden="true"><i class="fa-solid fa-church"></i></span>
        </div>
        <p id="enformasysPreloaderText" class="enformasys-preloader-text">Processing...</p>
    </div>

    <!-- Subtle Warm Atmospheric Backdrop -->
    <div class="login-backdrop fixed inset-0 bg-gradient-to-br from-[#FCF9F7] via-[#F7EFEA] to-[#EFE0D9]/70 pointer-events-none z-0" aria-hidden="true"></div>

    <!-- Floating Animated Theme Toggle -->
    <button id="enformasysThemeToggle"
            type="button"
            data-theme-toggle="1"
            class="enformasys-theme-toggle fixed top-4 right-4 sm:top-6 sm:right-6 z-30 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/85 backdrop-blur-md hover:bg-white text-[#59443F] border border-[#EBD3CC] shadow-sm hover:shadow transition cursor-pointer"
            aria-label="Toggle dark mode"
            aria-pressed="false"
            title="Switch to dark mode">
        <i class="fa-regular fa-moon text-sm theme-icon-moon" aria-hidden="true"></i>
        <i class="fa-regular fa-sun text-sm theme-icon-sun" aria-hidden="true"></i>
        <span class="sr-only">Toggle color theme</span>
    </button>

    <main class="w-full max-w-lg mx-auto relative z-10">
        <!-- Soft Glow Orbs -->
        <div class="absolute -top-12 -right-10 w-64 h-64 rounded-full bg-gradient-to-br from-[#EBD3CC]/70 to-[#D6A6A6]/35 blur-3xl pointer-events-none z-0" aria-hidden="true"></div>
        <div class="absolute -bottom-10 -left-10 w-60 h-60 rounded-full bg-gradient-to-tr from-[#D6A6A6]/40 to-[#EBD3CC]/40 blur-3xl pointer-events-none z-0" aria-hidden="true"></div>

        <div class="fp-card login-card bg-white rounded-3xl border border-[#EBD3CC] p-7 sm:p-9 relative z-10">
            <!-- Top Row: Back to Login -->
            <div class="flex items-center justify-between gap-2 mb-6">
                <a href="login.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-[#785E56] hover:text-[#4A3731] transition no-underline">
                    <i class="fa-solid fa-arrow-left text-[11px]" aria-hidden="true"></i>
                    <span>Back to Login</span>
                </a>
                <?php if ($step > 1): ?>
                    <a href="forgot_password.php?restart=1" class="text-[11px] font-semibold text-[#8B7168] hover:text-[#4A3731] transition no-underline">
                        Start Over
                    </a>
                <?php endif; ?>
            </div>

            <!-- Institutional Crest & Title -->
            <div class="flex flex-col items-center text-center mb-6">
                <div class="w-16 h-16 rounded-full bg-white p-0.5 border border-[#D6A6A6]/70 ring-4 ring-[#EBD3CC]/60 shadow-sm overflow-hidden mb-3 flex items-center justify-center">
                    <img src="assets/LOGO%20(2).jpg"
                         alt="Littleway College Seminary Crest"
                         class="w-full h-full rounded-full object-cover scale-[1.32]"
                         onerror="this.onerror=null;this.src='assets/logo.jpg';">
                </div>
                <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#8B7168]">EnformaSys &bull; Account Recovery</span>
                <h1 class="form-title font-display font-extrabold text-2xl sm:text-[26px] text-[#4A3731] tracking-tight mt-1 mb-1">
                    <?php
                    if ($step === 2) echo 'Verify Your Email';
                    elseif ($step === 3) echo 'Set New Password';
                    else echo 'Forgot Password';
                    ?>
                </h1>
                <p class="form-subtitle text-xs sm:text-sm text-[#8B7168] font-medium m-0 max-w-sm">
                    <?php if ($step === 2): ?>
                        Enter the 6-digit verification code sent to <strong class="text-[#4A3731]"><?php echo htmlspecialchars((string)($fpState['masked_email'] ?? '***'), ENT_QUOTES, 'UTF-8'); ?></strong>.
                    <?php elseif ($step === 3): ?>
                        Choose a strong new password for <strong class="text-[#4A3731]"><?php echo htmlspecialchars((string)($fpState['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>.
                    <?php else: ?>
                        Enter your username or linked Gmail address to receive a 6-digit verification code.
                    <?php endif; ?>
                </p>
            </div>

            <!-- 3-Step Progress Indicator -->
            <ol class="grid grid-cols-3 gap-2 mb-6" aria-label="Password reset steps">
                <?php
                $stepsMeta = [
                    1 => ['label' => 'Identify', 'icon' => 'fa-user-check'],
                    2 => ['label' => 'Verify Email', 'icon' => 'fa-envelope-open-text'],
                    3 => ['label' => 'New Password', 'icon' => 'fa-lock'],
                ];
                foreach ($stepsMeta as $num => $meta):
                    $isDone = $step > $num;
                    $isCurrent = $step === $num;
                    $pillClass = $isDone
                        ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                        : ($isCurrent
                            ? 'bg-[#5c3a31] border-[#5c3a31] text-white shadow-xs'
                            : 'bg-[#FCF9F7] border-[#EBD3CC]/80 text-[#8B7168]');
                ?>
                <li class="flex items-center justify-center gap-1.5 px-2 py-2 rounded-xl border text-[11px] font-bold transition <?php echo $pillClass; ?>"
                    <?php echo $isCurrent ? 'aria-current="step"' : ''; ?>>
                    <?php if ($isDone): ?>
                        <i class="fa-solid fa-check text-[10px]" aria-hidden="true"></i>
                    <?php else: ?>
                        <span><?php echo $num; ?>.</span>
                    <?php endif; ?>
                    <span class="truncate"><?php echo htmlspecialchars($meta['label']); ?></span>
                </li>
                <?php endforeach; ?>
            </ol>

            <!-- Feedback Banners -->
            <?php if ($error !== ''): ?>
                <div class="error-box flex items-start gap-2.5 p-3.5 mb-5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold" role="alert" aria-live="polite">
                    <i class="fa-solid fa-circle-exclamation text-rose-600 shrink-0 mt-0.5" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="success-box flex items-start gap-2.5 p-3.5 mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-semibold" role="status" aria-live="polite">
                    <i class="fa-solid fa-circle-check text-emerald-600 shrink-0 mt-0.5" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($infoNotice !== ''): ?>
                <div class="flex items-start gap-2.5 p-3.5 mb-5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs sm:text-sm font-semibold" role="status" aria-live="polite">
                    <i class="fa-solid fa-envelope-circle-check text-amber-600 shrink-0 mt-0.5" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($infoNotice, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- STEP 1: IDENTIFY ACCOUNT -->
            <?php if ($step === 1): ?>
                <form method="post" action="forgot_password.php" class="flex flex-col gap-4 fp-submit-form" id="fpStep1Form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="fp_action" value="lookup_account">

                    <div class="flex flex-col gap-1.5">
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block" for="fpIdentifier">
                            Username or Linked Gmail Address
                        </label>
                        <div class="relative flex items-center w-full">
                            <input id="fpIdentifier"
                                   type="text"
                                   name="identifier"
                                   value="<?php echo htmlspecialchars($manualIdentifier !== '' ? $manualIdentifier : (string)($_POST['identifier'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Enter your username or email@gmail.com"
                                   maxlength="120"
                                   required
                                   autofocus
                                   autocomplete="username"
                                   class="form-input w-full pl-4 pr-11 py-3 rounded-xl bg-[#FCF9F7]/70 focus:bg-white border border-[#EBD3CC] text-[#4A3731] font-medium text-sm placeholder-[#8B7168]/55 focus:outline-none focus:border-[#5c3a31] focus:ring-2 focus:ring-[#5c3a31]/15 transition-all">
                            <span class="input-trailing-icon absolute right-3.5 text-[#8B7168] pointer-events-none" aria-hidden="true">
                                <i class="fa-regular fa-envelope text-sm"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" data-loading-text="Sending Code..." class="btn-seminary-primary w-full py-3.5 px-6 rounded-xl text-white font-semibold text-sm tracking-wide shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>Send Verification Code</span>
                        <i class="fa-solid fa-paper-plane text-xs" aria-hidden="true"></i>
                    </button>
                </form>

                <!-- Fallback Manual Reset Notice / Action -->
                <div class="mt-5 pt-5 border-t border-[#EBD3CC]/70">
                    <div class="p-3.5 rounded-2xl bg-[#FCF9F7] border border-[#EBD3CC]/80 text-center">
                        <p class="text-xs text-[#785E56] font-medium m-0 mb-2.5">
                            No email linked to your account yet?
                        </p>
                        <form method="post" action="forgot_password.php" class="fp-submit-form" id="fpManualForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="fp_action" value="manual_reset">
                            <input type="hidden" name="manual_identifier" id="fpManualIdentifier" value="<?php echo htmlspecialchars($manualIdentifier, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit"
                                    data-loading-text="Submitting Request..."
                                    onclick="var src=document.getElementById('fpIdentifier');var dst=document.getElementById('fpManualIdentifier');if(src&&dst&&src.value.trim()!==''){dst.value=src.value.trim();}"
                                    class="action-btn-secondary w-full py-2.5 px-4 rounded-xl text-xs font-bold inline-flex items-center justify-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-user-shield text-xs" aria-hidden="true"></i>
                                <span>Submit Manual Reset Request to Registrar/Admin</span>
                            </button>
                        </form>
                    </div>
                </div>

            <!-- STEP 2: ENTER 6-DIGIT VERIFICATION CODE -->
            <?php elseif ($step === 2): ?>
                <form method="post" action="forgot_password.php" class="flex flex-col gap-5 fp-submit-form" id="fpStep2Form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="fp_action" value="verify_otp">
                    <input type="hidden" name="otp_code" id="fpOtpHidden" value="">

                    <div>
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block text-center mb-3">
                            6-Digit Verification Code
                        </label>
                        <div class="flex items-center justify-center gap-2 sm:gap-2.5" id="otpDigitGroup">
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <input type="text"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       maxlength="1"
                                       autocomplete="<?php echo $i === 0 ? 'one-time-code' : 'off'; ?>"
                                       aria-label="Digit <?php echo $i + 1; ?> of 6"
                                       class="otp-digit-input"
                                       <?php echo $i === 0 ? 'autofocus' : ''; ?>>
                            <?php endfor; ?>
                        </div>
                        <p class="text-[11px] text-[#8B7168] text-center mt-2.5 mb-0 font-medium">
                            Code expires in 10 minutes. You can also paste all 6 digits at once.
                        </p>
                    </div>

                    <button type="submit" id="fpVerifyBtn" data-loading-text="Verifying Code..." class="btn-seminary-primary w-full py-3.5 px-6 rounded-xl text-white font-semibold text-sm tracking-wide shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>Verify Code</span>
                        <i class="fa-solid fa-shield-check text-xs" aria-hidden="true"></i>
                    </button>
                </form>

                <!-- Resend Code Cooldown Row -->
                <div class="mt-5 pt-4 border-t border-[#EBD3CC]/70 flex items-center justify-between gap-2 flex-wrap">
                    <span class="text-xs text-[#8B7168] font-medium">Didn&rsquo;t receive the email?</span>
                    <form method="post" action="forgot_password.php" class="m-0 p-0 fp-submit-form" id="fpResendForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="fp_action" value="resend_otp">
                        <button type="submit"
                                id="fpResendBtn"
                                data-cooldown="<?php echo (int)$resendCooldown; ?>"
                                data-loading-text="Resending..."
                                <?php echo $resendCooldown > 0 ? 'disabled' : ''; ?>
                                class="text-xs font-bold text-[#5c3a31] hover:underline bg-transparent border-0 p-0 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <?php echo $resendCooldown > 0 ? "Resend code in {$resendCooldown}s" : 'Resend Code'; ?>
                        </button>
                    </form>
                </div>

            <!-- STEP 3: SET NEW PASSWORD -->
            <?php elseif ($step === 3): ?>
                <form method="post" action="forgot_password.php" class="flex flex-col gap-4 fp-submit-form" id="fpStep3Form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="fp_action" value="set_new_password">

                    <div class="flex flex-col gap-1.5">
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block" for="fpNewPassword">
                            New Password
                        </label>
                        <div class="relative flex items-center w-full">
                            <input id="fpNewPassword"
                                   type="password"
                                   name="new_password"
                                   minlength="8"
                                   required
                                   autofocus
                                   autocomplete="new-password"
                                   placeholder="At least 8 characters"
                                   class="form-input w-full pl-4 pr-11 py-3 rounded-xl bg-[#FCF9F7]/70 focus:bg-white border border-[#EBD3CC] text-[#4A3731] font-medium text-sm focus:outline-none focus:border-[#5c3a31] focus:ring-2 focus:ring-[#5c3a31]/15 transition-all">
                            <button type="button" data-pw-toggle="fpNewPassword" class="toggle-password absolute right-2.5 w-8 h-8 rounded-lg text-[#8B7168] hover:text-[#4A3731] flex items-center justify-center border-0 bg-transparent cursor-pointer" aria-label="Show password">
                                <i class="fa-regular fa-eye text-sm" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block" for="fpConfirmPassword">
                            Confirm New Password
                        </label>
                        <div class="relative flex items-center w-full">
                            <input id="fpConfirmPassword"
                                   type="password"
                                   name="confirm_password"
                                   minlength="8"
                                   required
                                   autocomplete="new-password"
                                   placeholder="Re-enter your new password"
                                   class="form-input w-full pl-4 pr-11 py-3 rounded-xl bg-[#FCF9F7]/70 focus:bg-white border border-[#EBD3CC] text-[#4A3731] font-medium text-sm focus:outline-none focus:border-[#5c3a31] focus:ring-2 focus:ring-[#5c3a31]/15 transition-all">
                            <button type="button" data-pw-toggle="fpConfirmPassword" class="toggle-password absolute right-2.5 w-8 h-8 rounded-lg text-[#8B7168] hover:text-[#4A3731] flex items-center justify-center border-0 bg-transparent cursor-pointer" aria-label="Show confirm password">
                                <i class="fa-regular fa-eye text-sm" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p id="fpMatchHint" class="text-[11px] font-semibold m-0 hidden"></p>
                    </div>

                    <button type="submit" data-loading-text="Updating Password..." class="btn-seminary-primary w-full py-3.5 px-6 mt-1 rounded-xl text-white font-semibold text-sm tracking-wide shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>Update Password &amp; Sign In</span>
                        <i class="fa-solid fa-check text-xs" aria-hidden="true"></i>
                    </button>
                </form>
            <?php endif; ?>

            <div class="form-footer mt-6 pt-4 border-t border-[#EBD3CC]/70 text-center">
                <p class="help-subtitle text-xs text-[#8B7168] font-medium m-0">
                    Littleway College Seminary &bull; Secure Account Recovery
                </p>
            </div>
        </div>
    </main>

    <script>
    (function () {
        // 1. Theme Switcher with Circular View Transition + Smooth Fallback
        var THEME_KEY = 'enformasys_theme';
        var fbTimer = null;
        function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
        function syncBtns(d) {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.setAttribute('aria-pressed', d ? 'true' : 'false');
                var l = d ? 'Switch to light mode' : 'Switch to dark mode';
                b.setAttribute('aria-label', l);
                b.setAttribute('title', l);
            });
        }
        function applyTheme(t, persist) {
            var d = t === 'dark';
            if (d) document.documentElement.setAttribute('data-theme', 'dark');
            else document.documentElement.removeAttribute('data-theme');
            var m = document.querySelector('meta[name="color-scheme"]');
            if (m) m.setAttribute('content', d ? 'dark' : 'light');
            if (persist) { try { localStorage.setItem(THEME_KEY, d ? 'dark' : 'light'); } catch (_) {} }
            syncBtns(d);
        }
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
            if (!btn) return;
            e.preventDefault();
            var next = isDark() ? 'light' : 'dark';
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                applyTheme(next, true);
                return;
            }
            var x = window.innerWidth / 2, y = window.innerHeight / 2;
            if (e && typeof e.clientX === 'number' && (e.clientX !== 0 || e.clientY !== 0)) {
                x = e.clientX; y = e.clientY;
            } else if (btn.getBoundingClientRect) {
                var r = btn.getBoundingClientRect();
                x = r.left + r.width / 2; y = r.top + r.height / 2;
            }
            if (typeof document.startViewTransition === 'function') {
                var endR = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
                try {
                    var tr = document.startViewTransition(function () { applyTheme(next, true); });
                    tr.ready.then(function () {
                        document.documentElement.animate(
                            { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + endR + 'px at ' + x + 'px ' + y + 'px)'] },
                            { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
                        );
                    }).catch(function () {});
                    return;
                } catch (_) {}
            }
            if (fbTimer) clearTimeout(fbTimer);
            document.documentElement.classList.add('theme-animating');
            applyTheme(next, true);
            fbTimer = setTimeout(function () { document.documentElement.classList.remove('theme-animating'); fbTimer = null; }, 340);
        });
        window.addEventListener('storage', function (e) {
            if (e.key === THEME_KEY) applyTheme(e.newValue === 'dark' ? 'dark' : 'light', false);
        });
        syncBtns(isDark());

        // 2. 6-Digit OTP Box Ergonomics (auto-advance, backspace, paste)
        var otpBoxes = Array.from(document.querySelectorAll('#otpDigitGroup .otp-digit-input'));
        var otpHidden = document.getElementById('fpOtpHidden');
        function syncOtpHidden() {
            if (!otpHidden) return;
            otpHidden.value = otpBoxes.map(function (b) { return b.value.replace(/\D/g, '').slice(0, 1); }).join('');
        }
        otpBoxes.forEach(function (box, idx) {
            box.addEventListener('input', function () {
                var digits = box.value.replace(/\D/g, '');
                if (digits.length > 1) {
                    for (var j = 0; j < digits.length && (idx + j) < otpBoxes.length; j++) {
                        otpBoxes[idx + j].value = digits[j];
                    }
                    var nextIdx = Math.min(otpBoxes.length - 1, idx + digits.length);
                    otpBoxes[nextIdx].focus();
                } else {
                    box.value = digits;
                    if (digits && idx < otpBoxes.length - 1) {
                        otpBoxes[idx + 1].focus();
                    }
                }
                syncOtpHidden();
            });
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    otpBoxes[idx - 1].value = '';
                    otpBoxes[idx - 1].focus();
                    syncOtpHidden();
                } else if (e.key === 'ArrowLeft' && idx > 0) {
                    otpBoxes[idx - 1].focus();
                } else if (e.key === 'ArrowRight' && idx < otpBoxes.length - 1) {
                    otpBoxes[idx + 1].focus();
                }
            });
            box.addEventListener('paste', function (e) {
                var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
                var clean = pasted.replace(/\D/g, '').slice(0, 6);
                if (clean.length > 0) {
                    e.preventDefault();
                    for (var k = 0; k < otpBoxes.length; k++) {
                        otpBoxes[k].value = clean[k] || '';
                    }
                    syncOtpHidden();
                    otpBoxes[Math.min(5, clean.length)].focus();
                }
            });
        });

        // 3. Resend Cooldown Countdown (60s)
        var resendBtn = document.getElementById('fpResendBtn');
        if (resendBtn) {
            var secs = parseInt(resendBtn.getAttribute('data-cooldown') || '0', 10) || 0;
            if (secs > 0) {
                var cdTimer = setInterval(function () {
                    secs--;
                    if (secs <= 0) {
                        clearInterval(cdTimer);
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'Resend Code';
                    } else {
                        resendBtn.textContent = 'Resend code in ' + secs + 's';
                    }
                }, 1000);
            }
        }

        // 4. Password Visibility & Live Match Check (Step 3)
        document.querySelectorAll('[data-pw-toggle]').forEach(function (tbtn) {
            tbtn.addEventListener('click', function () {
                var target = document.getElementById(tbtn.getAttribute('data-pw-toggle'));
                if (!target) return;
                var show = target.type === 'password';
                target.type = show ? 'text' : 'password';
                var ic = tbtn.querySelector('i');
                if (ic) ic.className = show ? 'fa-regular fa-eye-slash text-sm' : 'fa-regular fa-eye text-sm';
            });
        });
        var newPw = document.getElementById('fpNewPassword');
        var confPw = document.getElementById('fpConfirmPassword');
        var matchHint = document.getElementById('fpMatchHint');
        function checkMatch() {
            if (!newPw || !confPw || !matchHint) return;
            if (!confPw.value) { matchHint.classList.add('hidden'); return; }
            matchHint.classList.remove('hidden');
            if (newPw.value === confPw.value) {
                matchHint.textContent = 'Passwords match';
                matchHint.className = 'text-[11px] font-semibold m-0 text-emerald-700';
            } else {
                matchHint.textContent = 'Passwords do not match yet';
                matchHint.className = 'text-[11px] font-semibold m-0 text-rose-700';
            }
        }
        if (newPw && confPw) {
            newPw.addEventListener('input', checkMatch);
            confPw.addEventListener('input', checkMatch);
        }

        // 5. Submit Loading Bar, Branded Preloader & Inline Button Spinner
        var bar = document.getElementById('enformasys-top-progress');
        var overlay = document.getElementById('enformasys-preloader');
        var preText = document.getElementById('enformasysPreloaderText');
        document.querySelectorAll('form.fp-submit-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                syncOtpHidden();
                var btn = form.querySelector('button[type="submit"]');
                var lbl = (btn && btn.getAttribute('data-loading-text')) ? btn.getAttribute('data-loading-text') : 'Processing...';
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('opacity-80', 'cursor-wait');
                    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-xs" aria-hidden="true"></i><span>' + lbl + '</span>';
                }
                if (bar) { bar.style.width = '65%'; bar.classList.add('is-active'); }
                if (preText) preText.textContent = lbl;
                if (overlay) { overlay.classList.add('is-active'); overlay.setAttribute('aria-hidden', 'false'); }
            });
        });
    })();
    </script>
</body>
</html>
