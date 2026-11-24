<?php
include '../includes/session_fix.php';
require_roles(['Dean']);
include '../includes/db.php';

$current_ay = get_current_academic_year();
$active_sem_default = function_exists('get_active_semester') ? get_active_semester() : '1st';
if (!in_array($active_sem_default, ['1st', '2nd', 'Summer'], true)) {
    $active_sem_default = '1st';
}

$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') {
    $filter_academic_year = $current_ay;
}
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st', '2nd', 'Summer'], true)) {
    $filter_semester = $active_sem_default;
}

$ay_options = [$current_ay];
$ay_res = $conn->query("
    (SELECT DISTINCT academic_year FROM academic_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM spiritual_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM human_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM pastoral_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM lodging_invoices WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM lodging_payments WHERE academic_year IS NOT NULL AND academic_year <> '')
    ORDER BY academic_year DESC
");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$formation = [
    'Academic' => ['avg' => null, 'count' => 0],
    'Human' => ['avg' => null, 'count' => 0],
    'Spiritual' => ['avg' => null, 'count' => 0],
    'Pastoral' => ['avg' => null, 'count' => 0],
];

$stmt_acad = $conn->prepare("SELECT AVG(r.average_score) as avg_score, COUNT(*) as c
    FROM academic_evaluation_results r
    JOIN academic_evaluations e ON e.id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_acad->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_acad->execute();
$row = $stmt_acad->get_result()->fetch_assoc();
if ($row) {
    $formation['Academic']['avg'] = $row['avg_score'] !== null ? (float)$row['avg_score'] : null;
    $formation['Academic']['count'] = (int)($row['c'] ?? 0);
}

$stmt_human = $conn->prepare("SELECT AVG(r.average_rating) as avg_score, COUNT(*) as c
    FROM human_results r
    JOIN human_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_human->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_human->execute();
$row = $stmt_human->get_result()->fetch_assoc();
if ($row) {
    $formation['Human']['avg'] = $row['avg_score'] !== null ? (float)$row['avg_score'] : null;
    $formation['Human']['count'] = (int)($row['c'] ?? 0);
}

$stmt_spiritual = $conn->prepare("SELECT AVG(r.average_rating) as avg_score, COUNT(*) as c
    FROM spiritual_results r
    JOIN spiritual_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_spiritual->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_spiritual->execute();
$row = $stmt_spiritual->get_result()->fetch_assoc();
if ($row) {
    $formation['Spiritual']['avg'] = $row['avg_score'] !== null ? (float)$row['avg_score'] : null;
    $formation['Spiritual']['count'] = (int)($row['c'] ?? 0);
}

$stmt_pastoral = $conn->prepare("SELECT AVG(r.average_rating) as avg_score, COUNT(*) as c
    FROM pastoral_results r
    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_pastoral->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_pastoral->execute();
$row = $stmt_pastoral->get_result()->fetch_assoc();
if ($row) {
    $formation['Pastoral']['avg'] = $row['avg_score'] !== null ? (float)$row['avg_score'] : null;
    $formation['Pastoral']['count'] = (int)($row['c'] ?? 0);
}

$billed_total = 0.0;
$paid_total = 0.0;
$stmt_billed = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
    FROM lodging_invoices i
    JOIN lodging_invoice_items it ON it.invoice_id = i.id
    WHERE i.archived_at IS NULL
      AND i.status IN ('issued', 'partially_paid', 'paid')
      AND i.academic_year = ?");
$stmt_billed->bind_param('s', $filter_academic_year);
$stmt_billed->execute();
$billed_total = (float)($stmt_billed->get_result()->fetch_assoc()['total'] ?? 0);

$stmt_paid = $conn->prepare("SELECT COALESCE(SUM(pa.amount),0) as total
    FROM lodging_payment_allocations pa
    JOIN lodging_invoices i ON pa.invoice_id = i.id
    WHERE i.archived_at IS NULL
      AND i.academic_year = ?");
$stmt_paid->bind_param('s', $filter_academic_year);
$stmt_paid->execute();
$paid_total = (float)($stmt_paid->get_result()->fetch_assoc()['total'] ?? 0);

$outstanding_total = max(0.0, $billed_total - $paid_total);

$paid_count = 0;
$unpaid_count = 0;
$stmt_paid_vs = $conn->prepare("SELECT
        SUM(CASE WHEN t.balance <= 0.00001 AND t.billed > 0 THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN t.balance > 0.00001 THEN 1 ELSE 0 END) as unpaid_count
    FROM (
        SELECT s.id,
            COALESCE((SELECT SUM(it.amount)
                FROM lodging_invoices i
                JOIN lodging_invoice_items it ON it.invoice_id = i.id
                WHERE i.seminarian_id = s.id AND i.archived_at IS NULL AND i.status IN ('issued', 'partially_paid', 'paid') AND i.academic_year = ?
            ),0) AS billed,
            COALESCE((SELECT SUM(pa.amount)
                FROM lodging_invoices i
                JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
                WHERE i.seminarian_id = s.id AND i.archived_at IS NULL AND i.academic_year = ?
            ),0) AS paid,
            COALESCE((SELECT SUM(it.amount)
                FROM lodging_invoices i
                JOIN lodging_invoice_items it ON it.invoice_id = i.id
                WHERE i.seminarian_id = s.id AND i.archived_at IS NULL AND i.status IN ('issued', 'partially_paid', 'paid') AND i.academic_year = ?
            ),0) - COALESCE((SELECT SUM(pa.amount)
                FROM lodging_invoices i
                JOIN lodging_payment_allocations pa ON pa.invoice_id = i.id
                WHERE i.seminarian_id = s.id AND i.archived_at IS NULL AND i.academic_year = ?
            ),0) AS balance
        FROM seminarians s
        JOIN users u ON u.id = s.user_id
        WHERE u.role = 'Student'
    ) t");
$stmt_paid_vs->bind_param('ssss', $filter_academic_year, $filter_academic_year, $filter_academic_year, $filter_academic_year);
$stmt_paid_vs->execute();
$row = $stmt_paid_vs->get_result()->fetch_assoc();
if ($row) {
    $paid_count = (int)($row['paid_count'] ?? 0);
    $unpaid_count = (int)($row['unpaid_count'] ?? 0);
}

$total_accounts = $paid_count + $unpaid_count;
$clearance_pct = $total_accounts > 0 ? round(($paid_count / $total_accounts) * 100) : 0;
$collection_rate = $billed_total > 0 ? round(($paid_total / $billed_total) * 100, 1) : 0.0;
$outstanding_rate = $billed_total > 0 ? round(($outstanding_total / $billed_total) * 100, 1) : 0.0;
$semester_label = $filter_semester === 'Summer' ? 'Summer Term' : ($filter_semester . ' Semester');

$pageTitle = 'Admin Analytics';
$activeNav = 'admin_analytics';
$filter_ay = $filter_academic_year;
$filter_sem = $filter_semester;
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body font-['Inter',sans-serif] space-y-6">
            <!-- Clean Context Subheader (Filters live exclusively in the unified top header) -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight font-['Plus_Jakarta_Sans','Inter',sans-serif] m-0">
                        Admin Dashboard Analytics
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-500 font-medium mt-1 mb-0">
                        Financial collections, account clearances, and formation pillar performance
                    </p>
                </div>
                <div class="inline-flex items-center gap-2 self-start sm:self-center px-3.5 py-1.5 rounded-full bg-[#FAF8F6] border border-[#E8C7C5]/80 text-xs font-semibold text-[#59443F]">
                    <span class="w-2 h-2 rounded-full bg-[#59443F]"></span>
                    <span>Active Term:</span>
                    <span class="font-bold"><?php echo htmlspecialchars($filter_academic_year); ?> &middot; <?php echo htmlspecialchars($semester_label); ?></span>
                </div>
            </div>

            <!-- 3 Balanced KPI Cards -->
            <section aria-label="Key Financial Metrics" class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <!-- KPI 1: Total Collections -->
                <article class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs hover:shadow-sm transition-shadow flex flex-col justify-between gap-4 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200/70 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-coins"></i>
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 truncate">Total Collections</span>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 shrink-0 tabular-nums">
                            <?php echo htmlspecialchars(number_format($collection_rate, 1)); ?>% collected
                        </span>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-[28px] font-bold text-slate-900 tracking-tight leading-none tabular-nums">
                            &#8369;<?php echo htmlspecialchars(number_format($paid_total, 2)); ?>
                        </div>
                        <p class="text-xs text-slate-500 font-medium mt-2 mb-0 tabular-nums">
                            Out of &#8369;<?php echo htmlspecialchars(number_format($billed_total, 2)); ?> billed this AY
                        </p>
                    </div>
                </article>

                <!-- KPI 2: Remaining Balances -->
                <article class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs hover:shadow-sm transition-shadow flex flex-col justify-between gap-4 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <?php if ($outstanding_total > 0): ?>
                                <span class="w-9 h-9 rounded-xl bg-rose-50 text-rose-700 border border-rose-200/70 flex items-center justify-center text-sm shrink-0">
                                    <i class="fa-solid fa-scale-balanced"></i>
                                </span>
                            <?php else: ?>
                                <span class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200/70 flex items-center justify-center text-sm shrink-0">
                                    <i class="fa-solid fa-scale-balanced"></i>
                                </span>
                            <?php endif; ?>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 truncate">Remaining Balances</span>
                        </div>
                        <?php if ($outstanding_total > 0): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200/60 shrink-0 tabular-nums">
                                <?php echo htmlspecialchars(number_format($outstanding_rate, 1)); ?>% due
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 shrink-0">
                                Settled
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-[28px] font-bold text-slate-900 tracking-tight leading-none tabular-nums">
                            &#8369;<?php echo htmlspecialchars(number_format($outstanding_total, 2)); ?>
                        </div>
                        <p class="text-xs text-slate-500 font-medium mt-2 mb-0 tabular-nums">
                            <?php echo $unpaid_count > 0 ? ($unpaid_count . ' account' . ($unpaid_count === 1 ? '' : 's') . ' with open balance') : 'All billed accounts cleared'; ?>
                        </p>
                    </div>
                </article>

                <!-- KPI 3: Paid Accounts -->
                <article class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs hover:shadow-sm transition-shadow flex flex-col justify-between gap-4 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-9 h-9 rounded-xl bg-[#FAF8F6] text-[#59443F] border border-[#E8C7C5]/80 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-user-check"></i>
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 truncate">Paid Accounts</span>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-[#FAF8F6] text-[#59443F] border border-[#E8C7C5]/80 shrink-0 tabular-nums">
                            <?php echo (int)$clearance_pct; ?>% cleared
                        </span>
                    </div>
                    <div>
                        <div class="flex items-baseline gap-1.5 leading-none tabular-nums">
                            <span class="text-2xl sm:text-[28px] font-bold text-slate-900 tracking-tight"><?php echo (int)$paid_count; ?></span>
                            <span class="text-sm font-semibold text-slate-400">/ <?php echo (int)$total_accounts; ?> accounts</span>
                        </div>
                        <div class="mt-3">
                            <div class="w-full h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuenow="<?php echo (int)$clearance_pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-[#59443F] transition-all duration-300" style="width: <?php echo (int)$clearance_pct; ?>%;"></div>
                            </div>
                            <div class="flex items-center justify-between text-[11px] text-slate-500 font-medium mt-1.5 tabular-nums">
                                <span><?php echo (int)$paid_count; ?> paid</span>
                                <span><?php echo (int)$unpaid_count; ?> unpaid</span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Charts Section -->
            <section aria-label="Analytics Charts" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
                <!-- Formation Pillar Averages (Bar Chart) -->
                <article class="lg:col-span-7 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col min-w-0">
                    <div class="flex items-center justify-between gap-3 mb-5">
                        <div>
                            <h3 class="text-base font-bold text-slate-900 tracking-tight m-0 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-[#FAF8F6] text-[#59443F] border border-[#E8C7C5]/70 inline-flex items-center justify-center text-xs">
                                    <i class="fa-solid fa-chart-column"></i>
                                </span>
                                <span>Formation Pillar Averages</span>
                            </h3>
                            <p class="text-xs text-slate-500 font-medium mt-1 mb-0">
                                Mean evaluation ratings (0.00 &ndash; 5.00 scale) for <?php echo htmlspecialchars($semester_label); ?>
                            </p>
                        </div>
                    </div>
                    <div class="relative flex-1 min-h-[240px] max-h-[260px]">
                        <canvas id="formationBar" class="block w-full h-full"></canvas>
                    </div>
                </article>

                <!-- Payment Clearance Status (Donut Chart) -->
                <article class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col justify-between min-w-0">
                    <div class="mb-4">
                        <h3 class="text-base font-bold text-slate-900 tracking-tight m-0 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-[#FAF8F6] text-[#59443F] border border-[#E8C7C5]/70 inline-flex items-center justify-center text-xs">
                                <i class="fa-solid fa-chart-pie"></i>
                            </span>
                            <span>Payment Clearance Status</span>
                        </h3>
                        <p class="text-xs text-slate-500 font-medium mt-1 mb-0">
                            Account settlement breakdown for AY <?php echo htmlspecialchars($filter_academic_year); ?>
                        </p>
                    </div>

                    <div class="relative mx-auto w-full max-w-[240px] h-[220px] max-h-[260px] flex items-center justify-center my-1">
                        <canvas id="paymentPie" class="block w-full h-full"></canvas>
                        <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                            <span class="text-2xl font-extrabold text-slate-900 tracking-tight leading-none tabular-nums"><?php echo (int)$clearance_pct; ?>%</span>
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mt-1">Cleared</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-4 mt-3 border-t border-slate-100">
                        <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-slate-50/80 border border-slate-200/60">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-[#59443F] shrink-0"></span>
                                <span class="text-xs font-semibold text-slate-600">Paid</span>
                            </div>
                            <span class="text-xs font-bold text-slate-900 tabular-nums"><?php echo (int)$paid_count; ?></span>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-slate-50/80 border border-slate-200/60">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-[#E8C7C5] shrink-0"></span>
                                <span class="text-xs font-semibold text-slate-600">Unpaid</span>
                            </div>
                            <span class="text-xs font-bold text-slate-900 tabular-nums"><?php echo (int)$unpaid_count; ?></span>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Formation Snapshot Table -->
            <section aria-label="Formation Snapshot" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-8 h-8 rounded-xl bg-[#FAF8F6] text-[#59443F] border border-[#E8C7C5]/70 inline-flex items-center justify-center text-xs">
                            <i class="fa-solid fa-table"></i>
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-slate-900 tracking-tight m-0">Formation Snapshot</h3>
                            <p class="text-xs text-slate-500 font-medium mt-0.5 mb-0">Evaluation breakdown across all four seminary formation dimensions</p>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200/70 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                                <th class="py-3 px-6">Formation Pillar</th>
                                <th class="py-3 px-6">Progress Scale</th>
                                <th class="py-3 px-6 text-right">Average Score</th>
                                <th class="py-3 px-6 text-right">Total Evaluations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            <?php
                            $pillar_colors = [
                                'Academic' => '#59443F',
                                'Human' => '#8B7168',
                                'Spiritual' => '#B68983',
                                'Pastoral' => '#D6A6A6',
                            ];
                            foreach ($formation as $k => $v) {
                                $avg_val = $v['avg'] !== null ? (float)$v['avg'] : null;
                                $pct_bar = $avg_val !== null ? max(0, min(100, round(($avg_val / 5.0) * 100))) : 0;
                                $dot_color = $pillar_colors[$k] ?? '#59443F';
                            ?>
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="py-3.5 px-6">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($dot_color); ?>;"></span>
                                            <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($k); ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-6 w-48">
                                        <div class="w-full h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-300" style="width: <?php echo (int)$pct_bar; ?>%; background-color: <?php echo htmlspecialchars($dot_color); ?>;"></div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-6 text-right tabular-nums">
                                        <?php if ($avg_val === null): ?>
                                            <span class="text-xs font-medium text-slate-400">No data</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/70">
                                                <?php echo htmlspecialchars(number_format($avg_val, 2)); ?> / 5.00
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-6 text-right font-semibold text-slate-700 tabular-nums">
                                        <?php echo (int)$v['count']; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function () {
            if (window.Chart) {
                Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
                Chart.defaults.color = '#64748b';
            }

            const formationLabels = <?php echo json_encode(array_keys($formation)); ?>;
            const formationValues = <?php echo json_encode(array_map(function ($v) { return $v['avg'] === null ? 0 : round((float)$v['avg'], 2); }, array_values($formation))); ?>;

            const ctxBar = document.getElementById('formationBar');
            if (ctxBar) {
                new Chart(ctxBar, {
                    type: 'bar',
                    data: {
                        labels: formationLabels,
                        datasets: [{
                            label: 'Average Score',
                            data: formationValues,
                            backgroundColor: ['#59443F', '#8B7168', '#B68983', '#D6A6A6'],
                            hoverBackgroundColor: ['#463430', '#735B53', '#A1746E', '#C48F8F'],
                            borderRadius: { topLeft: 8, topRight: 8 },
                            borderSkipped: 'bottom',
                            maxBarThickness: 40,
                            barPercentage: 0.55
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    font: { size: 12, weight: '600' },
                                    color: '#475569'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                max: 5,
                                border: { display: false, dash: [4, 4] },
                                grid: {
                                    color: 'rgba(148, 163, 184, 0.28)',
                                    tickLength: 0
                                },
                                ticks: {
                                    stepSize: 1,
                                    padding: 8,
                                    font: { size: 11, weight: '500' }
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#1e293b',
                                padding: 10,
                                cornerRadius: 8,
                                displayColors: false,
                                callbacks: {
                                    label: function (ctx) {
                                        return 'Average: ' + Number(ctx.parsed.y || 0).toFixed(2) + ' / 5.00';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            const ctxPie = document.getElementById('paymentPie');
            if (ctxPie) {
                const paidVal = <?php echo (int)$paid_count; ?>;
                const unpaidVal = <?php echo (int)$unpaid_count; ?>;
                const hasData = (paidVal + unpaidVal) > 0;
                new Chart(ctxPie, {
                    type: 'doughnut',
                    data: {
                        labels: hasData ? ['Paid', 'Unpaid'] : ['No Accounts'],
                        datasets: [{
                            data: hasData ? [paidVal, unpaidVal] : [1],
                            backgroundColor: hasData ? ['#59443F', '#E8C7C5'] : ['#e2e8f0'],
                            hoverBackgroundColor: hasData ? ['#463430', '#D6A6A6'] : ['#e2e8f0'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: hasData,
                                backgroundColor: '#1e293b',
                                padding: 10,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function (ctx) {
                                        const total = paidVal + unpaidVal;
                                        const val = Number(ctx.parsed || 0);
                                        const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                        return ctx.label + ': ' + val + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
