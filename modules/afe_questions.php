<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator']);
include '../includes/db.php';

if (isset($_POST['update_question'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['question_id'] ?? 0);
    $category = trim((string)($_POST['category'] ?? ''));
    $text = trim((string)($_POST['question_text'] ?? ''));
    $order = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($id > 0 && $category !== '' && $text !== '') {
        $stmt = $conn->prepare("UPDATE academic_evaluation_questions SET category = ?, question_text = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssiii', $category, $text, $order, $active, $id);
        $stmt->execute();
        $success = 'Question updated.';
    } else {
        $error = 'Please provide category and question text.';
    }
}

$questions = [];
$res = $conn->query("SELECT id, category, question_text, sort_order, is_active FROM academic_evaluation_questions ORDER BY sort_order ASC, id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $questions[] = $row;
    }
}
?>
<?php
$pageTitle = 'Evaluation Questions';
$activeNav = 'afe_questions';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-list"></i> Academic Evaluation Questions</h1>
                <input type="text" id="qSearch" class="search-box" placeholder="Search question..." style="max-width: 320px;">
            </div>

            <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>

            <div class="data-card">
                <div class="data-card-header">
                    <h3><i class="fas fa-table"></i> Question Bank</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Category</th>
                                <th>Question</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q) { ?>
                                <tr class="q-row">
                                    <td class="q-order"><?php echo (int)$q['sort_order']; ?></td>
                                    <td class="q-category"><?php echo htmlspecialchars((string)$q['category']); ?></td>
                                    <td class="q-text"><?php echo htmlspecialchars((string)$q['question_text']); ?></td>
                                    <td><?php echo (int)$q['is_active'] === 1 ? "<span class='badge badge-success'>Active</span>" : "<span class='badge badge-warning'>Inactive</span>"; ?></td>
                                    <td style="white-space: nowrap;">
                                        <button type="button" class="editQBtn"
                                            data-q-id="<?php echo (int)$q['id']; ?>"
                                            data-q-order="<?php echo (int)$q['sort_order']; ?>"
                                            data-q-category="<?php echo htmlspecialchars((string)$q['category']); ?>"
                                            data-q-text="<?php echo htmlspecialchars((string)$q['question_text']); ?>"
                                            data-q-active="<?php echo (int)$q['is_active']; ?>"
                                            style="width: auto; padding: 0.4rem 0.6rem; background: var(--accent);">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="modal" id="editQModal" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editQTitle">
                <div class="modal-header">
                    <div class="modal-title" id="editQTitle"><i class="fas fa-pen"></i> Update Question</div>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="post" id="editQForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="question_id" id="editQId" value="">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                            <div class="field">
                                <label>Order</label>
                                <input type="number" name="sort_order" id="editQOrder" min="0" step="1" required>
                            </div>
                            <div class="field">
                                <label>Category</label>
                                <input type="text" name="category" id="editQCategory" required>
                            </div>
                            <div class="field" style="display: flex; align-items: center; gap: 0.75rem;">
                                <input type="checkbox" id="editQActive" name="is_active" value="1" style="width: 20px; height: 20px;">
                                <label for="editQActive" style="margin: 0;">Active</label>
                            </div>
                        </div>
                        <div class="field" style="margin-top: 1rem;">
                            <label>Question</label>
                            <textarea name="question_text" id="editQText" rows="4" required></textarea>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="modal-close" data-modal-close>Cancel</button>
                            <button type="submit" name="update_question">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.getElementById('qSearch').addEventListener('keyup', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.q-row').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        (function () {
            const modal = document.getElementById('editQModal');
            const idEl = document.getElementById('editQId');
            const orderEl = document.getElementById('editQOrder');
            const catEl = document.getElementById('editQCategory');
            const textEl = document.getElementById('editQText');
            const activeEl = document.getElementById('editQActive');
            if (!modal) return;

            function open(btn) {
                idEl.value = btn.getAttribute('data-q-id') || '';
                orderEl.value = btn.getAttribute('data-q-order') || '0';
                catEl.value = btn.getAttribute('data-q-category') || '';
                textEl.value = btn.getAttribute('data-q-text') || '';
                activeEl.checked = (btn.getAttribute('data-q-active') || '0') === '1';
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                catEl.focus();
            }
            function close() {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            }
            document.querySelectorAll('.editQBtn').forEach(function (b) {
                b.addEventListener('click', function () { open(b); });
            });
            modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
            modal.querySelectorAll('[data-modal-close]').forEach(function (b) { b.addEventListener('click', close); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>

