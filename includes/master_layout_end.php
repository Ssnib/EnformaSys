<?php
/**
 * EnformaSys Master Layout Closer
 * Closes the main content wrapper and loads layout scripts.
 */
require_once __DIR__ . '/url.php';
?>
        </main>
    </div>

    <script src="<?= htmlspecialchars(capstone_url('assets/app_layout.js')) ?>"></script>
    <script src="<?= htmlspecialchars(capstone_url('assets/ajax.js')) ?>"></script>
</body>
</html>
