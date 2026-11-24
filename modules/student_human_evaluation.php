<?php
include '../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
include '../includes/db.php';
include '../includes/access_requests.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$user_id = (int)$_SESSION['user'];
$stmt_sem = $conn->prepare("SELECT id, human_eval_view_enabled FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt_sem->bind_param('i', $user_id);
$stmt_sem->execute();
$sem = $stmt_sem->get_result()->fetch_assoc();
if (!$sem) {
    header('Location: ../index.php');
    exit;
}
$student_id = (int)$sem['id'];
$active_term_meta = capstone_access_resolve_active_term($conn);
$filter_ay = isset($_GET['academic_year']) && trim((string)$_GET['academic_year']) !== ''
    ? trim((string)$_GET['academic_year'])
    : $active_term_meta['academic_year'];
$filter_sem_raw = isset($_GET['semester']) ? (string)$_GET['semester'] : '';
$filter_sem = capstone_access_normalize_semester($filter_sem_raw);
if (!in_array($filter_sem, ['1st', '2nd', 'Summer'], true)) {
    $filter_sem = $active_term_meta['semester'];
}
if (isset($_POST['request_access'])) {
    $postAy = trim((string)($_POST['academic_year'] ?? ''));
    $postSem = capstone_access_normalize_semester((string)($_POST['semester'] ?? ''));
    if ($postAy !== '') $filter_ay = $postAy;
    if (in_array($postSem, ['1st', '2nd', 'Summer'], true)) $filter_sem = $postSem;
}

$allowed = capstone_access_can_view_evaluation($conn, $student_id, 'Human', $filter_ay, $filter_sem);
$approved_terms = capstone_access_get_approved_eval_terms($conn, $student_id, 'Human');

$request_error = null;
$request_success = null;
$pending_request = null;
if (!$allowed) {
    if (isset($_POST['request_access'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed.');
        }
        $result = capstone_access_request_create($conn, $student_id, 'Human', $user_id, null, 'whole', null, $filter_ay, $filter_sem);
        if ($result['ok']) {
            $request_success = "Request submitted for AY {$filter_ay} ({$filter_sem} Semester). Please wait for your Human Formator to enable access.";
        } else {
            $request_error = (string)($result['error'] ?? 'Failed to submit request.');
        }
    }
    $pending_request = capstone_access_request_get_pending($conn, $student_id, 'Human', $filter_ay, $filter_sem);
}

$ay_options = [$active_term_meta['academic_year']];
$stmt_ay = $conn->prepare("SELECT DISTINCT academic_year FROM human_evaluations WHERE seminarian_id = ? AND archived_at IS NULL AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
if ($stmt_ay) {
    $stmt_ay->bind_param('i', $student_id);
    if ($stmt_ay->execute()) {
        $ar = $stmt_ay->get_result();
        while ($ar && ($rowAy = $ar->fetch_assoc())) {
            $v = trim((string)$rowAy['academic_year']);
            if ($v !== '' && !in_array($v, $ay_options, true)) $ay_options[] = $v;
        }
    }
    $stmt_ay->close();
}

$rows = null;
if (!empty($approved_terms)) {
    $stmt = $conn->prepare("SELECT e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
            r.average_rating, r.interpretation,
            a.growth_status, a.growth_percentage
        FROM human_evaluations e
        LEFT JOIN human_results r ON r.evaluation_id = e.evaluation_id
        LEFT JOIN human_analytics a ON a.evaluation_id = e.evaluation_id
        WHERE e.seminarian_id = ? AND e.archived_at IS NULL
        ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $rows = $stmt->get_result();
}
?>
<?php
$pageTitle = 'My Human Evaluation';
$activeNav = 'my_human_eval';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;">
                <h1 class="page-title" style="margin:0;"><i class="fas fa-user-check"></i> My Human Formation Evaluation</h1>
                <form method="get" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin:0;padding:0;background:transparent;border:none;box-shadow:none;">
                    <select name="academic_year" style="padding:0.4rem 0.75rem;border-radius:10px;border:1px solid #d1d5db;">
                        <?php foreach ($ay_options as $ayOpt) { ?>
                            <option value="<?php echo htmlspecialchars($ayOpt); ?>" <?php echo $ayOpt === $filter_ay ? 'selected' : ''; ?>><?php echo htmlspecialchars($ayOpt); ?></option>
                        <?php } ?>
                    </select>
                    <select name="semester" style="padding:0.4rem 0.75rem;border-radius:10px;border:1px solid #d1d5db;">
                        <?php foreach (['1st', '2nd', 'Summer'] as $semOpt) { ?>
                            <option value="<?php echo $semOpt; ?>" <?php echo $semOpt === $filter_sem ? 'selected' : ''; ?>><?php echo $semOpt; ?> Sem</option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="capstone-btn-primary" style="width:auto;padding:0.45rem 0.9rem;">Filter</button>
                </form>
            </div>

            <?php if (!$allowed) { ?>
                <div class="card" style="border-left: 6px solid #f59e0b; margin-top: 1.25rem;">
                    <h3><i class="fas fa-lock"></i> Access Locked (AY <?php echo htmlspecialchars($filter_ay . ' · ' . $filter_sem . ' Semester'); ?>)</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">Your Human Formation Evaluation reports for AY <?php echo htmlspecialchars($filter_ay . ' (' . $filter_sem . ' Semester)'); ?> are locked until approved by your Human Formator.</div>
                    <?php if ($request_success) { ?>
                        <div style="margin-top: 0.75rem;" class="success"><?php echo htmlspecialchars($request_success); ?></div>
                    <?php } ?>
                    <?php if ($request_error) { ?>
                        <div style="margin-top: 0.75rem;" class="error"><?php echo htmlspecialchars($request_error); ?></div>
                    <?php } ?>
                    <form method="post" style="margin-top: 0.9rem; box-shadow: none; background: transparent; padding: 0; border: none;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_ay); ?>">
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_sem); ?>">
                        <button type="submit" name="request_access" class="capstone-btn-primary" <?php echo $pending_request ? 'disabled' : ''; ?>>
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $pending_request ? "Request Sent ({$filter_sem} Sem)" : "Request Evaluation Access ({$filter_sem} Semester)"; ?>
                        </button>
                        <?php if ($pending_request) { ?>
                            <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">
                                Pending since <?php echo htmlspecialchars(date('M d, Y', strtotime((string)$pending_request['requested_at']))); ?>
                            </div>
                        <?php } ?>
                    </form>
                </div>
            <?php } ?>

            <?php if (!empty($approved_terms)) { ?>
                <div class="data-card" style="margin-top: 1.25rem;">
                    <div class="data-card-header">
                        <h3><i class="fas fa-list"></i> Evaluation History</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Average</th>
                                    <th>Interpretation</th>
                                    <th>Growth</th>
                                    <th>Date</th>
                                    <th>Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows && $rows->num_rows === 0) { ?>
                                    <tr><td colspan="7" style="color: var(--text-secondary);">No human evaluations yet.</td></tr>
                                <?php } ?>
                                <?php if ($rows) { while ($r = $rows->fetch_assoc()) {
                                    $rowTermKey = trim((string)$r['academic_year']) . '|' . capstone_access_normalize_semester((string)$r['semester']);
                                    if (empty($approved_terms[$rowTermKey])) continue;
                                    $row_interp = getFormationInterpretation($r['average_rating'] ?? null);
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$r['academic_year']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$r['semester']); ?></td>
                                        <td><?php echo $r['average_rating'] === null ? '-' : htmlspecialchars(number_format((float)$r['average_rating'], 2)); ?></td>
                                        <td><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo htmlspecialchars((string)$row_interp['badge_class']); ?>"><?php echo htmlspecialchars((string)$row_interp['label']); ?></span></td>
                                        <td><?php echo (string)($r['growth_status'] ?? '') !== '' ? htmlspecialchars((string)$r['growth_status']) : '-'; ?></td>
                                        <td style="color: var(--text-secondary);"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
                                        <td style="white-space: nowrap;">
                                            <a class="back-link" style="margin: 0;" href="hfe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>"><i class="fas fa-file-lines"></i> View</a>
                                        </td>
                                    </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
