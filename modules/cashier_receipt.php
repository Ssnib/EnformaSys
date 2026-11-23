<?php
include '../includes/session_fix.php';
require_roles(['Cashier']);
include '../includes/db.php';

$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    header('Location: cashier_payments.php');
    exit;
}

$stmt = $conn->prepare(
    "SELECT p.*, COALESCE(up.full_name, u.username) as full_name, up.student_id as student_no, s.year_level, s.program,\n"
    . "  COALESCE(rcv_up.full_name, rcv_u.username, 'Cashier') as received_by_name\n"
    . "FROM lodging_payments p\n"
    . "JOIN seminarians s ON s.id = p.seminarian_id\n"
    . "JOIN users u ON u.id = s.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "LEFT JOIN users rcv_u ON rcv_u.id = p.received_by\n"
    . "LEFT JOIN user_profiles rcv_up ON rcv_up.user_id = rcv_u.id\n"
    . "WHERE p.id = ?\n"
    . "LIMIT 1"
);
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
if (!$payment) {
    header('Location: cashier_payments.php');
    exit;
}

$payment_items = [];
$stmt_items = $conn->prepare("SELECT particular, amount FROM cashier_payment_items WHERE payment_id = ? AND amount > 0.0001 ORDER BY sort_order ASC, id ASC");
$stmt_items->bind_param('i', $payment_id);
$stmt_items->execute();
$res_items = $stmt_items->get_result();
while ($res_items && ($r = $res_items->fetch_assoc())) {
    $p_label = trim((string)($r['particular'] ?? ''));
    $p_val = (float)($r['amount'] ?? 0);
    if ($p_label !== '' && $p_val > 0.0001) {
        $payment_items[] = ['particular' => $p_label, 'amount' => $p_val];
    }
}

// Legacy receipt fallback: only when no itemized rows exist in cashier_payment_items
if (count($payment_items) === 0) {
    $notes_raw = trim((string)($payment['notes'] ?? ''));
    if ($notes_raw !== '') {
        foreach (explode(';', $notes_raw) as $seg) {
            $seg = trim($seg);
            if ($seg !== '' && preg_match('/^(.+?):\s*([0-9,]+(?:\.[0-9]+)?)$/', $seg, $m)) {
                $lbl = trim($m[1]);
                $val = (float)str_replace(',', '', $m[2]);
                if ($lbl !== '' && $val > 0.0001) {
                    $payment_items[] = ['particular' => $lbl, 'amount' => $val];
                }
            }
        }
    }
}

if (count($payment_items) === 0 && (float)($payment['amount'] ?? 0) > 0.0001) {
    $fallback_label = trim((string)($payment['remarks'] ?? ''));
    if ($fallback_label === '') {
        $fallback_label = 'General Assessment Payment';
    }
    $payment_items[] = [
        'particular' => $fallback_label,
        'amount' => (float)$payment['amount'],
    ];
}

$seminary_name = 'Littleway College Seminary';
$seminary_address = 'Sitio Muhon Brgy. Binicuil, Kabankalan City, Negros Occidental';
$seminary_contact = 'Tel: (088) 813-2456 • Email: littlewaycollegeseminary@gmail.com';

$amt_paid = (float)($payment['amount'] ?? 0);
$amt_tendered = isset($payment['amount_tendered']) && $payment['amount_tendered'] !== null ? (float)$payment['amount_tendered'] : $amt_paid;
$change_given = (float)($payment['overpayment'] ?? 0);
$prev_balance = (float)($payment['previous_balance'] ?? 0);
$rem_balance = (float)($payment['remaining_balance'] ?? 0);
?>
<?php
$pageTitle = 'Official Receipt';
$activeNav = 'cashier_payments';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
<style>
.receipt-document {
    background: #ffffff;
    border: 3px solid #065f46;
    border-radius: 14px;
    overflow: hidden;
    margin-top: 1.25rem;
    box-shadow: 0 4px 20px rgba(6, 95, 70, 0.12);
    position: relative;
}

.receipt-header {
    background: #065f46;
    color: #ffffff;
    padding: 1.75rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
    border-bottom: 3px solid #047857;
}

.receipt-header .brand {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.receipt-header .title-badge-area {
    text-align: right;
}

.receipt-header h1 {
    font-size: 1.75rem;
    font-weight: 900;
    letter-spacing: 0.12em;
    margin: 0;
    text-transform: uppercase;
    color: #ffffff;
}

.or-badge {
    display: inline-block;
    background: #fef9c3;
    border: 2px solid #ca8a04;
    color: #713f12;
    font-size: 1.15rem;
    font-weight: 900;
    padding: 0.35rem 1.25rem;
    border-radius: 999px;
    margin-top: 0.4rem;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.receipt-body {
    padding: 2rem;
}

.receipt-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 1.5rem;
}

.info-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.info-card h4 {
    margin: 0 0 0.75rem 0;
    color: #065f46;
    font-size: 0.95rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #a7f3d0;
    padding-bottom: 0.35rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.3rem 0;
    font-size: 0.92rem;
}

.info-label {
    color: var(--text-secondary, #64748b);
    font-weight: 600;
}

.info-val {
    color: #0f172a;
    font-weight: 700;
    text-align: right;
}

.receipt-table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.25rem 0;
}

.receipt-table th {
    background: #f0fdf4;
    color: #065f46;
    font-weight: 800;
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 2px solid #065f46;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.receipt-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.95rem;
}

.receipt-table tr:last-child td {
    border-bottom: 2px solid #065f46;
}

.financial-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    background: #f0fdf4;
    border: 2px solid #059669;
    border-radius: 10px;
    padding: 1.25rem;
    margin: 1.5rem 0;
}

.summary-box {
    text-align: center;
}

.summary-box .lbl {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #065f46;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.summary-box .val {
    font-size: 1.3rem;
    font-weight: 900;
    color: #0f172a;
}

.summary-box.highlight {
    background: #ffffff;
    border-radius: 8px;
    padding: 0.5rem;
    box-shadow: 0 2px 6px rgba(6, 95, 70, 0.1);
}

.summary-box.highlight .val {
    color: #065f46;
}

.summary-box.sukli {
    background: #ecfdf5;
    border-radius: 8px;
    padding: 0.5rem;
    border: 1px dashed #059669;
}

.summary-box.sukli .val {
    color: #047857;
}

.receipt-footer {
    margin-top: 2.5rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3rem;
    align-items: flex-end;
}

.signature-block {
    text-align: center;
}

.signature-line {
    border-top: 2px solid #0f172a;
    padding-top: 0.5rem;
    font-weight: 800;
    color: #0f172a;
    font-size: 0.95rem;
}

.signature-subtitle {
    font-size: 0.8rem;
    color: var(--text-secondary, #64748b);
}

@media print {
    body * {
        visibility: hidden;
    }
    .receipt-document, .receipt-document * {
        visibility: visible;
    }
    .receipt-document {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        border: 2px solid #065f46 !important;
        box-shadow: none !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .no-print {
        display: none !important;
    }
    .receipt-document::after {
        content: 'PAID';
        position: fixed;
        top: 38%;
        left: 20%;
        font-size: 8.5rem;
        font-weight: 900;
        color: rgba(6, 95, 70, 0.08) !important;
        transform: rotate(-30deg);
        pointer-events: none;
        z-index: 10;
        letter-spacing: 0.2em;
    }
}
</style>

        <div class="content-body">
            <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
                <div>
                    <a class="back-link" style="margin:0;" href="cashier_payments.php"><i class="fas fa-arrow-left"></i> Back to Payments</a>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" onclick="window.print()" style="width:auto; background:#065f46; color:#fff; font-weight:700;"><i class="fas fa-print"></i> Print Official Receipt</button>
                </div>
            </div>

            <div class="receipt-document">
                <div class="receipt-header">
                    <div class="brand">
                        <img src="../assets/LOGO%20(2).jpg" alt="Logo" style="width:68px; height:68px; object-fit:cover; border-radius:10px; border:2px solid #ffffff;">
                        <div>
                            <div style="font-size:1.3rem; font-weight:900; letter-spacing:0.04em;"><?php echo htmlspecialchars($seminary_name); ?></div>
                            <div style="font-size:0.85rem; opacity:0.9;"><?php echo htmlspecialchars($seminary_address); ?></div>
                            <div style="font-size:0.85rem; opacity:0.9;"><?php echo htmlspecialchars($seminary_contact); ?></div>
                        </div>
                    </div>
                    <div class="title-badge-area">
                        <h1>Official Receipt</h1>
                        <div class="or-badge">
                            OR NO. <?php echo htmlspecialchars((string)($payment['or_number'] ?? '')); ?>
                        </div>
                    </div>
                </div>

                <div class="receipt-body">
                    <div class="receipt-grid">
                        <div class="info-card">
                            <h4><i class="fas fa-user-graduate"></i> Seminarian Information</h4>
                            <div class="info-row">
                                <span class="info-label">Full Name:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['full_name'] ?? '')); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Student ID:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['student_no'] ?? '—')); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Program:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['program'] ?? '—')); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Year Level:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['year_level'] ?? '—')); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Academic Year / Term:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['academic_year'] ?? '')) . ' • ' . htmlspecialchars((string)($payment['semester'] ?? '')); ?> Sem</span>
                            </div>
                        </div>

                        <div class="info-card">
                            <h4><i class="fas fa-receipt"></i> Payment Details</h4>
                            <div class="info-row">
                                <span class="info-label">Date Paid:</span>
                                <span class="info-val"><?php echo htmlspecialchars(date('F d, Y', strtotime((string)($payment['payment_date'] ?? date('Y-m-d'))))); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Method:</span>
                                <span class="info-val"><?php echo htmlspecialchars(strtoupper((string)($payment['method'] ?? 'CASH'))); ?></span>
                            </div>
                            <?php if (!empty($payment['reference_no'])) { ?>
                            <div class="info-row">
                                <span class="info-label">Reference No:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)$payment['reference_no']); ?></span>
                            </div>
                            <?php } ?>
                            <div class="info-row">
                                <span class="info-label">Payment Source:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)($payment['payer_type'] ?? 'Self / Direct')); ?></span>
                            </div>
                            <?php if (!empty($payment['payer_name'])) { ?>
                            <div class="info-row">
                                <span class="info-label">Payer Name:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)$payment['payer_name']); ?></span>
                            </div>
                            <?php } ?>
                            <?php if (!empty($payment['remarks'])) { ?>
                            <div class="info-row">
                                <span class="info-label">Remarks:</span>
                                <span class="info-val"><?php echo htmlspecialchars((string)$payment['remarks']); ?></span>
                            </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Particulars Table -->
                    <table class="receipt-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Particulars / Fee Description</th>
                                <th style="text-align: right; width: 180px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($payment_items) === 0) { ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--text-secondary);">No itemized particulars recorded.</td>
                                </tr>
                            <?php } else { 
                                $idx = 1;
                                $items_sum = 0.0;
                                foreach ($payment_items as $it) { 
                                    $item_amt = (float)($it['amount'] ?? 0);
                                    $items_sum += $item_amt;
                            ?>
                                <tr>
                                    <td style="color: var(--text-secondary);"><?php echo $idx++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string)($it['particular'] ?? '')); ?></strong>
                                        <?php if (stripos((string)($it['particular'] ?? ''), 'previous balance') !== false || stripos((string)($it['particular'] ?? ''), 'prior balance') !== false) { ?>
                                            <span style="font-size:0.75rem; background:#fee2e2; color:#991b1b; padding:1px 6px; border-radius:4px; font-weight:700; margin-left:6px;"><i class="fas fa-clock-rotate-left"></i> Forwarded</span>
                                        <?php } ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 700;">₱<?php echo htmlspecialchars(number_format($item_amt, 2)); ?></td>
                                </tr>
                            <?php } ?>
                                <tr style="background: #f8fafc;">
                                    <td colspan="2" style="font-weight: 900; color: #065f46; text-transform: uppercase;">Total Particulars Settled</td>
                                    <td style="text-align: right; font-weight: 900; font-size: 1.1rem; color: #065f46;">₱<?php echo htmlspecialchars(number_format($items_sum, 2)); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <!-- Financial Summary Bar -->
                    <div class="financial-summary">
                        <div class="summary-box">
                            <div class="lbl">Previous Balance</div>
                            <div class="val">₱<?php echo htmlspecialchars(number_format($prev_balance, 2)); ?></div>
                        </div>
                        <div class="summary-box highlight">
                            <div class="lbl">Amount Paid</div>
                            <div class="val">₱<?php echo htmlspecialchars(number_format($amt_paid, 2)); ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="lbl">Cash Tendered</div>
                            <div class="val">₱<?php echo htmlspecialchars(number_format($amt_tendered, 2)); ?></div>
                        </div>
                        <?php if ($change_given > 0.0001) { ?>
                        <div class="summary-box sukli">
                            <div class="lbl">Change (Sukli)</div>
                            <div class="val">₱<?php echo htmlspecialchars(number_format($change_given, 2)); ?></div>
                        </div>
                        <?php } ?>
                        <div class="summary-box">
                            <div class="lbl">Remaining Balance</div>
                            <div class="val">₱<?php echo htmlspecialchars(number_format($rem_balance, 2)); ?></div>
                        </div>
                    </div>

                    <!-- Signatures & Validation Footer -->
                    <div class="receipt-footer">
                        <div class="signature-block">
                            <div class="signature-line"><?php echo htmlspecialchars((string)$payment['received_by_name']); ?></div>
                            <div class="signature-subtitle">Authorized Cashier Signature</div>
                        </div>
                        <div class="signature-block">
                            <div class="signature-line"><?php echo htmlspecialchars(date('F d, Y h:i A', strtotime((string)($payment['created_at'] ?? 'now')))); ?></div>
                            <div class="signature-subtitle">Date & Time Issued</div>
                        </div>
                    </div>

                    <div style="text-align:center; margin-top:2rem; font-size:0.75rem; color:var(--text-secondary); letter-spacing:0.04em;">
                        This document serves as an Official Receipt for fees paid to Littleway College Seminary. Valid only when signed by authorized cashier personnel.
                    </div>
                </div>
            </div>
        </div>
<?php if (isset($_GET['auto_print']) && (string)$_GET['auto_print'] === '1') { ?>
        <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 350);
        });
        </script>
<?php } ?>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
