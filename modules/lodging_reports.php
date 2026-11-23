<?php
include '../includes/session_fix.php';
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'] ?? '', ['Cashier', 'Registrar', 'Dean', 'Rector'], true)) {
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

$students = [];
if ($role === 'Cashier') {
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
} else {
    $stmt_students = $conn->query("SELECT s.id, COALESCE(up.full_name, u.username) as username
        FROM seminarians s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        ORDER BY COALESCE(up.full_name, u.username) ASC");
    while ($stmt_students && ($row = $stmt_students->fetch_assoc())) {
        $students[] = $row;
    }
}

$filter_seminarian_id = isset($_GET['seminarian_id']) ? (int)$_GET['seminarian_id'] : 0;
$filter_from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$filter_to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

$from_date = $filter_from !== '' ? $filter_from : date('Y-m-01');
$to_date = $filter_to !== '' ? $filter_to : date('Y-m-d');

$payments_total = 0.0;
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $stmt_pay_sum = $conn->prepare('SELECT COALESCE(SUM(amount),0) as total FROM lodging_payments WHERE payment_date BETWEEN ? AND ? AND academic_year = ?');
    $stmt_pay_sum->bind_param('sss', $from_date, $to_date, $filter_academic_year);
} else {
    $stmt_pay_sum = $conn->prepare('SELECT COALESCE(SUM(amount),0) as total FROM lodging_payments WHERE payment_date BETWEEN ? AND ?');
    $stmt_pay_sum->bind_param('ss', $from_date, $to_date);
}
$stmt_pay_sum->execute();
$payments_total = (float)($stmt_pay_sum->get_result()->fetch_assoc()['total'] ?? 0);

$billed_total = 0.0;
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $stmt_inv_sum = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
    FROM lodging_invoices i
    JOIN lodging_invoice_items it ON it.invoice_id = i.id
    WHERE i.archived_at IS NULL
      AND i.status IN ('issued', 'partially_paid', 'paid')
      AND i.issue_date BETWEEN ? AND ?
      AND i.academic_year = ?");
    $stmt_inv_sum->bind_param('sss', $from_date, $to_date, $filter_academic_year);
} else {
    $stmt_inv_sum = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
    FROM lodging_invoices i
    JOIN lodging_invoice_items it ON it.invoice_id = i.id
    WHERE i.archived_at IS NULL
      AND i.status IN ('issued', 'partially_paid', 'paid')
      AND i.issue_date BETWEEN ? AND ?");
    $stmt_inv_sum->bind_param('ss', $from_date, $to_date);
}
$stmt_inv_sum->execute();
$billed_total = (float)($stmt_inv_sum->get_result()->fetch_assoc()['total'] ?? 0);

$outstanding_total = 0.0;
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $stmt_outstanding = $conn->prepare("SELECT COALESCE(SUM(t.total - t.paid),0) as total
    FROM (
        SELECT i.id,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid
        FROM lodging_invoices i
        WHERE i.archived_at IS NULL
          AND i.status IN ('issued', 'partially_paid')
          AND i.issue_date BETWEEN ? AND ?
          AND i.academic_year = ?
    ) t");
    $stmt_outstanding->bind_param('sss', $from_date, $to_date, $filter_academic_year);
} else {
    $stmt_outstanding = $conn->prepare("SELECT COALESCE(SUM(t.total - t.paid),0) as total
    FROM (
        SELECT i.id,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid
        FROM lodging_invoices i
        WHERE i.archived_at IS NULL
          AND i.status IN ('issued', 'partially_paid')
          AND i.issue_date BETWEEN ? AND ?
    ) t");
    $stmt_outstanding->bind_param('ss', $from_date, $to_date);
}
$stmt_outstanding->execute();
$outstanding_total = (float)($stmt_outstanding->get_result()->fetch_assoc()['total'] ?? 0);

$balances_res = null;
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $balances_sql = "SELECT s.id as seminarian_id, u.username,
            COALESCE((SELECT SUM(it.amount)
                FROM lodging_invoices i
                JOIN lodging_invoice_items it ON it.invoice_id = i.id
                WHERE i.seminarian_id = s.id
                  AND i.archived_at IS NULL
                  AND i.status IN ('issued', 'partially_paid', 'paid')
                  AND i.academic_year = ?
            ), 0) AS billed,
            COALESCE((SELECT SUM(pa.amount)
                FROM lodging_invoices i
                JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
                WHERE i.seminarian_id = s.id
                  AND i.archived_at IS NULL
                  AND i.academic_year = ?
            ), 0) AS paid
        FROM seminarians s
        JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Cashier' AND sa.assigned_user_id = ? AND sa.active = 1
        JOIN users u ON s.user_id = u.id
        ORDER BY u.username";
    $stmt_bal = $conn->prepare($balances_sql);
    $stmt_bal->bind_param('iss', $cashier_id, $filter_academic_year, $filter_academic_year);
    $stmt_bal->execute();
    $balances_res = $stmt_bal->get_result();
} else {
    $balances_sql = "SELECT s.id as seminarian_id, u.username,
            COALESCE((SELECT SUM(it.amount)
                FROM lodging_invoices i
                JOIN lodging_invoice_items it ON it.invoice_id = i.id
                WHERE i.seminarian_id = s.id
                  AND i.archived_at IS NULL
                  AND i.status IN ('issued', 'partially_paid', 'paid')
            ), 0) AS billed,
            COALESCE((SELECT SUM(pa.amount)
                FROM lodging_invoices i
                JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
                WHERE i.seminarian_id = s.id
                  AND i.archived_at IS NULL
            ), 0) AS paid
        FROM seminarians s
        JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Cashier' AND sa.assigned_user_id = ? AND sa.active = 1
        JOIN users u ON s.user_id = u.id
        ORDER BY u.username";
    $stmt_bal = $conn->prepare($balances_sql);
    $stmt_bal->bind_param('i', $cashier_id);
    $stmt_bal->execute();
    $balances_res = $stmt_bal->get_result();
}

$ledger_invoices = [];
$ledger_payments = [];
$ledger_name = '';
if ($filter_seminarian_id > 0) {
    $stmt_as = $conn->prepare("SELECT 1 FROM seminarian_assignments WHERE seminarian_id = ? AND assignment_type = 'Cashier' AND assigned_user_id = ? AND active = 1 LIMIT 1");
    $stmt_as->bind_param('ii', $filter_seminarian_id, $cashier_id);
    $stmt_as->execute();
    $as_ok = $stmt_as->get_result();
    if (!$as_ok || $as_ok->num_rows === 0) {
        $filter_seminarian_id = 0;
    }
}

if ($filter_seminarian_id > 0) {
    foreach ($students as $s) {
        if ((int)$s['id'] === (int)$filter_seminarian_id) {
            $ledger_name = (string)$s['username'];
            break;
        }
    }
    if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
        $stmt_inv = $conn->prepare("SELECT i.*, 
            (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total_amount,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid_amount
        FROM lodging_invoices i
        WHERE i.seminarian_id = ? AND i.archived_at IS NULL AND i.academic_year = ?
        ORDER BY i.issue_date DESC, i.id DESC");
        $stmt_inv->bind_param('is', $filter_seminarian_id, $filter_academic_year);
    } else {
        $stmt_inv = $conn->prepare("SELECT i.*, 
            (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total_amount,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid_amount
        FROM lodging_invoices i
        WHERE i.seminarian_id = ? AND i.archived_at IS NULL
        ORDER BY i.issue_date DESC, i.id DESC");
        $stmt_inv->bind_param('i', $filter_seminarian_id);
    }
    $stmt_inv->execute();
    $res = $stmt_inv->get_result();
    while ($row = $res->fetch_assoc()) {
        $ledger_invoices[] = $row;
    }

    if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
        $stmt_pay = $conn->prepare('SELECT * FROM lodging_payments WHERE seminarian_id = ? AND academic_year = ? ORDER BY payment_date DESC, id DESC');
        $stmt_pay->bind_param('is', $filter_seminarian_id, $filter_academic_year);
    } else {
        $stmt_pay = $conn->prepare('SELECT * FROM lodging_payments WHERE seminarian_id = ? ORDER BY payment_date DESC, id DESC');
        $stmt_pay->bind_param('i', $filter_seminarian_id);
    }
    $stmt_pay->execute();
    $res2 = $stmt_pay->get_result();
    while ($row = $res2->fetch_assoc()) {
        $ledger_payments[] = $row;
    }
}
?>

<?php
$pageTitle = 'Lodging Reports';
$activeNav = 'balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Lodging Reports</span>
            </nav>

            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap;">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fas fa-chart-pie"></i> Lodging Reports</h1>
                <button type="button" onclick="window.print()" style="width: auto;"><i class="fas fa-print"></i> Print</button>
            </div>

            <div class="card" style="margin: 1.25rem 0 2rem;">
                <h3><i class="fas fa-filter"></i> Report Filters</h3>
                <div style="display: grid; grid-template-columns: 1.3fr 1fr 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div class="field" style="margin: 0;">
                        <label>Seminarian (optional)</label>
                        <div class="typeahead">
                            <input type="text" id="filterStudentSearch" placeholder="Search student..." autocomplete="off" value="<?php
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
                        <label>From</label>
                        <input type="date" id="filterFrom" value="<?php echo htmlspecialchars($from_date); ?>">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label>To</label>
                        <input type="date" id="filterTo" value="<?php echo htmlspecialchars($to_date); ?>">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label>Academic Year</label>
                        <select id="filterAcademicYear">
                            <option value="all" <?php echo $filter_academic_year === 'all' ? 'selected' : ''; ?>>All</option>
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $filter_academic_year === $ay ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" id="applyFilters" style="width: auto;">Apply</button>
                    <a href="lodging_reports.php" class="back-link" style="margin: 0;">Clear</a>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="color: #3b82f6;"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="stat-value"><?php echo htmlspecialchars(number_format($billed_total, 2)); ?></div>
                    <div class="stat-label">Billed (Range)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color: #10b981;"><i class="fas fa-hand-holding-dollar"></i></div>
                    <div class="stat-value"><?php echo htmlspecialchars(number_format($payments_total, 2)); ?></div>
                    <div class="stat-label">Collected (Range)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color: #f59e0b;"><i class="fas fa-scale-balanced"></i></div>
                    <div class="stat-value"><?php echo htmlspecialchars(number_format($outstanding_total, 2)); ?></div>
                    <div class="stat-label">Outstanding (Range)</div>
                </div>
            </div>

            <div class="data-card" style="margin-bottom: 2rem;">
                <div class="data-card-header">
                    <h3><i class="fas fa-scale-balanced"></i> Balances</h3>
                    <input type="text" id="balanceSearch" class="search-box" placeholder="Search students...">
                </div>
                <div style="overflow-x: auto;">
                    <table id="balanceTable">
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Total Billed</th>
                                <th>Total Paid</th>
                                <th>Balance</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($balances_res) { while ($row = $balances_res->fetch_assoc()) {
                                $billed = (float)($row['billed'] ?? 0);
                                $paid = (float)($row['paid'] ?? 0);
                                $balance = $billed - $paid;
                            ?>
                            <tr class="balance-row">
                                <td class="student-name"><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><span class="badge badge-primary"><?php echo htmlspecialchars(number_format($billed, 2)); ?></span></td>
                                <td><span class="badge badge-primary"><?php echo htmlspecialchars(number_format($paid, 2)); ?></span></td>
                                <td><span class="badge <?php echo $balance <= 0.00001 ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars(number_format(max(0, $balance), 2)); ?></span></td>
                                <td>
                                    <a href="lodging_reports.php?seminarian_id=<?php echo (int)$row['seminarian_id']; ?>&from=<?php echo htmlspecialchars($from_date); ?>&to=<?php echo htmlspecialchars($to_date); ?>&academic_year=<?php echo htmlspecialchars($filter_academic_year); ?>" style="text-decoration: none;">
                                        <span class="badge badge-primary">View Ledger</span>
                                    </a>
                                </td>
                            </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($filter_seminarian_id > 0) { ?>
            <div class="card" style="margin-bottom: 2rem;">
                <h3><i class="fas fa-user"></i> Ledger: <?php echo htmlspecialchars($ledger_name); ?></h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem; align-items: start;">
                    <div>
                        <h4 style="margin-bottom: 0.75rem; color: #0f172a;">Invoices</h4>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Academic Year</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Total</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ledger_invoices as $inv) {
                                        $total = (float)($inv['total_amount'] ?? 0);
                                        $paid = (float)($inv['paid_amount'] ?? 0);
                                        $bal = $total - $paid;
                                        $status = (string)($inv['status'] ?? '');
                                        $status_class = 'badge-primary';
                                        if ($status === 'paid') $status_class = 'badge-success';
                                        if ($status === 'void') $status_class = 'badge-warning';
                                        if ($status === 'draft') $status_class = 'badge-warning';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($inv['invoice_no']); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)($inv['academic_year'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($inv['issue_date']); ?></td>
                                        <td><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars(strtoupper($status)); ?></span></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars(number_format($total, 2)); ?></span></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars(number_format($paid, 2)); ?></span></td>
                                        <td><span class="badge <?php echo $bal <= 0.00001 ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars(number_format(max(0, $bal), 2)); ?></span></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h4 style="margin-bottom: 0.75rem; color: #0f172a;">Payments</h4>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Academic Year</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ledger_payments as $pay) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pay['payment_date']); ?></td>
                                        <td><?php echo htmlspecialchars((string)($pay['academic_year'] ?? '')); ?></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars(number_format((float)$pay['amount'], 2)); ?></span></td>
                                        <td><?php echo htmlspecialchars(strtoupper((string)$pay['method'])); ?></td>
                                        <td><?php echo htmlspecialchars((string)($pay['reference_no'] ?? '')); ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>
    <script>
    (function () {
        const students = <?php echo json_encode($students); ?>;
        const input = document.getElementById('filterStudentSearch');
        const hidden = document.getElementById('filterStudentId');
        const list = document.getElementById('filterStudentList');
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
                    list.hidden = true;
                });
                list.appendChild(div);
            });
            list.hidden = items.length === 0;
        }

        input.addEventListener('input', function () {
            const q = input.value.trim().toLowerCase();
            if (!q) {
                hidden.value = '';
                list.hidden = true;
                list.innerHTML = '';
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
            window.location.href = 'lodging_reports.php' + (params.toString() ? ('?' + params.toString()) : '');
        });
    })();

    document.getElementById('balanceSearch').addEventListener('keyup', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.balance-row').forEach(function (row) {
            const student = (row.querySelector('.student-name')?.textContent || '').toLowerCase();
            if (student.includes(q)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
