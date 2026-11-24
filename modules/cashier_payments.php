<?php
include '../includes/session_fix.php';
require_roles(['Cashier']);
include '../includes/db.php';
include '../includes/lodging.php';

$current_ay = get_current_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$preselected_seminarian = (int)($_GET['seminarian_id'] ?? 0);
$preselected_ay = trim((string)($_GET['academic_year'] ?? ''));
$preselected_sem = trim((string)($_GET['semester'] ?? ''));
if ($preselected_ay !== '' && in_array($preselected_ay, $ay_options, true)) {
    $current_ay = $preselected_ay;
}
$current_sem = in_array($preselected_sem, ['1st', '2nd'], true) ? $preselected_sem : '1st';

$students = [];
$res_students = $conn->query(
    "SELECT s.id as seminarian_id, u.username, COALESCE(up.full_name, u.username) as full_name, up.student_id as student_no, s.program, s.year_level\n"
    . "FROM seminarians s\n"
    . "JOIN users u ON u.id = s.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "WHERE s.enrollment_status IN ('Enrolled', 'Pending', 'Partially Paid')\n"
    . "ORDER BY full_name ASC"
);
if ($res_students) {
    while ($r = $res_students->fetch_assoc()) {
        $students[] = $r;
    }
}

$payer_types = [
    'Parents',
    'Parish Sponsors',
    'Diocese',
    'Benefactors',
    'Foundations',
    'Individual Sponsors',
    'Other',
];
$methods = [
    'cash' => 'Cash',
    'bank_deposit' => 'Bank Deposit',
    'online_transfer' => 'Online Transfer',
    'check' => 'Check',
    'other' => 'Other',
];
$particular_options = [
    'Boarding Fee',
    'Tuition Fee',
    'Transportation Fee',
    'Computer Fee',
    'Miscellaneous Fee',
    'Other Charges',
    'Previous Balance',
    'Semester Fees',
    'Other',
];

if (isset($_POST['record_payment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $seminarian_id = (int)($_POST['seminarian_id'] ?? 0);
    $academic_year = trim((string)($_POST['academic_year'] ?? $current_ay));
    $semester = trim((string)($_POST['semester'] ?? '1st'));
    $or_number = trim((string)($_POST['or_number'] ?? ''));
    $payment_date = (string)($_POST['payment_date'] ?? date('Y-m-d'));
    $selected_amount = (float)($_POST['amount'] ?? 0);
    $tendered_raw = isset($_POST['amount_tendered']) && trim((string)$_POST['amount_tendered']) !== '' ? (float)$_POST['amount_tendered'] : null;

    $is_partial = false;
    $insufficient_tendered = false;
    if ($tendered_raw !== null && $tendered_raw < ($selected_amount - 0.005)) {
        $insufficient_tendered = true;
    }

    $actual_payment = round($selected_amount, 2);
    if ($tendered_raw !== null) {
        $amount_tendered = round($tendered_raw, 2);
        $change_given = max(0.0, round($amount_tendered - $actual_payment, 2));
    } else {
        $amount_tendered = $actual_payment;
        $change_given = 0.0;
    }
    $amount = $actual_payment;
    $method = trim((string)($_POST['method'] ?? 'cash'));
    $reference_no = trim((string)($_POST['reference_no'] ?? ''));
    $payer_type = trim((string)($_POST['payer_type'] ?? ''));
    $payer_name = trim((string)($_POST['payer_name'] ?? ''));
    $particulars_select_arr = $_POST['particulars_select'] ?? [];
    $particulars_other_arr = $_POST['particulars_other'] ?? [];
    $particular_amount_arr = $_POST['particular_amount'] ?? [];
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (!is_array($particulars_select_arr)) $particulars_select_arr = [];
    if (!is_array($particulars_other_arr)) $particulars_other_arr = [];
    if (!is_array($particular_amount_arr)) $particular_amount_arr = [];

    $payment_items = [];
    $items_total = 0.0;
    $max_rows = max(count($particulars_select_arr), count($particular_amount_arr), count($particulars_other_arr));
    for ($i = 0; $i < $max_rows; $i++) {
        $sel = trim((string)($particulars_select_arr[$i] ?? ''));
        $other_txt = trim((string)($particulars_other_arr[$i] ?? ''));
        $amt = (float)($particular_amount_arr[$i] ?? 0);
        if ($sel === '' && $other_txt === '' && !isset($particular_amount_arr[$i])) {
            continue;
        }
        $particular = $sel === 'Other' ? $other_txt : $sel;
        if ($particular === '') {
            continue;
        }
        if ($amt < 0) {
            $error = 'Particular amount cannot be negative.';
            break;
        }
        $payment_items[] = ['particular' => $particular, 'amount' => $amt];
        $items_total += $amt;
    }

    if (count($payment_items) === 0 && $selected_amount > 0) {
        // Items will be allocated dynamically via cashier_allocate_payment_particulars()
    }

    if ($seminarian_id <= 0) {
        $error = 'Please select a seminarian.';
    } elseif ($or_number === '') {
        $error = 'Official Receipt Number is required.';
    } elseif (!in_array($academic_year, $ay_options, true)) {
        $error = 'Invalid Academic Year.';
    } elseif (!in_array($semester, ['1st', '2nd'], true)) {
        $error = 'Invalid semester.';
    } elseif ($actual_payment <= 0) {
        $error = 'Amount to Pay must be greater than 0.';
    } elseif (!empty($insufficient_tendered)) {
        $error = 'Insufficient cash tendered. Cash received must be at least equal to Amount to Pay.';
    } elseif (!array_key_exists($method, $methods)) {
        $error = 'Invalid payment method.';
    } elseif ($payer_type !== '' && !in_array($payer_type, $payer_types, true)) {
        $error = 'Invalid payer type.';
    } else {
        $stmt_or = $conn->prepare("SELECT COUNT(*) as c FROM lodging_payments WHERE or_number = ?");
        $stmt_or->bind_param('s', $or_number);
        $stmt_or->execute();
        $exists = (int)($stmt_or->get_result()->fetch_assoc()['c'] ?? 0);
        if ($exists > 0) {
            $error = 'Official Receipt Number already exists.';
        }
    }

    if (!isset($error)) {
        lodging_ensure_student_invoice($conn, $seminarian_id, $academic_year, $semester);

        $bal_info = lodging_get_seminarian_balance($conn, $seminarian_id, $academic_year, $semester);
        $previous_balance = (float)$bal_info['remaining_balance'];
        $total_amount_due = (float)$bal_info['total_due'];
        $total_payments_made = (float)$bal_info['total_paid'];

        if ($actual_payment > ($previous_balance + 0.005)) {
            $formatted_bal = number_format($previous_balance, 2);
            $error = "Payment amount exceeds the current remaining balance. Please enter an amount equal to or less than ₱{$formatted_bal}.";
        }
    }

    if (!isset($error)) {
        $conn->begin_transaction();
        try {
            $received_by = (int)($_SESSION['user'] ?? 0);

            $distributed_payment_items = cashier_allocate_payment_particulars(
                $conn,
                $seminarian_id,
                $academic_year,
                $semester,
                $actual_payment,
                $payment_items
            );

            $notes_summary = [];
            foreach ($distributed_payment_items as $it) {
                $clean_part = trim((string)preg_replace('/\s*\([^)]*\)/', '', (string)($it['particular'] ?? '')));
                if ($it['amount'] > 0.0001 && $clean_part !== '' && !in_array($clean_part, $notes_summary, true)) {
                    $notes_summary[] = $clean_part;
                }
            }
            $notes_value = implode(', ', $notes_summary);

            $stmt = $conn->prepare("INSERT INTO lodging_payments (or_number, payer_type, payer_name, remarks, seminarian_id, payment_date, academic_year, semester, amount, amount_tendered, method, reference_no, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $payer_type_db = $payer_type !== '' ? $payer_type : null;
            $payer_name_db = $payer_name !== '' ? $payer_name : null;
            $remarks_db = $remarks !== '' ? $remarks : null;
            $reference_db = $reference_no !== '' ? $reference_no : null;
            $notes_db = $notes_value !== '' ? $notes_value : null;
            $stmt->bind_param('ssssisssddsssi', $or_number, $payer_type_db, $payer_name_db, $remarks_db, $seminarian_id, $payment_date, $academic_year, $semester, $actual_payment, $amount_tendered, $method, $reference_db, $notes_db, $received_by);
            $stmt->execute();
            $payment_id = (int)$conn->insert_id;

            if ($payment_id > 0) {
                $stmt_item = $conn->prepare("INSERT INTO cashier_payment_items (payment_id, particular, amount, sort_order) VALUES (?, ?, ?, ?)");
                $sort_idx = 0;
                foreach ($distributed_payment_items as $it) {
                    if ($it['amount'] > 0 || stripos($it['particular'], 'rice') !== false) {
                        $p_part = trim((string)preg_replace('/\s*\([^)]*\)/', '', (string)$it['particular']));
                        $p_amt = (float)$it['amount'];
                        $stmt_item->bind_param('isdi', $payment_id, $p_part, $p_amt, $sort_idx);
                        $stmt_item->execute();
                        $sort_idx++;
                    }
                }
                lodging_allocate_payment($conn, $payment_id, $seminarian_id, $actual_payment);
            }

            // Dynamic Balance Computation:
            // Exact Partial Payment Deduction: New Balance = Old Balance - Amount Tendered
            $new_total_payments = round($total_payments_made + $actual_payment, 2);
            $remaining_balance = max(0.0, round($previous_balance - $actual_payment, 2));

            if ($payment_id > 0) {
                $stmt_upd = $conn->prepare("UPDATE lodging_payments SET previous_balance = ?, remaining_balance = ?, overpayment = ? WHERE id = ?");
                $stmt_upd->bind_param('dddi', $previous_balance, $remaining_balance, $change_given, $payment_id);
                $stmt_upd->execute();
            }

            $sync_info = cashier_sync_enrollment_after_payment($conn, $seminarian_id, $academic_year, $semester);

            $conn->commit();
            log_activity((int)($_SESSION['user'] ?? 0), "Recorded payment OR {$or_number} for seminarian ID {$seminarian_id}");
            $redir = 'cashier_receipt.php?payment_id=' . (int)$payment_id . '&auto_print=1' . (!empty($sync_info['upgraded_to_enrolled']) ? '&upgraded=1' : '');
            header('Location: ' . $redir);
            exit;
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ($e->getCode() === 1062) {
                $error = 'Official Receipt Number already exists. Another transaction may have used it simultaneously.';
            } else {
                $error = 'Failed to record payment: ' . $e->getMessage();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to record payment: ' . $e->getMessage();
        }
    }
}

$filter_ay = trim((string)($_GET['academic_year'] ?? ''));
$filter_sem = trim((string)($_GET['semester'] ?? ''));
if ($filter_ay === '' || !in_array($filter_ay, $ay_options, true)) {
    $filter_ay = $current_ay;
}
if (!in_array($filter_sem, ['1st', '2nd'], true)) {
    $filter_sem = '1st';
}

$payments = [];
$stmt_list = $conn->prepare(
    "SELECT p.*, COALESCE(up.full_name, u.username) as full_name,\n"
    . "  (\n"
    . "    SELECT GROUP_CONCAT(pi.particular ORDER BY pi.sort_order ASC, pi.id ASC SEPARATOR ', ')\n"
    . "    FROM cashier_payment_items pi\n"
    . "    WHERE pi.payment_id = p.id AND pi.amount > 0.0001\n"
    . "  ) as particulars_summary\n"
    . "FROM lodging_payments p\n"
    . "JOIN seminarians s ON s.id = p.seminarian_id\n"
    . "JOIN users u ON u.id = s.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "WHERE p.academic_year = ? AND p.semester = ?\n"
    . "ORDER BY p.payment_date DESC, p.id DESC\n"
    . "LIMIT 100"
);
$stmt_list->bind_param('ss', $filter_ay, $filter_sem);
$stmt_list->execute();
$res_list = $stmt_list->get_result();
while ($res_list && ($r = $res_list->fetch_assoc())) {
    $payments[] = $r;
}
?>
<?php
$pageTitle = 'Payments';
$activeNav = 'cashier_payments';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <h1 class="page-title"><i class="fas fa-money-bill-wave"></i> Payments</h1>

            <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
            <div id="ajaxMessage"></div>

            <div class="card" style="margin: 0 0 1.25rem 0;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <h3 style="margin:0;"><i class="fas fa-plus-circle"></i> Record Payment</h3>
                    <div style="font-size:0.85rem; color:var(--text-secondary);">Select seminarian to load fee checklist and balance</div>
                </div>
                <form method="post" id="recordPaymentForm" style="margin-top: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-grid two">
                        <div class="field">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <label for="seminarianSelect">Seminarian <span style="color:#ef4444;">*</span></label>
                                <span style="font-size:0.75rem; color:var(--text-secondary);">Type below to quick-filter</span>
                            </div>
                            <div style="margin-bottom:0.35rem;">
                                <input type="text" id="seminarianQuickSearch" placeholder="🔍 Quick search name or student ID (Press Enter)..." autocomplete="off" style="padding:0.45rem 0.65rem; font-size:0.85rem; width:100%; box-sizing:border-box; border:1px solid var(--border-color, #cbd5e1); border-radius:6px;">
                            </div>
                            <select name="seminarian_id" id="seminarianSelect" required>
                                <option value="">— Select Seminarian —</option>
                                <?php foreach ($students as $s) {
                                    $sid = (int)($s['seminarian_id'] ?? 0);
                                    $nm = (string)($s['full_name'] ?? $s['username'] ?? '');
                                    $sno = trim((string)($s['student_no'] ?? ''));
                                    $sno_prefix = $sno !== '' ? '[' . $sno . '] ' : '';
                                    $meta = trim((string)($s['program'] ?? '')) . ' • ' . trim((string)($s['year_level'] ?? ''));
                                    $isSelected = ($sid === $preselected_seminarian) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $sid; ?>" data-student-no="<?php echo htmlspecialchars($sno); ?>" data-student-name="<?php echo htmlspecialchars($nm); ?>" <?php echo $isSelected; ?>>
                                        <?php echo htmlspecialchars($sno_prefix . $nm . ' (' . $meta . ')'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                                <label for="orNumberInput">Official Receipt (OR) No. <span style="color:#ef4444;">*</span></label>
                                <span id="autoOrBadge" style="font-size:0.75rem; color:#059669; font-weight:700;"><i class="fas fa-bolt"></i> Auto-Generated</span>
                            </div>
                            <input type="text" name="or_number" id="orNumberInput" placeholder="Auto-generating next OR..." autocomplete="off" required>
                            <div id="orFeedback" style="font-size:0.8rem; margin-top:0.25rem; min-height:1.2rem;"></div>
                        </div>
                    </div>

                    <div class="form-grid two">
                        <div class="field">
                            <label for="academicYearSelect">Academic Year <span style="color:#ef4444;">*</span></label>
                            <select name="academic_year" id="academicYearSelect" required>
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="semesterSelect">Semester <span style="color:#ef4444;">*</span></label>
                            <select name="semester" id="semesterSelect" required>
                                <option value="1st" <?php echo $current_sem === '1st' ? 'selected' : ''; ?>>First Semester</option>
                                <option value="2nd" <?php echo $current_sem === '2nd' ? 'selected' : ''; ?>>Second Semester</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dynamic Assessment Details / Fee Breakdown Panel (Auto-loads on student selection) -->
                    <div id="assessmentBreakdownCard" class="hidden" style="margin-top: 1rem; padding: 1rem 1.25rem; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:0.85rem; padding-bottom:0.65rem; border-bottom:1px solid #e2e8f0;">
                            <div>
                                <div style="font-weight:900; font-size:0.98rem; color:#1e3a5f; display:flex; align-items:center; gap:0.45rem; flex-wrap:wrap;">
                                    <i class="fas fa-file-invoice-dollar" style="color:#065f46;"></i>
                                    <span>Assessment Details &amp; Fee Breakdown</span>
                                    <span id="bdStudentTermBadge" style="font-size:0.74rem; font-weight:800; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; padding:2px 8px; border-radius:999px;">—</span>
                                </div>
                                <div id="bdStudentSubtitle" style="font-size:0.8rem; color:#64748b; margin-top:0.15rem;">Live term fee summary &amp; payment balance</div>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                <a id="printSoaBtn" href="#" target="_blank" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.9rem; font-size:0.8rem; font-weight:800; color:#ffffff; background:#1e3a5f; border-radius:8px; text-decoration:none; box-shadow:0 2px 6px rgba(30,58,95,0.2);">
                                    <i class="fas fa-print"></i> <span>Print SOA / Receipt</span>
                                </a>
                                <button type="button" id="printBreakdownBtn" onclick="window.print()" style="width:auto; display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.85rem; font-size:0.8rem; font-weight:700; color:#1e3a5f; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer;">
                                    <i class="fas fa-file-lines"></i> <span>Print Page</span>
                                </button>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:0.65rem; margin-bottom:0.85rem;">
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:0.65rem 0.8rem;">
                                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Current Semester Fees</div>
                                <div id="bdCurrentTermFees" style="font-size:1.05rem; font-weight:900; color:#0f172a; margin-top:2px;">₱0.00</div>
                            </div>
                            <div style="background:#ffffff; border:1px solid #fecaca; border-radius:8px; padding:0.65rem 0.8rem;">
                                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; color:#991b1b; letter-spacing:0.04em;">Previous Balance</div>
                                <div id="bdPreviousBalance" style="font-size:1.05rem; font-weight:900; color:#b91c1c; margin-top:2px;">₱0.00</div>
                            </div>
                            <div style="background:#ffffff; border:1px solid #a7f3d0; border-radius:8px; padding:0.65rem 0.8rem;">
                                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; color:#047857; letter-spacing:0.04em;">Total Paid</div>
                                <div id="bdTotalPaid" style="font-size:1.05rem; font-weight:900; color:#059669; margin-top:2px;">₱0.00</div>
                            </div>
                            <div style="background:#ffffff; border:1.5px solid #065f46; border-radius:8px; padding:0.65rem 0.8rem;">
                                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; color:#065f46; letter-spacing:0.04em;">Remaining Balance</div>
                                <div id="bdRemainingBalance" style="font-size:1.05rem; font-weight:900; color:#065f46; margin-top:2px;">₱0.00</div>
                            </div>
                        </div>

                        <div style="overflow-x:auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px;">
                            <table style="width:100%; margin:0; border:none; border-collapse:collapse; font-size:0.82rem;">
                                <thead>
                                    <tr style="background:#f1f5f9;">
                                        <th style="padding:0.5rem 0.75rem; font-size:0.72rem; text-align:left;">Fee Particular</th>
                                        <th style="padding:0.5rem 0.75rem; font-size:0.72rem; text-align:right;">Assessed</th>
                                        <th style="padding:0.5rem 0.75rem; font-size:0.72rem; text-align:right;">Paid</th>
                                        <th style="padding:0.5rem 0.75rem; font-size:0.72rem; text-align:right;">Balance Due</th>
                                    </tr>
                                </thead>
                                <tbody id="bdItemsTbody">
                                    <tr>
                                        <td colspan="4" style="padding:0.75rem; text-align:center; color:#64748b;">Select a seminarian to view breakdown.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-grid two">
                        <div class="field">
                            <label>Date Paid <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                        </div>
                        <div class="field">
                            <label>Payment Method <span style="color:#ef4444;">*</span></label>
                            <select name="method" required>
                                <?php foreach ($methods as $k => $lbl) { ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid two">
                        <div class="field">
                            <label>Reference No. (optional)</label>
                            <input type="text" name="reference_no" placeholder="Bank ref / check no. / transaction id">
                        </div>
                        <div class="field">
                            <label>Payment Source</label>
                            <select name="payer_type">
                                <option value="">Select Source</option>
                                <?php foreach ($payer_types as $pt) { ?>
                                    <option value="<?php echo htmlspecialchars($pt); ?>"><?php echo htmlspecialchars($pt); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid two">
                        <div class="field">
                            <label for="payerNameInput">Payer Name / Sponsor</label>
                            <input type="text" name="payer_name" id="payerNameInput" placeholder="Name of student, sponsor, or parent">
                        </div>
                        <div class="field">
                            <label>Remarks / Notes (optional)</label>
                            <input type="text" name="remarks" placeholder="Optional notes for cashier record">
                        </div>
                    </div>

                    <!-- Fee Checklist Section -->
                    <div class="field" style="margin-top: 1.25rem;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem; padding-bottom:0.5rem; border-bottom:1px solid var(--border-color, #e2e8f0);">
                            <div>
                                <div style="font-weight:900; font-size:1.05rem; color:#065f46;"><i class="fas fa-list-check"></i> Fee Checklist</div>
                                <div style="font-size:0.85rem; color:var(--text-secondary);">Check the fees being collected and manually enter the amount to pay for each selected fee.</div>
                            </div>
                            <div style="display:flex; gap:0.5rem;">
                                <button type="button" id="btnSelectAllFees" style="width:auto; padding:0.35rem 0.75rem; font-size:0.8rem; background:rgba(6,95,70,0.1); color:#065f46; border:1px solid #065f46; border-radius:6px; font-weight:700; cursor:pointer;">Select All</button>
                                <button type="button" id="btnClearFees" style="width:auto; padding:0.35rem 0.75rem; font-size:0.8rem; background:transparent; color:var(--text-secondary); border:1px solid var(--border-color, #cbd5e1); border-radius:6px; cursor:pointer;">Clear All</button>
                            </div>
                        </div>

                        <!-- Dynamic Fees Checklist Grid -->
                        <div id="feesChecklistContainer" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 0.75rem;">
                            <div style="color:var(--text-secondary); grid-column:1/-1; padding:1.5rem; text-align:center; background:var(--bg-light, #f8fafc); border-radius:8px; border:1px dashed var(--border-color, #cbd5e1);">
                                <i class="fas fa-user-graduate" style="font-size:1.5rem; margin-bottom:0.5rem; display:block; opacity:0.5;"></i>
                                Select a seminarian above to view the fee checklist and account balance.
                            </div>
                        </div>

                        <!-- Custom / Other Particulars Container -->
                        <div style="margin-top:1rem; padding:1rem; background:var(--bg-card, #f8fafc); border:1px dashed var(--border-color, #cbd5e1); border-radius:8px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem;">
                                <span style="font-weight:700; font-size:0.9rem; color:#0f172a;"><i class="fas fa-plus-circle" style="color:#065f46;"></i> Additional / Custom Particulars</span>
                                <button type="button" id="addCustomFeeBtn" style="width:auto; padding:0.3rem 0.75rem; font-size:0.8rem; background:#065f46; color:#fff; border-radius:6px; border:none; cursor:pointer; font-weight:600;"><i class="fas fa-plus"></i> Add Custom Fee</button>
                            </div>
                            <div id="customParticularsContainer" style="display:flex; flex-direction:column; gap:0.5rem;">
                                <!-- Custom fee rows added dynamically -->
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Totals & Change Calculation Summary Bar -->
                    <div class="payment-summary-card" style="margin-top:1.5rem; padding:1.25rem; background:linear-gradient(135deg, #064e3b 0%, #065f46 100%); color:#fff; border-radius:12px; box-shadow:0 4px 14px rgba(6,78,59,0.2);">
                        <!-- Upper Line Items Transparency Row: Current Fees + Previous Balance = Grand Total Due -->
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; padding-bottom:0.85rem; margin-bottom:1rem; border-bottom:1px solid rgba(255,255,255,0.2); font-size:0.88rem;">
                            <div style="display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap;">
                                <div>
                                    <span style="opacity:0.85; font-weight:600;"><i class="fas fa-receipt"></i> Current Semester Fees:</span>
                                    <strong style="color:#ffffff; font-weight:800; margin-left:4px;" id="displayCurrentTermFees">₱0.00</strong>
                                </div>
                                <div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.25rem;">
                                    <span style="opacity:0.85; font-weight:600;"><i class="fas fa-clock-rotate-left"></i> Previous Balance:</span>
                                    <strong style="color:#fca5a5; font-weight:800; margin-left:4px;" id="displayPreviousTermBalance">₱0.00</strong>
                                </div>
                                <div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.25rem;">
                                    <span style="opacity:0.85; font-weight:600;"><i class="fas fa-calculator"></i> Grand Total Due:</span>
                                    <strong style="color:#fef08a; font-weight:900; font-size:1.05rem; margin-left:4px;" id="displayGrandTotalDue">₱0.00</strong>
                                </div>
                                <div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.25rem;">
                                    <span style="opacity:0.85; font-weight:600;"><i class="fas fa-circle-check"></i> Total Paid:</span>
                                    <strong style="color:#a7f3d0; font-weight:800; margin-left:4px;" id="displayTotalPaidSummary">₱0.00</strong>
                                </div>
                            </div>
                            <div style="font-size:0.75rem; background:rgba(0,0,0,0.2); padding:3px 10px; border-radius:999px; color:#a7f3d0; font-weight:700;">
                                <i class="fas fa-arrow-down-short-wide"></i> FIFO Payment Allocation Active
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items:flex-end;">
                            <div>
                                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.85; font-weight:700;">Total Balance Due</div>
                                <div style="font-size:1.35rem; font-weight:900; color:#fed7aa;" id="displayPrevBalance">₱0.00</div>
                                <button type="button" id="btnPayFullBalance" style="margin-top:0.35rem; padding:2px 8px; font-size:0.72rem; background:rgba(254,215,170,0.2); color:#fed7aa; border:1px solid rgba(254,215,170,0.4); border-radius:4px; cursor:pointer; font-weight:700;">Pay Full Balance</button>
                            </div>

                            <div style="background:rgba(255,255,255,0.12); padding:0.75rem 1rem; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.25rem;">
                                    <label for="amountPaidInput" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.95; font-weight:700; color:#fff; margin:0;">Amount to Pay (₱) <span style="color:#f87171;">*</span></label>
                                    <span id="displaySelectedTotal" style="font-size:0.72rem; color:#fef08a; font-weight:700;">Fees: ₱0.00</span>
                                </div>
                                <input type="number" name="amount" id="amountPaidInput" step="0.01" min="0" placeholder="0.00" value="0.00" required style="background:#fff; color:#064e3b; font-size:1.25rem; font-weight:900; padding:0.45rem 0.65rem; border-radius:6px; border:2px solid #fef08a; width:100%; box-sizing:border-box;">
                                <div style="font-size:0.7rem; color:#fef08a; margin-top:0.25rem;" id="amountToPayHelp">Payment Amount credited to account</div>
                            </div>

                            <div style="background:rgba(255,255,255,0.12); padding:0.75rem 1rem; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.25rem;">
                                    <label for="amountTenderedInput" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.95; font-weight:700; color:#fff; margin:0;">Cash Tendered (₱)</label>
                                    <span style="font-size:0.7rem; opacity:0.85;">Amount received</span>
                                </div>
                                <input type="number" name="amount_tendered" id="amountTenderedInput" step="0.01" min="0" placeholder="0.00" autocomplete="off" style="background:#fff; color:#0f172a; font-size:1.25rem; font-weight:900; padding:0.45rem 0.65rem; border-radius:6px; border:2px solid #38bdf8; width:100%; box-sizing:border-box;">
                                <div id="insufficientTenderedWarning" class="hidden mt-1.5 text-xs font-semibold text-red-700 bg-red-50 border border-red-200 rounded px-2.5 py-1" style="display:none;" role="alert">
                                    <i class="fas fa-exclamation-circle mr-1"></i>Insufficient cash tendered
                                </div>
                                <div style="margin-top: 0.35rem; display: flex; flex-wrap: wrap; gap: 0.25rem;" id="quickDenominations">
                                    <button type="button" class="denom-btn" data-mode="exact" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.25); color:#fff; border:1px solid rgba(255,255,255,0.4); border-radius:4px; cursor:pointer; font-weight:700;">Exact</button>
                                    <button type="button" class="denom-btn" data-mode="add" data-val="500" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:4px; cursor:pointer;">+500</button>
                                    <button type="button" class="denom-btn" data-mode="add" data-val="1000" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:4px; cursor:pointer;">+1k</button>
                                    <button type="button" class="denom-btn" data-mode="set" data-val="1000" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:4px; cursor:pointer;">₱1k</button>
                                    <button type="button" class="denom-btn" data-mode="set" data-val="5000" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:4px; cursor:pointer;">₱5k</button>
                                    <button type="button" class="denom-btn" data-mode="set" data-val="10000" style="padding:2px 7px; font-size:0.75rem; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:4px; cursor:pointer;">₱10k</button>
                                </div>
                            </div>

                            <div id="changeSummaryBox" class="hidden bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg p-3 font-semibold" style="display:none;">
                                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.25rem;">
                                    <label for="changeInput" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#047857; font-weight:800; margin:0;">Change (Sukli)</label>
                                    <span id="changeStatusBadge" style="font-size:0.7rem; font-weight:700; color:#059669;"></span>
                                </div>
                                <input type="text" id="changeInput" readonly value="" style="background:#ffffff; color:#047857; font-size:1.35rem; font-weight:900; padding:0.4rem 0.65rem; border-radius:6px; border:1.5px solid #34d399; width:100%; box-sizing:border-box; text-align:right; cursor:not-allowed;">
                                <div style="font-size:0.72rem; color:#047857; margin-top:0.25rem;" id="changeHelpText">Change to return to payer</div>
                            </div>

                            <div style="background:rgba(0,0,0,0.2); padding:0.75rem 1rem; border-radius:8px;">
                                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.95; font-weight:700; color:#a7f3d0;">Remaining Balance</div>
                                <div style="font-size:1.35rem; font-weight:900; color:#6ee7b7;" id="displayNewRemainingBalance">₱0.00</div>
                                <div style="font-size:0.7rem; opacity:0.8; margin-top:0.25rem;">After this payment</div>
                            </div>
                        </div>

                        <!-- Live Real-Time Payment & Partial Payment Alert Banner -->
                        <div id="overpaymentAlert" style="display:none; margin-top:1rem; padding:0.75rem 1rem; border-radius:8px; font-weight:700; font-size:0.9rem;">
                        </div>

                        <div style="margin-top:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; border-top:1px solid rgba(255,255,255,0.2); padding-top:1rem;">
                            <div style="font-size:0.85rem; opacity:0.9;">
                                <i class="fas fa-info-circle"></i> Payments automatically allocate to term invoice items and balance.
                            </div>
                            <div style="display:flex; align-items:center; gap:0.65rem; flex-wrap:wrap;">
                                <a id="summaryPrintSoaBtn" href="#" target="_blank" class="hidden" style="background:rgba(255,255,255,0.16); color:#ffffff; font-weight:800; font-size:0.9rem; padding:0.7rem 1.15rem; border:1px solid rgba(255,255,255,0.35); border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:0.45rem;">
                                    <i class="fas fa-file-invoice"></i> <span>Print SOA</span>
                                </a>
                                <a id="printLastReceiptBtn" href="#" target="_blank" class="hidden" style="background:#a7f3d0; color:#064e3b; font-weight:900; font-size:0.9rem; padding:0.7rem 1.15rem; border:none; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:0.45rem; box-shadow:0 3px 8px rgba(0,0,0,0.2);">
                                    <i class="fas fa-print"></i> <span>Print Last Receipt</span>
                                </a>
                                <button type="submit" name="record_payment" id="submitPaymentBtn" style="background:#fef08a; color:#713f12; font-weight:900; font-size:1rem; padding:0.75rem 1.75rem; border:none; border-radius:8px; cursor:pointer; box-shadow:0 3px 8px rgba(0,0,0,0.25); transition:transform 0.1s ease; display:inline-flex; align-items:center; gap:0.5rem;">
                                    <i class="fas fa-print"></i> <span>Save & Print Receipt</span> <span style="font-size:0.7rem; font-weight:700; background:rgba(113,63,18,0.15); padding:2px 6px; border-radius:4px;">Ctrl+Enter / F2</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="data-card">
                <div class="data-card-header">
                    <h3><i class="fas fa-list"></i> Payments List</h3>
                    <form method="get" id="paymentsFilterForm" style="display:flex; gap:0.5rem; align-items:center; margin:0;">
                        <select name="academic_year">
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_ay ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <select name="semester">
                            <option value="1st" <?php echo $filter_sem === '1st' ? 'selected' : ''; ?>>First Semester</option>
                            <option value="2nd" <?php echo $filter_sem === '2nd' ? 'selected' : ''; ?>>Second Semester</option>
                        </select>
                        <button type="submit" style="width:auto;">Load</button>
                    </form>
                </div>
                <div style="overflow-x:auto;">
                    <table class="table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>OR No.</th>
                                <th>Seminarian</th>
                                <th>Source</th>
                                <th>Particulars</th>
                                <th>Amount Paid</th>
                                <th>Tendered</th>
                                <th>Change</th>
                                <th>Method</th>
                                <th>Balance</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody id="paymentsTbody">
                            <?php if (count($payments) === 0) { ?>
                                <tr><td colspan="11" style="color: var(--text-secondary);">No payments found.</td></tr>
                            <?php } else { foreach ($payments as $p) { ?>
                                <tr>
                                    <td data-label="Date"><?php echo htmlspecialchars((string)($p['payment_date'] ?? '')); ?></td>
                                    <td data-label="OR No."><strong><?php echo htmlspecialchars((string)($p['or_number'] ?? '')); ?></strong></td>
                                    <td data-label="Seminarian"><?php echo htmlspecialchars((string)($p['full_name'] ?? '')); ?></td>
                                    <td data-label="Source"><?php echo htmlspecialchars((string)($p['payer_type'] ?? '')); ?></td>
                                    <td data-label="Particulars"><?php echo htmlspecialchars((string)($p['particulars_summary'] ?? $p['notes'] ?? '')); ?></td>
                                    <td data-label="Amount Paid"><?php echo htmlspecialchars(number_format((float)($p['amount'] ?? 0), 2)); ?></td>
                                    <td data-label="Tendered"><?php echo htmlspecialchars(number_format((float)($p['amount_tendered'] ?? $p['amount'] ?? 0), 2)); ?></td>
                                    <td data-label="Change"><?php echo ((float)($p['overpayment'] ?? 0) > 0.0001) ? ('₱' . htmlspecialchars(number_format((float)$p['overpayment'], 2))) : '—'; ?></td>
                                    <td data-label="Method"><?php echo htmlspecialchars(strtoupper((string)($p['method'] ?? ''))); ?></td>
                                    <td data-label="Balance"><?php echo htmlspecialchars(number_format((float)($p['remaining_balance'] ?? 0), 2)); ?></td>
                                    <td data-label="Receipt"><a class="back-link" style="margin:0;" href="cashier_receipt.php?payment_id=<?php echo (int)($p['id'] ?? 0); ?>" target="_blank">Print</a></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function () {
            const form = document.getElementById('recordPaymentForm');
            const seminarianSelect = document.getElementById('seminarianSelect');
            const aySelect = document.getElementById('academicYearSelect');
            const semSelect = document.getElementById('semesterSelect');
            const orInput = document.getElementById('orNumberInput');
            const orFeedback = document.getElementById('orFeedback');
            const payerNameInput = document.getElementById('payerNameInput');
            const useSuggestedOrBtn = document.getElementById('useSuggestedOrBtn');
            const checklistContainer = document.getElementById('feesChecklistContainer');
            const customContainer = document.getElementById('customParticularsContainer');
            const addCustomBtn = document.getElementById('addCustomFeeBtn');
            const selectAllBtn = document.getElementById('btnSelectAllFees');
            const clearFeesBtn = document.getElementById('btnClearFees');
            const displaySelectedTotal = document.getElementById('displaySelectedTotal');
            const displayCurrentTermFees = document.getElementById('displayCurrentTermFees');
            const displayPreviousTermBalance = document.getElementById('displayPreviousTermBalance');
            const displayGrandTotalDue = document.getElementById('displayGrandTotalDue');
            const displayTotalPaidSummary = document.getElementById('displayTotalPaidSummary');
            const displayPrevBalance = document.getElementById('displayPrevBalance');
            const assessmentBreakdownCard = document.getElementById('assessmentBreakdownCard');
            const bdStudentTermBadge = document.getElementById('bdStudentTermBadge');
            const bdStudentSubtitle = document.getElementById('bdStudentSubtitle');
            const bdCurrentTermFees = document.getElementById('bdCurrentTermFees');
            const bdPreviousBalance = document.getElementById('bdPreviousBalance');
            const bdTotalPaid = document.getElementById('bdTotalPaid');
            const bdRemainingBalance = document.getElementById('bdRemainingBalance');
            const bdItemsTbody = document.getElementById('bdItemsTbody');
            const printSoaBtn = document.getElementById('printSoaBtn');
            const summaryPrintSoaBtn = document.getElementById('summaryPrintSoaBtn');
            const printLastReceiptBtn = document.getElementById('printLastReceiptBtn');
            const btnPayFullBalance = document.getElementById('btnPayFullBalance');
            const displayNewRemainingBalance = document.getElementById('displayNewRemainingBalance');
            const overpaymentAlert = document.getElementById('overpaymentAlert');
            const submitPaymentBtn = document.getElementById('submitPaymentBtn');
            const amountPaidInput = document.getElementById('amountPaidInput');
            const amountTenderedInput = document.getElementById('amountTenderedInput');
            const insufficientTenderedWarning = document.getElementById('insufficientTenderedWarning');
            const changeSummaryBox = document.getElementById('changeSummaryBox');
            const changeInput = document.getElementById('changeInput');
            const changeStatusBadge = document.getElementById('changeStatusBadge');
            const changeHelpText = document.getElementById('changeHelpText');
            const msg = document.getElementById('ajaxMessage');
            const listForm = document.getElementById('paymentsFilterForm');
            const tbody = document.getElementById('paymentsTbody');

            if (!form || !seminarianSelect || !orInput) return;

            let currentPreviousBalance = 0.0;
            let currentSuggestedOr = '';
            let userModifiedTendered = false;
            let userModifiedAmountPaid = false;

            function escapeHtml(str) {
                return String(str || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatCurrency(val) {
                return Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function calculateChangeAndBalance() {
                const amountToPay = Math.round((parseFloat(amountPaidInput ? amountPaidInput.value : '0') || 0) * 100) / 100;
                const tenderedStr = amountTenderedInput ? amountTenderedInput.value.trim() : '';
                const hasTendered = tenderedStr !== '';
                const tendered = hasTendered ? Math.round((parseFloat(tenderedStr) || 0) * 100) / 100 : 0.0;
                const currentBal = Math.round(currentPreviousBalance * 100) / 100;

                const isInsufficient = hasTendered && (tendered < (amountToPay - 0.001));
                const change = (hasTendered && !isInsufficient)
                    ? Math.max(0, Math.round((tendered - amountToPay) * 100) / 100)
                    : 0.0;
                const effectivePayment = amountToPay;

                // Show/hide Insufficient cash tendered warning
                if (insufficientTenderedWarning) {
                    if (isInsufficient) {
                        insufficientTenderedWarning.classList.remove('hidden');
                        insufficientTenderedWarning.style.display = 'block';
                    } else {
                        insufficientTenderedWarning.classList.add('hidden');
                        insufficientTenderedWarning.style.display = 'none';
                    }
                }
                if (amountTenderedInput) {
                    amountTenderedInput.style.borderColor = isInsufficient ? '#ef4444' : '#38bdf8';
                }

                // Show Change (Sukli) box ONLY when change > 0 (hide completely on exact payment or empty)
                if (changeSummaryBox) {
                    if (hasTendered && change > 0.001) {
                        changeSummaryBox.classList.remove('hidden');
                        changeSummaryBox.style.display = 'block';
                        if (changeInput) {
                            changeInput.value = '₱ ' + formatCurrency(change);
                        }
                        if (changeStatusBadge) {
                            changeStatusBadge.textContent = '₱ ' + formatCurrency(change);
                        }
                        if (changeHelpText) {
                            changeHelpText.innerHTML = '<i class="fas fa-check-circle"></i> Sukli to return: <strong>₱ ' + formatCurrency(change) + '</strong>';
                        }
                    } else {
                        changeSummaryBox.classList.add('hidden');
                        changeSummaryBox.style.display = 'none';
                        if (changeInput) {
                            changeInput.value = '';
                        }
                        if (changeStatusBadge) {
                            changeStatusBadge.textContent = '';
                        }
                    }
                }

                // Balance remaining after this payment (strictly amountToPay credited)
                const newRemaining = Math.max(0, Math.round((currentBal - effectivePayment) * 100) / 100);
                if (displayNewRemainingBalance) {
                    displayNewRemainingBalance.textContent = '₱' + formatCurrency(newRemaining);
                }

                // Overpayment / Insufficient Tendered Validation & Live Alerts
                const exceedsBalance = effectivePayment > (currentBal + 0.005) && currentBal > 0;
                const shouldDisableSubmit = exceedsBalance || isInsufficient;

                if (exceedsBalance) {
                    if (overpaymentAlert) {
                        overpaymentAlert.style.display = 'block';
                        overpaymentAlert.style.background = '#fef2f2';
                        overpaymentAlert.style.border = '1px solid #ef4444';
                        overpaymentAlert.style.color = '#991b1b';
                        overpaymentAlert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Payment amount (₱' + formatCurrency(effectivePayment) + ') exceeds the current remaining balance of ₱' + formatCurrency(currentBal) + '. Please enter an amount equal to or less than ₱' + formatCurrency(currentBal) + '.';
                    }
                } else if (isInsufficient) {
                    if (overpaymentAlert) {
                        overpaymentAlert.style.display = 'block';
                        overpaymentAlert.style.background = '#fef2f2';
                        overpaymentAlert.style.border = '1px solid #ef4444';
                        overpaymentAlert.style.color = '#991b1b';
                        overpaymentAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> <strong>Insufficient cash tendered:</strong> Cash received (₱' + formatCurrency(tendered) + ') is less than Amount to Pay (₱' + formatCurrency(amountToPay) + ').';
                    }
                } else if (overpaymentAlert) {
                    if (currentBal <= 0) {
                        overpaymentAlert.style.display = 'block';
                        overpaymentAlert.style.background = '#f0fdf4';
                        overpaymentAlert.style.border = '1px solid #86efac';
                        overpaymentAlert.style.color = '#166534';
                        overpaymentAlert.innerHTML = '<i class="fas fa-check-circle"></i> This student has no remaining balance due for this term (Account Cleared: ₱0.00).';
                    } else {
                        overpaymentAlert.style.display = 'none';
                        overpaymentAlert.innerHTML = '';
                    }
                }

                if (submitPaymentBtn) {
                    submitPaymentBtn.disabled = shouldDisableSubmit;
                    submitPaymentBtn.style.opacity = shouldDisableSubmit ? '0.5' : '1';
                    submitPaymentBtn.style.cursor = shouldDisableSubmit ? 'not-allowed' : 'pointer';
                }

                return { effectivePayment, change, isInsufficient, amountToPay, tendered, hasTendered };
            }

            function recalculateTotals() {
                let sum = 0.0;
                // Sum standard checklist items that are checked
                const checkedStandard = checklistContainer.querySelectorAll('.fee-checkbox:checked');
                checkedStandard.forEach(function (cb) {
                    const row = cb.closest('.fee-checklist-card');
                    if (row) {
                        const amtInput = row.querySelector('.fee-amount-input');
                        const v = parseFloat(amtInput ? amtInput.value : '0') || 0;
                        sum += v;
                    }
                });

                // Sum custom items that are checked
                const checkedCustom = customContainer.querySelectorAll('.custom-fee-checkbox:checked');
                checkedCustom.forEach(function (cb) {
                    const row = cb.closest('.custom-fee-row');
                    if (row) {
                        const amtInput = row.querySelector('.custom-fee-amount');
                        const v = parseFloat(amtInput ? amtInput.value : '0') || 0;
                        sum += v;
                    }
                });

                sum = Math.round(sum * 100) / 100;
                if (displaySelectedTotal) {
                    displaySelectedTotal.textContent = 'Fees: ₱' + formatCurrency(sum);
                }

                // If user hasn't typed a custom Amount to Pay, sync it strictly with manually entered checked fee amounts
                if (!userModifiedAmountPaid && amountPaidInput) {
                    amountPaidInput.value = sum > 0 ? sum.toFixed(2) : '0.00';
                }

                // Auto-sync exact amount tendered if cashier hasn't manually customized it
                if (!userModifiedTendered && amountTenderedInput && amountPaidInput) {
                    const toPay = parseFloat(amountPaidInput.value || '0') || 0;
                    amountTenderedInput.value = toPay > 0 ? toPay.toFixed(2) : '';
                }

                return calculateChangeAndBalance();
            }

            async function checkOrNumber(val) {
                val = (val || '').trim();
                if (!val) {
                    orFeedback.innerHTML = '';
                    orInput.style.borderColor = '';
                    return;
                }
                try {
                    const res = await fetch('ajax.php?action=check_or_number&or_number=' + encodeURIComponent(val));
                    const data = await res.json();
                    if (data && data.taken) {
                        orFeedback.innerHTML = '<span style="color:#ef4444; font-weight:700;"><i class="fas fa-times-circle"></i> OR Number already exists in records!</span>';
                        orInput.style.borderColor = '#ef4444';
                    } else {
                        orFeedback.innerHTML = '<span style="color:#059669; font-weight:700;"><i class="fas fa-check-circle"></i> Available</span>';
                        orInput.style.borderColor = '#059669';
                    }
                } catch (err) {
                    orFeedback.innerHTML = '';
                }
            }

            async function suggestOrNumber(force = false) {
                if (!force && orInput.value.trim() !== '') return;
                try {
                    const res = await fetch('ajax.php?action=suggest_or_number');
                    const data = await res.json();
                    if (data && data.suggested) {
                        currentSuggestedOr = data.suggested;
                        orInput.value = data.suggested;
                        orInput.placeholder = data.suggested;
                        checkOrNumber(data.suggested);
                    }
                } catch (err) {
                    // ignore
                }
            }

            orInput.addEventListener('focus', function () {
                suggestOrNumber(false);
            });
            orInput.addEventListener('blur', function () {
                checkOrNumber(this.value);
            });

            if (amountPaidInput) {
                amountPaidInput.addEventListener('input', function () {
                    userModifiedAmountPaid = true;
                    calculateChangeAndBalance();
                });
            }

            if (amountTenderedInput) {
                amountTenderedInput.addEventListener('input', function () {
                    userModifiedTendered = true;
                    calculateChangeAndBalance();
                });
            }

            if (btnPayFullBalance) {
                btnPayFullBalance.addEventListener('click', function () {
                    // Auto-check all unpaid fee items and fill their exact remaining balances
                    checklistContainer.querySelectorAll('.fee-checkbox').forEach(function (cb) {
                        const card = cb.closest('.fee-checklist-card');
                        if (!card) return;
                        const amt = card.querySelector('.fee-amount-input');
                        const rem = parseFloat(card.dataset.remBal || '0') || 0;
                        if (card.dataset.isZero !== '1' && rem > 0) {
                            cb.checked = true;
                            if (amt) {
                                amt.disabled = false;
                                amt.value = rem.toFixed(2);
                            }
                            if (card.dataset.isPrevBal === '1') {
                                card.style.borderColor = '#ef4444';
                                card.style.background = '#fff1f2';
                            } else {
                                card.style.borderColor = '#065f46';
                                card.style.background = 'rgba(6,95,70,0.04)';
                            }
                        } else {
                            cb.checked = false;
                            if (amt) {
                                amt.disabled = true;
                                amt.value = '';
                            }
                        }
                    });
                    userModifiedAmountPaid = false;
                    recalculateTotals();
                });
            }

            // Quick Denomination Chips Click Handler
            document.querySelectorAll('.denom-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const mode = this.dataset.mode;
                    const due = parseFloat((amountPaidInput ? amountPaidInput.value : '0') || '0') || 0;
                    let cur = parseFloat((amountTenderedInput ? amountTenderedInput.value : '0') || '0') || 0;

                    if (mode === 'exact') {
                        userModifiedTendered = false;
                        if (amountTenderedInput) {
                            amountTenderedInput.value = due > 0 ? due.toFixed(2) : '';
                        }
                    } else if (mode === 'add') {
                        userModifiedTendered = true;
                        const addVal = parseFloat(this.dataset.val || '0');
                        const base = cur > 0 ? cur : due;
                        if (amountTenderedInput) {
                            amountTenderedInput.value = (base + addVal).toFixed(2);
                        }
                    } else if (mode === 'set') {
                        userModifiedTendered = true;
                        const setVal = parseFloat(this.dataset.val || '0');
                        if (amountTenderedInput) {
                            amountTenderedInput.value = setVal.toFixed(2);
                        }
                    } else if (mode === 'round') {
                        userModifiedTendered = true;
                        const target = cur > 0 ? cur : due;
                        let rounded = Math.ceil(target / 100) * 100;
                        if (rounded <= target) rounded += 100;
                        if (amountTenderedInput) {
                            amountTenderedInput.value = rounded.toFixed(2);
                        }
                    }
                    calculateChangeAndBalance();
                });
            });

            function renderFeeItems(fees) {
                checklistContainer.innerHTML = '';
                if (!fees || fees.length === 0) {
                    checklistContainer.innerHTML = '<div style="color:var(--text-secondary); grid-column:1/-1; padding:1.25rem; text-align:center; background:var(--bg-light, #f8fafc); border-radius:8px;">No scheduled fees found for this seminarian. Use "Additional Particulars" below to add custom fees.</div>';
                    recalculateTotals();
                    return;
                }

                fees.forEach(function (f) {
                    const card = document.createElement('div');
                    card.className = 'fee-checklist-card';
                    card.style.display = 'flex';
                    card.style.alignItems = 'center';
                    card.style.justifyContent = 'space-between';
                    card.style.gap = '0.75rem';
                    card.style.padding = '0.75rem 1rem';
                    card.style.borderRadius = '8px';
                    card.style.transition = 'all 0.15s ease';

                    const isPrevBal = Boolean(f.is_previous_balance);
                    const particularEsc = escapeHtml(f.particular || '');
                    const remBal = typeof f.item_remaining_balance !== 'undefined' ? parseFloat(f.item_remaining_balance) : parseFloat(f.amount || 0);
                    const origAmt = typeof f.original_amount !== 'undefined' ? parseFloat(f.original_amount) : remBal;
                    const isZero = (remBal <= 0.0001);
                    const readonlyAttr = isZero ? 'readonly' : '';
                    const readonlyStyle = isZero ? 'background:#f1f5f9; color:#64748b; cursor:not-allowed;' : '';

                    card.dataset.isZero = isZero ? '1' : '0';
                    card.dataset.isPrevBal = isPrevBal ? '1' : '0';
                    card.dataset.remBal = remBal.toFixed(2);

                    card.style.background = 'var(--bg-card, #fff)';
                    card.style.border = '1px solid var(--border-color, #e2e8f0)';

                    let statusBadge = '';
                    if (isPrevBal) {
                        if (isZero) {
                            statusBadge = '<span class="fee-status-badge" style="font-size:0.75rem; background:#e2e8f0; color:#475569; padding:2px 8px; border-radius:4px; font-weight:600;"><i class="fas fa-check-circle" style="color:#059669; font-size:0.7rem; margin-right:3px;"></i> Cleared (₱0.00)</span>';
                        } else {
                            statusBadge = '<span class="fee-status-badge" style="font-size:0.75rem; background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:4px; font-weight:700;"><i class="fas fa-clock-rotate-left"></i> Prev Balance: ₱' + formatCurrency(remBal) + '</span>';
                        }
                    } else if (isZero) {
                        if (origAmt > 0) {
                            statusBadge = '<span class="fee-status-badge" style="font-size:0.75rem; background:#e2e8f0; color:#475569; padding:2px 8px; border-radius:4px; font-weight:600;"><i class="fas fa-check-circle" style="color:#059669; font-size:0.7rem; margin-right:3px;"></i> Fully Paid (₱0.00)</span>';
                        } else {
                            statusBadge = '<span class="fee-status-badge" style="font-size:0.75rem; background:#f1f5f9; color:#64748b; padding:2px 8px; border-radius:4px; font-weight:600;">(Non-Monetary)</span>';
                        }
                    } else {
                        statusBadge = '<span class="fee-status-badge" style="font-size:0.75rem; background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-weight:600;">Balance: ₱' + formatCurrency(remBal) + '</span>';
                    }

                    const labelContent = isPrevBal
                        ? '<span style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; color:#991b1b; font-weight:800;"><i class="fas fa-history" style="color:#ef4444;"></i> ' + particularEsc + statusBadge + '</span>'
                        : '<span style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">' + particularEsc + statusBadge + '</span>';

                    card.innerHTML =
                        '<label style="display:flex; align-items:center; gap:0.6rem; margin:0; cursor:pointer; flex:1; font-weight:600;">' +
                            '<input type="checkbox" name="particulars_select[]" value="' + particularEsc + '" class="fee-checkbox" style="width:1.15rem; height:1.15rem; cursor:pointer;">' +
                            labelContent +
                        '</label>' +
                        '<div style="width:140px; display:flex; align-items:center;">' +
                            '<span style="font-size:0.85rem; color:var(--text-secondary); margin-right:4px;">₱</span>' +
                            '<input type="number" name="particular_amount[]" class="fee-amount-input" step="0.01" min="0" value="" placeholder="0.00" disabled ' + readonlyAttr + ' style="padding:0.4rem; font-weight:700; width:100%; border:1px solid ' + (isPrevBal && !isZero ? '#ef4444' : 'var(--border-color, #cbd5e1)') + '; border-radius:6px; text-align:right; ' + readonlyStyle + '">' +
                        '</div>';

                    const cb = card.querySelector('.fee-checkbox');
                    const amtInput = card.querySelector('.fee-amount-input');

                    cb.addEventListener('change', function () {
                        amtInput.disabled = !this.checked;
                        if (this.checked) {
                            if (!isZero && (amtInput.value === '' || parseFloat(amtInput.value) === 0)) {
                                amtInput.value = remBal.toFixed(2);
                            }
                            if (card.dataset.isPrevBal === '1' && card.dataset.isZero !== '1') {
                                card.style.borderColor = '#ef4444';
                                card.style.background = '#fff1f2';
                                amtInput.focus();
                            } else if (card.dataset.isZero === '1') {
                                card.style.borderColor = '#cbd5e1';
                                card.style.background = '#f8fafc';
                            } else {
                                card.style.borderColor = '#065f46';
                                card.style.background = 'rgba(6,95,70,0.04)';
                                amtInput.focus();
                            }
                        } else {
                            amtInput.value = '';
                            card.style.borderColor = 'var(--border-color, #e2e8f0)';
                            card.style.background = 'var(--bg-card, #fff)';
                        }
                        recalculateTotals();
                    });

                    if (!isZero) {
                        amtInput.addEventListener('input', recalculateTotals);
                    }
                    checklistContainer.appendChild(card);
                });

                recalculateTotals();
            }

            function addCustomParticularRow(desc, amt) {
                const row = document.createElement('div');
                row.className = 'custom-fee-row';
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.gap = '0.5rem';
                row.style.flexWrap = 'wrap';

                const descVal = escapeHtml(desc || '');
                const amtVal = amt ? Number(amt).toFixed(2) : '';

                row.innerHTML =
                    '<input type="checkbox" checked class="custom-fee-checkbox" style="width:1.15rem; height:1.15rem; cursor:pointer;">' +
                    '<input type="hidden" name="particulars_select[]" class="custom-fee-select-hidden" value="' + descVal + '">' +
                    '<input type="text" placeholder="Description (e.g. Retreat Fee, ID Card)" value="' + descVal + '" required style="flex:1 1 200px; padding:0.45rem 0.65rem; border:1px solid var(--border-color, #cbd5e1); border-radius:6px;" class="custom-fee-desc-input">' +
                    '<div style="display:flex; align-items:center; width:140px;">' +
                        '<span style="font-size:0.85rem; color:var(--text-secondary); margin-right:4px;">₱</span>' +
                        '<input type="number" name="particular_amount[]" class="custom-fee-amount" step="0.01" min="0.01" value="' + amtVal + '" placeholder="0.00" required style="padding:0.45rem; font-weight:700; width:100%; border:1px solid var(--border-color, #cbd5e1); border-radius:6px; text-align:right;">' +
                    '</div>' +
                    '<button type="button" class="btn-remove-custom" style="width:auto; padding:0.45rem 0.65rem; background:#fee2e2; color:#b91c1c; border:none; border-radius:6px; cursor:pointer;" title="Remove"><i class="fas fa-trash"></i></button>';

                const cb = row.querySelector('.custom-fee-checkbox');
                const hiddenSel = row.querySelector('.custom-fee-select-hidden');
                const otherInput = row.querySelector('.custom-fee-desc-input');
                const amtInput = row.querySelector('.custom-fee-amount');
                const rmBtn = row.querySelector('.btn-remove-custom');

                otherInput.addEventListener('input', function () {
                    if (hiddenSel) hiddenSel.value = otherInput.value.trim();
                });

                cb.addEventListener('change', function () {
                    if (hiddenSel) hiddenSel.disabled = !this.checked;
                    otherInput.disabled = !this.checked;
                    amtInput.disabled = !this.checked;
                    recalculateTotals();
                });

                amtInput.addEventListener('input', recalculateTotals);
                rmBtn.addEventListener('click', function () {
                    row.remove();
                    recalculateTotals();
                });

                customContainer.appendChild(row);
                otherInput.focus();
                recalculateTotals();
            }

            addCustomBtn.addEventListener('click', function () {
                addCustomParticularRow('', '');
            });

            selectAllBtn.addEventListener('click', function () {
                checklistContainer.querySelectorAll('.fee-checkbox').forEach(function (cb) {
                    cb.checked = true;
                    const card = cb.closest('.fee-checklist-card');
                    if (card) {
                        const amt = card.querySelector('.fee-amount-input');
                        if (amt) {
                            amt.disabled = false;
                            const rem = parseFloat(card.dataset.remBal || '0') || 0;
                            if (card.dataset.isZero !== '1' && rem > 0 && (amt.value === '' || parseFloat(amt.value) === 0)) {
                                amt.value = rem.toFixed(2);
                            }
                        }
                        if (card.dataset.isPrevBal === '1' && card.dataset.isZero !== '1') {
                            card.style.borderColor = '#ef4444';
                            card.style.background = '#fff1f2';
                        } else if (card.dataset.isZero === '1') {
                            card.style.borderColor = '#cbd5e1';
                            card.style.background = '#f8fafc';
                        } else {
                            card.style.borderColor = '#065f46';
                            card.style.background = 'rgba(6,95,70,0.04)';
                        }
                    }
                });
                recalculateTotals();
            });

            clearFeesBtn.addEventListener('click', function () {
                checklistContainer.querySelectorAll('.fee-checkbox').forEach(function (cb) {
                    cb.checked = false;
                    const card = cb.closest('.fee-checklist-card');
                    if (card) {
                        const amt = card.querySelector('.fee-amount-input');
                        if (amt) {
                            amt.disabled = true;
                            amt.value = '';
                        }
                        card.style.borderColor = 'var(--border-color, #e2e8f0)';
                        card.style.background = 'var(--bg-card, #fff)';
                    }
                });
                recalculateTotals();
            });

            async function loadStudentFees() {
                const sid = seminarianSelect.value;
                const ay = aySelect.value;
                const sem = semSelect.value;
                userModifiedAmountPaid = false;
                userModifiedTendered = false;
                if (payerNameInput) {
                    payerNameInput.value = '';
                }
                if (!sid) {
                    checklistContainer.innerHTML = '<div style="color:var(--text-secondary); grid-column:1/-1; padding:1.5rem; text-align:center; background:var(--bg-light, #f8fafc); border-radius:8px; border:1px dashed var(--border-color, #cbd5e1);"><i class="fas fa-user-graduate" style="font-size:1.5rem; margin-bottom:0.5rem; display:block; opacity:0.5;"></i>Select a seminarian above to view the fee checklist and account balance.</div>';
                    if (assessmentBreakdownCard) {
                        assessmentBreakdownCard.classList.add('hidden');
                        assessmentBreakdownCard.style.display = 'none';
                    }
                    if (summaryPrintSoaBtn) {
                        summaryPrintSoaBtn.classList.add('hidden');
                        summaryPrintSoaBtn.style.display = 'none';
                    }
                    if (displayCurrentTermFees) displayCurrentTermFees.textContent = '₱0.00';
                    if (displayPreviousTermBalance) displayPreviousTermBalance.textContent = '₱0.00';
                    if (displayGrandTotalDue) displayGrandTotalDue.textContent = '₱0.00';
                    if (displayTotalPaidSummary) displayTotalPaidSummary.textContent = '₱0.00';
                    if (displayPrevBalance) displayPrevBalance.textContent = '₱0.00';
                    currentPreviousBalance = 0.0;
                    if (amountPaidInput) amountPaidInput.value = '0.00';
                    if (amountTenderedInput) amountTenderedInput.value = '';
                    if (displayNewRemainingBalance) displayNewRemainingBalance.textContent = '₱0.00';
                    recalculateTotals();
                    return;
                }

                checklistContainer.innerHTML = '<div style="grid-column:1/-1; padding:1.5rem; text-align:center; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading fees and account balance...</div>';
                const soaHref = 'cashier_soa.php?student_id=' + encodeURIComponent(sid) + '&academic_year=' + encodeURIComponent(ay) + '&semester=' + encodeURIComponent(sem);
                if (printSoaBtn) printSoaBtn.href = soaHref;
                if (summaryPrintSoaBtn) {
                    summaryPrintSoaBtn.href = soaHref;
                    summaryPrintSoaBtn.classList.remove('hidden');
                    summaryPrintSoaBtn.style.display = 'inline-flex';
                }

                try {
                    const res = await fetch('ajax.php?action=get_student_fees&seminarian_id=' + encodeURIComponent(sid) + '&academic_year=' + encodeURIComponent(ay) + '&semester=' + encodeURIComponent(sem));
                    const data = await res.json();
                    if (data && data.ok) {
                        currentPreviousBalance = parseFloat(data.remaining_balance !== undefined ? data.remaining_balance : (data.previous_balance || 0));
                        
                        const curTermDue = parseFloat(data.current_term_due || 0);
                        const prevBal = parseFloat(data.previous_balance || 0);
                        const grandTotal = parseFloat(data.total_due || 0);
                        const totalPaid = parseFloat(data.total_paid || 0);

                        if (displayCurrentTermFees) {
                            displayCurrentTermFees.textContent = '₱' + formatCurrency(curTermDue);
                        }
                        if (displayPreviousTermBalance) {
                            displayPreviousTermBalance.textContent = '₱' + formatCurrency(prevBal);
                        }
                        if (displayGrandTotalDue) {
                            displayGrandTotalDue.textContent = '₱' + formatCurrency(grandTotal);
                        }
                        if (displayTotalPaidSummary) {
                            displayTotalPaidSummary.textContent = '₱' + formatCurrency(totalPaid);
                        }
                        if (displayPrevBalance) {
                            displayPrevBalance.textContent = '₱' + formatCurrency(currentPreviousBalance);
                        }

                        if (assessmentBreakdownCard) {
                            assessmentBreakdownCard.classList.remove('hidden');
                            assessmentBreakdownCard.style.display = 'block';
                        }
                        if (bdStudentTermBadge) {
                            bdStudentTermBadge.textContent = 'AY ' + ay + ' • ' + sem + ' Sem';
                        }
                        if (bdStudentSubtitle && data.student) {
                            const stMeta = [data.student.full_name || '', data.student.student_id || '', data.student.program || '', data.student.year_level || ''].filter(Boolean).join(' • ');
                            bdStudentSubtitle.textContent = stMeta || 'Live term fee summary & payment balance';
                        }
                        if (bdCurrentTermFees) bdCurrentTermFees.textContent = '₱' + formatCurrency(curTermDue);
                        if (bdPreviousBalance) bdPreviousBalance.textContent = '₱' + formatCurrency(prevBal);
                        if (bdTotalPaid) bdTotalPaid.textContent = '₱' + formatCurrency(totalPaid);
                        if (bdRemainingBalance) bdRemainingBalance.textContent = '₱' + formatCurrency(currentPreviousBalance);
                        if (bdItemsTbody) {
                            const feeList = Array.isArray(data.fees) ? data.fees : [];
                            if (feeList.length === 0) {
                                bdItemsTbody.innerHTML = '<tr><td colspan="4" style="padding:0.75rem; text-align:center; color:#64748b; font-style:italic;">No itemized fees assessed for this term yet.</td></tr>';
                            } else {
                                bdItemsTbody.innerHTML = feeList.map(function (item) {
                                    const rem = typeof item.item_remaining_balance !== 'undefined' ? parseFloat(item.item_remaining_balance) : parseFloat(item.amount || 0);
                                    const orig = typeof item.original_amount !== 'undefined' ? parseFloat(item.original_amount) : rem;
                                    const paidAmt = Math.max(0, orig - rem);
                                    const rowBg = item.is_previous_balance ? 'background:#fff1f2;' : '';
                                    const remColor = rem > 0.005 ? '#b45309' : '#059669';
                                    return '<tr style="' + rowBg + '">' +
                                        '<td style="padding:0.45rem 0.75rem; font-weight:700; color:#0f172a;">' + escapeHtml(item.particular || 'Fee Item') + '</td>' +
                                        '<td style="padding:0.45rem 0.75rem; text-align:right; color:#475569;">₱' + formatCurrency(orig) + '</td>' +
                                        '<td style="padding:0.45rem 0.75rem; text-align:right; color:#059669; font-weight:700;">₱' + formatCurrency(paidAmt) + '</td>' +
                                        '<td style="padding:0.45rem 0.75rem; text-align:right; color:' + remColor + '; font-weight:800;">₱' + formatCurrency(rem) + '</td>' +
                                    '</tr>';
                                }).join('');
                            }
                        }

                        renderFeeItems(data.fees || []);
                        if (currentPreviousBalance <= 0) {
                            if (overpaymentAlert) {
                                overpaymentAlert.style.display = 'block';
                                overpaymentAlert.style.background = '#f0fdf4';
                                overpaymentAlert.style.border = '1px solid #86efac';
                                overpaymentAlert.style.color = '#166534';
                                overpaymentAlert.innerHTML = '<i class="fas fa-check-circle"></i> This student has no remaining balance due for this term (Account Cleared: ₱0.00).';
                            }
                        }
                    } else {
                        checklistContainer.innerHTML = '<div style="color:#ef4444; grid-column:1/-1; padding:1rem; text-align:center;">Failed to load fees: ' + escapeHtml(data.error || 'Unknown error') + '</div>';
                    }
                } catch (err) {
                    checklistContainer.innerHTML = '<div style="color:#ef4444; grid-column:1/-1; padding:1rem; text-align:center;">Error communicating with server.</div>';
                }
            }

            seminarianSelect.addEventListener('change', loadStudentFees);
            aySelect.addEventListener('change', loadStudentFees);
            semSelect.addEventListener('change', loadStudentFees);

            // Seminarian Quick Search Filter
            const searchInput = document.getElementById('seminarianQuickSearch');
            if (searchInput && seminarianSelect) {
                const originalOptions = Array.from(seminarianSelect.options);
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const curVal = seminarianSelect.value;
                    seminarianSelect.innerHTML = '';
                    originalOptions.forEach(function (opt) {
                        if (!opt.value) {
                            seminarianSelect.appendChild(opt.cloneNode(true));
                            return;
                        }
                        const text = (opt.textContent || '').toLowerCase();
                        const sno = (opt.dataset.studentNo || '').toLowerCase();
                        if (query === '' || text.includes(query) || sno.includes(query)) {
                            const clone = opt.cloneNode(true);
                            if (clone.value === curVal) clone.selected = true;
                            seminarianSelect.appendChild(clone);
                        }
                    });
                    if (query && seminarianSelect.options.length === 2) {
                        seminarianSelect.selectedIndex = 1;
                        loadStudentFees();
                    }
                });

                searchInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (seminarianSelect.options.length > 1) {
                            if (!seminarianSelect.value && seminarianSelect.options[1]) {
                                seminarianSelect.selectedIndex = 1;
                            }
                            loadStudentFees();
                            amountTenderedInput.focus();
                        }
                    }
                });
            }

            // Keyboard Shortcuts: Ctrl+Enter and F2 to Submit Payment
            window.addEventListener('keydown', function (e) {
                if ((e.ctrlKey && e.key === 'Enter') || e.key === 'F2') {
                    e.preventDefault();
                    if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        const submitBtn = document.getElementById('submitPaymentBtn');
                        if (submitBtn) submitBtn.click();
                    }
                }
            });

            function setMessage(type, text) {
                if (!msg) return;
                msg.innerHTML = '';
                if (!text) return;
                const p = document.createElement('p');
                p.className = type === 'error' ? 'error' : 'success';
                p.textContent = text;
                msg.appendChild(p);
            }

            function renderPayments(items) {
                if (!tbody) return;
                tbody.innerHTML = '';
                if (!items || items.length === 0) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 11;
                    td.style.color = 'var(--text-secondary)';
                    td.textContent = 'No payments found.';
                    tr.appendChild(td);
                    tbody.appendChild(tr);
                    return;
                }
                items.forEach(function (p) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td data-label="Date">' + (p.payment_date || '') + '</td>' +
                        '<td data-label="OR No."><strong>' + (p.or_number || '') + '</strong></td>' +
                        '<td data-label="Seminarian">' + (p.full_name || '') + '</td>' +
                        '<td data-label="Source">' + (p.payer_type || '') + '</td>' +
                        '<td data-label="Particulars">' + (p.particulars || '') + '</td>' +
                        '<td data-label="Amount Paid">' + formatCurrency(p.amount) + '</td>' +
                        '<td data-label="Tendered">' + formatCurrency(p.amount_tendered || p.amount) + '</td>' +
                        '<td data-label="Change">' + (parseFloat(p.change || 0) > 0.0001 ? ('₱' + formatCurrency(p.change)) : '—') + '</td>' +
                        '<td data-label="Method">' + String(p.method || '').toUpperCase() + '</td>' +
                        '<td data-label="Balance">' + formatCurrency(p.remaining_balance) + '</td>' +
                        '<td data-label="Receipt"><a class="back-link" style="margin:0;" href="cashier_receipt.php?payment_id=' + (p.id || 0) + '&auto_print=1" target="_blank">Print</a></td>';
                    tbody.appendChild(tr);
                });
            }

            async function loadPayments(ay, sem) {
                if (!window.CapstoneAjax) return;
                const data = await window.CapstoneAjax.get('ajax.php', { action: 'cashier_payments_list', academic_year: ay, semester: sem });
                renderPayments(data.payments || []);
            }

            if (listForm) {
                listForm.addEventListener('submit', async function (e) {
                    if (!window.CapstoneAjax) return;
                    e.preventDefault();
                    try {
                        const fd = new FormData(listForm);
                        await loadPayments(fd.get('academic_year') || '', fd.get('semester') || '');
                    } catch (err) {
                        setMessage('error', err && err.message ? err.message : 'Failed to load payments.');
                    }
                });
            }

            form.addEventListener('submit', async function (e) {
                if (!window.CapstoneAjax) return;
                e.preventDefault();
                setMessage('', '');

                const currentBal = Math.round(currentPreviousBalance * 100) / 100;
                const calc = calculateChangeAndBalance();
                const effectivePayment = calc.effectivePayment;
                const amountToPay = calc.amountToPay;
                const tendered = calc.tendered;

                const checkedFeeCount = checklistContainer.querySelectorAll('.fee-checkbox:checked').length;
                const checkedCustomCount = Array.from(customContainer.querySelectorAll('.custom-fee-checkbox:checked')).filter(function (cb) {
                    const row = cb.closest('.custom-fee-row');
                    const inp = row ? row.querySelector('.custom-fee-desc-input') : null;
                    return inp && inp.value.trim() !== '';
                }).length;
                if (checkedFeeCount === 0 && checkedCustomCount === 0) {
                    alert('Please select at least one fee particular being paid.');
                    return;
                }

                if (effectivePayment <= 0) {
                    alert('Please enter an amount to pay greater than 0.');
                    return;
                }

                if (calc.isInsufficient) {
                    alert('Insufficient cash tendered. Please enter cash received equal to or greater than ₱' + formatCurrency(amountToPay) + '.');
                    return;
                }

                // Overpayment Validation: Compare Effective Payment Amount against Current Remaining Balance
                if (effectivePayment > (currentBal + 0.005) && currentBal > 0) {
                    alert('Payment amount exceeds the current remaining balance. Please enter an amount equal to or less than ₱' + formatCurrency(currentBal) + '.');
                    return;
                }

                try {
                    const fd = new FormData(form);
                    fd.append('action', 'cashier_record_payment');
                    const resp = await window.CapstoneAjax.post('ajax.php?action=cashier_record_payment', fd);
                    const remBal = parseFloat(resp.remaining_balance || 0);
                    const statusMsg = resp.upgraded_to_enrolled
                        ? 'Payment recorded. Seminarian status automatically updated to Enrolled! Remaining Balance: ₱' + formatCurrency(remBal) + '. Opening receipt...'
                        : 'Payment OR ' + (fd.get('or_number') || '') + ' recorded successfully! Updated Remaining Balance: ₱' + formatCurrency(remBal) + '. Opening receipt...';
                    setMessage('success', statusMsg);
                    if (typeof window.showToast === 'function') {
                        window.showToast(statusMsg, 'success', 3500);
                    }
                    const ay = fd.get('academic_year') || '';
                    const sem = fd.get('semester') || '';
                    await loadPayments(ay, sem);
                    if (resp.receipt_url) {
                        const printUrl = resp.receipt_url + (resp.receipt_url.includes('?') ? '&' : '?') + 'auto_print=1';
                        if (printLastReceiptBtn) {
                            printLastReceiptBtn.href = printUrl;
                            printLastReceiptBtn.classList.remove('hidden');
                            printLastReceiptBtn.style.display = 'inline-flex';
                        }
                        window.open(printUrl, '_blank');
                    }
                    form.reset();
                    userModifiedTendered = false;
                    userModifiedAmountPaid = false;
                    calculateChangeAndBalance();
                    checklistContainer.innerHTML = '<div style="color:var(--text-secondary); grid-column:1/-1; padding:1.5rem; text-align:center; background:var(--bg-light, #f8fafc); border-radius:8px; border:1px dashed var(--border-color, #cbd5e1);"><i class="fas fa-user-graduate" style="font-size:1.5rem; margin-bottom:0.5rem; display:block; opacity:0.5;"></i>Select a seminarian above to view the fee checklist and account balance.</div>';
                    if (assessmentBreakdownCard) {
                        assessmentBreakdownCard.classList.add('hidden');
                        assessmentBreakdownCard.style.display = 'none';
                    }
                    if (summaryPrintSoaBtn) {
                        summaryPrintSoaBtn.classList.add('hidden');
                        summaryPrintSoaBtn.style.display = 'none';
                    }
                    customContainer.innerHTML = '';
                    orFeedback.innerHTML = '';
                    orInput.style.borderColor = '';
                    if (displayCurrentTermFees) displayCurrentTermFees.textContent = '₱0.00';
                    if (displayPreviousTermBalance) displayPreviousTermBalance.textContent = '₱0.00';
                    if (displayGrandTotalDue) displayGrandTotalDue.textContent = '₱0.00';
                    if (displayTotalPaidSummary) displayTotalPaidSummary.textContent = '₱0.00';
                    displayPrevBalance.textContent = '₱0.00';
                    currentPreviousBalance = 0.0;
                    if (displayNewRemainingBalance) displayNewRemainingBalance.textContent = '₱0.00';
                    if (changeInput) {
                        changeInput.value = '₱0.00';
                        changeInput.style.borderColor = '#10b981';
                        changeInput.style.color = '#6ee7b7';
                    }
                    if (changeStatusBadge) {
                        changeStatusBadge.textContent = '₱0.00';
                        changeStatusBadge.style.color = '#a7f3d0';
                    }
                    if (changeHelpText) {
                        changeHelpText.textContent = 'Change to return to payer';
                        changeHelpText.style.color = '#6ee7b7';
                    }
                    if (overpaymentAlert) {
                        overpaymentAlert.style.display = 'none';
                        overpaymentAlert.innerHTML = '';
                    }
                    recalculateTotals();
                    suggestOrNumber(true);
                } catch (err) {
                    setMessage('error', err && err.message ? err.message : 'Failed to record payment.');
                }
            });

            // Initial auto-suggest OR immediately
            suggestOrNumber(true);

            // If a seminarian is preselected (e.g. redirected from accounts list), load fees immediately
            if (seminarianSelect.value) {
                loadStudentFees();
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
