<?php
include '../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Cashier') {
    header('Location: ../index.php');
    exit;
}
include '../includes/db.php';
include '../includes/lodging.php';
$role = $_SESSION['role'];
$cashier_id = (int)($_SESSION['user'] ?? 0);

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') {
    $filter_academic_year = $current_ay;
}

$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM lodging_invoices WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM lodging_payments WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

if (isset($_POST['add_payment'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $seminarian_id = (int)($_POST['seminarian_id'] ?? 0);
    $payment_date = (string)($_POST['payment_date'] ?? date('Y-m-d'));
    $academic_year = trim((string)($_POST['academic_year'] ?? $current_ay));
    $amount = (float)($_POST['amount'] ?? 0);
    $method = 'cash';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $received_by = (int)$_SESSION['user'];

    if ($seminarian_id <= 0) {
        $error = 'Please select a seminarian.';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than 0.';
    } else {
        $stmt_as = $conn->prepare("SELECT 1 FROM seminarian_assignments WHERE seminarian_id = ? AND assignment_type = 'Cashier' AND assigned_user_id = ? AND active = 1 LIMIT 1");
        $stmt_as->bind_param('ii', $seminarian_id, $cashier_id);
        $stmt_as->execute();
        $as_ok = $stmt_as->get_result();
        if (!$as_ok || $as_ok->num_rows === 0) {
            $error = 'Seminarian is not assigned to you.';
        }
    }

    if (!isset($error)) {
        $notes_db = $notes !== '' ? $notes : null;
        $academic_year_db = $academic_year !== '' ? $academic_year : $current_ay;
        $stmt = $conn->prepare('INSERT INTO lodging_payments (seminarian_id, payment_date, academic_year, amount, method, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issdssi', $seminarian_id, $payment_date, $academic_year_db, $amount, $method, $notes_db, $received_by);
        $stmt->execute();
        $payment_id = (int)$stmt->insert_id;

        lodging_allocate_payment($conn, $payment_id, $seminarian_id, $amount);

        $stmt_student = $conn->prepare("SELECT u.username
            FROM seminarians s
            JOIN seminarian_assignments sa ON sa.seminarian_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
              AND sa.assignment_type = 'Cashier'
              AND sa.assigned_user_id = ?
              AND sa.active = 1
            LIMIT 1");
        $stmt_student->bind_param('ii', $seminarian_id, $cashier_id);
        $stmt_student->execute();
        $student_res = $stmt_student->get_result();
        if ($student_res) {
            $student_name = (string)($student_res->fetch_assoc()['username'] ?? '');
            if ($student_name !== '') {
                log_activity($_SESSION['user'], "Recorded lodging payment for {$student_name}");
            }
        }
        $success = 'Payment recorded and allocated to outstanding invoices.';
    }
}

$students = [];
$stmt_students = $conn->prepare("SELECT s.id, u.username
    FROM seminarians s
    JOIN seminarian_assignments sa ON sa.seminarian_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE sa.assignment_type = 'Cashier'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
    ORDER BY u.username");
$stmt_students->bind_param('i', $cashier_id);
$stmt_students->execute();
$students_res = $stmt_students->get_result();
while ($students_res && ($row = $students_res->fetch_assoc())) {
    $students[] = $row;
}

$filter_seminarian_id = isset($_GET['seminarian_id']) ? (int)$_GET['seminarian_id'] : 0;
$filter_from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$filter_to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

$where = '1=1';
$params = [];
$types = '';
$where .= " AND EXISTS (
    SELECT 1 FROM seminarian_assignments sa
    WHERE sa.seminarian_id = p.seminarian_id
      AND sa.assignment_type = 'Cashier'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
)";
$params[] = $cashier_id;
$types .= 'i';
if ($filter_seminarian_id > 0) {
    $where .= ' AND p.seminarian_id = ?';
    $params[] = $filter_seminarian_id;
    $types .= 'i';
}
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $where .= ' AND p.academic_year = ?';
    $params[] = $filter_academic_year;
    $types .= 's';
}
if ($filter_from !== '') {
    $where .= ' AND p.payment_date >= ?';
    $params[] = $filter_from;
    $types .= 's';
}
if ($filter_to !== '') {
    $where .= ' AND p.payment_date <= ?';
    $params[] = $filter_to;
    $types .= 's';
}

$sql = "SELECT p.*, u.username,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.payment_id = p.id) AS allocated_amount
        FROM lodging_payments p
        JOIN seminarians s ON p.seminarian_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE {$where}
        ORDER BY p.payment_date DESC, p.id DESC";
$stmt_list = $conn->prepare($sql);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind);
}
$stmt_list->execute();
$payments_res = $stmt_list->get_result();
?>
<?php
$pageTitle = 'Lodging Payments';
$activeNav = 'balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Lodging Payments</span>
            </nav>

            <?php if (isset($success)) echo "<p class='success'>{$success}</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>{$error}</p>"; ?>

            <div class="minimal-toolbar" style="margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0.25rem;"><i class="fas fa-money-bill-wave"></i> Lodging Payments</h1>
                    <div style="font-size: 0.8125rem; color: var(--text-secondary); font-weight: 600;">Record lodging collections and track account allocations</div>
                </div>
            </div>

            <?php if (isset($success)) echo "<div class='minimal-card' style='margin-bottom: 1rem; background: rgba(16, 185, 129, 0.05); color: #047857; padding: 0.75rem;'><i class='fas fa-circle-check'></i> {$success}</div>"; ?>
            <?php if (isset($error)) echo "<div class='minimal-card' style='margin-bottom: 1rem; background: rgba(239, 68, 68, 0.05); color: #dc2626; padding: 0.75rem;'><i class='fas fa-circle-exclamation'></i> {$error}</div>"; ?>

            <div class="minimal-card" style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.9375rem; font-weight: 700; margin-bottom: 0.875rem;"><i class="fas fa-plus-circle" style="color: #10b981;"></i> Record Lodging Payment</h3>
                <form method="post" id="addPaymentForm" style="margin-top: 0.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.875rem; margin-bottom: 0.875rem;">
                        <div class="field" style="margin: 0;">
                            <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Seminarian</label>
                            <div class="typeahead">
                                <input type="text" id="studentSearchPayment" class="search-box" placeholder="Search student..." autocomplete="off" required style="width: 100%;">
                                <input type="hidden" name="seminarian_id" id="studentIdPayment">
                                <div class="typeahead-list" id="studentListPayment" hidden></div>
                            </div>
                        </div>

                        <div class="field" style="margin: 0;">
                            <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Payment Date</label>
                            <input type="date" name="payment_date" class="search-box" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%;">
                        </div>

                        <div class="field" style="margin: 0;">
                            <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Academic Year</label>
                            <select name="academic_year" class="minimal-select" style="width: 100%;">
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="field" style="margin: 0;">
                            <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Amount (₱)</label>
                            <input type="number" id="paymentAmountInput" name="amount" min="0.01" step="0.01" class="search-box num-col" placeholder="0.00" required style="width: 100%; font-weight: 700; font-size: 1.125rem;">
                            <div style="display: flex; gap: 0.35rem; margin-top: 0.35rem; flex-wrap: wrap;">
                                <button type="button" class="quick-chip" onclick="document.getElementById('paymentAmountInput').value = '1000';">+1k</button>
                                <button type="button" class="quick-chip" onclick="document.getElementById('paymentAmountInput').value = '2500';">+2.5k</button>
                                <button type="button" class="quick-chip" onclick="document.getElementById('paymentAmountInput').value = '5000';">+5k</button>
                                <button type="button" class="quick-chip" onclick="document.getElementById('paymentAmountInput').value = '10000';">+10k</button>
                            </div>
                        </div>
                    </div>

                    <div class="field" style="margin: 0 0 1rem 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Notes (Optional)</label>
                        <input type="text" name="notes" class="search-box" placeholder="Optional remarks or receipt notes..." style="width: 100%;">
                    </div>

                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" name="add_payment" class="action-btn-primary">
                            <i class="fas fa-save"></i> Save Payment
                        </button>
                    </div>
                </form>
            </div>

            <div class="minimal-card" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h3 style="font-size: 0.875rem; font-weight: 700; margin: 0; text-transform: uppercase; color: var(--text-secondary);"><i class="fas fa-filter"></i> Filters</h3>
                    <a href="lodging_payments.php" class="action-btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.6rem;">Reset</a>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; align-items: flex-end;">
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Seminarian</label>
                        <div class="typeahead">
                            <input type="text" id="filterStudentSearch" class="search-box" placeholder="Search student..." autocomplete="off" value="<?php
                                $selected_name = '';
                                if ($filter_seminarian_id > 0) {
                                    foreach ($students as $s) {
                                        if ((int)$s['id'] === (int)$filter_seminarian_id) { $selected_name = $s['username']; break; }
                                    }
                                }
                                echo htmlspecialchars($selected_name);
                            ?>">
                            <input type="hidden" id="filterStudentId" value="<?php echo (int)$filter_seminarian_id; ?>">
                            <div class="typeahead-list" id="filterStudentList" hidden></div>
                        </div>
                    </div>
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">From</label>
                        <input type="date" id="filterFrom" class="search-box" value="<?php echo htmlspecialchars($filter_from); ?>" style="width: 100%;">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">To</label>
                        <input type="date" id="filterTo" class="search-box" value="<?php echo htmlspecialchars($filter_to); ?>" style="width: 100%;">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Academic Year</label>
                        <select id="filterAcademicYear" class="minimal-select" style="width: 100%;">
                            <option value="all" <?php echo $filter_academic_year === 'all' ? 'selected' : ''; ?>>All Terms</option>
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $filter_academic_year === $ay ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div style="display: none;">
                        <button type="button" id="applyFilters">Apply</button>
                    </div>
                </div>
            </div>

            <div class="minimal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.875rem; flex-wrap: wrap; gap: 0.75rem;">
                    <h3 style="font-size: 0.9375rem; font-weight: 700; margin: 0;"><i class="fas fa-receipt" style="color: var(--primary);"></i> Lodging Payments</h3>
                    <div class="search-container" style="max-width: 280px; width: 100%;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="paymentSearch" placeholder="Search payments...">
                        <span class="search-kbd">/</span>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="minimal-table" id="paymentTable">
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>AY</th>
                                <th>Date</th>
                                <th style="text-align: right;">Amount (₱)</th>
                                <th style="text-align: right;">Allocated (₱)</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($payments_res->num_rows === 0) { ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 1.5rem;">No payment records found.</td></tr>
                            <?php } ?>
                            <?php while ($row = $payments_res->fetch_assoc()) {
                                $amt = (float)($row['amount'] ?? 0);
                                $alloc = (float)($row['allocated_amount'] ?? 0);
                            ?>
                            <tr class="payment-row">
                                <td class="student-name"><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars((string)($row['academic_year'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($row['payment_date']); ?></td>
                                <td class="num-col" style="text-align: right; font-weight: 700; color: #10b981;"><?php echo htmlspecialchars(number_format($amt, 2)); ?></td>
                                <td class="num-col" style="text-align: right;"><?php echo htmlspecialchars(number_format($alloc, 2)); ?></td>
                                <td style="font-size: 0.8125rem; color: var(--text-secondary); max-width: 260px;"><?php echo htmlspecialchars((string)($row['notes'] ?? '—')); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <script>
    (function () {
        const students = <?php echo json_encode($students); ?>;

        function attachTypeahead(inputId, hiddenId, listId) {
            const input = document.getElementById(inputId);
            const hidden = document.getElementById(hiddenId);
            const list = document.getElementById(listId);
            if (!input || !hidden || !list) return;

            function renderList(items) {
                list.innerHTML = '';
                items.slice(0, 8).forEach(function (s) {
                    const div = document.createElement('div');
                    div.className = 'typeahead-item';
                    div.innerHTML = `<span>${s.username}</span><span class="typeahead-meta">#${s.id}</span>`;
                    div.addEventListener('click', function () {
                        input.value = s.username;
                        hidden.value = s.id;
                        hidden.dispatchEvent(new Event('change'));
                        list.hidden = true;
                    });
                    list.appendChild(div);
                });
                list.hidden = items.length === 0;
            }

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
                const q = input.value.trim().toLowerCase();
                if (!q) {
                    hidden.value = '';
                    hidden.dispatchEvent(new Event('change'));
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

        attachTypeahead('studentSearchPayment', 'studentIdPayment', 'studentListPayment');
        attachTypeahead('filterStudentSearch', 'filterStudentId', 'filterStudentList');
    })();

    (function () {
        const btn = document.getElementById('applyFilters');
        const sid = document.getElementById('filterStudentId');
        const from = document.getElementById('filterFrom');
        const to = document.getElementById('filterTo');
        const ay = document.getElementById('filterAcademicYear');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const params = new URLSearchParams();
            if (sid && sid.value) params.set('seminarian_id', String(sid.value));
            if (from && from.value) params.set('from', from.value);
            if (to && to.value) params.set('to', to.value);
            if (ay && ay.value) params.set('academic_year', ay.value);
            window.location.href = 'lodging_payments.php' + (params.toString() ? ('?' + params.toString()) : '');
        });

        // Zero-click auto-filtering on filter changes
        [ay, from, to, sid].forEach(el => {
            if (el) el.addEventListener('change', () => btn.click());
        });
    })();

    document.getElementById('paymentSearch').addEventListener('keyup', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.payment-row').forEach(function (row) {
            const student = (row.querySelector('.student-name')?.textContent || '').toLowerCase();
            const notes = row.children[5]?.textContent?.toLowerCase() || '';
            if (student.includes(q) || notes.includes(q)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
