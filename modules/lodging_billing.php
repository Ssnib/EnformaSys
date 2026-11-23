<?php
require_once '../includes/session_fix.php';
require_roles(['Dean', 'Rector', 'Admin']);
require_once '../includes/db.php';
require_once '../includes/lodging.php';
$role = (string)($_SESSION['role'] ?? '');
$can_manage_bills = in_array($role, ['Dean', 'Rector', 'Admin'], true);
$cashier_id = (int)($_SESSION['user'] ?? 0);

$filter_seminarian_id = isset($_GET['seminarian_id']) ? (int)$_GET['seminarian_id'] : 0;
$filter_status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$filter_from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$filter_to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
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

if (isset($_POST['create_invoice'])) {
    if (!$can_manage_bills) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $seminarian_id = (int)($_POST['seminarian_id'] ?? 0);
    $issue_date = (string)($_POST['issue_date'] ?? date('Y-m-d'));
    $due_date = (string)($_POST['due_date'] ?? '');
    $period_start = (string)($_POST['period_start'] ?? '');
    $period_end = (string)($_POST['period_end'] ?? '');
    $academic_year = trim((string)($_POST['academic_year'] ?? $current_ay));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $status = (string)($_POST['status'] ?? 'issued');
    $notes = (string)($_POST['notes'] ?? '');
    $description = trim((string)($_POST['description'] ?? 'Lodging fee'));
    $quantity = (float)($_POST['quantity'] ?? 1);
    $unit_price = (float)($_POST['unit_price'] ?? 0);

    if (!in_array($status, ['draft', 'issued'])) {
        $status = 'issued';
    }
    if ($seminarian_id <= 0) {
        $error = 'Please select a seminarian.';
    } elseif ($description === '') {
        $error = 'Description is required.';
    } elseif ($quantity <= 0) {
        $error = 'Quantity must be greater than 0.';
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
        $amount = round($quantity * $unit_price, 2);
        $invoice_no = lodging_generate_invoice_no($conn);
        $created_by = (int)$_SESSION['user'];
        $due_date_db = $due_date !== '' ? $due_date : null;
        $period_start_db = $period_start !== '' ? $period_start : null;
        $period_end_db = $period_end !== '' ? $period_end : null;
        $notes_db = $notes !== '' ? $notes : null;
        $academic_year_db = $academic_year !== '' ? $academic_year : $current_ay;
        $semester_db = in_array($semester, ['1st', '2nd'], true) ? $semester : null;

        $stmt = $conn->prepare('INSERT INTO lodging_invoices (seminarian_id, invoice_no, issue_date, due_date, period_start, period_end, academic_year, semester, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssssssi', $seminarian_id, $invoice_no, $issue_date, $due_date_db, $period_start_db, $period_end_db, $academic_year_db, $semester_db, $status, $notes_db, $created_by);
        $stmt->execute();
        $invoice_id = (int)$stmt->insert_id;

        $stmt_item = $conn->prepare('INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?)');
        $stmt_item->bind_param('isddd', $invoice_id, $description, $quantity, $unit_price, $amount);
        $stmt_item->execute();

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
                log_activity($_SESSION['user'], "Created lodging invoice {$invoice_no} for {$student_name}");
            }
        }
        $success = 'Invoice created successfully.';
    }
}

if (isset($_POST['void_invoice'])) {
    if (!$can_manage_bills) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if ($invoice_id > 0) {
        $stmt = $conn->prepare("UPDATE lodging_invoices SET status = 'void' WHERE id = ?");
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $success = 'Invoice voided.';
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

$where = 'i.archived_at IS NULL';
$params = [];
$types = '';
$where .= " AND EXISTS (
    SELECT 1 FROM seminarian_assignments sa
    WHERE sa.seminarian_id = i.seminarian_id
      AND sa.assignment_type = 'Cashier'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
)";
$params[] = $cashier_id;
$types .= 'i';
if ($filter_seminarian_id > 0) {
    $where .= ' AND i.seminarian_id = ?';
    $params[] = $filter_seminarian_id;
    $types .= 'i';
}
if ($filter_status !== '' && in_array($filter_status, ['draft', 'issued', 'paid', 'void'])) {
    $where .= ' AND i.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_academic_year !== '' && $filter_academic_year !== 'all') {
    $where .= ' AND i.academic_year = ?';
    $params[] = $filter_academic_year;
    $types .= 's';
}
if ($filter_from !== '') {
    $where .= ' AND i.issue_date >= ?';
    $params[] = $filter_from;
    $types .= 's';
}
if ($filter_to !== '') {
    $where .= ' AND i.issue_date <= ?';
    $params[] = $filter_to;
    $types .= 's';
}

$sql = "SELECT i.*, u.username,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total_amount,
            (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid_amount
        FROM lodging_invoices i
        JOIN seminarians s ON i.seminarian_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE {$where}
        ORDER BY i.issue_date DESC, i.id DESC";
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
$invoices_res = $stmt_list->get_result();
?>
<?php
$pageTitle = 'Lodging Billing';
$activeNav = 'balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Lodging Billing</span>
            </nav>

            <div class="minimal-toolbar" style="margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0.25rem;"><i class="fas fa-file-invoice-dollar"></i> Lodging Billing</h1>
                    <div style="font-size: 0.8125rem; color: var(--text-secondary); font-weight: 600;">Manage invoices and accommodation charges</div>
                </div>
                <div>
                    <button type="button" id="openCreateInvoice" class="action-btn-primary">
                        <i class="fas fa-plus"></i> Create Invoice
                    </button>
                </div>
            </div>

            <?php if (isset($success)) echo "<div class='minimal-card' style='margin-bottom: 1rem; background: rgba(16, 185, 129, 0.05); color: #047857; padding: 0.75rem;'><i class='fas fa-circle-check'></i> {$success}</div>"; ?>
            <?php if (isset($error)) echo "<div class='minimal-card' style='margin-bottom: 1rem; background: rgba(239, 68, 68, 0.05); color: #dc2626; padding: 0.75rem;'><i class='fas fa-circle-exclamation'></i> {$error}</div>"; ?>

            <div class="minimal-card" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
                    <div class="pill-group">
                        <?php
                            $status_pills = [
                                '' => 'All',
                                'issued' => 'Issued',
                                'paid' => 'Paid',
                                'draft' => 'Draft',
                                'void' => 'Void'
                            ];
                            foreach ($status_pills as $sp_val => $sp_label) {
                                $is_active = ($filter_status === $sp_val);
                                $url = 'lodging_billing.php?' . http_build_query(array_merge($_GET, ['status' => $sp_val]));
                                echo '<a href="' . htmlspecialchars($url) . '" class="pill-btn ' . ($is_active ? 'active' : '') . '">' . htmlspecialchars($sp_label) . '</a>';
                            }
                        ?>
                    </div>
                    <div>
                        <a href="lodging_billing.php" class="action-btn-secondary" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">Reset Filters</a>
                    </div>
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
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">From</label>
                        <input type="date" id="filterFrom" class="search-box" value="<?php echo htmlspecialchars($filter_from); ?>" style="width: 100%;">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">To</label>
                        <input type="date" id="filterTo" class="search-box" value="<?php echo htmlspecialchars($filter_to); ?>" style="width: 100%;">
                    </div>
                    <div style="display: none;">
                        <input type="hidden" id="filterStatus" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <button type="button" id="applyFilters">Apply</button>
                    </div>
                </div>
            </div>

            <div class="minimal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.875rem; flex-wrap: wrap; gap: 0.75rem;">
                    <h3 style="font-size: 0.9375rem; font-weight: 700; margin: 0;"><i class="fas fa-receipt" style="color: var(--primary);"></i> Invoices</h3>
                    <div class="search-container" style="max-width: 280px; width: 100%;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="invoiceSearch" placeholder="Search invoices...">
                        <span class="search-kbd">/</span>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="minimal-table" id="invoiceTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Seminarian</th>
                                <th>AY</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th style="text-align: right;">Total (₱)</th>
                                <th style="text-align: right;">Paid (₱)</th>
                                <th style="text-align: right;">Balance (₱)</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices_res->num_rows === 0) { ?>
                                <tr><td colspan="10" style="text-align: center; color: var(--text-secondary); padding: 1.5rem;">No invoices matching selected filters.</td></tr>
                            <?php } ?>
                            <?php while ($row = $invoices_res->fetch_assoc()) {
                                $total = (float)($row['total_amount'] ?? 0);
                                $paid = (float)($row['paid_amount'] ?? 0);
                                $bal = $total - $paid;
                                $status = (string)($row['status'] ?? '');
                                $status_class = 'status-due';
                                if ($status === 'paid') $status_class = 'status-cleared';
                                if ($status === 'draft') $status_class = 'status-partial';
                                if ($status === 'void') $status_class = 'status-due';
                            ?>
                            <tr class="invoice-row">
                                <td class="invoice-no"><strong style="color: var(--primary); font-variant-numeric: tabular-nums;"><?php echo htmlspecialchars($row['invoice_no']); ?></strong></td>
                                <td class="student-name"><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars((string)($row['academic_year'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($row['issue_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['due_date'] ?? '—'); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars(strtoupper($status)); ?></span></td>
                                <td class="num-col" style="text-align: right;"><?php echo htmlspecialchars(number_format($total, 2)); ?></td>
                                <td class="num-col" style="text-align: right; color: #10b981;"><?php echo htmlspecialchars(number_format($paid, 2)); ?></td>
                                <td class="num-col" style="text-align: right; font-weight: 700; color: <?php echo $bal <= 0.00001 ? '#10b981' : '#dc2626'; ?>;"><?php echo htmlspecialchars(number_format(max(0, $bal), 2)); ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a class="action-btn-secondary" href="cashier_soa.php?student_id=<?php echo (int)$row['seminarian_id']; ?>&academic_year=<?php echo urlencode((string)($row['academic_year'] ?? '')); ?>&semester=<?php echo urlencode((string)($row['semester'] ?? '')); ?>" target="_blank" title="View Statement of Account" style="font-size: 0.75rem; padding: 0.25rem 0.5rem; text-decoration: none; margin-right: 0.35rem; display: inline-block;">
                                        <i class="fas fa-file-invoice"></i> SOA
                                    </a>
                                    <?php if ($status !== 'void') { ?>
                                    <form method="post" style="display: inline; margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="invoice_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" name="void_invoice" class="action-btn-danger" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;" onclick="return confirm('Void this invoice?');" title="Void Invoice">
                                            <i class="fas fa-ban"></i> Void
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
        </div>
    <div class="modal" id="createInvoiceModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="createInvoiceTitle">
            <div class="modal-header">
                <div class="modal-title" id="createInvoiceTitle"><i class="fas fa-plus-circle"></i> Create Invoice</div>
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="field">
                        <label>Seminarian</label>
                        <div class="typeahead">
                            <input type="text" id="studentSearchInvoice" placeholder="Search student..." autocomplete="off" required>
                            <input type="hidden" name="seminarian_id" id="studentIdInvoice">
                            <div class="typeahead-list" id="studentListInvoice" hidden></div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
                        <div class="field">
                            <label>Issue Date</label>
                            <input type="date" name="issue_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="field">
                            <label>Due Date</label>
                            <input type="date" name="due_date">
                        </div>
                        <div class="field">
                            <label>Academic Year</label>
                            <select name="academic_year">
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Semester</label>
                            <select name="semester">
                                <option value="1st">1st Semester</option>
                                <option value="2nd">2nd Semester</option>
                                <option value="">Full Year / Annual</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <option value="issued">Issued</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
                        <div class="field">
                            <label>Period Start</label>
                            <input type="date" name="period_start">
                        </div>
                        <div class="field">
                            <label>Period End</label>
                            <input type="date" name="period_end">
                        </div>
                    </div>

                    <div class="field">
                        <label>Description</label>
                        <input type="text" name="description" placeholder="e.g. Lodging fee (April 2026)" required>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
                        <div class="field">
                            <label>Quantity</label>
                            <input type="number" name="quantity" min="0.01" step="0.01" value="1" required>
                        </div>
                        <div class="field">
                            <label>Unit Price</label>
                            <input type="number" name="unit_price" min="0" step="0.01" value="0" required>
                        </div>
                    </div>

                    <div class="field" style="margin: 0;">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Optional notes"></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="modal-close" data-modal-close>Cancel</button>
                        <button type="submit" name="create_invoice">Create Invoice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const modal = document.getElementById('createInvoiceModal');
        const openBtn = document.getElementById('openCreateInvoice');
        if (!modal || !openBtn) return;

        function openModal() {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            const input = modal.querySelector('#studentSearchInvoice');
            if (input) input.focus();
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
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

        attachTypeahead('filterStudentSearch', 'filterStudentId', 'filterStudentList');
        attachTypeahead('studentSearchInvoice', 'studentIdInvoice', 'studentListInvoice');
    })();

    (function () {
        const btn = document.getElementById('applyFilters');
        const sid = document.getElementById('filterStudentId');
        const status = document.getElementById('filterStatus');
        const ay = document.getElementById('filterAcademicYear');
        const from = document.getElementById('filterFrom');
        const to = document.getElementById('filterTo');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const params = new URLSearchParams();
            if (sid && sid.value) params.set('seminarian_id', String(sid.value));
            if (status && status.value) params.set('status', status.value);
            if (ay && ay.value) params.set('academic_year', ay.value);
            if (from && from.value) params.set('from', from.value);
            if (to && to.value) params.set('to', to.value);
            window.location.href = 'lodging_billing.php' + (params.toString() ? ('?' + params.toString()) : '');
        });

        // Zero-click filter triggers
        if (ay) ay.addEventListener('change', () => btn.click());
        if (from) from.addEventListener('change', () => btn.click());
        if (to) to.addEventListener('change', () => btn.click());
    })();

    document.getElementById('invoiceSearch').addEventListener('keyup', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.invoice-row').forEach(function (row) {
            const invoiceNo = (row.querySelector('.invoice-no')?.textContent || '').toLowerCase();
            const student = (row.querySelector('.student-name')?.textContent || '').toLowerCase();
            if (invoiceNo.includes(q) || student.includes(q)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
