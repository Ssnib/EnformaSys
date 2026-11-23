<?php
include '../includes/session_fix.php';
require_roles(['Registrar', 'Dean']);
include '../includes/db.php';

$show_archived = isset($_GET['archived']) && $_GET['archived'] === '1';

if (isset($_POST['archive_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $evaluation_id = (int)($_POST['evaluation_id'] ?? 0);
    if ($evaluation_id > 0) {
        $stmt = $conn->prepare("UPDATE evaluations SET archived_at = NOW() WHERE id = ? AND type = 'Spiritual' AND archived_at IS NULL");
        $stmt->bind_param('i', $evaluation_id);
        $stmt->execute();
        log_activity($_SESSION['user'], "Archived spiritual evaluation");
        $success = "Evaluation archived successfully.";
    }
}

if (isset($_POST['restore_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $evaluation_id = (int)($_POST['evaluation_id'] ?? 0);
    if ($evaluation_id > 0) {
        $stmt = $conn->prepare("UPDATE evaluations SET archived_at = NULL WHERE id = ? AND type = 'Spiritual' AND archived_at IS NOT NULL");
        $stmt->bind_param('i', $evaluation_id);
        $stmt->execute();
        log_activity($_SESSION['user'], "Restored spiritual evaluation");
        $success = "Evaluation restored successfully.";
    }
}

if (isset($_POST['update_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $evaluation_id = (int)($_POST['evaluation_id'] ?? 0);
    $score = (float)($_POST['score'] ?? 0);
    $comments = $_POST['comments'] ?? '';
    $date = $_POST['date'] ?? '';

    if ($evaluation_id > 0) {
        $stmt = $conn->prepare("UPDATE evaluations SET score = ?, comments = ?, date = ? WHERE id = ? AND type = 'Spiritual' AND archived_at IS NULL");
        $stmt->bind_param('dssi', $score, $comments, $date, $evaluation_id);
        $stmt->execute();
        log_activity($_SESSION['user'], "Updated spiritual evaluation");
        $success = "Evaluation updated successfully.";
    }
}

if (isset($_POST['add_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $seminarian_id = $_POST['seminarian_id'];
    $score = $_POST['score'];
    $comments = $_POST['comments'];
    $date = $_POST['date'];

    $stmt = $conn->prepare("INSERT INTO evaluations (seminarian_id, type, score, comments, date) VALUES (?, 'Spiritual', ?, ?, ?)");
    $stmt->bind_param("idss", $seminarian_id, $score, $comments, $date);
    $stmt->execute();
    
    // Log Activity
    $student_res = $conn->query("SELECT u.username FROM seminarians s JOIN users u ON s.user_id = u.id WHERE s.id = $seminarian_id");
    $student_name = $student_res->fetch_assoc()['username'];
    log_activity($_SESSION['user'], "Added spiritual evaluation for $student_name");
    
    $success = "Evaluation added successfully.";
}

$eval_sql = $show_archived
    ? "SELECT e.*, u.username FROM evaluations e JOIN seminarians s ON e.seminarian_id = s.id JOIN users u ON s.user_id = u.id WHERE e.type = 'Spiritual' AND e.archived_at IS NOT NULL ORDER BY e.date DESC, e.id DESC"
    : "SELECT e.*, u.username FROM evaluations e JOIN seminarians s ON e.seminarian_id = s.id JOIN users u ON s.user_id = u.id WHERE e.type = 'Spiritual' AND e.archived_at IS NULL ORDER BY e.date DESC, e.id DESC";
$result = $conn->query($eval_sql);
$students = [];
$students_res = $conn->query("SELECT s.id, u.username FROM seminarians s JOIN users u ON s.user_id = u.id ORDER BY u.username");
if ($students_res) {
    while ($row = $students_res->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<?php
$pageTitle = 'Spiritual Formation';
$activeNav = 'formation';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <a href="formation.php" style="color: var(--primary); text-decoration: none;">Formation</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Spiritual</span>
            </nav>
            <div style="margin-bottom: 2rem;">
                <a href="formation.php" class="back-link">Back to Progress Categories</a>
                <h1 class="page-title"><i class="fas fa-praying-hands"></i> Spiritual Formation</h1>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
                <?php if (!$show_archived) { ?>
                    <button type="button" id="openSpiritualModal" style="width: auto; padding: 0.6rem 1rem;">
                        <i class="fas fa-plus"></i> Add Evaluation
                    </button>
                <?php } ?>
            </div>

            <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>

            <div class="data-card" style="margin-bottom: 2rem;">
                <div class="data-card-header">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; width: 100%;">
                        <h3 style="margin: 0;"><i class="fas fa-history"></i> <?php echo $show_archived ? 'Archived Spiritual Evaluations' : 'Spiritual Evaluation History'; ?></h3>
                        <a href="spiritual.php?archived=<?php echo $show_archived ? 0 : 1; ?>" class="back-link" style="margin: 0; white-space: nowrap;">
                            <?php echo $show_archived ? 'Show Active' : 'Show Archived'; ?>
                        </a>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Performance</th>
                                <th>Comments</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()) { 
                                $class = $row['score'] < 3.0 ? 'low' : '';
                            ?>
                            <tr class="<?php echo $class; ?>">
                                <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo render_rating_badge($row['score']); ?></td>
                                <td style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['comments']); ?></td>
                                <td style="color: var(--text-secondary);"><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if (!$show_archived) { ?>
                                        <button type="button" class="editEvaluationBtn"
                                            data-evaluation-id="<?php echo (int)$row['id']; ?>"
                                            data-evaluation-student="<?php echo htmlspecialchars($row['username']); ?>"
                                            data-evaluation-score="<?php echo htmlspecialchars((string)$row['score']); ?>"
                                            data-evaluation-comments="<?php echo htmlspecialchars($row['comments']); ?>"
                                            data-evaluation-date="<?php echo htmlspecialchars($row['date']); ?>"
                                            style="width: auto; padding: 0.4rem 0.6rem; background: var(--accent);">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    <?php } ?>
                                    <?php if ($show_archived) { ?>
                                        <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="evaluation_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="restore_evaluation" style="width: auto; padding: 0.4rem 0.6rem; background: var(--accent);" onclick="return confirm('Restore this evaluation?');">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="evaluation_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="archive_evaluation" style="width: auto; padding: 0.4rem 0.6rem; background: var(--danger);" onclick="return confirm('Archive this evaluation?');">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        </form>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal" id="spiritualModal" aria-hidden="true">
                <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="spiritualModalTitle">
                    <div class="modal-header">
                        <div class="modal-title" id="spiritualModalTitle"><i class="fas fa-plus-circle"></i> Add Spiritual Evaluation</div>
                        <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="field">
                                <label>Seminarian</label>
                                <div class="typeahead">
                                    <input type="text" id="studentSearchSpiritual" placeholder="Search student..." autocomplete="off" required>
                                    <input type="hidden" name="seminarian_id" id="studentIdSpiritual">
                                    <div class="typeahead-list" id="studentListSpiritual" hidden></div>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="field">
                                    <label>Performance Rating (1 - 5)</label>
                                    <select name="score" required>
                                        <option value="1">1 - Deficient</option>
                                        <option value="2">2 - Needs Growth</option>
                                        <option value="3" selected>3 - Solidly Developing</option>
                                        <option value="4">4 - Often Demonstrated</option>
                                        <option value="5">5 - Consistently Demonstrated</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Date</label>
                                    <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="field">
                                <label>Spiritual Director's Comments</label>
                                <textarea name="comments" rows="3" placeholder="Enter spiritual observations..."></textarea>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="modal-close" data-modal-close>Cancel</button>
                                <button type="submit" name="add_evaluation">Record Evaluation</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal" id="editSpiritualModal" aria-hidden="true">
                <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editSpiritualTitle">
                    <div class="modal-header">
                        <div class="modal-title" id="editSpiritualTitle"><i class="fas fa-pen"></i> Update Spiritual Evaluation</div>
                        <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" id="editSpiritualForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="evaluation_id" id="editSpiritualId" value="">

                            <div class="field">
                                <label>Seminarian</label>
                                <input type="text" id="editSpiritualStudent" disabled>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="field">
                                    <label>Performance Rating (1 - 5)</label>
                                    <select name="score" id="editSpiritualScore" required>
                                        <option value="1">1 - Deficient</option>
                                        <option value="2">2 - Needs Growth</option>
                                        <option value="3">3 - Solidly Developing</option>
                                        <option value="4">4 - Often Demonstrated</option>
                                        <option value="5">5 - Consistently Demonstrated</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Date</label>
                                    <input type="date" name="date" id="editSpiritualDate" required>
                                </div>
                            </div>

                            <div class="field">
                                <label>Spiritual Director's Comments</label>
                                <textarea name="comments" id="editSpiritualComments" rows="3"></textarea>
                            </div>

                            <div class="modal-actions">
                                <button type="button" class="modal-close" data-modal-close>Cancel</button>
                                <button type="submit" name="update_evaluation">Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <script>
    (function () {
        const modal = document.getElementById('spiritualModal');
        const openBtn = document.getElementById('openSpiritualModal');
        if (!modal || !openBtn) return;

        const students = <?php echo json_encode($students); ?>;
        const input = document.getElementById('studentSearchSpiritual');
        const hidden = document.getElementById('studentIdSpiritual');
        const list = document.getElementById('studentListSpiritual');
        const form = modal.querySelector('form');

        function renderList(items) {
            list.innerHTML = '';
            items.slice(0, 8).forEach(function (s) {
                const div = document.createElement('div');
                div.className = 'typeahead-item';
                div.innerHTML = `<span>${s.username}</span><span class="typeahead-meta">#${s.id}</span>`;
                div.addEventListener('click', function () {
                    input.value = s.username;
                    hidden.value = s.id;
                    list.hidden = true;
                });
                list.appendChild(div);
            });
            list.hidden = items.length === 0;
        }

        if (input && hidden && list) {
            function showAll() {
                const q = input.value.trim().toLowerCase();
                if (!q) {
                    renderList(students);
                } else {
                    const matches = students.filter(s => (s.username || '').toLowerCase().includes(q));
                    renderList(matches);
                }
            }

            input.addEventListener('focus', showAll);
            input.addEventListener('click', showAll);

            input.addEventListener('input', function () {
                hidden.value = '';
                const q = input.value.trim().toLowerCase();
                if (!q) {
                    renderList(students);
                    return;
                }
                const matches = students.filter(s => (s.username || '').toLowerCase().includes(q));
                renderList(matches);
            });
            document.addEventListener('click', function (e) {
                if (!list || list.hidden) return;
                if (e.target === input || list.contains(e.target)) return;
                list.hidden = true;
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                if (hidden && !hidden.value) {
                    e.preventDefault();
                    if (input) {
                        input.focus();
                        input.setCustomValidity('Please select a student from the list.');
                        input.reportValidity();
                        setTimeout(() => input.setCustomValidity(''), 0);
                    }
                }
            });
        }

        function openModal() {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            const first = modal.querySelector('select, input, textarea, button');
            if (first) first.focus();
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            openBtn.focus();
        }

        openBtn.addEventListener('click', openModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });
    })();

    (function () {
        const modal = document.getElementById('editSpiritualModal');
        if (!modal) return;

        const idEl = document.getElementById('editSpiritualId');
        const studentEl = document.getElementById('editSpiritualStudent');
        const scoreEl = document.getElementById('editSpiritualScore');
        const dateEl = document.getElementById('editSpiritualDate');
        const commentsEl = document.getElementById('editSpiritualComments');

        function openModal(btn) {
            idEl.value = btn.getAttribute('data-evaluation-id') || '';
            studentEl.value = btn.getAttribute('data-evaluation-student') || '';
            scoreEl.value = btn.getAttribute('data-evaluation-score') || '3';
            dateEl.value = btn.getAttribute('data-evaluation-date') || '';
            commentsEl.value = btn.getAttribute('data-evaluation-comments') || '';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            scoreEl.focus();
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.editEvaluationBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn);
            });
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });
    })();
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
