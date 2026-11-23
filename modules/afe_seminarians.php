<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$portal_state = eval_handle_report_access_request($conn, (string)($_SESSION['role'] ?? ''));
$cfg = $portal_state['config'];

$pageTitle = (string)($cfg['page_title'] ?? 'Academic Evaluation Access');
$activeNav = (string)($cfg['active_nav'] ?? 'afe_seminarians');
require_once __DIR__ . '/../includes/layout_app_start.php';
eval_render_report_access_page(
    $portal_state['students'],
    (string)$cfg['view_col'],
    $portal_state['success'],
    (string)$cfg['pillar'],
    $portal_state['error']
);
?>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>

