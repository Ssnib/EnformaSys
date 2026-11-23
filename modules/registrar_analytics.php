<?php
include '../includes/session_fix.php';
require_roles(['Registrar', 'Dean']);
include '../includes/db.php';

$current_ay = get_current_academic_year();
$filter_ay = trim((string)($_GET['academic_year'] ?? ''));
if ($filter_ay === '') {
    $filter_ay = $current_ay;
}

$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$where = "1=1";
$params = [];
$types = '';
if ($filter_ay !== '' && $filter_ay !== 'all') {
    $where .= " AND COALESCE(s.current_academic_year, '') = ?";
    $params[] = $filter_ay;
    $types .= 's';
}

$totals = [
    'total' => 0,
    'active' => 0,
    'under_eval' => 0,
    'dismissed' => 0,
    'completed' => 0,
    'graduates' => 0,
];

$stmt_tot = $conn->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN s.formation_status = 'Active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN s.formation_status = 'Under Evaluation' THEN 1 ELSE 0 END) as under_eval_count,
        SUM(CASE WHEN s.formation_status = 'Dismissed' THEN 1 ELSE 0 END) as dismissed_count,
        SUM(CASE WHEN s.formation_status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN s.is_graduate = 1 OR s.year_level = 'Completed' THEN 1 ELSE 0 END) as graduate_count
    FROM seminarians s
    WHERE {$where}");
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_tot, 'bind_param'], $bind);
}
$stmt_tot->execute();
$row = $stmt_tot->get_result()->fetch_assoc();
if ($row) {
    $totals['total'] = (int)($row['total'] ?? 0);
    $totals['active'] = (int)($row['active_count'] ?? 0);
    $totals['under_eval'] = (int)($row['under_eval_count'] ?? 0);
    $totals['dismissed'] = (int)($row['dismissed_count'] ?? 0);
    $totals['completed'] = (int)($row['completed_count'] ?? 0);
    $totals['graduates'] = (int)($row['graduate_count'] ?? 0);
}

$grouped_program = [];
$sql_prog = "SELECT COALESCE(NULLIF(s.program,''), 'Unspecified') as label, COUNT(*) as c
    FROM seminarians s
    WHERE {$where}
    GROUP BY label
    ORDER BY c DESC, label ASC";
$stmt_prog = $conn->prepare($sql_prog);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_prog, 'bind_param'], $bind);
}
$stmt_prog->execute();
$res_prog = $stmt_prog->get_result();
while ($r = $res_prog->fetch_assoc()) {
    $grouped_program[] = $r;
}

$grouped_year = [];
$sql_year = "SELECT COALESCE(NULLIF(s.year_level,''), 'Unspecified') as label, COUNT(*) as c
    FROM seminarians s
    WHERE {$where}
    GROUP BY label
    ORDER BY c DESC, label ASC";
$stmt_year = $conn->prepare($sql_year);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_year, 'bind_param'], $bind);
}
$stmt_year->execute();
$res_year = $stmt_year->get_result();
while ($r = $res_year->fetch_assoc()) {
    $grouped_year[] = $r;
}

$grouped_status = [];
$sql_status = "SELECT COALESCE(NULLIF(s.formation_status,''), 'Unspecified') as label, COUNT(*) as c
    FROM seminarians s
    WHERE {$where}
    GROUP BY label
    ORDER BY c DESC, label ASC";
$stmt_status = $conn->prepare($sql_status);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_status, 'bind_param'], $bind);
}
$stmt_status->execute();
$res_status = $stmt_status->get_result();
while ($r = $res_status->fetch_assoc()) {
    $grouped_status[] = $r;
}

$program_labels = array_map(static fn ($r) => (string)$r['label'], $grouped_program);
$program_values = array_map(static fn ($r) => (int)$r['c'], $grouped_program);
$year_labels = array_map(static fn ($r) => (string)$r['label'], $grouped_year);
$year_values = array_map(static fn ($r) => (int)$r['c'], $grouped_year);
$status_labels = array_map(static fn ($r) => (string)$r['label'], $grouped_status);
$status_values = array_map(static fn ($r) => (int)$r['c'], $grouped_status);

$pageTitle = 'Registrar Analytics';
$activeNav = 'registrar_analytics';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>

<div class="content-body font-['Inter',sans-serif]">
    <!-- Top Toolbar Header & Filter Control -->
    <div class="bg-white rounded-2xl border border-slate-200/90 shadow-xs px-6 py-5 mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200/60">
                    <i class="fa-solid fa-chart-pie text-[10px]"></i> Registrar Portal
                </span>
                <span class="text-xs text-slate-400 font-medium">• Enrollment Intelligence</span>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2.5">
                Registrar Analytics
            </h1>
            <p class="text-xs text-slate-500 font-medium mt-0.5">Demographics, curriculum distribution, and formation status overview</p>
        </div>

        <!-- Academic Year Term Switcher -->
        <div class="flex items-center gap-3 self-start md:self-center bg-slate-50 border border-slate-200/80 p-1.5 rounded-xl shadow-2xs">
            <div class="flex items-center gap-2 pl-2">
                <i class="fa-regular fa-calendar-days text-slate-400 text-xs"></i>
                <label for="aySelect" class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Term:</label>
            </div>
            <select id="aySelect" class="bg-white border border-slate-200 text-slate-800 text-xs font-bold rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition cursor-pointer">
                <option value="all" <?php echo $filter_ay === 'all' ? 'selected' : ''; ?>>All Academic Years</option>
                <?php foreach ($ay_options as $ay) { ?>
                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $filter_ay === $ay ? 'selected' : ''; ?>>
                        AY <?php echo htmlspecialchars($ay); ?>
                    </option>
                <?php } ?>
            </select>
        </div>
    </div>

    <!-- 1. Top Row: 5 Premium Interactive Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <!-- Card 1: Total Seminarians (Blue) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg group relative overflow-hidden" style="border-top: 3px solid #3b82f6;">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Seminarians</span>
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-sm shrink-0 transition-transform duration-300 group-hover:scale-110 shadow-2xs">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="text-3xl font-black text-slate-900 tracking-tight mb-1 num-col">
                <?php echo (int)$totals['total']; ?>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-blue-700">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                <span>Official Headcount</span>
            </div>
        </div>

        <!-- Card 2: Active Status (Green) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg group relative overflow-hidden" style="border-top: 3px solid #10b981;">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active</span>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-sm shrink-0 transition-transform duration-300 group-hover:scale-110 shadow-2xs">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>
            <div class="text-3xl font-black text-emerald-600 tracking-tight mb-1 num-col">
                <?php echo (int)$totals['active']; ?>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span><?php echo $totals['total'] > 0 ? round(($totals['active'] / $totals['total']) * 100) : 0; ?>% of Total</span>
            </div>
        </div>

        <!-- Card 3: Under Evaluation (Amber) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg group relative overflow-hidden" style="border-top: 3px solid #f59e0b;">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Under Evaluation</span>
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center text-sm shrink-0 transition-transform duration-300 group-hover:scale-110 shadow-2xs">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
            </div>
            <div class="text-3xl font-black text-amber-600 tracking-tight mb-1 num-col">
                <?php echo (int)$totals['under_eval']; ?>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-amber-700">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                <span>Review In Progress</span>
            </div>
        </div>

        <!-- Card 4: Dismissed (Rose / Red) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg group relative overflow-hidden" style="border-top: 3px solid #ef4444;">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Dismissed</span>
                <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center text-sm shrink-0 transition-transform duration-300 group-hover:scale-110 shadow-2xs">
                    <i class="fa-solid fa-user-slash"></i>
                </div>
            </div>
            <div class="text-3xl font-black text-rose-600 tracking-tight mb-1 num-col">
                <?php echo (int)$totals['dismissed']; ?>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-rose-700">
                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                <span>Archived / Inactive</span>
            </div>
        </div>

        <!-- Card 5: Graduates (Purple) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg group relative overflow-hidden" style="border-top: 3px solid #8b5cf6;">
            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Graduates</span>
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 border border-purple-100 flex items-center justify-center text-sm shrink-0 transition-transform duration-300 group-hover:scale-110 shadow-2xs">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
            </div>
            <div class="text-3xl font-black text-purple-600 tracking-tight mb-1 num-col">
                <?php echo (int)$totals['graduates']; ?>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-purple-700">
                <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                <span>Completed Formation</span>
            </div>
        </div>
    </div>

    <!-- 2. Interactive Charts Section (Program & Year Level Distribution) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 items-start">
        <!-- Chart 1: Program Distribution -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 sm:p-6 transition-all duration-300 hover:shadow-md">
            <div class="flex items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-sm shrink-0 shadow-2xs">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 tracking-tight">Program Distribution</h2>
                        <p class="text-[11px] text-slate-500 font-medium">Population by degree &amp; academic curriculum</p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200/60">
                    <?php echo count($program_labels); ?> Programs
                </span>
            </div>

            <!-- Canvas Container with ample height for readable wrapped labels -->
            <div class="relative w-full" style="min-height: 300px; height: 320px;">
                <canvas id="programChart"></canvas>
            </div>
        </div>

        <!-- Chart 2: Year Level Distribution -->
        <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 sm:p-6 transition-all duration-300 hover:shadow-md">
            <div class="flex items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-sm shrink-0 shadow-2xs">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 tracking-tight">Year Level Distribution</h2>
                        <p class="text-[11px] text-slate-500 font-medium">Seminarians by academic stage &amp; level</p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                    <?php echo count($year_labels); ?> Levels
                </span>
            </div>

            <!-- Canvas Container -->
            <div class="relative w-full" style="min-height: 300px; height: 320px;">
                <canvas id="yearChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 3. Formation Status Breakdown Section (Modern Card with Donut Chart & Detailed Metrics) -->
    <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 sm:p-6 transition-all duration-300 hover:shadow-md">
        <div class="flex items-center justify-between gap-3 mb-5 pb-3 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 border border-purple-100 flex items-center justify-center text-sm shrink-0 shadow-2xs">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900 tracking-tight">Formation Status Breakdown</h2>
                    <p class="text-[11px] text-slate-500 font-medium">Distribution across Active, Under Evaluation, Dismissed, and Completed</p>
                </div>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200/80">
                Total: <?php echo (int)$totals['total']; ?> Enrolled
            </span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
            <!-- Left: Interactive Donut Chart -->
            <div class="lg:col-span-5 flex flex-col items-center justify-center">
                <div class="relative w-full max-w-[270px] h-[270px]">
                    <canvas id="statusChart"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span class="text-2xl font-black text-slate-900 num-col"><?php echo (int)$totals['total']; ?></span>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Seminarians</span>
                    </div>
                </div>
            </div>

            <!-- Right: Interactive Breakdown Progress List -->
            <div class="lg:col-span-7 space-y-3.5">
                <?php
                $status_theme_map = [
                    'Active' => ['dot' => 'bg-emerald-500', 'bar' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'bg' => 'bg-emerald-50/70'],
                    'Under Evaluation' => ['dot' => 'bg-amber-500', 'bar' => 'bg-amber-500', 'text' => 'text-amber-700', 'bg' => 'bg-amber-50/70'],
                    'Dismissed' => ['dot' => 'bg-rose-500', 'bar' => 'bg-rose-500', 'text' => 'text-rose-700', 'bg' => 'bg-rose-50/70'],
                    'Completed' => ['dot' => 'bg-purple-500', 'bar' => 'bg-purple-500', 'text' => 'text-purple-700', 'bg' => 'bg-purple-50/70'],
                    'Unspecified' => ['dot' => 'bg-slate-400', 'bar' => 'bg-slate-400', 'text' => 'text-slate-600', 'bg' => 'bg-slate-50'],
                ];

                foreach ($grouped_status as $st):
                    $lbl = $st['label'];
                    $cnt = (int)$st['c'];
                    $pct = $totals['total'] > 0 ? round(($cnt / $totals['total']) * 100, 1) : 0;
                    $thm = $status_theme_map[$lbl] ?? ['dot' => 'bg-blue-500', 'bar' => 'bg-blue-500', 'text' => 'text-blue-700', 'bg' => 'bg-blue-50'];
                ?>
                <div class="p-3.5 rounded-xl border border-slate-100 <?php echo $thm['bg']; ?> hover:border-slate-200 transition">
                    <div class="flex items-center justify-between text-xs mb-1.5">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full <?php echo $thm['dot']; ?>"></span>
                            <span class="font-bold text-slate-800"><?php echo htmlspecialchars($lbl); ?></span>
                        </div>
                        <div class="flex items-center gap-2 font-bold">
                            <span class="text-slate-900 num-col"><?php echo $cnt; ?></span>
                            <span class="text-[11px] font-semibold text-slate-500">(<?php echo $pct; ?>%)</span>
                        </div>
                    </div>
                    <!-- Modern Progress Bar -->
                    <div class="w-full h-2 rounded-full bg-slate-200/70 overflow-hidden">
                        <div class="<?php echo $thm['bar']; ?> h-full rounded-full transition-all duration-700" style="width: <?php echo $pct; ?>%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js 4.4.1 Library for High-Fidelity Responsive Charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    // 1. Term select filter listener
    const aySel = document.getElementById('aySelect');
    if (aySel) {
        aySel.addEventListener('change', function () {
            const params = new URLSearchParams(window.location.search);
            const v = aySel.value || '<?php echo htmlspecialchars($current_ay); ?>';
            params.set('academic_year', v);
            window.location.search = params.toString();
        });
    }

    // 2. Extracted Data from Backend
    const rawProgramLabels = <?php echo json_encode($program_labels); ?>;
    const programValues = <?php echo json_encode($program_values); ?>;
    const rawYearLabels = <?php echo json_encode($year_labels); ?>;
    const yearValues = <?php echo json_encode($year_values); ?>;
    const statusLabels = <?php echo json_encode($status_labels); ?>;
    const statusValues = <?php echo json_encode($status_values); ?>;

    // Helper: Smart label word wrapping for X-axis labels
    function formatXAxisLabel(str) {
        if (!str) return '';
        if (str.length <= 16) return str;
        // Break into lines by words
        const words = str.split(' ');
        const lines = [];
        let cur = '';
        words.forEach(w => {
            if ((cur + ' ' + w).trim().length > 15) {
                if (cur) lines.push(cur.trim());
                cur = w;
            } else {
                cur += ' ' + w;
            }
        });
        if (cur) lines.push(cur.trim());
        return lines;
    }

    // 3. Program Distribution (Bar Chart with vibrant multi-color palette & rounded corners)
    const programCanvas = document.getElementById('programChart');
    if (programCanvas && window.Chart) {
        const programPalette = [
            '#3b82f6', // Royal Blue
            '#6366f1', // Indigo
            '#8b5cf6', // Violet
            '#0ea5e9', // Sky Blue
            '#10b981', // Emerald
            '#f59e0b', // Amber
            '#ec4899', // Pink
            '#14b8a6'  // Teal
        ];
        const programHoverPalette = [
            '#2563eb',
            '#4f46e5',
            '#7c3aed',
            '#0284c7',
            '#059669',
            '#d97706',
            '#db2777',
            '#0d9488'
        ];

        const formattedProgramLabels = rawProgramLabels.map(formatXAxisLabel);

        new Chart(programCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: formattedProgramLabels,
                datasets: [{
                    label: 'Enrolled Seminarians',
                    data: programValues,
                    backgroundColor: rawProgramLabels.map((_, i) => programPalette[i % programPalette.length]),
                    hoverBackgroundColor: rawProgramLabels.map((_, i) => programHoverPalette[i % programHoverPalette.length]),
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 'flex',
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 900,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return rawProgramLabels[idx] || '';
                            },
                            label: function(context) {
                                const val = context.parsed.y;
                                const total = programValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ` Population: ${val} seminarian${val === 1 ? '' : 's'} (${pct}% of total)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: {
                            color: '#64748b',
                            autoSkip: false,
                            maxRotation: 40,
                            minRotation: 0,
                            font: { family: "'Inter', sans-serif", size: 11, weight: '600' }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9', drawBorder: false },
                        ticks: {
                            color: '#94a3b8',
                            stepSize: 1,
                            font: { family: "'Inter', sans-serif", size: 11 }
                        }
                    }
                }
            }
        });
    }

    // 4. Year Level Distribution (Bar Chart with stage color palette)
    const yearCanvas = document.getElementById('yearChart');
    if (yearCanvas && window.Chart) {
        const yearPalette = [
            '#10b981', // Emerald for Propaedeutic
            '#3b82f6', // Royal Blue for 1st Year
            '#6366f1', // Indigo for 2nd Year
            '#8b5cf6', // Violet for 3rd Year
            '#0f172a', // Navy for 4th Year
            '#f59e0b', // Amber for other
            '#64748b'  // Slate
        ];
        const yearHoverPalette = [
            '#059669',
            '#2563eb',
            '#4f46e5',
            '#7c3aed',
            '#1e293b',
            '#d97706',
            '#475569'
        ];

        const formattedYearLabels = rawYearLabels.map(formatXAxisLabel);

        new Chart(yearCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: formattedYearLabels,
                datasets: [{
                    label: 'Seminarians',
                    data: yearValues,
                    backgroundColor: rawYearLabels.map((_, i) => yearPalette[i % yearPalette.length]),
                    hoverBackgroundColor: rawYearLabels.map((_, i) => yearHoverPalette[i % yearHoverPalette.length]),
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 'flex',
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 900,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return rawYearLabels[idx] || '';
                            },
                            label: function(context) {
                                const val = context.parsed.y;
                                const total = yearValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ` Level Count: ${val} seminarian${val === 1 ? '' : 's'} (${pct}% of total)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: {
                            color: '#64748b',
                            autoSkip: false,
                            maxRotation: 35,
                            minRotation: 0,
                            font: { family: "'Inter', sans-serif", size: 11, weight: '600' }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9', drawBorder: false },
                        ticks: {
                            color: '#94a3b8',
                            stepSize: 1,
                            font: { family: "'Inter', sans-serif", size: 11 }
                        }
                    }
                }
            }
        });
    }

    // 5. Formation Status Breakdown (Modern Doughnut Chart with center cutout)
    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas && window.Chart) {
        const statusColors = {
            'Active': '#10b981',
            'Under Evaluation': '#f59e0b',
            'Dismissed': '#ef4444',
            'Completed': '#8b5cf6',
            'Unspecified': '#94a3b8'
        };

        const bgList = statusLabels.map(l => statusColors[l] || '#3b82f6');

        new Chart(statusCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: bgList,
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 1000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed;
                                const total = statusValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ` ${val} Seminarians (${pct}%)`;
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
