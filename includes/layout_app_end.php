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
    <script defer src="<?php echo htmlspecialchars(capstone_url('assets/app_layout.js')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/app_layout.js'); ?>"></script>
    <script defer src="<?php echo htmlspecialchars(capstone_url('assets/ajax.js')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/ajax.js'); ?>"></script>
</body>
</html>
