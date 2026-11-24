<?php
/**
 * EnformaSys Master Layout Closer
 * Closes the main content wrapper and loads layout scripts.
 */
require_once __DIR__ . '/url.php';
?>
        </main>
    </div>

    <script defer src="<?= htmlspecialchars(capstone_url('assets/app_layout.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/app_layout.js') ?>"></script>
    <script defer src="<?= htmlspecialchars(capstone_url('assets/ajax.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/ajax.js') ?>"></script>
</body>
</html>
