<?php
require_once '../includes/session_fix.php';
require_roles(['Dean', 'Rector', 'Admin']);
require_once '../includes/db.php';
require_once '../includes/lodging.php';

$role = (string)($_SESSION['role'] ?? '');
$can_manage_fees = in_array($role, ['Dean', 'Rector', 'Admin'], true);

$current_ay = get_active_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$programs = ['Propaedeutic', 'Bachelor of Arts in Philosophy'];
$year_levels = ['Propaedeutic Stage', 'First Year', 'Second Year', 'Third Year', 'Fourth Year'];

if (isset($_POST['create_schedule'])) {
    if (!$can_manage_fees) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $ay = trim((string)($_POST['academic_year'] ?? ''));
    $sem = trim((string)($_POST['semester'] ?? ''));
    $program = trim((string)($_POST['program'] ?? ''));
    $yl = trim((string)($_POST['year_level'] ?? ''));
    if ($ay === '' || !in_array($ay, $ay_options, true)) {
        $ay = $current_ay;
    }
    if (!in_array($sem, ['1st', '2nd'], true)) {
        $sem = '1st';
    }
    if ($program !== '' && !in_array($program, $programs, true)) {
        $program = '';
    }
    if ($yl !== '' && !in_array($yl, $year_levels, true)) {
        $yl = '';
    }

    $stmt = $conn->prepare("INSERT INTO cashier_fee_schedules (academic_year, semester, program, year_level, is_active, created_by) VALUES (?, ?, ?, ?, 1, ?)");
    $created_by = (int)($_SESSION['user'] ?? 0);
    $program_db = $program !== '' ? $program : null;
    $yl_db = $yl !== '' ? $yl : null;
    $stmt->bind_param('ssssi', $ay, $sem, $program_db, $yl_db, $created_by);
    $stmt->execute();
    $sched_id_new = (int)$conn->insert_id;
    log_activity($created_by, "Created fee schedule #{$sched_id_new} ({$ay} {$sem})");
    $success = 'Fee schedule created.';
}

if (isset($_POST['add_item'])) {
    if (!$can_manage_fees) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $desc = trim((string)($_POST['description'] ?? ''));
    $amt = (float)($_POST['amount'] ?? 0);
    if ($schedule_id <= 0) {
        $error = 'Invalid schedule.';
    } elseif ($desc === '') {
        $error = 'Description is required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO cashier_fee_items (schedule_id, description, amount, sort_order) VALUES (?, ?, ?, 0)");
        $stmt->bind_param('isd', $schedule_id, $desc, $amt);
        $stmt->execute();
        log_activity((int)($_SESSION['user'] ?? 0), "Added fee item '{$desc}' (PHP " . number_format($amt, 2) . ") to schedule #{$schedule_id}");
        $success = 'Fee item added.';
    }
}

if (isset($_POST['update_item'])) {
    if (!$can_manage_fees) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $item_id = (int)($_POST['item_id'] ?? 0);
    $desc = trim((string)($_POST['description'] ?? ''));
    $amt = (float)($_POST['amount'] ?? 0);
    if ($item_id <= 0) {
        $error = 'Invalid fee item.';
    } elseif ($desc === '') {
        $error = 'Description is required.';
    } elseif ($amt < 0) {
        $error = 'Amount cannot be negative.';
    } else {
        $stmt = $conn->prepare("UPDATE cashier_fee_items SET description = ?, amount = ? WHERE id = ?");
        $stmt->bind_param('sdi', $desc, $amt, $item_id);
        $stmt->execute();
        log_activity((int)($_SESSION['user'] ?? 0), "Updated fee item #{$item_id} ('{$desc}', PHP " . number_format($amt, 2) . ")");
        $success = 'Fee item updated.';
    }
}

if (isset($_POST['delete_item'])) {
    if (!$can_manage_fees) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $item_id = (int)($_POST['item_id'] ?? 0);
    if ($item_id > 0) {
        $stmt = $conn->prepare("DELETE FROM cashier_fee_items WHERE id = ?");
        $stmt->bind_param('i', $item_id);
        $stmt->execute();
        log_activity((int)($_SESSION['user'] ?? 0), "Removed fee item #{$item_id}");
        $success = 'Fee item removed.';
    }
}

if (isset($_POST['generate_bills'])) {
    if (!$can_manage_fees) {
        die('Unauthorized: Only the Admin can create, edit, or modify bills and fees.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $stmt_sched = $conn->prepare("SELECT * FROM cashier_fee_schedules WHERE id = ? AND archived_at IS NULL LIMIT 1");
    $stmt_sched->bind_param('i', $schedule_id);
    $stmt_sched->execute();
    $sched = $stmt_sched->get_result()->fetch_assoc();
    if (!$sched) {
        $error = 'Schedule not found.';
    } else {
        $ay = (string)($sched['academic_year'] ?? $current_ay);
        $sem = (string)($sched['semester'] ?? '1st');
        $program = trim((string)($sched['program'] ?? ''));
        $yl = trim((string)($sched['year_level'] ?? ''));

        $items = [];
        $stmt_items = $conn->prepare("SELECT description, amount FROM cashier_fee_items WHERE schedule_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt_items->bind_param('i', $schedule_id);
        $stmt_items->execute();
        $res_items = $stmt_items->get_result();
        while ($res_items && ($r = $res_items->fetch_assoc())) {
            $items[] = $r;
        }
        if (count($items) === 0) {
            $error = 'Add fee items first.';
        } else {
            $sql_students = "SELECT s.id, s.program, s.year_level
                FROM seminarians s
                WHERE s.enrollment_status = 'Enrolled'
                  AND (s.year_level <> 'Completed' OR s.year_level IS NULL OR s.year_level = '')";
            $types = '';
            $params = [];
            if ($program !== '') {
                $sql_students .= " AND s.program = ?";
                $types .= 's';
                $params[] = $program;
            }
            if ($yl !== '') {
                $sql_students .= " AND s.year_level = ?";
                $types .= 's';
                $params[] = $yl;
            }
            $stmt_students = $conn->prepare($sql_students);
            if ($types !== '') {
                $bind = [];
                $bind[] = $types;
                foreach ($params as $k => $v) {
                    $bind[] = &$params[$k];
                }
                call_user_func_array([$stmt_students, 'bind_param'], $bind);
            }
            $stmt_students->execute();
            $res_students = $stmt_students->get_result();

            $created_by = (int)($_SESSION['user'] ?? 0);
            $created = 0;

            $conn->begin_transaction();
            try {
                $stmt_exists = $conn->prepare("SELECT id FROM lodging_invoices WHERE seminarian_id = ? AND academic_year = ? AND semester = ? AND invoice_type = 'semester_fees' AND archived_at IS NULL AND status <> 'void' LIMIT 1");
                $stmt_ins_inv = $conn->prepare("INSERT INTO lodging_invoices (seminarian_id, invoice_no, issue_date, due_date, period_start, period_end, academic_year, semester, year_level, program, invoice_type, fee_schedule_id, status, notes, created_by) VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, 'semester_fees', ?, 'issued', NULL, ?)");
                $stmt_upd_inv = $conn->prepare("UPDATE lodging_invoices SET fee_schedule_id = ?, year_level = ?, program = ? WHERE id = ?");
                $stmt_del_items = $conn->prepare("DELETE FROM lodging_invoice_items WHERE invoice_id = ?");
                $stmt_ins_item = $conn->prepare("INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, 1, ?, ?)");

                while ($res_students && ($st = $res_students->fetch_assoc())) {
                    $sid = (int)($st['id'] ?? 0);
                    if ($sid <= 0) continue;

                    $stmt_exists->bind_param('iss', $sid, $ay, $sem);
                    $stmt_exists->execute();
                    $ex = $stmt_exists->get_result()->fetch_assoc();
                    $yl_snap = (string)($st['year_level'] ?? '');
                    $prog_snap = (string)($st['program'] ?? '');

                    if ($ex) {
                        $invoice_id = (int)$ex['id'];
                        $stmt_upd_inv->bind_param('issi', $schedule_id, $yl_snap, $prog_snap, $invoice_id);
                        $stmt_upd_inv->execute();
                        $stmt_del_items->bind_param('i', $invoice_id);
                        $stmt_del_items->execute();
                    } else {
                        $invoice_no = lodging_generate_invoice_no($conn);
                        $issue_date = date('Y-m-d');
                        $stmt_ins_inv->bind_param('issssssii', $sid, $invoice_no, $issue_date, $ay, $sem, $yl_snap, $prog_snap, $schedule_id, $created_by);
                        $stmt_ins_inv->execute();
                        $invoice_id = (int)$conn->insert_id;
                    }

                    foreach ($items as $it) {
                        $desc = (string)($it['description'] ?? '');
                        $amt = (float)($it['amount'] ?? 0);
                        $stmt_ins_item->bind_param('isdd', $invoice_id, $desc, $amt, $amt);
                        $stmt_ins_item->execute();
                    }
                    if (function_exists('lodging_recompute_invoice_status')) {
                        lodging_recompute_invoice_status($conn, $invoice_id);
                    }
                    $created++;
                }

                $conn->commit();
                $success = 'Processed semester billing for ' . (int)$created . ' student(s) using schedule #' . $schedule_id . '.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to generate bills.';
            }
        }
    }
}

$schedules = [];
$res_sched = $conn->query("SELECT * FROM cashier_fee_schedules WHERE archived_at IS NULL ORDER BY academic_year DESC, semester DESC, id DESC");
if ($res_sched) {
    while ($r = $res_sched->fetch_assoc()) {
        $schedules[] = $r;
    }
}

$items_by_schedule = [];
$res_items_all = $conn->query("SELECT * FROM cashier_fee_items ORDER BY schedule_id ASC, sort_order ASC, id ASC");
if ($res_items_all) {
    while ($r = $res_items_all->fetch_assoc()) {
        $sid = (int)($r['schedule_id'] ?? 0);
        if (!isset($items_by_schedule[$sid])) $items_by_schedule[$sid] = [];
        $items_by_schedule[$sid][] = $r;
    }
}
?>
<?php
$pageTitle = 'Semester Fees';
$activeNav = 'cashier_fees';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body" style="padding-top: 1rem;">
            <!-- Page Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0.25rem; font-size: 1.5rem; font-weight: 800;"><i class="fas fa-file-invoice-dollar" style="color: #0f766e; margin-right: 0.35rem;"></i> Semester Fee Schedules</h1>
                    <div style="color: #64748b; font-size: 0.85rem; font-weight: 600;">Define standard tuition, lodging, and academic fee templates for automated billing.</div>
                </div>
                <button type="button" id="toggleCreateSchedBtn" class="action-btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                    <i class="fas fa-plus"></i> New Fee Schedule
                </button>
            </div>

            <?php if (isset($success)) { ?>
                <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>
            <?php if (isset($error)) { ?>
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-red-600"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php } ?>

            <!-- Collapsible Create Schedule Card -->
            <div id="createScheduleCard" class="minimal-card hidden" style="margin-bottom: 1.5rem; border-left: 4px solid #0f766e;">
                <div class="minimal-toolbar" style="background: #f8fafc;">
                    <strong style="color: #0f172a; font-size: 0.95rem;"><i class="fas fa-calendar-plus" style="color: #0f766e; margin-right: 0.35rem;"></i> Create New Fee Schedule Template</strong>
                    <button type="button" id="closeCreateSchedBtn" class="text-slate-400 hover:text-slate-600 text-xs font-bold uppercase">&times; Close</button>
                </div>
                <form method="post" style="padding: 1.25rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase;">Academic Year</label>
                            <select name="academic_year" class="minimal-select" style="width: 100%; margin-top: 0.25rem;" required>
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase;">Semester</label>
                            <select name="semester" class="minimal-select" style="width: 100%; margin-top: 0.25rem;" required>
                                <option value="1st">First Semester</option>
                                <option value="2nd">Second Semester</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase;">Program (Optional)</label>
                            <select name="program" class="minimal-select" style="width: 100%; margin-top: 0.25rem;">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $p) { ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase;">Year Level (Optional)</label>
                            <select name="year_level" class="minimal-select" style="width: 100%; margin-top: 0.25rem;">
                                <option value="">All Year Levels</option>
                                <?php foreach ($year_levels as $yl) { ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>"><?php echo htmlspecialchars($yl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                        <button type="button" id="cancelCreateSchedBtn" class="action-btn-secondary">Cancel</button>
                        <button type="submit" name="create_schedule" class="action-btn-primary"><i class="fas fa-check"></i> Save Schedule</button>
                    </div>
                </form>
            </div>

            <!-- Schedules Table Card -->
            <div class="minimal-card">
                <div class="minimal-toolbar">
                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="schedSearchInput" placeholder="Filter schedules by AY, program or level..." autocomplete="off">
                        <kbd class="search-kbd">/</kbd>
                    </div>

                    <div class="pill-group" id="schedSemPills">
                        <button type="button" class="pill-btn active" data-sem="all">All Terms</button>
                        <button type="button" class="pill-btn" data-sem="1st">1st Semester</button>
                        <button type="button" class="pill-btn" data-sem="2nd">2nd Semester</button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th style="min-width: 130px;">Term</th>
                                <th style="min-width: 180px;">Applicable Scope</th>
                                <th style="min-width: 320px;">Fee Items & Subtotal</th>
                                <th style="min-width: 360px;">Add New Fee Item</th>
                                <th style="text-align: right; min-width: 140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($schedules) === 0) { ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                        <i class="fas fa-file-invoice text-3xl mb-2 text-slate-300"></i>
                                        <div>No fee schedules configured yet. Click "New Fee Schedule" to create one.</div>
                                    </td>
                                </tr>
                            <?php } else { foreach ($schedules as $s) {
                                $sid = (int)($s['id'] ?? 0);
                                $ay_sched = (string)($s['academic_year'] ?? '');
                                $sem_sched = (string)($s['semester'] ?? '');
                                $scope_prog = trim((string)($s['program'] ?? '')) !== '' ? (string)$s['program'] : 'All Programs';
                                $scope_yl = trim((string)($s['year_level'] ?? '')) !== '' ? (string)$s['year_level'] : 'All Levels';
                                $items = $items_by_schedule[$sid] ?? [];

                                $sched_total = 0.0;
                                foreach ($items as $it) {
                                    $sched_total += (float)($it['amount'] ?? 0);
                                }
                            ?>
                                <tr class="sched-row" data-sem="<?php echo htmlspecialchars($sem_sched); ?>" data-search="<?php echo htmlspecialchars(strtolower($ay_sched . ' ' . $sem_sched . ' ' . $scope_prog . ' ' . $scope_yl)); ?>">
                                    <td>
                                        <strong style="color: #0f172a; font-size: 0.95rem;">AY <?php echo htmlspecialchars($ay_sched); ?></strong>
                                        <div style="margin-top: 0.2rem;">
                                            <span class="status-badge <?php echo $sem_sched === '1st' ? 'status-info' : 'status-cleared'; ?>">
                                                <?php echo htmlspecialchars($sem_sched); ?> Semester
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($scope_prog); ?></div>
                                        <div style="font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($scope_yl); ?></div>
                                    </td>
                                    <td>
                                        <?php if (count($items) === 0) { ?>
                                            <span style="color: #94a3b8; font-size: 0.825rem; font-style: italic;">No fee items configured. Use the form on the right to add items.</span>
                                        <?php } else { ?>
                                            <div style="display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.5rem;">
                                                <?php foreach ($items as $it) {
                                                    $it_id = (int)($it['id'] ?? 0);
                                                    $it_desc = (string)($it['description'] ?? '');
                                                    $it_amt = (float)($it['amount'] ?? 0);
                                                ?>
                                                    <div style="background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 6px; padding: 0.35rem 0.65rem;">
                                                        <div id="feeItemView_<?php echo $it_id; ?>" style="display: flex; justify-content: space-between; align-items: center;">
                                                            <span style="font-size: 0.85rem; color: #334155; font-weight: 600;"><?php echo htmlspecialchars($it_desc); ?></span>
                                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                                <strong style="font-size: 0.875rem; font-variant-numeric: tabular-nums; color: #0f172a;">₱<?php echo htmlspecialchars(number_format($it_amt, 2)); ?></strong>
                                                                <button type="button" title="Edit item" class="text-slate-400 hover:text-teal-700 transition" onclick="toggleEditFeeItem(<?php echo $it_id; ?>, true)" style="background: none; border: none; cursor: pointer; padding: 0;">
                                                                    <i class="fas fa-pen text-xs"></i>
                                                                </button>
                                                                <form method="post" style="display: inline; margin: 0; padding: 0;">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                    <input type="hidden" name="item_id" value="<?php echo $it_id; ?>">
                                                                    <button type="submit" name="delete_item" title="Remove item" class="text-slate-400 hover:text-red-600 transition" onclick="return confirm('Remove this fee item?');" style="background: none; border: none; cursor: pointer; padding: 0;">
                                                                        <i class="fas fa-times text-xs"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                        <form method="post" id="feeItemEdit_<?php echo $it_id; ?>" class="hidden" style="display: none; gap: 0.35rem; align-items: center; margin: 0; padding: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="item_id" value="<?php echo $it_id; ?>">
                                                            <input type="text" name="description" value="<?php echo htmlspecialchars($it_desc); ?>" required style="flex: 1; padding: 0.25rem 0.5rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.8rem;">
                                                            <input type="number" name="amount" value="<?php echo htmlspecialchars(number_format($it_amt, 2, '.', '')); ?>" step="0.01" min="0" required style="width: 90px; padding: 0.25rem 0.5rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.8rem; font-variant-numeric: tabular-nums;">
                                                            <button type="submit" name="update_item" class="action-btn-primary" style="padding: 0.25rem 0.55rem; font-size: 0.75rem;" title="Save changes">
                                                                <i class="fas fa-check text-xs"></i>
                                                            </button>
                                                            <button type="button" class="action-btn-secondary" style="padding: 0.25rem 0.55rem; font-size: 0.75rem;" onclick="toggleEditFeeItem(<?php echo $it_id; ?>, false)" title="Cancel">
                                                                <i class="fas fa-xmark text-xs"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <div style="border-top: 1px dashed #cbd5e1; padding-top: 0.35rem; display: flex; justify-content: space-between; align-items: center;">
                                                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b;">Schedule Subtotal:</span>
                                                <strong style="color: #0f766e; font-size: 0.95rem; font-variant-numeric: tabular-nums;">₱<?php echo htmlspecialchars(number_format($sched_total, 2)); ?></strong>
                                            </div>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <!-- Preset 1-Click Fee Chips -->
                                        <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-bottom: 0.5rem;">
                                            <button type="button" class="quick-chip" onclick="fillFee(this, 'Tuition Fee', 15000)">+ Tuition (15k)</button>
                                            <button type="button" class="quick-chip" onclick="fillFee(this, 'Board & Lodging', 12000)">+ Lodging (12k)</button>
                                            <button type="button" class="quick-chip" onclick="fillFee(this, 'Registration Fee', 1500)">+ Reg (1.5k)</button>
                                            <button type="button" class="quick-chip" onclick="fillFee(this, 'Library Fee', 500)">+ Lib (500)</button>
                                        </div>

                                        <form method="post" style="display: flex; gap: 0.35rem; align-items: center; margin: 0; padding: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="schedule_id" value="<?php echo $sid; ?>">
                                            <input type="text" name="description" placeholder="Description" required style="flex: 1; padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.825rem;">
                                            <input type="number" name="amount" placeholder="0.00" step="0.01" min="0" required style="width: 100px; padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.825rem; font-variant-numeric: tabular-nums;">
                                            <button type="submit" name="add_item" class="action-btn-secondary" style="padding: 0.4rem 0.65rem;">
                                                <i class="fas fa-plus text-xs"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <form method="post" style="display: inline; margin: 0; padding: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="schedule_id" value="<?php echo $sid; ?>">
                                            <button type="submit" name="generate_bills" class="action-btn-pay" onclick="return confirm('Generate student billing invoices for AY <?php echo htmlspecialchars($ay_sched); ?> (<?php echo htmlspecialchars($sem_sched); ?> sem)?');">
                                                <i class="fas fa-paper-plane text-xs"></i> Bill Students
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        function toggleEditFeeItem(itemId, showEdit) {
            const viewEl = document.getElementById('feeItemView_' + itemId);
            const editEl = document.getElementById('feeItemEdit_' + itemId);
            if (!viewEl || !editEl) return;
            if (showEdit) {
                viewEl.style.display = 'none';
                editEl.classList.remove('hidden');
                editEl.style.display = 'flex';
            } else {
                editEl.style.display = 'none';
                editEl.classList.add('hidden');
                viewEl.style.display = 'flex';
            }
        }

        function fillFee(btn, desc, amt) {
            const form = btn.closest('td')?.querySelector('form');
            if (!form) return;
            const descInput = form.querySelector('input[name="description"]');
            const amtInput = form.querySelector('input[name="amount"]');
            if (descInput) descInput.value = desc;
            if (amtInput) amtInput.value = Number(amt).toFixed(2);
            amtInput?.focus();
        }

        // Toggle schedule creation drawer
        const toggleBtn = document.getElementById('toggleCreateSchedBtn');
        const closeBtn = document.getElementById('closeCreateSchedBtn');
        const cancelBtn = document.getElementById('cancelCreateSchedBtn');
        const createCard = document.getElementById('createScheduleCard');

        const toggleSchedCard = () => {
            if (createCard) {
                createCard.classList.toggle('hidden');
                if (!createCard.classList.contains('hidden')) {
                    createCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        };

        toggleBtn?.addEventListener('click', toggleSchedCard);
        closeBtn?.addEventListener('click', toggleSchedCard);
        cancelBtn?.addEventListener('click', toggleSchedCard);

        // Filter schedules by search and semester pills
        (function() {
            const searchInput = document.getElementById('schedSearchInput');
            const semPills = document.querySelectorAll('#schedSemPills .pill-btn');
            const rows = document.querySelectorAll('.sched-row');

            let activeSem = 'all';

            function filterRows() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                rows.forEach(row => {
                    const searchData = (row.getAttribute('data-search') || '').toLowerCase();
                    const rowSem = (row.getAttribute('data-sem') || '').toLowerCase();

                    const matchesSearch = !q || searchData.includes(q);
                    const matchesSem = activeSem === 'all' || rowSem === activeSem.toLowerCase();

                    row.style.display = (matchesSearch && matchesSem) ? '' : 'none';
                });
            }

            searchInput?.addEventListener('input', filterRows);

            semPills.forEach(pill => {
                pill.addEventListener('click', () => {
                    semPills.forEach(p => p.classList.remove('active'));
                    pill.classList.add('active');
                    activeSem = pill.getAttribute('data-sem') || 'all';
                    filterRows();
                });
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
