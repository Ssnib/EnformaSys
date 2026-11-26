<?php
include 'includes/session_fix.php';
if (!enformasys_is_production()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

if (isset($_POST['login'])) {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die("CSRF token validation failed.");
    }
    include 'includes/db.php';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $clientIp = enformasys_client_ip();

    if ($username === '' || $password === '' || strlen($username) > 120) {
        $error = "Please enter both a valid username and password.";
    } else {
        $throttle = enformasys_login_throttle_status($conn, $username, $clientIp);
        if (!empty($throttle['blocked'])) {
            http_response_code(429);
            $mins = max(1, (int)ceil(((int)$throttle['remaining_seconds']) / 60));
            $error = "Too many failed login attempts. Please wait {$mins} minute" . ($mins === 1 ? '' : 's') . " before trying again.";
            enformasys_audit_log(
                $conn,
                null,
                "[SECURITY] Login throttled (lockout active) for '{$username}' from IP {$clientIp}",
                'Auth',
                null,
                'login_throttled',
                null,
                $clientIp
            );
        } else {
            $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $authOk = false;
            $user = null;
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                if (password_verify($password, (string)$user['password'])) {
                    $authOk = true;
                }
            }

            if ($authOk && $user) {
                $uid = (int)$user['id'];
                enformasys_clear_login_attempts($conn, $username, $clientIp);

                // Transparently upgrade password hash if algorithm/cost changes
                if (password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT)) {
                    $rehash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtRehash = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($stmtRehash) {
                        $stmtRehash->bind_param('si', $rehash, $uid);
                        $stmtRehash->execute();
                        $stmtRehash->close();
                    }
                }

                // Prevent session fixation & rotate CSRF token on sign-in
                if (PHP_SAPI !== 'cli') {
                    @session_regenerate_id(true);
                }
                $_SESSION['user'] = $uid;
                $_SESSION['user_id'] = $uid;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = (string)$user['role'];
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                unset($_SESSION['active_academic_year'], $_SESSION['active_semester']);
                if (function_exists('enformasys_invalidate_ay_cache')) {
                    enformasys_invalidate_ay_cache();
                }
                if (function_exists('enformasys_warm_session_cache')) {
                    enformasys_warm_session_cache($conn, $uid, $username, (string)$user['role']);
                }

                enformasys_audit_log(
                    $conn,
                    $uid,
                    "Successful login ({$user['role']}) from IP {$clientIp}",
                    'Auth',
                    $uid,
                    'login_success',
                    null,
                    $clientIp
                );

                header('Location: index.php');
                exit;
            } else {
                enformasys_record_failed_login($conn, $username, $clientIp);
                $error = "Invalid credentials. Please check your username and password.";
            }
        }
    }
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
    <title>Login - EnformaSys | Littleway College Seminary</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Lora:ital,wght@0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/style.css'); ?>">
    <link rel="stylesheet" href="css/motion.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/motion.css'); ?>">
    <link rel="stylesheet" href="css/floating_theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/floating_theme.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .font-serif-seminary {
            font-family: 'Lora', Georgia, Cambria, 'Times New Roman', serif;
        }
        .font-ui {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .font-display {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
        }
        .login-card-elevated {
            box-shadow:
                0 24px 48px -12px rgba(74, 55, 49, 0.11),
                0 10px 24px -6px rgba(74, 55, 49, 0.06),
                0 1px 3px rgba(74, 55, 49, 0.04);
        }
    </style>
</head>
<body class="login-page font-ui bg-[#FAF6F4] min-h-screen text-[#4A3731] antialiased flex items-center justify-center p-5 sm:p-8 lg:p-12 relative overflow-x-hidden selection:bg-[#EBD3CC] selection:text-[#4A3731]">
    <!-- Global Non-Blocking Progress Bar & Branded Preloader -->
    <div id="enformasys-top-progress" aria-hidden="true"></div>
    <div id="enformasys-preloader" role="status" aria-live="polite" aria-hidden="true">
        <div class="enformasys-preloader-ring">
            <div class="enformasys-preloader-spinner" aria-hidden="true"></div>
            <img src="assets/LOGO%20(2).jpg" alt="EnformaSys Crest" class="enformasys-preloader-crest" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
            <span class="enformasys-preloader-fallback" style="display:none;" aria-hidden="true"><i class="fa-solid fa-church"></i></span>
        </div>
        <p id="enformasysPreloaderText" class="enformasys-preloader-text">Loading...</p>
    </div>

    <!-- Subtle Warm Atmospheric Backdrop -->
    <div class="login-backdrop fixed inset-0 bg-gradient-to-br from-[#FCF9F7] via-[#F7EFEA] to-[#EFE0D9]/70 pointer-events-none z-0" aria-hidden="true"></div>

    <!-- Floating Theme Switcher -->
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

    <main class="login-wrapper w-full max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center z-10 relative">
        <!-- Left Column: Institutional Crest, Refined Badges & Seminary Typography -->
        <section class="login-left lg:col-span-7 flex flex-col gap-6 text-left items-start">
            <div class="login-brand-left flex flex-col gap-6 text-left items-start w-full">

                <!-- Official Circular Littleway College Seminary Crest + Refined Pill Badges -->
                <div class="flex flex-col gap-5 items-start">
                    <div class="inline-flex items-center gap-3.5">
                        <div class="w-20 h-20 rounded-full bg-white p-0.5 border border-[#D6A6A6]/70 ring-4 ring-[#EBD3CC]/65 shadow-md overflow-hidden shrink-0">
                            <img src="assets/LOGO%20(2).jpg"
                                 alt="Official Seal of Littleway College Seminary"
                                 class="w-full h-full rounded-full object-cover object-center scale-[1.34]">
                        </div>
                        <div class="flex flex-col">
                            <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#8B7168]">Diocese of Kabankalan</span>
                            <span class="text-xs font-semibold text-[#4A3731]/80 mt-0.5">Formation &amp; Academic Portal</span>
                        </div>
                    </div>

                    <!-- Refined Pill Badges Row -->
                    <div class="brand-top-row flex items-center gap-2 flex-wrap">
                        <span class="brand-subtitle-tag inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/80 text-[#4A3731] text-[11px] font-bold uppercase tracking-[0.14em] border border-[#EBD3CC] shadow-2xs">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#4A3731]"></span>
                            <span>EnformaSys</span>
                        </span>
                        <span class="pill-badge inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#EBD3CC]/55 text-[#4A3731] text-xs font-semibold border border-[#D6A6A6]/60 shadow-2xs">
                            <i class="fa-solid fa-shield-halved text-[11px] text-[#4A3731]/85" aria-hidden="true"></i>
                            <span>Secure access</span>
                        </span>
                        <span class="pill-badge inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#EBD3CC]/55 text-[#4A3731] text-xs font-semibold border border-[#D6A6A6]/60 shadow-2xs">
                            <i class="fa-solid fa-bolt text-[11px] text-[#4A3731]/85" aria-hidden="true"></i>
                            <span>Fast workflow</span>
                        </span>
                    </div>
                </div>

                <!-- Seminary Heading & Quote in Lora Serif -->
                <div class="welcome-heading-wrap flex flex-col gap-4 text-left items-start">
                    <h1 class="welcome-title font-serif-seminary font-semibold text-3xl sm:text-4xl lg:text-[46px] text-[#4A3731] tracking-tight leading-[1.16] text-left m-0">
                        Welcome to Littleway College Seminary
                    </h1>
                    <blockquote class="welcome-quote font-serif-seminary italic text-sm sm:text-base lg:text-[17px] text-[#785E56] leading-relaxed max-w-xl text-left border-l-2 border-[#D6A6A6] pl-4 m-0">
                        &ldquo;The path to holiness is not always in great deeds, but in little things done with great love.&rdquo;
                    </blockquote>
                </div>
            </div>
        </section>

        <!-- Right Column: Elevated Sign In Card -->
        <section class="login-right lg:col-span-5 flex justify-center w-full relative">
            <!-- Soft Atmospheric Glow Orbs -->
            <div class="absolute -top-12 -right-10 sm:-top-16 sm:-right-14 w-72 h-72 sm:w-88 sm:h-88 rounded-full bg-gradient-to-br from-[#EBD3CC]/75 to-[#D6A6A6]/45 blur-3xl pointer-events-none z-0 transform-gpu" aria-hidden="true"></div>
            <div class="absolute -bottom-10 -left-10 sm:-bottom-14 sm:-left-12 w-64 h-64 sm:w-80 sm:h-80 rounded-full bg-gradient-to-tr from-[#D6A6A6]/45 to-[#EBD3CC]/45 blur-3xl pointer-events-none z-0 transform-gpu" aria-hidden="true"></div>

            <div class="login-card login-card-elevated bg-white rounded-3xl border border-[#EBD3CC] p-9 sm:p-10 w-full max-w-md transition-all relative z-10">
                <div class="card-header-block text-center mb-7">
                    <h2 class="form-title font-display font-extrabold text-2xl sm:text-[28px] text-[#4A3731] tracking-tight mb-1.5">Sign in</h2>
                    <p class="form-subtitle text-xs sm:text-sm text-[#8B7168] font-medium m-0">Enter your credentials to access your portal.</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="error-box flex items-center gap-2.5 p-3.5 mb-5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold" role="alert" aria-live="polite">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 shrink-0" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($success)): ?>
                    <div class="success-box flex items-center gap-2.5 p-3.5 mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-semibold" role="status" aria-live="polite">
                        <i class="fa-solid fa-circle-check text-emerald-600 shrink-0" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="" class="login-form flex flex-col gap-4" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Username Field -->
                    <div class="form-group flex flex-col gap-1.5">
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block" for="loginUsername">Username</label>
                        <div class="input-wrapper relative flex items-center w-full">
                            <input id="loginUsername"
                                   type="text"
                                   name="username"
                                   placeholder="Enter your username"
                                   value="<?php echo htmlspecialchars((string)($_POST['username'] ?? '')); ?>"
                                   class="form-input w-full pl-4 pr-11 py-3 rounded-xl bg-[#FCF9F7]/60 focus:bg-white border border-[#EBD3CC] text-[#4A3731] font-medium text-sm placeholder-[#8B7168]/55 focus:outline-none focus:border-[#4A3731] focus:ring-2 focus:ring-[#4A3731]/15 transition-all duration-200"
                                   required
                                   autofocus
                                   autocomplete="username"
                                   autocorrect="off"
                                   autocapitalize="none"
                                   spellcheck="false"
                                   enterkeyhint="next">
                            <span class="input-trailing-icon absolute right-3.5 text-[#8B7168] pointer-events-none flex items-center justify-center" aria-hidden="true">
                                <i class="fa-regular fa-circle-user text-sm"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group flex flex-col gap-1.5">
                        <label class="form-label text-xs font-semibold uppercase tracking-wider text-[#785E56] block" for="loginPassword">Password</label>
                        <div class="input-wrapper relative flex items-center w-full">
                            <input id="loginPassword"
                                   type="password"
                                   name="password"
                                   placeholder="Enter your password"
                                   class="form-input password-input w-full pl-4 pr-11 py-3 rounded-xl bg-[#FCF9F7]/60 focus:bg-white border border-[#EBD3CC] text-[#4A3731] font-medium text-sm placeholder-[#8B7168]/55 focus:outline-none focus:border-[#4A3731] focus:ring-2 focus:ring-[#4A3731]/15 transition-all duration-200"
                                   required
                                   autocomplete="current-password"
                                   enterkeyhint="done">
                            <button type="button"
                                    id="toggleLoginPassword"
                                    class="toggle-password absolute right-2.5 w-8 h-8 rounded-lg text-[#8B7168] hover:text-[#4A3731] hover:bg-[#EBD3CC]/35 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4A3731] transition cursor-pointer flex items-center justify-center border-0 bg-transparent"
                                    aria-label="Show password"
                                    aria-pressed="false">
                                <i class="fa-regular fa-eye text-sm" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me + Forgot Password Row -->
                    <div class="flex items-center justify-between gap-3 pt-0.5">
                        <div class="inline-flex items-center gap-2">
                            <input type="checkbox"
                                   id="rememberMe"
                                   name="remember_me"
                                   value="1"
                                   class="w-4 h-4 rounded border-[#D6A6A6] accent-[#4A3731] cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4A3731]">
                            <label for="rememberMe" class="text-xs sm:text-[13px] font-medium text-[#785E56] hover:text-[#4A3731] cursor-pointer select-none">
                                Remember me
                            </label>
                        </div>
                        <a href="forgot_password.php" class="forgot-link text-xs sm:text-[13px] font-semibold text-[#785E56] hover:text-[#4A3731] transition hover:underline no-underline">
                            Forgot Password?
                        </a>
                    </div>

                    <button type="submit" name="login" class="login-btn btn-seminary-primary w-full py-3.5 px-6 mt-1.5 rounded-full text-white font-semibold text-sm tracking-wide shadow-md transition-all duration-200 ease-out flex items-center justify-center gap-2 cursor-pointer">
                        <span>Login</span>
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </button>
                </form>

                <div class="form-footer mt-7 pt-5 border-t border-[#EBD3CC]/75 text-center">
                    <p class="help-subtitle text-xs text-[#8B7168] font-medium m-0">Need help? Contact the EnformaSys Administrator.</p>
                </div>
            </div>
        </section>
    </main>

    <script>
        (function () {
            // Theme Toggle with Zero-FOUC Persistence
            var THEME_KEY = 'enformasys_theme';
            function isDarkMode() {
                return document.documentElement.getAttribute('data-theme') === 'dark';
            }
            function syncThemeButtons(dark) {
                document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                    b.setAttribute('aria-pressed', dark ? 'true' : 'false');
                    var lbl = dark ? 'Switch to light mode' : 'Switch to dark mode';
                    b.setAttribute('aria-label', lbl);
                    b.setAttribute('title', lbl);
                });
            }
            function setLoginTheme(theme, persist) {
                var dark = theme === 'dark';
                if (dark) document.documentElement.setAttribute('data-theme', 'dark');
                else document.documentElement.removeAttribute('data-theme');
                var meta = document.querySelector('meta[name="color-scheme"]');
                if (meta) meta.setAttribute('content', dark ? 'dark' : 'light');
                if (persist) {
                    try { localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light'); } catch (_) {}
                }
                syncThemeButtons(dark);
            }
            document.addEventListener('click', function (e) {
                var tbtn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
                if (!tbtn) return;
                e.preventDefault();
                setLoginTheme(isDarkMode() ? 'light' : 'dark', true);
            });
            window.addEventListener('storage', function (e) {
                if (e.key === THEME_KEY) setLoginTheme(e.newValue === 'dark' ? 'dark' : 'light', false);
            });
            syncThemeButtons(isDarkMode());

            const passwordInput = document.getElementById('loginPassword');
            const toggleBtn = document.getElementById('toggleLoginPassword');
            if (passwordInput && toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    const willShow = passwordInput.type === 'password';
                    passwordInput.type = willShow ? 'text' : 'password';
                    toggleBtn.setAttribute('aria-pressed', willShow ? 'true' : 'false');
                    toggleBtn.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
                    const icon = toggleBtn.querySelector('i');
                    if (icon) {
                        icon.className = willShow ? 'fa-regular fa-eye-slash text-sm' : 'fa-regular fa-eye text-sm';
                    }
                });
            }

            // Persist remembered username cleanly across browser sessions when opted in
            const storageKey = 'enformasys_remembered_username';
            const userInput = document.getElementById('loginUsername');
            const rememberBox = document.getElementById('rememberMe');
            const loginForm = document.getElementById('loginForm');
            if (userInput && rememberBox) {
                try {
                    const savedUser = localStorage.getItem(storageKey);
                    if (savedUser) {
                        if (!userInput.value) {
                            userInput.value = savedUser;
                        }
                        rememberBox.checked = true;
                    }
                    if (loginForm) {
                        loginForm.addEventListener('submit', function () {
                            try {
                                if (rememberBox.checked && userInput.value.trim() !== '') {
                                    localStorage.setItem(storageKey, userInput.value.trim());
                                } else {
                                    localStorage.removeItem(storageKey);
                                }
                            } catch (err) {}
                        });
                    }
                } catch (err) {}
            }

            // Global Slim Progress Bar, Preloader Overlay & Login Button Spinner
            var bar = document.getElementById('enformasys-top-progress');
            var overlay = document.getElementById('enformasys-preloader');
            var textEl = document.getElementById('enformasysPreloaderText');
            var trickle = null;
            var pct = 0;

            function startBar() {
                if (!bar) return;
                if (trickle) clearInterval(trickle);
                pct = 25;
                bar.style.width = pct + '%';
                bar.classList.add('is-active');
                trickle = setInterval(function () {
                    if (pct < 88) {
                        pct = Math.min(88, pct + 8);
                        bar.style.width = pct + '%';
                    }
                }, 200);
            }

            function hideLoader() {
                if (trickle) { clearInterval(trickle); trickle = null; }
                if (overlay) {
                    overlay.classList.remove('is-active');
                    overlay.setAttribute('aria-hidden', 'true');
                }
                if (bar) {
                    bar.classList.remove('is-active');
                    bar.style.width = '0%';
                }
                if (loginForm) {
                    var btn = loginForm.querySelector('button[type="submit"]');
                    if (btn && btn.dataset.origHtml) {
                        btn.innerHTML = btn.dataset.origHtml;
                        btn.disabled = false;
                        btn.classList.remove('opacity-80', 'cursor-wait');
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', hideLoader);
            window.addEventListener('pageshow', hideLoader);

            document.addEventListener('click', function (e) {
                if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
                if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
                var href = (a.getAttribute('href') || '').trim();
                if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
                startBar();
                if (textEl) textEl.textContent = 'Loading...';
                if (overlay) {
                    overlay.classList.add('is-active');
                    overlay.setAttribute('aria-hidden', 'false');
                }
            });

            if (loginForm) {
                loginForm.addEventListener('submit', function (e) {
                    if (e.defaultPrevented) return;
                    var btn = loginForm.querySelector('button[type="submit"]');
                    if (btn && !btn.dataset.origHtml) {
                        if (btn.name && !loginForm.querySelector('input[data-login-submitter="1"]')) {
                            var h = document.createElement('input');
                            h.type = 'hidden';
                            h.name = btn.name;
                            h.value = btn.value || '1';
                            h.setAttribute('data-login-submitter', '1');
                            loginForm.appendChild(h);
                        }
                        btn.dataset.origHtml = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-xs" aria-hidden="true"></i><span>Signing in...</span>';
                        btn.disabled = true;
                        btn.classList.add('opacity-80', 'cursor-wait');
                    }
                    startBar();
                    if (textEl) textEl.textContent = 'Processing...';
                    if (overlay) {
                        overlay.classList.add('is-active');
                        overlay.setAttribute('aria-hidden', 'false');
                    }
                });
            }
        })();
    </script>
</body>
</html>
