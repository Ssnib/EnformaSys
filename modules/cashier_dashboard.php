<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Cashier']);
require_once __DIR__ . '/../includes/db.php';

$today_total = 0.0;
$month_total = 0.0;
$outstanding = 0.0;
$overpayments = 0.0;
$active_accounts = 0;

$res_today = $conn->query("SELECT COALESCE(SUM(amount),0) as t FROM lodging_payments WHERE payment_date = CURDATE()");
if ($res_today) {
    $today_total = (float)($res_today->fetch_assoc()['t'] ?? 0);
}

$res_month = $conn->query("SELECT COALESCE(SUM(amount),0) as t FROM lodging_payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())");
if ($res_month) {
    $month_total = (float)($res_month->fetch_assoc()['t'] ?? 0);
}

$res_over = $conn->query("SELECT COALESCE(SUM(overpayment),0) as t FROM lodging_payments WHERE overpayment IS NOT NULL AND overpayment > 0");
if ($res_over) {
    $overpayments = (float)($res_over->fetch_assoc()['t'] ?? 0);
}

$res_active = $conn->query("SELECT COUNT(*) as c FROM seminarians WHERE enrollment_status = 'Enrolled'");
if ($res_active) {
    $active_accounts = (int)($res_active->fetch_assoc()['c'] ?? 0);
}

$res_out = $conn->query(
    "SELECT COALESCE(SUM(t.total - t.paid),0) as t\n"
    . "FROM (\n"
    . "  SELECT i.id,\n"
    . "    (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items it WHERE it.invoice_id = i.id) AS total,\n"
    . "    (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations pa WHERE pa.invoice_id = i.id) AS paid\n"
    . "  FROM lodging_invoices i\n"
    . "  WHERE i.archived_at IS NULL AND i.status IN ('issued', 'partially_paid')\n"
    . ") t"
);
if ($res_out) {
    $outstanding = (float)($res_out->fetch_assoc()['t'] ?? 0);
}

$month_labels = [];
$month_values = [];
$res_monthly = $conn->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') as ym, COALESCE(SUM(amount),0) as total\n"
    . "FROM lodging_payments\n"
    . "WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)\n"
    . "GROUP BY ym\n"
    . "ORDER BY ym ASC"
);
if ($res_monthly) {
    while ($r = $res_monthly->fetch_assoc()) {
        $ym = (string)($r['ym'] ?? '');
        if ($ym === '') continue;
        $dt = DateTime::createFromFormat('Y-m', $ym);
        $label = $dt ? $dt->format('M Y') : $ym;
        $month_labels[] = $label;
        $month_values[] = (float)($r['total'] ?? 0);
    }
}

$source_labels = [];
$source_values = [];
$res_sources = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(payer_type),''),'Unknown') as src, COALESCE(SUM(amount),0) as total\n"
    . "FROM lodging_payments\n"
    . "WHERE academic_year = '" . $conn->real_escape_string(get_current_academic_year()) . "'\n"
    . "GROUP BY src\n"
    . "ORDER BY total DESC"
);
if ($res_sources) {
    while ($r = $res_sources->fetch_assoc()) {
        $source_labels[] = (string)($r['src'] ?? 'Unknown');
        $source_values[] = (float)($r['total'] ?? 0);
    }
}

// Fetch Pending SOA Requests (Urgent action queue)
$pending_soa_count = 0;
$pending_soa_requests = [];
$res_soas = $conn->query("
    SELECT r.id, r.seminarian_id, r.academic_year, r.semester, r.notes, r.requested_at,
           COALESCE(up.full_name, u.username) as student_name,
           u.username
    FROM cashier_soa_requests r
    JOIN seminarians s ON s.id = r.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE r.status = 'Pending'
    ORDER BY r.requested_at DESC, r.id DESC
    LIMIT 5
");
if ($res_soas) {
    while ($rsoa = $res_soas->fetch_assoc()) {
        $pending_soa_requests[] = $rsoa;
    }
}
$res_soa_c = $conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE status = 'Pending'");
if ($res_soa_c) {
    $pending_soa_count = (int)($res_soa_c->fetch_assoc()['c'] ?? 0);
}

// Fetch Recent 6 Payments (Immediate transaction visibility)
$recent_payments = [];
$res_rec = $conn->query("
    SELECT p.id, p.or_number, p.payment_date, p.amount, p.payer_type, p.seminarian_id,
           COALESCE(up.full_name, u.username) as student_name,
           u.username
    FROM lodging_payments p
    JOIN seminarians s ON p.seminarian_id = s.id
    JOIN users u ON s.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 6
");
if ($res_rec) {
    while ($rp = $res_rec->fetch_assoc()) {
        $recent_payments[] = $rp;
    }
}
?>
<?php
$pageTitle = 'Cashier Dashboard';
$activeNav = 'cashier_dashboard';
$suppressHeader = true;
$headerMbClass = 'mb-0';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <!-- Main Content Area (Everything to the right of the sidebar): Flex 2-Column Layout -->
        <div class="flex flex-col xl:flex-row gap-6 items-start">
            
            <!-- LEFT COLUMN (flex-1 flex flex-col gap-6 overflow-hidden): Top Header, 4 Horizontal Stat Cards, Action Required & Recent Transactions -->
            <div class="flex-1 w-full flex flex-col gap-6 overflow-hidden">

                <!-- 1. Top Header ('Cashier Dashboard' title and search bar) -->
                <?php require __DIR__ . '/../includes/header.php'; ?>

                <!-- 2. 4 Stat Cards in a Single Horizontal Row (grid grid-cols-1 lg:grid-cols-4 gap-4) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    <!-- Card 1: Collections Today -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">Collections Today</span>
                            <div class="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-xs shrink-0">
                                <i class="fa-solid fa-calendar-day text-[#59443F]"></i>
                            </div>
                        </div>
                        <div>
                            <div class="text-xl sm:text-2xl font-black text-[#59443F] leading-tight truncate">
                                ₱<?php echo htmlspecialchars(number_format($today_total, 2)); ?>
                            </div>
                            <span class="text-[11px] font-medium text-[#8B7168] mt-1 block truncate">Daily Real-Time Total</span>
                        </div>
                    </div>

                    <!-- Card 2: This Month -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">This Month</span>
                            <div class="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-xs shrink-0">
                                <i class="fa-solid fa-chart-line text-[#59443F]"></i>
                            </div>
                        </div>
                        <div>
                            <div class="text-xl sm:text-2xl font-black text-[#59443F] leading-tight truncate">
                                ₱<?php echo htmlspecialchars(number_format($month_total, 2)); ?>
                            </div>
                            <span class="text-[11px] font-medium text-[#8B7168] mt-1 block truncate">Month-to-Date Inflow</span>
                        </div>
                    </div>

                    <!-- Card 3: Outstanding Due -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">Outstanding Due</span>
                            <div class="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-xs shrink-0">
                                <i class="fa-solid fa-scale-unbalanced text-[#59443F]"></i>
                            </div>
                        </div>
                        <div>
                            <div class="text-xl sm:text-2xl font-black text-[#59443F] leading-tight truncate">
                                ₱<?php echo htmlspecialchars(number_format($outstanding, 2)); ?>
                            </div>
                            <span class="text-[11px] font-medium text-[#8B7168] mt-1 block truncate">Active Unpaid Invoices</span>
                        </div>
                    </div>

                    <!-- Card 4: Pending SOA Requests -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">Pending SOA</span>
                            <div class="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-xs shrink-0">
                                <i class="fa-solid fa-file-invoice text-[#59443F]"></i>
                            </div>
                        </div>
                        <div>
                            <div class="text-xl sm:text-2xl font-black text-[#59443F] leading-tight truncate">
                                <?php echo (int)$pending_soa_count; ?>
                            </div>
                            <span class="text-[11px] font-medium text-[#8B7168] mt-1 block truncate"><?php echo $pending_soa_count > 0 ? 'Requires Clearance' : 'All Clear'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recent Collections Table -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-clock-rotate-left text-base text-[#59443F]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Recent Transactions</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Today and recent payment receipts</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2.5 self-start sm:self-center flex-wrap sm:flex-nowrap">
                                <div class="relative w-full sm:w-48">
                                    <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-[#8B7168]"></i>
                                    <input type="text" id="cashierTableSearch" onkeyup="filterCashierTable(this.value)" class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Filter payments...">
                                </div>
                                <a href="cashier_payments.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-plus text-xs"></i> Record Payment
                                </a>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="cashierRecentTable" class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                                        <th class="py-3 px-5 sm:px-6">Receipt / OR</th>
                                        <th class="py-3 px-4">Seminarian</th>
                                        <th class="py-3 px-4 text-right">Amount</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-5 sm:px-6 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (empty($recent_payments)): ?>
                                        <tr><td colspan="5" class="text-center py-10 px-4 text-[#8B7168] font-medium text-xs">No recent payments recorded.</td></tr>
                                    <?php else: foreach ($recent_payments as $rp): ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-3.5 px-5 sm:px-6 font-mono font-bold text-[#59443F]"><?php echo htmlspecialchars($rp['or_number'] ?: 'OR#' . $rp['id']); ?></td>
                                            <td class="py-3.5 px-4 font-bold text-[#59443F]"><?php echo htmlspecialchars($rp['student_name']); ?></td>
                                            <td class="py-3.5 px-4 text-right font-black text-[#59443F]">₱<?php echo htmlspecialchars(number_format((float)$rp['amount'], 2)); ?></td>
                                            <td class="py-3.5 px-4 text-[#8B7168] text-[11px]"><?php echo date('M j, Y', strtotime($rp['payment_date'])); ?></td>
                                            <td class="py-3.5 px-5 sm:px-6 text-right">
                                                <a href="cashier_receipt.php?payment_id=<?php echo (int)$rp['id']; ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[11px] font-bold border border-[#E8C7C5]/60 transition shadow-2xs">
                                                    <i class="fa-solid fa-print text-[10px]"></i> Receipt
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Monthly Collections Trend Chart -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-xl">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-chart-line text-base text-[#59443F]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Monthly Collections Trend</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Annual cash inflow distribution</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                Last 12 Months
                            </span>
                        </div>
                        <div class="w-full h-64 flex items-center justify-center">
                            <canvas id="monthlyCollections" class="w-full h-full"></canvas>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN (w-full xl:w-80 shrink-0): Calendar Widget at the very top right (flush with Top Header), Frequent Operations Hub below -->
                <div class="w-full xl:w-80 shrink-0 flex flex-col gap-6">
                    <!-- 1. Calendar Widget (Flush with the top of the Header on the left!) -->
                    <div id="timetableMiniCalendar"></div>
                    <div id="academicCalendarWidget"></div>

                    <!-- 2. Frequent Operations Hub -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-solid fa-bolt text-base text-[#59443F]"></i>
                            </div>
                            <div>
                                <h3 class="font-heading font-extrabold text-sm text-[#59443F] leading-tight">Frequent Operations Hub</h3>
                                <p class="text-[10px] text-[#8B7168]">1-Click financial management shortcuts</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-2.5">
                            <a href="cashier_accounts.php" class="block p-3.5 rounded-2xl bg-[#F8EEEE]/60 hover:bg-[#F8EEEE] border border-[#E8C7C5]/50 transition group">
                                <div class="flex items-center gap-2.5 mb-1">
                                    <div class="w-8 h-8 rounded-xl bg-white text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs font-bold shadow-2xs">
                                        <i class="fa-solid fa-users text-[#59443F]"></i>
                                    </div>
                                    <span class="font-bold text-xs text-[#59443F] group-hover:text-[#8B7168] transition">Student Accounts</span>
                                </div>
                                <p class="text-[11px] text-[#8B7168] leading-relaxed">Balance status, statement lookup, and quick ledger drawers.</p>
                            </a>

                            <a href="cashier_payments.php" class="block p-3.5 rounded-2xl bg-[#F8EEEE]/60 hover:bg-[#F8EEEE] border border-[#E8C7C5]/50 transition group">
                                <div class="flex items-center gap-2.5 mb-1">
                                    <div class="w-8 h-8 rounded-xl bg-white text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs font-bold shadow-2xs">
                                        <i class="fa-solid fa-money-bill-wave text-[#59443F]"></i>
                                    </div>
                                    <span class="font-bold text-xs text-[#59443F] group-hover:text-[#8B7168] transition">Payment Processing</span>
                                </div>
                                <p class="text-[11px] text-[#8B7168] leading-relaxed">Fast denomination chips, sequential OR autofill, auto-print.</p>
                            </a>

                            <a href="cashier_requests.php" class="block p-3.5 rounded-2xl bg-[#F8EEEE]/60 hover:bg-[#F8EEEE] border border-[#E8C7C5]/50 transition group">
                                <div class="flex items-center gap-2.5 mb-1">
                                    <div class="w-8 h-8 rounded-xl bg-white text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs font-bold shadow-2xs">
                                        <i class="fa-solid fa-file-lines text-[#59443F]"></i>
                                    </div>
                                    <span class="font-bold text-xs text-[#59443F] group-hover:text-[#8B7168] transition">SOA Clearance Requests</span>
                                </div>
                                <p class="text-[11px] text-[#8B7168] leading-relaxed">Review student requests and issue official SOA documents.</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <script src="../assets/simple_charts.js"></script>
        <script src="../assets/mini_calendar.js?v=<?php echo file_exists(__DIR__ . '/../assets/mini_calendar.js') ? filemtime(__DIR__ . '/../assets/mini_calendar.js') : '1'; ?>"></script>
        <script>
        (function () {
            // Initialize Mini Calendar for Timetable
            if (document.getElementById('timetableMiniCalendar') && window.ModernMiniCalendar) {
                new ModernMiniCalendar('timetableMiniCalendar', { baseUrl: 'ajax.php', timetableUrl: 'timetable.php' });
            }

            const lineCanvas = document.getElementById('monthlyCollections');
            if (lineCanvas && window.SimpleCharts) {
                const labels = <?php echo json_encode($month_labels); ?>;
                const values = <?php echo json_encode($month_values); ?>;
                const max = values.length ? Math.max.apply(null, values) : 0;
                window.SimpleCharts.line(lineCanvas, labels, values, { yMin: 0, yMax: Math.max(1, Math.ceil(max / 100) * 100) });
            }
        })();

        function filterCashierTable(q) {
            q = (q || '').toLowerCase();
            document.querySelectorAll('#cashierRecentTable tbody tr').forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        }
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
