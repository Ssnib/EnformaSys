<?php
include '../includes/session_fix.php';
require_roles(['Cashier', 'Dean']);
include '../includes/db.php';

$current_ay = get_current_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$ay_extra_res = $conn->query("(SELECT DISTINCT academic_year FROM lodging_payments WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND academic_year <> '')
    ORDER BY academic_year DESC");
if ($ay_extra_res) {
    while ($r = $ay_extra_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$academic_year = trim((string)($_GET['academic_year'] ?? $current_ay));
$active_sem_default = function_exists('get_active_semester') ? enformasys_normalize_semester(get_active_semester()) : '1st';
if (!in_array($active_sem_default, ['1st', '2nd'], true)) {
    $active_sem_default = '1st';
}
$semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : $active_sem_default;
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

if (!in_array($academic_year, $ay_options, true)) {
    $academic_year = $current_ay;
}
if (!in_array($semester, ['1st', '2nd'], true)) {
    $semester = $active_sem_default;
}
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}
if ($from !== '' && $to !== '' && $from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$where = "p.academic_year = ? AND p.semester = ?";
$types = 'ss';
$params = [$academic_year, $semester];
if ($from !== '') {
    $where .= " AND p.payment_date >= ?";
    $types .= 's';
    $params[] = $from;
}
if ($to !== '') {
    $where .= " AND p.payment_date <= ?";
    $types .= 's';
    $params[] = $to;
}

// 1. Total Collections (filtered by term & optional date window)
$stmt_col = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) AS total, COUNT(*) AS tx_count
    FROM lodging_payments p
    WHERE {$where}");
$bind = [$types];
foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
}
call_user_func_array([$stmt_col, 'bind_param'], $bind);
$stmt_col->execute();
$col_row = $stmt_col->get_result()->fetch_assoc() ?: [];
$total_collections = (float)($col_row['total'] ?? 0);
$tx_count = (int)($col_row['tx_count'] ?? 0);

// 2. Assessed Fees & Outstanding Balances for selected Academic Term
$stmt_fee = $conn->prepare("SELECT
        COUNT(*) AS assessed_accounts,
        COALESCE(SUM(total_assessed_fee), 0) AS total_assessed,
        COALESCE(SUM(GREATEST(fee_balance, 0)), 0) AS total_outstanding,
        SUM(CASE WHEN fee_balance > 0.009 THEN 1 ELSE 0 END) AS balance_accounts
    FROM enrollments
    WHERE academic_year = ?
      AND semester = ?
      AND enrollment_status NOT IN ('Dropped', 'Withdrawn')");
$stmt_fee->bind_param('ss', $academic_year, $semester);
$stmt_fee->execute();
$fee_row = $stmt_fee->get_result()->fetch_assoc() ?: [];
$assessed_accounts = (int)($fee_row['assessed_accounts'] ?? 0);
$total_assessed_fees = (float)($fee_row['total_assessed'] ?? 0);
$total_outstanding = (float)($fee_row['total_outstanding'] ?? 0);
$balance_accounts = (int)($fee_row['balance_accounts'] ?? 0);

// 3. Detailed Collection Transactions Table
$transactions = [];
$stmt_tx = $conn->prepare("SELECT
        p.id,
        p.payment_date,
        p.or_number,
        COALESCE(NULLIF(TRIM(p.payer_name), ''), NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(st.full_name), ''), u.username, CONCAT('Seminarian #', p.seminarian_id)) AS payee_name,
        COALESCE(NULLIF(TRIM(up.student_id), ''), NULLIF(TRIM(st.student_no), ''), '') AS student_no,
        p.method,
        p.amount,
        p.notes,
        p.remarks,
        GROUP_CONCAT(
            CASE WHEN cpi.amount > 0 THEN CONCAT(cpi.particular, ' (₱', FORMAT(cpi.amount, 2), ')') END
            ORDER BY cpi.sort_order ASC, cpi.id ASC
            SEPARATOR '; '
        ) AS item_particulars
    FROM lodging_payments p
    LEFT JOIN seminarians s ON s.id = p.seminarian_id
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students st ON st.id = p.seminarian_id
    LEFT JOIN cashier_payment_items cpi ON cpi.payment_id = p.id
    WHERE {$where}
    GROUP BY p.id, p.payment_date, p.or_number, payee_name, student_no, p.method, p.amount, p.notes, p.remarks
    ORDER BY p.payment_date DESC, p.id DESC");
$bind_tx = [$types];
foreach ($params as $k => $v) {
    $bind_tx[] = &$params[$k];
}
call_user_func_array([$stmt_tx, 'bind_param'], $bind_tx);
$stmt_tx->execute();
$res_tx = $stmt_tx->get_result();
while ($res_tx && ($row = $res_tx->fetch_assoc())) {
    $transactions[] = $row;
}

function cr_format_method(?string $method): string {
    $map = [
        'cash' => 'Cash',
        'bank_deposit' => 'Bank Deposit',
        'online_transfer' => 'Online Transfer',
        'bank' => 'Bank',
        'gcash' => 'GCash',
        'check' => 'Check',
        'other' => 'Other',
    ];
    $key = strtolower(trim((string)$method));
    return $map[$key] ?? ($key !== '' ? ucwords(str_replace('_', ' ', $key)) : 'Cash');
}

function cr_format_particulars(array $row): string {
    $items = trim((string)($row['item_particulars'] ?? ''));
    if ($items !== '') {
        return $items;
    }
    $notes = trim((string)($row['notes'] ?? ''));
    if ($notes !== '') {
        return $notes;
    }
    $remarks = trim((string)($row['remarks'] ?? ''));
    return $remarks !== '' ? $remarks : 'General Formation & Lodging Payment';
}
?>
<?php
$pageTitle = 'Financial Reports';
$activeNav = 'cashier_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="w-full">
            <!-- Compact Inline Filter Bar -->
            <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4" aria-label="Report Filters">
                <form method="get" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end m-0 p-0 bg-transparent border-0 shadow-none">
                    <div>
                        <label for="filterAcademicYear" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Academic Year</label>
                        <select id="filterAcademicYear" name="academic_year" required
                                class="w-full h-10 px-3 text-sm font-medium text-gray-800 bg-gray-50 hover:bg-white border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition cursor-pointer">
                            <?php foreach ($ay_options as $ay): ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="filterSemester" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Semester</label>
                        <select id="filterSemester" name="semester" required
                                class="w-full h-10 px-3 text-sm font-medium text-gray-800 bg-gray-50 hover:bg-white border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition cursor-pointer">
                            <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>First Semester</option>
                            <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>Second Semester</option>
                        </select>
                    </div>

                    <div>
                        <label for="filterDateFrom" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Date From</label>
                        <input id="filterDateFrom" type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"
                               class="w-full h-10 px-3 text-sm font-medium text-gray-800 bg-gray-50 hover:bg-white border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                    </div>

                    <div>
                        <label for="filterDateTo" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Date To</label>
                        <input id="filterDateTo" type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"
                               class="w-full h-10 px-3 text-sm font-medium text-gray-800 bg-gray-50 hover:bg-white border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                    </div>

                    <div>
                        <button type="submit"
                                class="w-full h-10 inline-flex items-center justify-center gap-2 px-4 rounded-lg text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 border-0 shadow-sm transition cursor-pointer">
                            <i class="fas fa-filter text-xs"></i>
                            <span>Generate</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- 3-Column KPI Metric Row -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6" aria-label="Financial Summary Metrics">
                <!-- KPI 1: Total Collections -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Total Collections</span>
                        <div class="text-2xl font-bold text-emerald-600 mt-1.5 tracking-tight tabular-nums">
                            &#8369;<?php echo htmlspecialchars(number_format($total_collections, 2)); ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            <?php echo $tx_count; ?> <?php echo $tx_count === 1 ? 'official receipt' : 'official receipts'; ?>
                            <?php if ($from !== '' || $to !== ''): ?>
                                &middot; Date filtered
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-hand-holding-dollar text-lg"></i>
                    </div>
                </div>

                <!-- KPI 2: Total Assessed Fees -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Total Assessed Fees</span>
                        <div class="text-2xl font-bold text-gray-900 mt-1.5 tracking-tight tabular-nums">
                            &#8369;<?php echo htmlspecialchars(number_format($total_assessed_fees, 2)); ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            Across <?php echo $assessed_accounts; ?> enrolled <?php echo $assessed_accounts === 1 ? 'record' : 'records'; ?> (AY <?php echo htmlspecialchars($academic_year); ?>)
                        </p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-file-invoice-dollar text-lg"></i>
                    </div>
                </div>

                <!-- KPI 3: Outstanding Receivables / Balances -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Outstanding Receivables</span>
                        <div class="text-2xl font-bold <?php echo $total_outstanding > 0 ? 'text-rose-600' : 'text-emerald-600'; ?> mt-1.5 tracking-tight tabular-nums">
                            &#8369;<?php echo htmlspecialchars(number_format($total_outstanding, 2)); ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            <?php echo $balance_accounts; ?> <?php echo $balance_accounts === 1 ? 'account' : 'accounts'; ?> with unpaid balance
                        </p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-scale-unbalanced text-lg"></i>
                    </div>
                </div>
            </section>

            <!-- Detailed Collection Transactions Table -->
            <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mt-6" aria-label="Collection Transactions">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800 m-0">Collection Transactions</h2>
                        <p class="text-xs text-gray-500 mt-0.5 mb-0">
                            AY <?php echo htmlspecialchars($academic_year); ?> &middot; <?php echo $semester === '1st' ? 'First Semester' : 'Second Semester'; ?>
                            <?php if ($from !== '' || $to !== ''): ?>
                                &middot; <?php echo $from !== '' ? htmlspecialchars($from) : 'Start'; ?> to <?php echo $to !== '' ? htmlspecialchars($to) : 'Present'; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($from !== '' || $to !== ''): ?>
                            <a href="?academic_year=<?php echo urlencode($academic_year); ?>&semester=<?php echo urlencode($semester); ?>"
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition no-underline">
                                <i class="fas fa-times text-[10px]"></i>
                                <span>Clear Dates</span>
                            </a>
                        <?php endif; ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                            <?php echo $tx_count; ?> <?php echo $tx_count === 1 ? 'Record' : 'Records'; ?>
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-full border-collapse !mt-0 text-left">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th class="py-3.5 px-5 whitespace-nowrap">Date</th>
                                <th class="py-3.5 px-5 whitespace-nowrap">O.R. Number</th>
                                <th class="py-3.5 px-5 whitespace-nowrap">Payee / Student</th>
                                <th class="py-3.5 px-5 whitespace-nowrap">Particulars / Fees</th>
                                <th class="py-3.5 px-5 text-right whitespace-nowrap">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="5" class="py-12 px-5 text-center">
                                        <div class="flex flex-col items-center justify-center text-gray-400">
                                            <i class="fas fa-receipt text-3xl text-gray-300 mb-2"></i>
                                            <p class="text-sm font-semibold text-gray-600 m-0">No collection transactions found</p>
                                            <p class="text-xs text-gray-400 mt-1 mb-0">Try adjusting the academic term or date range filters above.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $tx):
                                    $raw_date = (string)($tx['payment_date'] ?? '');
                                    $ts = $raw_date !== '' ? strtotime($raw_date) : false;
                                    $formatted_date = $ts ? date('M d, Y', $ts) : $raw_date;
                                    $particulars = cr_format_particulars($tx);
                                    $method_label = cr_format_method($tx['method'] ?? 'cash');
                                ?>
                                    <tr class="hover:bg-gray-50/70 transition-colors">
                                        <td class="py-3.5 px-5 text-gray-600 whitespace-nowrap font-medium">
                                            <?php echo htmlspecialchars($formatted_date); ?>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap">
                                            <a href="cashier_receipt.php?payment_id=<?php echo (int)$tx['id']; ?>"
                                               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold font-mono bg-slate-100 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 transition no-underline"
                                               title="View Official Receipt">
                                                <span><?php echo htmlspecialchars((string)($tx['or_number'] ?: ('OR-' . str_pad((string)$tx['id'], 4, '0', STR_PAD_LEFT)))); ?></span>
                                            </a>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap">
                                            <div class="font-semibold text-gray-900"><?php echo htmlspecialchars((string)$tx['payee_name']); ?></div>
                                            <div class="text-xs text-gray-400">
                                                <?php if (!empty($tx['student_no'])): ?>
                                                    <?php echo htmlspecialchars((string)$tx['student_no']); ?> &middot;
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($method_label); ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-5 text-gray-600 max-w-md">
                                            <div class="line-clamp-2 text-xs leading-relaxed" title="<?php echo htmlspecialchars($particulars); ?>">
                                                <?php echo htmlspecialchars($particulars); ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-5 text-right font-bold text-emerald-700 whitespace-nowrap tabular-nums">
                                            &#8369;<?php echo htmlspecialchars(number_format((float)$tx['amount'], 2)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($transactions)): ?>
                            <tfoot>
                                <tr class="bg-gray-50 border-t border-gray-200">
                                    <td colspan="4" class="py-3.5 px-5 text-xs font-bold uppercase tracking-wider text-gray-600 text-right">
                                        Total Collected (<?php echo $tx_count; ?> <?php echo $tx_count === 1 ? 'Transaction' : 'Transactions'; ?>)
                                    </td>
                                    <td class="py-3.5 px-5 text-right text-sm font-extrabold text-emerald-700 whitespace-nowrap tabular-nums">
                                        &#8369;<?php echo htmlspecialchars(number_format($total_collections, 2)); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </section>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
