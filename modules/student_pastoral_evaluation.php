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
$stmt_sem = $conn->prepare("SELECT id, pastoral_eval_view_enabled FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt_sem->bind_param('i', $user_id);
$stmt_sem->execute();
$sem = $stmt_sem->get_result()->fetch_assoc();
if (!$sem) {
    header('Location: ../index.php');
    exit;
}
$student_id = (int)$sem['id'];
$allowed = (int)($sem['pastoral_eval_view_enabled'] ?? 0) === 1;

$request_error = null;
$request_success = null;
$pending_request = null;
if (!$allowed) {
    if (isset($_POST['request_access'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed.');
        }
        $result = capstone_access_request_create($conn, $student_id, 'Pastoral', $user_id);
        if ($result['ok']) {
            $request_success = 'Request submitted. Please wait for your Pastoral Formator to enable access.';
        } else {
            $request_error = (string)($result['error'] ?? 'Failed to submit request.');
        }
    }
    $pending_request = capstone_access_request_get_pending($conn, $student_id, 'Pastoral');
}

$rows = null;
if ($allowed) {
    $stmt = $conn->prepare("SELECT e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
            r.average_rating, r.interpretation,
            a.growth_status, a.growth_percentage
        FROM pastoral_evaluations e
        LEFT JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id
        LEFT JOIN pastoral_analytics a ON a.evaluation_id = e.evaluation_id
        WHERE e.seminarian_id = ? AND e.archived_at IS NULL
        ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $rows = $stmt->get_result();
}
?>
<?php
$pageTitle = 'My Pastoral Evaluation';
$activeNav = 'my_pastoral_eval';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <h1 class="page-title"><i class="fas fa-hands-holding-circle"></i> My Pastoral Formation Evaluation</h1>

            <?php if (!$allowed) { ?>
                <div class="card" style="border-left: 6px solid #f59e0b; margin-top: 1.25rem;">
                    <h3><i class="fas fa-lock"></i> Not Authorized</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">Your Pastoral Formation Evaluation reports are not yet enabled for viewing.</div>
                    <?php if ($request_success) { ?>
                        <div style="margin-top: 0.75rem;" class="success"><?php echo htmlspecialchars($request_success); ?></div>
                    <?php } ?>
                    <?php if ($request_error) { ?>
                        <div style="margin-top: 0.75rem;" class="error"><?php echo htmlspecialchars($request_error); ?></div>
                    <?php } ?>
                    <form method="post" style="margin-top: 0.9rem; box-shadow: none; background: transparent; padding: 0; border: none;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="request_access" class="capstone-btn-primary" <?php echo $pending_request ? 'disabled' : ''; ?>>
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $pending_request ? 'Request Sent' : 'Request Access'; ?>
                        </button>
                        <?php if ($pending_request) { ?>
                            <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">
                                Pending since <?php echo htmlspecialchars(date('M d, Y', strtotime((string)$pending_request['requested_at']))); ?>
                            </div>
                        <?php } ?>
                    </form>
                </div>
            <?php } else { ?>
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
                                    <tr><td colspan="7" style="color: var(--text-secondary);">No pastoral evaluations yet.</td></tr>
                                <?php } ?>
                                <?php if ($rows) { while ($r = $rows->fetch_assoc()) {
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
                                            <a class="back-link" style="margin: 0;" href="pfe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>"><i class="fas fa-file-lines"></i> View</a>
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
