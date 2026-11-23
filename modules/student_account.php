<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_fix.php';
require_login();
if (($_SESSION['role'] ?? '') !== 'Student') {
    capstone_redirect('index.php');
}

require_once __DIR__ . '/../includes/db.php';

$user_id = (int)$_SESSION['user'];

$stmt_user = $conn->prepare('SELECT id, username, password FROM users WHERE id = ? LIMIT 1');
$stmt_user->bind_param('i', $user_id);
$stmt_user->execute();
$user_row = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

if (!$user_row) {
    capstone_redirect('logout.php');
}

$current_username = (string)($user_row['username'] ?? '');
$_SESSION['username'] = $current_username;

/**
 * Helper to respond cleanly to AJAX/API requests with proper HTTP status codes.
 */
function student_account_json_response(int $status_code, array $payload): void
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$is_ajax_request = (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// Consolidate user settings into Profile (modules/student_edit_profile.php)
if ($method === 'GET' && !$is_ajax_request) {
    capstone_redirect('modules/student_edit_profile.php?tab=account');
}

$flash_success = null;
$flash_error = null;

if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw_body = file_get_contents('php://input');
    $json_body = is_string($raw_body) && trim($raw_body) !== '' ? json_decode($raw_body, true) : null;
    $input = is_array($json_body) ? $json_body : $_POST;

    $csrf_token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verify_csrf_token($csrf_token)) {
        if ($is_ajax_request || in_array($method, ['PUT', 'PATCH'], true)) {
            student_account_json_response(403, ['ok' => false, 'error' => 'Security validation failed (invalid CSRF token). Please refresh and try again.']);
        }
        $flash_error = 'Security validation failed (invalid CSRF token). Please refresh and try again.';
    } else {
        $new_username = trim((string)($input['new_username'] ?? $current_username));
        $current_password = (string)($input['current_password'] ?? '');
        $new_password = (string)($input['new_password'] ?? '');
        $confirm_password = (string)($input['confirm_password'] ?? '');

        $username_changed = ($new_username !== $current_username);
        $password_changed = ($new_password !== '' || $confirm_password !== '');

        $err_status = 400;
        $err_msg = null;

        if ($current_password === '') {
            $err_msg = 'Current password is required to verify your identity.';
        } elseif (!$username_changed && !$password_changed) {
            $err_msg = 'No changes detected. Update your username or enter a new password.';
        } elseif ($username_changed && (strlen($new_username) < 3 || strlen($new_username) > 50 || !preg_match('/^[A-Za-z0-9_.-]+$/', $new_username))) {
            $err_msg = 'New username must be 3 to 50 characters using letters, numbers, underscores, hyphens, or dots.';
        } elseif ($password_changed && strlen($new_password) < 8) {
            $err_msg = 'New password must be at least 8 characters long.';
        } elseif ($password_changed && $new_password !== $confirm_password) {
            $err_msg = 'New password and confirmation password do not match.';
        } else {
            // a & b. Verify current password against stored hash
            $stored_hash = (string)($user_row['password'] ?? '');
            if ($stored_hash === '' || !password_verify($current_password, $stored_hash)) {
                $err_status = 401;
                $err_msg = 'Current password is incorrect.';
            } else {
                // c. Ensure New Username is unique if changed
                if ($username_changed) {
                    $stmt_dup = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                    $stmt_dup->bind_param('si', $new_username, $user_id);
                    $stmt_dup->execute();
                    $dup_row = $stmt_dup->get_result()->fetch_assoc();
                    $stmt_dup->close();
                    if ($dup_row) {
                        $err_status = 409;
                        $err_msg = 'That username is already taken. Please choose a different username.';
                    }
                }
            }
        }

        if ($err_msg !== null) {
            if ($is_ajax_request || in_array($method, ['PUT', 'PATCH'], true)) {
                student_account_json_response($err_status, ['ok' => false, 'error' => $err_msg]);
            }
            $flash_error = $err_msg;
        } else {
            // d. Hash new password if provided and commit update
            $conn->begin_transaction();
            try {
                if ($username_changed && $password_changed) {
                    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt_upd = $conn->prepare('UPDATE users SET username = ?, password = ? WHERE id = ?');
                    $stmt_upd->bind_param('ssi', $new_username, $new_hash, $user_id);
                } elseif ($username_changed) {
                    $stmt_upd = $conn->prepare('UPDATE users SET username = ? WHERE id = ?');
                    $stmt_upd->bind_param('si', $new_username, $user_id);
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt_upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $stmt_upd->bind_param('si', $new_hash, $user_id);
                }

                if (!$stmt_upd->execute()) {
                    throw new RuntimeException('Failed to update account credentials.');
                }
                $stmt_upd->close();
                $conn->commit();

                if ($username_changed) {
                    $current_username = $new_username;
                    $_SESSION['username'] = $new_username;
                }
                if (function_exists('session_regenerate_id')) {
                    @session_regenerate_id(true);
                }
                if (function_exists('log_activity')) {
                    try {
                        log_activity($user_id, 'Student updated account credentials');
                    } catch (\Throwable $e) {
                        // Non-blocking audit log
                    }
                }

                $success_msg = ($username_changed && $password_changed)
                    ? 'Username and password updated successfully.'
                    : ($username_changed ? 'Username updated successfully.' : 'Password updated successfully.');

                if ($is_ajax_request || in_array($method, ['PUT', 'PATCH'], true)) {
                    student_account_json_response(200, [
                        'ok' => true,
                        'message' => $success_msg,
                        'username' => $current_username,
                    ]);
                }
                $flash_success = $success_msg;
            } catch (\Throwable $ex) {
                $conn->rollback();
                if ($is_ajax_request || in_array($method, ['PUT', 'PATCH'], true)) {
                    student_account_json_response(500, ['ok' => false, 'error' => 'Unable to save account changes. Please try again.']);
                }
                $flash_error = 'Unable to save account changes. Please try again.';
            }
        }
    }
}

$csrf_token = (string)($_SESSION['csrf_token'] ?? '');
$pageTitle = 'Account Settings';
$activeNav = 'my_account';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body font-['Inter',sans-serif] max-w-3xl mx-auto">
            <!-- Breadcrumbs -->
            <nav class="mb-4 text-xs font-semibold text-[#8B7168] flex items-center gap-2">
                <a href="<?php echo htmlspecialchars(capstone_url('modules/student_view.php')); ?>" class="text-[#59443F] hover:text-[#8B7168] transition">Dashboard</a>
                <span>/</span>
                <span class="text-[#8B7168]">Account Settings</span>
            </nav>

            <!-- Inline Feedback Banner (for non-JS or dynamic status) -->
            <div id="accountAlertBox"
                 class="<?php echo ($flash_success !== null || $flash_error !== null) ? 'flex' : 'hidden'; ?> mb-5 rounded-xl px-4 py-3 text-sm font-medium items-center gap-3 shadow-xs <?php echo $flash_error !== null ? 'bg-rose-50 border border-rose-200 text-rose-800' : 'bg-emerald-50 border border-emerald-200 text-emerald-800'; ?>"
                 role="alert">
                <i id="accountAlertIcon" class="fa-solid <?php echo $flash_error !== null ? 'fa-triangle-exclamation text-rose-600' : 'fa-circle-check text-emerald-600'; ?> text-lg shrink-0"></i>
                <span id="accountAlertText"><?php echo htmlspecialchars((string)($flash_error ?? $flash_success ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <!-- Account Settings Card -->
            <section class="bg-white rounded-xl shadow-sm border border-[#E8C7C5]/60 p-6 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 mb-6 border-b border-[#E8C7C5]/35">
                    <div class="flex items-center gap-3.5">
                        <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 flex items-center justify-center shrink-0 shadow-xs">
                            <i class="fa-solid fa-shield-halved text-lg"></i>
                        </div>
                        <div>
                            <h1 class="font-heading font-extrabold text-lg sm:text-xl text-[#59443F] m-0 tracking-tight">Account Settings</h1>
                            <p class="text-xs text-[#8B7168] font-medium mt-0.5 mb-0">Manage your login username and keep your password secure.</p>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars(capstone_url('modules/student_edit_profile.php')); ?>"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold self-start sm:self-center transition">
                        <i class="fa-solid fa-user-pen"></i> Edit Personal Profile
                    </a>
                </div>

                <form id="accountSettingsForm" method="post" action="student_account.php" class="space-y-5" novalidate style="margin: 0; padding: 0; border: none; box-shadow: none; background: transparent;">
                    <input type="hidden" name="csrf_token" id="csrfTokenInput" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- New Username -->
                    <div>
                        <label for="new_username" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                            New Username
                        </label>
                        <input type="text"
                               id="new_username"
                               name="new_username"
                               value="<?php echo htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8'); ?>"
                               data-current-username="<?php echo htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8'); ?>"
                               autocomplete="username"
                               required
                               minlength="3"
                               maxlength="50"
                               placeholder="Enter your username"
                               class="w-full px-4 py-3 rounded-xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                        <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                            Must be 3–50 characters (letters, numbers, underscores, hyphens, or dots).
                        </p>
                    </div>

                    <div class="pt-4 border-t border-[#E8C7C5]/30 space-y-5">
                        <!-- Current Password -->
                        <div>
                            <label for="current_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Current Password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="password"
                                       id="current_password"
                                       name="current_password"
                                       autocomplete="current-password"
                                       required
                                       placeholder="Enter your current password to confirm changes"
                                       class="w-full px-4 py-3 pr-11 rounded-xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                <button type="button"
                                        data-toggle-pw="current_password"
                                        aria-label="Toggle current password visibility"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                        style="width:auto;margin:0;box-shadow:none;">
                                    <i class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                            <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                                Required for updating either your username or password.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- New Password -->
                            <div>
                                <label for="new_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    New Password
                                </label>
                                <div class="relative">
                                    <input type="password"
                                           id="new_password"
                                           name="new_password"
                                           autocomplete="new-password"
                                           minlength="8"
                                           placeholder="Minimum 8 characters"
                                           class="w-full px-4 py-3 pr-11 rounded-xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                    <button type="button"
                                            data-toggle-pw="new_password"
                                            aria-label="Toggle new password visibility"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                            style="width:auto;margin:0;box-shadow:none;">
                                        <i class="fa-regular fa-eye text-sm"></i>
                                    </button>
                                </div>
                                <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                                    Leave blank to keep your current password.
                                </p>
                            </div>

                            <!-- Confirm New Password -->
                            <div>
                                <label for="confirm_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    Confirm New Password
                                </label>
                                <div class="relative">
                                    <input type="password"
                                           id="confirm_password"
                                           name="confirm_password"
                                           autocomplete="new-password"
                                           minlength="8"
                                           placeholder="Re-enter new password"
                                           class="w-full px-4 py-3 pr-11 rounded-xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                    <button type="button"
                                            data-toggle-pw="confirm_password"
                                            aria-label="Toggle confirm password visibility"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                            style="width:auto;margin:0;box-shadow:none;">
                                        <i class="fa-regular fa-eye text-sm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="pt-4 border-t border-[#E8C7C5]/30 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <a href="<?php echo htmlspecialchars(capstone_url('modules/student_view.php')); ?>"
                           class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold text-center transition">
                            Back to Dashboard
                        </a>
                        <button type="submit"
                                id="saveAccountBtn"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-[#59443F] hover:bg-[#6E554F] text-white text-xs sm:text-sm font-bold shadow-sm transition disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer">
                            <i id="saveAccountSpinner" class="fa-solid fa-circle-notch fa-spin hidden"></i>
                            <i id="saveAccountIcon" class="fa-solid fa-floppy-disk text-[#E8C7C5]"></i>
                            <span id="saveAccountBtnText">Save Account Changes</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <script>
        (function () {
            const form = document.getElementById('accountSettingsForm');
            const usernameInput = document.getElementById('new_username');
            const currentPwInput = document.getElementById('current_password');
            const newPwInput = document.getElementById('new_password');
            const confirmPwInput = document.getElementById('confirm_password');
            const csrfInput = document.getElementById('csrfTokenInput');
            const submitBtn = document.getElementById('saveAccountBtn');
            const spinner = document.getElementById('saveAccountSpinner');
            const icon = document.getElementById('saveAccountIcon');
            const btnText = document.getElementById('saveAccountBtnText');
            const alertBox = document.getElementById('accountAlertBox');
            const alertIcon = document.getElementById('accountAlertIcon');
            const alertText = document.getElementById('accountAlertText');

            if (!form) return;

            // Password visibility toggles
            form.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = document.getElementById(btn.getAttribute('data-toggle-pw'));
                    if (!target) return;
                    const show = target.type === 'password';
                    target.type = show ? 'text' : 'password';
                    const i = btn.querySelector('i');
                    if (i) {
                        i.classList.toggle('fa-eye', !show);
                        i.classList.toggle('fa-eye-slash', show);
                    }
                });
            });

            function showFeedback(message, isError) {
                if (typeof window.showToast === 'function') {
                    window.showToast(message, isError ? 'error' : 'success');
                }
                if (!alertBox || !alertText || !alertIcon) return;
                alertBox.classList.remove('hidden', 'bg-rose-50', 'border-rose-200', 'text-rose-800', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-800');
                alertBox.classList.add('flex');
                if (isError) {
                    alertBox.classList.add('bg-rose-50', 'border-rose-200', 'text-rose-800');
                    alertIcon.className = 'fa-solid fa-triangle-exclamation text-rose-600 text-lg shrink-0';
                } else {
                    alertBox.classList.add('bg-emerald-50', 'border-emerald-200', 'text-emerald-800');
                    alertIcon.className = 'fa-solid fa-circle-check text-emerald-600 text-lg shrink-0';
                }
                alertText.textContent = message;
            }

            function setLoading(loading) {
                submitBtn.disabled = loading;
                spinner.classList.toggle('hidden', !loading);
                icon.classList.toggle('hidden', loading);
                btnText.textContent = loading ? 'Saving Changes...' : 'Save Account Changes';
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                const newUsername = (usernameInput.value || '').trim();
                const originalUsername = usernameInput.getAttribute('data-current-username') || '';
                const currentPassword = currentPwInput.value || '';
                const newPassword = newPwInput.value || '';
                const confirmPassword = confirmPwInput.value || '';

                const usernameChanged = newUsername !== originalUsername;
                const passwordChanged = newPassword !== '' || confirmPassword !== '';

                // Client-side validation
                if (!currentPassword) {
                    showFeedback('Current password is required to save changes.', true);
                    currentPwInput.focus();
                    return;
                }
                if (!usernameChanged && !passwordChanged) {
                    showFeedback('Please update your username or enter a new password.', true);
                    return;
                }
                if (usernameChanged && (newUsername.length < 3 || newUsername.length > 50 || !/^[A-Za-z0-9_.-]+$/.test(newUsername))) {
                    showFeedback('Username must be 3–50 characters (letters, numbers, underscores, hyphens, or dots).', true);
                    usernameInput.focus();
                    return;
                }
                if (passwordChanged && newPassword.length < 8) {
                    showFeedback('New password must be at least 8 characters long.', true);
                    newPwInput.focus();
                    return;
                }
                if (passwordChanged && newPassword !== confirmPassword) {
                    showFeedback('New password and confirmation password do not match.', true);
                    confirmPwInput.focus();
                    return;
                }

                setLoading(true);
                try {
                    const res = await fetch('student_account.php?ajax=1', {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfInput ? csrfInput.value : ''
                        },
                        body: JSON.stringify({
                            csrf_token: csrfInput ? csrfInput.value : '',
                            new_username: newUsername,
                            current_password: currentPassword,
                            new_password: newPassword,
                            confirm_password: confirmPassword
                        })
                    });

                    const data = await res.json();
                    if (!res.ok || !data || data.ok !== true) {
                        throw new Error((data && data.error) ? data.error : 'Failed to update account settings.');
                    }

                    // Sync updated username and gracefully clear password fields
                    if (data.username) {
                        usernameInput.value = data.username;
                        usernameInput.setAttribute('data-current-username', data.username);
                    }
                    currentPwInput.value = '';
                    newPwInput.value = '';
                    confirmPwInput.value = '';
                    showFeedback(data.message || 'Account settings updated successfully.', false);
                } catch (err) {
                    showFeedback(err && err.message ? err.message : 'Unable to save changes. Please try again.', true);
                } finally {
                    setLoading(false);
                }
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
