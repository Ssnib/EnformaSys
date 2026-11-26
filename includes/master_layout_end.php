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
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
            if (!btn) return;
            e.preventDefault();
            apply(isDark() ? 'light' : 'dark', true);
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
