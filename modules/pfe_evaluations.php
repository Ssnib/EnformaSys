<?php
require_once '../includes/session_fix.php';
require_roles(['PastoralFormator']);
require_once '../includes/db.php';
require_once '../includes/pastoral_evaluation.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$formator_id = (int)($_SESSION['user'] ?? 0);
$show_archived = isset($_GET['archived']) && $_GET['archived'] === '1';

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = pfe_get_default_semester();

$year_level_options = eval_year_level_filter_options();
$filter_year_level = eval_normalize_year_level_option($_GET['year_level'] ?? '');
$yl_aliases = eval_year_level_sql_aliases($filter_year_level);

if (isset($_POST['archive_eval'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['evaluation_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE pastoral_evaluations SET archived_at = NOW() WHERE evaluation_id = ? AND formator_id = ? AND archived_at IS NULL");
        $stmt->bind_param('ii', $id, $formator_id);
        $stmt->execute();
        $success = 'Evaluation archived.';
    }
}

if (isset($_POST['restore_eval'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['evaluation_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE pastoral_evaluations SET archived_at = NULL WHERE evaluation_id = ? AND formator_id = ? AND archived_at IS NOT NULL");
        $stmt->bind_param('ii', $id, $formator_id);
        $stmt->execute();
        $success = 'Evaluation restored.';
    }
}

if (isset($_POST['delete_eval'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['evaluation_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM pastoral_evaluations WHERE evaluation_id = ? AND formator_id = ?");
        $stmt->bind_param('ii', $id, $formator_id);
        $stmt->execute();
        $success = 'Evaluation deleted.';
    }
}

$where = $show_archived ? 'e.archived_at IS NOT NULL' : 'e.archived_at IS NULL';
$where .= ' AND e.academic_year = ? AND e.semester = ? AND e.formator_id = ?';
$where .= " AND EXISTS (
    SELECT 1 FROM seminarian_assignments sa
    WHERE sa.seminarian_id = e.seminarian_id
      AND sa.assignment_type = 'Pastoral'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
)";

$types = 'ssii';
$params = [$filter_academic_year, $filter_semester, $formator_id, $formator_id];
if (!empty($yl_aliases)) {
    $yl_ph = implode(',', array_fill(0, count($yl_aliases), '?'));
    $where .= " AND s.year_level IN ({$yl_ph})";
    $types .= str_repeat('s', count($yl_aliases));
    $params = array_merge($params, $yl_aliases);
}

$stmt = $conn->prepare("SELECT e.evaluation_id, e.seminarian_id, e.evaluation_date,
        r.average_rating, r.interpretation,
        s.year_level, s.formation_stage, s.enrollment_status, s.formation_status, s.is_graduate, s.completed_at,
        stu.is_graduate AS stu_is_graduate, stu.graduated_at, stu.formation_status AS stu_formation_status,
        u.username, up.full_name
    FROM pastoral_evaluations e
    JOIN seminarians s ON s.id = e.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id
    WHERE {$where}
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

ob_start();
if ($rows->num_rows === 0) { ?>
    <tr><td colspan="5" style="color: var(--text-secondary);">No evaluations found.</td></tr>
<?php }
while ($r = $rows->fetch_assoc()) {
    $name = trim((string)($r['full_name'] ?? ''));
    if ($name === '') $name = (string)($r['username'] ?? '');
    $is_graduated = eval_is_seminarian_graduated($r);
?>
    <tr class="eval-row">
        <td class="student-name"><strong><?php echo htmlspecialchars($name); ?></strong></td>
        <td><?php echo $r['average_rating'] === null ? '-' : "<span class='badge badge-primary'>" . htmlspecialchars(number_format((float)$r['average_rating'], 2)) . "</span>"; ?></td>
        <td><?php echo htmlspecialchars((string)($r['interpretation'] ?? '')); ?></td>
        <td style="color: var(--text-secondary);"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
        <td style="white-space: nowrap;">
            <a href="pfe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>" class="capstone-btn-secondary" style="margin: 0; padding: 0.4rem 0.6rem; text-decoration: none;" title="View/Print">
                <i class="fas fa-print"></i>
            </a>
            <?php if (!$show_archived) { ?>
                <?php if ($is_graduated) { ?>
                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-medium" style="margin-left: 0.35rem;" title="Cannot submit evaluation: This student has already graduated/completed the program.">Graduated</span>
                <?php } else { ?>
                    <a href="pfe_evaluation_form.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>" class="capstone-btn-secondary" style="margin: 0 0 0 0.35rem; padding: 0.4rem 0.6rem; text-decoration: none;" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                <?php } ?>
                <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0 0 0 0.35rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$r['evaluation_id']; ?>">
                    <button type="submit" name="archive_eval" class="capstone-btn-danger" style="width: auto; padding: 0.4rem 0.6rem;" onclick="return confirm('Archive this evaluation?');" title="Archive"><i class="fas fa-archive"></i></button>
                </form>
            <?php } else { ?>
                <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0 0 0 0.35rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$r['evaluation_id']; ?>">
                    <button type="submit" name="restore_eval" class="capstone-btn-primary" style="width: auto; padding: 0.4rem 0.6rem;" onclick="return confirm('Restore this evaluation?');" title="Restore"><i class="fas fa-undo"></i></button>
                </form>
                <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0 0 0 0.35rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$r['evaluation_id']; ?>">
                    <button type="submit" name="delete_eval" class="capstone-btn-danger" style="width: auto; padding: 0.4rem 0.6rem;" onclick="return confirm('Delete this evaluation permanently?');" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
            <?php } ?>
        </td>
    </tr>
<?php }
$recorded_html = (string)ob_get_clean();

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    $batch_data = eval_fetch_paginated_batch_seminarians(
        $conn,
        'pastoral',
        $formator_id,
        $filter_academic_year,
        $filter_semester,
        max(1, (int)($_GET['page'] ?? 1)),
        10,
        isset($_GET['batch_status']) ? trim((string)$_GET['batch_status']) : 'all',
        '',
        $filter_year_level
    );
    ob_start();
    eval_render_batch_panel($conn, 'pastoral', $formator_id, $filter_academic_year, $filter_semester, true, $filter_year_level);
    $batch_html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'success' => true,
        'year_level' => $filter_year_level,
        'year_level_label' => $filter_year_level !== '' ? $filter_year_level : 'All Year Levels',
        'seminarians' => $batch_data['rows'] ?? [],
        'batch_html' => $batch_html,
        'recorded_html' => $recorded_html,
    ]);
    exit;
}
?>
<?php
$pageTitle = 'Pastoral Evaluations';
$activeNav = 'pfe_evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0.25rem;"><i class="fas fa-clipboard-check"></i> Pastoral Formation Evaluations</h1>
                    <div id="evalYearLevelSubtitle" style="color: var(--text-secondary); font-weight: 800;"><?php echo $filter_year_level !== '' ? htmlspecialchars($filter_year_level) : 'All Year Levels'; ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <a href="pfe_evaluation_form.php" class="capstone-btn-primary" style="text-decoration: none;">
                        <i class="fas fa-plus"></i> New Evaluation
                    </a>
                    <a href="pfe_evaluations.php?archived=<?php echo $show_archived ? 0 : 1; ?><?php echo $filter_year_level !== '' ? '&year_level=' . htmlspecialchars(urlencode($filter_year_level)) : ''; ?>" class="capstone-btn-secondary" style="text-decoration: none;">
                        <?php echo $show_archived ? 'Show Active' : 'Show Archived'; ?>
                    </a>
                </div>
            </div>

            <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>

            <?php
            eval_render_batch_panel($conn, 'pastoral', $formator_id, $filter_academic_year, $filter_semester, true, $filter_year_level);
            ?>

            <div class="data-card">
                <div class="data-card-header">
                    <h3><i class="fas fa-table"></i> <?php echo $show_archived ? 'Archived' : 'Recorded'; ?> Evaluations</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Average</th>
                                <th>Interpretation</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="evalBody">
                            <?php echo $recorded_html; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
