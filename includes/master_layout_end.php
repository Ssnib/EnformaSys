<?php
/**
 * EnformaSys Master Layout Closer
 * Closes the main content wrapper and loads layout scripts.
 */
require_once __DIR__ . '/url.php';
?>
        </main>
    </div>

    <script>
    (function () {
        if (window.__enformasysThemeInit) return;
        window.__enformasysThemeInit = true;
        var STORAGE_KEY = 'enformasys_theme';
        function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
        function syncBtns(d) {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.setAttribute('aria-pressed', d ? 'true' : 'false');
                var l = d ? 'Switch to light mode' : 'Switch to dark mode';
                b.setAttribute('aria-label', l); b.setAttribute('title', l);
            });
        }
        function apply(t, persist) {
            var d = t === 'dark';
            if (d) document.documentElement.setAttribute('data-theme', 'dark');
            else document.documentElement.removeAttribute('data-theme');
            var m = document.querySelector('meta[name="color-scheme"]');
            if (m) m.setAttribute('content', d ? 'dark' : 'light');
            if (persist) { try { localStorage.setItem(STORAGE_KEY, d ? 'dark' : 'light'); } catch (_) {} }
            syncBtns(d);
            try { window.dispatchEvent(new CustomEvent('enformasys:themechange', { detail: { theme: d ? 'dark' : 'light', isDark: d } })); } catch (_) {}
        }
        var fbTimer = null;
        function toggleWithAnim(e, btn) {
            var next = isDark() ? 'light' : 'dark';
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { apply(next, true); return; }
            var x = window.innerWidth / 2, y = window.innerHeight / 2;
            if (e && typeof e.clientX === 'number' && (e.clientX !== 0 || e.clientY !== 0)) { x = e.clientX; y = e.clientY; }
            else if (btn && btn.getBoundingClientRect) { var r = btn.getBoundingClientRect(); x = r.left + r.width / 2; y = r.top + r.height / 2; }
            if (typeof document.startViewTransition === 'function') {
                var endR = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
                try {
                    var tr = document.startViewTransition(function () { apply(next, true); });
                    tr.ready.then(function () {
                        document.documentElement.animate({ clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + endR + 'px at ' + x + 'px ' + y + 'px)'] }, { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' });
                    }).catch(function () {});
                    return;
                } catch (_) {}
            }
            if (fbTimer) clearTimeout(fbTimer);
            document.documentElement.classList.add('theme-animating');
            apply(next, true);
            fbTimer = setTimeout(function () { document.documentElement.classList.remove('theme-animating'); fbTimer = null; }, 340);
        }
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
            if (!btn) return;
            e.preventDefault();
            toggleWithAnim(e, btn);
        });
        window.addEventListener('storage', function (e) {
            if (e.key === STORAGE_KEY) apply(e.newValue === 'dark' ? 'dark' : 'light', false);
        });
        document.addEventListener('DOMContentLoaded', function () { syncBtns(isDark()); });
    })();
    </script>
    <script defer src="<?= htmlspecialchars(capstone_url('assets/app_layout.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/app_layout.js') ?>"></script>
    <script defer src="<?= htmlspecialchars(capstone_url('assets/ajax.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/ajax.js') ?>"></script>
</body>
</html>
