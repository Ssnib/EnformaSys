        </main>
    </div>
<?php
if (empty($GLOBALS['__enformasys_toast_fired'])) {
    $endToast = null;
    if (!empty($_SESSION['flash_success'])) {
        $endToast = ['msg' => (string)$_SESSION['flash_success'], 'type' => 'success'];
        unset($_SESSION['flash_success']);
    } elseif (!empty($_SESSION['flash_error'])) {
        $endToast = ['msg' => (string)$_SESSION['flash_error'], 'type' => 'error'];
        unset($_SESSION['flash_error']);
    } elseif (!empty($_SESSION['toast_success'])) {
        $endToast = ['msg' => (string)$_SESSION['toast_success'], 'type' => 'success'];
        unset($_SESSION['toast_success']);
    } elseif (!empty($_SESSION['toast_error'])) {
        $endToast = ['msg' => (string)$_SESSION['toast_error'], 'type' => 'error'];
        unset($_SESSION['toast_error']);
    } elseif (!empty($_SESSION['toast_info'])) {
        $endToast = ['msg' => (string)$_SESSION['toast_info'], 'type' => 'info'];
        unset($_SESSION['toast_info']);
    } else {
        $isPostReq = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $hasInlineFlash = (isset($flash_success) && trim((string)$flash_success) !== '')
            || (isset($flash_error) && trim((string)$flash_error) !== '');
        if ($isPostReq || $hasInlineFlash) {
            $errCand = $flash_error ?? $error ?? $error_msg ?? '';
            $okCand = $flash_success ?? $success ?? $success_msg ?? '';
            if (is_string($errCand) && trim($errCand) !== '') {
                $endToast = ['msg' => $errCand, 'type' => 'error'];
            } elseif (is_string($okCand) && trim($okCand) !== '') {
                $endToast = ['msg' => $okCand, 'type' => 'success'];
            }
        }
    }

    if ($endToast !== null) {
        $GLOBALS['__enformasys_toast_fired'] = true;
        $cleanToastMsg = trim(strip_tags(html_entity_decode($endToast['msg'], ENT_QUOTES, 'UTF-8')));
        if ($cleanToastMsg !== '') { ?>
    <script>
    (function() {
        function fireEndToast() {
            if (typeof window.showToast === 'function') {
                window.showToast(<?php echo json_encode($cleanToastMsg); ?>, <?php echo json_encode($endToast['type']); ?>, 3500);
            } else {
                setTimeout(fireEndToast, 50);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fireEndToast);
        } else {
            fireEndToast();
        }
    })();
    </script>
        <?php }
    }
}
?>
    <script>
    (function () {
        if (window.__enformasysLoaderInit) return;
        window.__enformasysLoaderInit = true;

        var bar = document.getElementById('enformasys-top-progress');
        var overlay = document.getElementById('enformasys-preloader');
        var textEl = document.getElementById('enformasysPreloaderText');
        var trickleTimer = null;
        var safetyTimer = null;
        var currentPct = 0;

        function setBar(pct, active) {
            if (!bar) return;
            currentPct = pct;
            bar.style.width = pct + '%';
            if (active) {
                bar.classList.add('is-active');
            } else {
                bar.classList.remove('is-active');
            }
        }

        function startProgress() {
            if (!bar) return;
            if (trickleTimer) clearInterval(trickleTimer);
            setBar(18, true);
            requestAnimationFrame(function () {
                setBar(48, true);
            });
            trickleTimer = setInterval(function () {
                if (currentPct < 88) {
                    setBar(Math.min(88, currentPct + Math.max(2, (90 - currentPct) * 0.18)), true);
                }
            }, 220);
        }

        function showLoading(label) {
            startProgress();
            if (textEl && label) {
                textEl.textContent = label;
            }
            if (overlay) {
                overlay.classList.add('is-active');
                overlay.setAttribute('aria-hidden', 'false');
            }
            if (safetyTimer) clearTimeout(safetyTimer);
            safetyTimer = setTimeout(function () {
                hideLoading(false);
            }, 12000);
        }

        function restoreSubmitButtons() {
            document.querySelectorAll('[data-enformasys-btn-spinning="1"]').forEach(function (btn) {
                if (btn.dataset.enformasysOrigHtml !== undefined) {
                    btn.innerHTML = btn.dataset.enformasysOrigHtml;
                } else if (btn.dataset.enformasysOrigVal !== undefined) {
                    btn.value = btn.dataset.enformasysOrigVal;
                }
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-wait');
                delete btn.dataset.enformasysBtnSpinning;
                delete btn.dataset.enformasysLoading;
            });
            document.querySelectorAll('form[data-enformasys-submitting]').forEach(function (f) {
                delete f.dataset.enformasysSubmitting;
                delete f.dataset.enformasysConfirmed;
            });
        }

        function hideLoading(instant) {
            if (trickleTimer) {
                clearInterval(trickleTimer);
                trickleTimer = null;
            }
            if (safetyTimer) {
                clearTimeout(safetyTimer);
                safetyTimer = null;
            }
            if (overlay) {
                overlay.classList.remove('is-active');
                overlay.setAttribute('aria-hidden', 'true');
            }
            if (bar) {
                if (instant) {
                    setBar(0, false);
                } else if (bar.classList.contains('is-active')) {
                    setBar(100, true);
                    setTimeout(function () {
                        setBar(0, false);
                    }, 140);
                }
            }
            restoreSubmitButtons();
        }

        function applyInlineSpinner(form, submitter, label) {
            if (!submitter || submitter.dataset.enformasysLoading === '1' || submitter.dataset.enformasysBtnSpinning === '1') {
                return;
            }
            if (submitter.name) {
                var oldHidden = form.querySelector('input[data-enformasys-submitter="1"]');
                if (!oldHidden) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = submitter.name;
                    hid.value = submitter.value || '1';
                    hid.setAttribute('data-enformasys-submitter', '1');
                    form.appendChild(hid);
                }
            }
            submitter.dataset.enformasysBtnSpinning = '1';
            submitter.dataset.enformasysLoading = '1';
            var txt = label || submitter.getAttribute('data-confirm-loading') || 'Processing...';
            if (submitter.tagName === 'INPUT') {
                submitter.dataset.enformasysOrigVal = submitter.value;
                submitter.value = txt;
            } else {
                submitter.dataset.enformasysOrigHtml = submitter.innerHTML;
                submitter.innerHTML = '<span class="enformasys-btn-spinner"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i><span>' + txt.replace(/[<>&"]/g, '') + '</span></span>';
            }
            submitter.disabled = true;
            submitter.classList.add('opacity-75', 'cursor-wait');
        }

        document.addEventListener('DOMContentLoaded', function () {
            hideLoading(true);
        });

        window.addEventListener('pageshow', function () {
            hideLoading(true);
        });

        window.addEventListener('beforeunload', function () {
            startProgress();
        });

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            var raw = e.target;
            var el = raw && raw.nodeType === 3 ? raw.parentElement : raw;
            var link = el && el.closest ? el.closest('a[href]') : null;
            if (!link) return;
            if (link.hasAttribute('download') || link.hasAttribute('data-no-loading') || link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-toggle')) {
                return;
            }
            var targetAttr = (link.getAttribute('target') || '').toLowerCase();
            if (targetAttr === '_blank') return;

            var href = (link.getAttribute('href') || '').trim();
            if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
                return;
            }
            try {
                var parsed = new URL(href, window.location.href);
                if (parsed.origin !== window.location.origin) return;
                if (parsed.pathname === window.location.pathname && parsed.search === window.location.search && parsed.hash) {
                    return;
                }
            } catch (_) {
                return;
            }
            showLoading('Loading...');
        });

        document.addEventListener('change', function (e) {
            var target = e.target;
            if (!target || target.tagName !== 'SELECT') return;
            var form = target.form;
            if (!form) return;
            if (form.id === 'topbarFilterForm' || String(target.getAttribute('onchange') || '').indexOf('submit') !== -1) {
                showLoading('Loading...');
            }
        });

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM' || e.defaultPrevented) return;
            if (form.hasAttribute('data-no-loading') || String(form.getAttribute('target') || '').toLowerCase() === '_blank') {
                return;
            }
            var method = String(form.getAttribute('method') || 'get').toLowerCase();
            var submitter = e.submitter || form.querySelector('button[type="submit"]:not(:disabled), input[type="submit"]:not(:disabled)');
            var isPost = (method === 'post');
            if (submitter && !form.hasAttribute('data-no-btn-spinner')) {
                applyInlineSpinner(form, submitter, isPost ? 'Processing...' : 'Loading...');
            }
            showLoading(isPost ? 'Processing...' : 'Loading...');
        });

        window.EnformaSysLoader = {
            show: showLoading,
            hide: hideLoading,
            startProgress: startProgress
        };
    })();
    </script>
    <script>
    (function () {
        if (window.__enformasysThemeInit) return;
        window.__enformasysThemeInit = true;

        var STORAGE_KEY = 'enformasys_theme';

        function isDarkTheme() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }

        function updateToggleButtons(dark) {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
                btn.setAttribute('aria-label', label);
                btn.setAttribute('title', label);
            });
        }

        function syncChartJs(dark) {
            if (typeof window.Chart === 'undefined') return;
            var textColor = dark ? '#b8aea7' : '#64748b';
            var headingColor = dark ? '#f5f0ec' : '#3f2822';
            var gridColor = dark ? 'rgba(245, 240, 236, 0.09)' : 'rgba(92, 58, 49, 0.10)';
            var tooltipBg = dark ? '#231f1d' : '#1e293b';
            var tooltipBorder = dark ? 'rgba(245, 240, 236, 0.14)' : 'rgba(15, 23, 42, 0.08)';

            try {
                if (window.Chart.defaults) {
                    window.Chart.defaults.color = textColor;
                    window.Chart.defaults.borderColor = gridColor;
                }
                var instances = window.Chart.instances ? Object.values(window.Chart.instances) : [];
                instances.forEach(function (chart) {
                    if (!chart || !chart.options) return;
                    var opts = chart.options;
                    if (opts.plugins) {
                        if (opts.plugins.legend && opts.plugins.legend.labels) {
                            opts.plugins.legend.labels.color = textColor;
                        }
                        if (opts.plugins.title) {
                            opts.plugins.title.color = headingColor;
                        }
                        if (opts.plugins.tooltip) {
                            opts.plugins.tooltip.backgroundColor = tooltipBg;
                            opts.plugins.tooltip.titleColor = '#f5f0ec';
                            opts.plugins.tooltip.bodyColor = '#f5f0ec';
                            opts.plugins.tooltip.borderColor = tooltipBorder;
                            opts.plugins.tooltip.borderWidth = 1;
                        }
                    }
                    if (opts.scales) {
                        Object.keys(opts.scales).forEach(function (sk) {
                            var sc = opts.scales[sk];
                            if (!sc) return;
                            if (sc.ticks) sc.ticks.color = textColor;
                            if (sc.title) sc.title.color = textColor;
                            if (sc.grid) sc.grid.color = gridColor;
                            if (sc.angleLines) sc.angleLines.color = gridColor;
                            if (sc.pointLabels) sc.pointLabels.color = textColor;
                        });
                    }
                    if (typeof chart.update === 'function') {
                        chart.update('none');
                    }
                });
            } catch (_) {}
        }

        function applyTheme(theme, persist) {
            var dark = theme === 'dark';
            if (dark) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) meta.setAttribute('content', dark ? 'dark' : 'light');

            if (persist) {
                try {
                    localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
                } catch (_) {}
            }

            updateToggleButtons(dark);
            syncChartJs(dark);

            try {
                window.dispatchEvent(new CustomEvent('enformasys:themechange', {
                    detail: { theme: dark ? 'dark' : 'light', isDark: dark }
                }));
            } catch (_) {}
        }

        var fallbackTimer = null;

        function toggleThemeWithTransition(event, triggerBtn) {
            var nextTheme = isDarkTheme() ? 'light' : 'dark';
            var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (prefersReduced) {
                applyTheme(nextTheme, true);
                return;
            }

            var x = window.innerWidth / 2;
            var y = window.innerHeight / 2;
            if (event && typeof event.clientX === 'number' && (event.clientX !== 0 || event.clientY !== 0)) {
                x = event.clientX;
                y = event.clientY;
            } else if (triggerBtn && triggerBtn.getBoundingClientRect) {
                var rect = triggerBtn.getBoundingClientRect();
                x = rect.left + rect.width / 2;
                y = rect.top + rect.height / 2;
            }

            if (typeof document.startViewTransition === 'function') {
                var endRadius = Math.hypot(
                    Math.max(x, window.innerWidth - x),
                    Math.max(y, window.innerHeight - y)
                );
                try {
                    var transition = document.startViewTransition(function () {
                        applyTheme(nextTheme, true);
                    });
                    transition.ready.then(function () {
                        document.documentElement.animate(
                            {
                                clipPath: [
                                    'circle(0px at ' + x + 'px ' + y + 'px)',
                                    'circle(' + endRadius + 'px at ' + x + 'px ' + y + 'px)'
                                ]
                            },
                            {
                                duration: 420,
                                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                                pseudoElement: '::view-transition-new(root)'
                            }
                        );
                    }).catch(function () {});
                    return;
                } catch (_) {}
            }

            // Smooth fallback for browsers without View Transitions API
            if (fallbackTimer) clearTimeout(fallbackTimer);
            document.documentElement.classList.add('theme-animating');
            applyTheme(nextTheme, true);
            fallbackTimer = setTimeout(function () {
                document.documentElement.classList.remove('theme-animating');
                fallbackTimer = null;
            }, 340);
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
            if (!btn) return;
            e.preventDefault();
            toggleThemeWithTransition(e, btn);
        });

        window.addEventListener('storage', function (e) {
            if (e.key === STORAGE_KEY) {
                applyTheme(e.newValue === 'dark' ? 'dark' : 'light', false);
            }
        });

        function initSync() {
            var dark = isDarkTheme();
            updateToggleButtons(dark);
            syncChartJs(dark);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSync);
        } else {
            initSync();
        }
        window.addEventListener('load', function () {
            syncChartJs(isDarkTheme());
        });

        window.EnformaSysTheme = {
            isDark: isDarkTheme,
            set: function (theme) { applyTheme(theme, true); },
            toggle: function (event, btn) { toggleThemeWithTransition(event, btn); },
            syncCharts: function () { syncChartJs(isDarkTheme()); }
        };
    })();
    </script>
    <script defer src="<?php echo htmlspecialchars(capstone_url('assets/app_layout.js')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/app_layout.js'); ?>"></script>
    <script defer src="<?php echo htmlspecialchars(capstone_url('assets/ajax.js')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/ajax.js'); ?>"></script>
<?php require __DIR__ . '/live_css_editor.php'; ?>
</body>
</html>
