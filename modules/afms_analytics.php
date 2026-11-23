<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = get_active_academic_year();
$filter_semester = isset($_GET['semester']) ? afms_normalize_semester_value((string)$_GET['semester']) : afms_normalize_semester_value(get_active_semester());

$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM gpa_records WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM student_grades WHERE academic_year IS NOT NULL AND academic_year <> '')
    UNION (SELECT DISTINCT academic_year FROM academic_evaluations WHERE archived_at IS NULL AND academic_year IS NOT NULL AND academic_year <> '')
    ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$category_counts = [
    'Excellent' => 0,
    'Very Good' => 0,
    'Good' => 0,
    'Needs Improvement' => 0,
    'Academic Risk' => 0,
];
$stmt_cat = $conn->prepare("SELECT performance_category, COUNT(*) as c
    FROM gpa_records
    WHERE academic_year = ? AND semester = ? AND semester_gpa IS NOT NULL
    GROUP BY performance_category");
$stmt_cat->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_cat->execute();
$res_cat = $stmt_cat->get_result();
while ($r = $res_cat->fetch_assoc()) {
    $k = (string)($r['performance_category'] ?? '');
    if ($k !== '' && array_key_exists($k, $category_counts)) {
        $category_counts[$k] = (int)($r['c'] ?? 0);
    }
}
$total_evaluated = array_sum($category_counts);

$passing_rate = 0.0;
$pass_total = 0;
$pass_count = 0;
$stmt_pass = $conn->prepare("SELECT COUNT(*) as total,
        SUM(CASE WHEN semester_gpa <= 3.00 THEN 1 ELSE 0 END) as pass_count
    FROM gpa_records
    WHERE academic_year = ? AND semester = ? AND semester_gpa IS NOT NULL");
$stmt_pass->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_pass->execute();
$pass_row = $stmt_pass->get_result()->fetch_assoc() ?: null;
if ($pass_row && (int)$pass_row['total'] > 0) {
    $pass_total = (int)$pass_row['total'];
    $pass_count = (int)$pass_row['pass_count'];
    $passing_rate = ($pass_count / $pass_total) * 100.0;
}

$irregular_count = 0;
$stmt_ir = $conn->prepare("SELECT COUNT(*) as c FROM irregular_students WHERE status = 'Active' AND academic_year = ? AND semester = ?");
$stmt_ir->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_ir->execute();
$irregular_count = (int)($stmt_ir->get_result()->fetch_assoc()['c'] ?? 0);

// Top Performers (Honors): GPA <= 1.75 or Excellent category
$honors_count = 0;
$stmt_hon = $conn->prepare("SELECT COUNT(*) as c
    FROM gpa_records
    WHERE academic_year = ? AND semester = ? AND semester_gpa IS NOT NULL
      AND (semester_gpa <= 1.75 OR performance_category = 'Excellent')");
$stmt_hon->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_hon->execute();
$honors_count = (int)($stmt_hon->get_result()->fetch_assoc()['c'] ?? 0);

// Top 5 Subject Failure Watchlist (Hardest Subjects: highest failure rate, lowest avg grade)
$watchlist_subjects = [];
$stmt_wl = $conn->prepare("SELECT
        sub.subject_id,
        sub.subject_code,
        sub.subject_name,
        COUNT(*) AS total_graded,
        SUM(CASE WHEN (sg.remarks = 'Failure' OR COALESCE(sg.final_computed_grade, CASE WHEN sg.prelim_grade IS NOT NULL AND sg.midterm_grade IS NOT NULL AND sg.final_grade IS NOT NULL THEN ROUND(sg.prelim_grade * 0.30 + sg.midterm_grade * 0.30 + sg.final_grade * 0.40, 2) ELSE COALESCE(sg.final_grade, sg.midterm_grade, sg.prelim_grade) END) < 75) THEN 1 ELSE 0 END) AS failure_count,
        ROUND(AVG(COALESCE(sg.final_computed_grade, CASE WHEN sg.prelim_grade IS NOT NULL AND sg.midterm_grade IS NOT NULL AND sg.final_grade IS NOT NULL THEN ROUND(sg.prelim_grade * 0.30 + sg.midterm_grade * 0.30 + sg.final_grade * 0.40, 2) ELSE COALESCE(sg.final_grade, sg.midterm_grade, sg.prelim_grade) END)), 2) AS avg_grade
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    WHERE sg.academic_year = ? AND sg.semester = ?
      AND (sg.final_computed_grade IS NOT NULL OR sg.prelim_grade IS NOT NULL OR sg.midterm_grade IS NOT NULL OR sg.final_grade IS NOT NULL)
    GROUP BY sub.subject_id, sub.subject_code, sub.subject_name
    ORDER BY (SUM(CASE WHEN (sg.remarks = 'Failure' OR COALESCE(sg.final_computed_grade, CASE WHEN sg.prelim_grade IS NOT NULL AND sg.midterm_grade IS NOT NULL AND sg.final_grade IS NOT NULL THEN ROUND(sg.prelim_grade * 0.30 + sg.midterm_grade * 0.30 + sg.final_grade * 0.40, 2) ELSE COALESCE(sg.final_grade, sg.midterm_grade, sg.prelim_grade) END) < 75) THEN 1 ELSE 0 END) / COUNT(*)) DESC, avg_grade ASC
    LIMIT 5");
$stmt_wl->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_wl->execute();
$res_wl = $stmt_wl->get_result();
$has_any_failure = false;
while ($r = $res_wl->fetch_assoc()) {
    $tot = max(1, (int)$r['total_graded']);
    $fail = (int)$r['failure_count'];
    $fail_rate = round(($fail / $tot) * 100, 1);
    if ($fail > 0) $has_any_failure = true;
    $watchlist_subjects[] = [
        'code' => (string)$r['subject_code'],
        'name' => (string)$r['subject_name'],
        'total_graded' => $tot,
        'failure_count' => $fail,
        'failure_rate' => $fail_rate,
        'avg_grade' => round((float)$r['avg_grade'], 2),
    ];
}

// Academic Evaluations Analytics (5-point rubric across criteria & Year Levels)
function afms_normalize_eval_year_level(string $raw): string {
    $val = trim($raw);
    $map = [
        'propaedeutic' => 'Propaedeutic Stage',
        'propaedeutic stage' => 'Propaedeutic Stage',
        '1st year' => 'First Year',
        'first year' => 'First Year',
        '2nd year' => 'Second Year',
        'second year' => 'Second Year',
        '3rd year' => 'Third Year',
        'third year' => 'Third Year',
        '4th year' => 'Fourth Year',
        'fourth year' => 'Fourth Year',
    ];
    $key = strtolower($val);
    return $map[$key] ?? ($val !== '' ? $val : 'Unassigned');
}

function afms_eval_bar_color(float $score, int $evalCount): string {
    if ($evalCount <= 0) return 'bg-gray-300';
    if ($score >= 4.0) return 'bg-green-500';
    if ($score >= 3.0) return 'bg-yellow-500';
    return 'bg-red-500';
}

$eval_year_level_options = [
    'all' => 'All Students',
    'Propaedeutic Stage' => 'Propaedeutic Stage',
    'First Year' => 'First Year',
    'Second Year' => 'Second Year',
    'Third Year' => 'Third Year',
    'Fourth Year' => 'Fourth Year',
];
$raw_eval_yl = isset($_GET['eval_year_level']) ? trim((string)$_GET['eval_year_level']) : 'all';
$filter_eval_yl = ($raw_eval_yl === '' || strtolower($raw_eval_yl) === 'all')
    ? 'all'
    : afms_normalize_eval_year_level($raw_eval_yl);
if (!array_key_exists($filter_eval_yl, $eval_year_level_options)) {
    $filter_eval_yl = 'all';
}

$eval_criteria_names = [];
$res_crit = $conn->query("SELECT category, MIN(sort_order) AS sort_pos
    FROM academic_evaluation_questions
    WHERE is_active = 1 AND category IS NOT NULL AND category <> ''
    GROUP BY category
    ORDER BY sort_pos ASC, category ASC");
if ($res_crit) {
    while ($rc = $res_crit->fetch_assoc()) {
        $eval_criteria_names[] = (string)$rc['category'];
    }
}

// Accumulate sums and counts per normalized Year Level (plus 'all')
$eval_agg = [];
foreach (array_keys($eval_year_level_options) as $yl_key) {
    $eval_agg[$yl_key] = [
        'eval_count' => 0,
        'overall_sum' => 0.0,
        'overall_count' => 0,
        'criteria' => [],
    ];
    foreach ($eval_criteria_names as $c_name) {
        $eval_agg[$yl_key]['criteria'][$c_name] = ['sum' => 0.0, 'count' => 0];
    }
}

$stmt_eval_cnt = $conn->prepare("SELECT
        COALESCE(NULLIF(e_term.year_level, ''), NULLIF(sem.year_level, ''), NULLIF(v_cur.year_level, ''), 'Unassigned') AS raw_year_level,
        COUNT(DISTINCT ae.id) AS eval_count
    FROM academic_evaluations ae
    LEFT JOIN (
        SELECT student_id, MAX(year_level) AS year_level
        FROM enrollments
        WHERE academic_year = ? AND semester = ?
        GROUP BY student_id
    ) e_term ON e_term.student_id = ae.student_id
    LEFT JOIN seminarians sem ON sem.id = ae.student_id
    LEFT JOIN v_student_current_status v_cur ON v_cur.student_id = ae.student_id
    WHERE ae.archived_at IS NULL
      AND ae.academic_year = ?
      AND ae.semester = ?
    GROUP BY raw_year_level");
$stmt_eval_cnt->bind_param('ssss', $filter_academic_year, $filter_semester, $filter_academic_year, $filter_semester);
$stmt_eval_cnt->execute();
$res_ec = $stmt_eval_cnt->get_result();
while ($row = $res_ec->fetch_assoc()) {
    $norm_yl = afms_normalize_eval_year_level((string)($row['raw_year_level'] ?? ''));
    $cnt = (int)($row['eval_count'] ?? 0);
    $eval_agg['all']['eval_count'] += $cnt;
    if (isset($eval_agg[$norm_yl])) {
        $eval_agg[$norm_yl]['eval_count'] += $cnt;
    }
}

$stmt_eval_resp = $conn->prepare("SELECT
        COALESCE(NULLIF(e_term.year_level, ''), NULLIF(sem.year_level, ''), NULLIF(v_cur.year_level, ''), 'Unassigned') AS raw_year_level,
        q.category,
        SUM(resp.rating) AS rating_sum,
        COUNT(resp.id) AS rating_count
    FROM academic_evaluations ae
    JOIN academic_evaluation_responses resp ON resp.evaluation_id = ae.id
    JOIN academic_evaluation_questions q ON q.id = resp.question_id
    LEFT JOIN (
        SELECT student_id, MAX(year_level) AS year_level
        FROM enrollments
        WHERE academic_year = ? AND semester = ?
        GROUP BY student_id
    ) e_term ON e_term.student_id = ae.student_id
    LEFT JOIN seminarians sem ON sem.id = ae.student_id
    LEFT JOIN v_student_current_status v_cur ON v_cur.student_id = ae.student_id
    WHERE ae.archived_at IS NULL
      AND ae.academic_year = ?
      AND ae.semester = ?
      AND q.is_active = 1
      AND resp.rating BETWEEN 1 AND 5
    GROUP BY raw_year_level, q.category");
$stmt_eval_resp->bind_param('ssss', $filter_academic_year, $filter_semester, $filter_academic_year, $filter_semester);
$stmt_eval_resp->execute();
$res_er = $stmt_eval_resp->get_result();
while ($row = $res_er->fetch_assoc()) {
    $norm_yl = afms_normalize_eval_year_level((string)($row['raw_year_level'] ?? ''));
    $cat = (string)($row['category'] ?? '');
    $sum = (float)($row['rating_sum'] ?? 0);
    $cnt = (int)($row['rating_count'] ?? 0);
    if ($cat === '' || $cnt <= 0) continue;

    foreach (['all', $norm_yl] as $bucket) {
        if (!isset($eval_agg[$bucket])) continue;
        if (!isset($eval_agg[$bucket]['criteria'][$cat])) {
            $eval_agg[$bucket]['criteria'][$cat] = ['sum' => 0.0, 'count' => 0];
        }
        $eval_agg[$bucket]['criteria'][$cat]['sum'] += $sum;
        $eval_agg[$bucket]['criteria'][$cat]['count'] += $cnt;
        $eval_agg[$bucket]['overall_sum'] += $sum;
        $eval_agg[$bucket]['overall_count'] += $cnt;
    }
}

$eval_datasets = [];
foreach ($eval_agg as $yl_key => $agg) {
    $scores = [];
    foreach ($eval_criteria_names as $c_name) {
        $c_sum = (float)($agg['criteria'][$c_name]['sum'] ?? 0);
        $c_cnt = (int)($agg['criteria'][$c_name]['count'] ?? 0);
        $scores[] = $c_cnt > 0 ? round($c_sum / $c_cnt, 2) : 0.0;
    }
    $overall = $agg['overall_count'] > 0 ? round($agg['overall_sum'] / $agg['overall_count'], 2) : 0.0;
    $eval_datasets[$yl_key] = [
        'evalCount' => (int)$agg['eval_count'],
        'overallAvg' => $overall,
        'scores' => $scores,
    ];
}
$active_eval_data = $eval_datasets[$filter_eval_yl] ?? $eval_datasets['all'];
?>
<?php
$pageTitle = 'Analytics';
$activeNav = 'afms_analytics';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="space-y-6">
            <!-- Header & Term Filter Toolbar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 tracking-tight m-0 flex items-center gap-2.5">
                        <i class="fas fa-chart-pie text-teal-700"></i>
                        <span>Academic Analytics</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium mt-1 mb-0">
                        Active Term: <strong class="text-gray-800"><?php echo htmlspecialchars($filter_academic_year); ?> &middot; <?php echo htmlspecialchars($filter_semester); ?> Semester</strong>
                    </p>
                </div>
                <form method="get" class="flex !flex-row flex-wrap items-center !gap-2.5 m-0 p-0 bg-transparent border-0 shadow-none">
                    <input type="hidden" name="eval_year_level" id="termFormEvalYearLevel" value="<?php echo htmlspecialchars($filter_eval_yl); ?>">
                    <select name="academic_year" onchange="this.form.submit()" class="px-3 py-2 text-xs font-semibold text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer">
                        <?php foreach ($ay_options as $ay) { ?>
                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_academic_year ? 'selected' : ''; ?>>
                                AY <?php echo htmlspecialchars($ay); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <select name="semester" onchange="this.form.submit()" class="px-3 py-2 text-xs font-semibold text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer">
                        <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                            <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?> Semester</option>
                        <?php } ?>
                    </select>
                </form>
            </div>

            <!-- 3-Column KPI Summary Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- KPI 1: Passing Rate -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Passing Rate (&le; 3.00)</span>
                        <div class="text-3xl font-extrabold text-emerald-600 mt-2 tracking-tight">
                            <?php echo htmlspecialchars(number_format($passing_rate, 1)); ?>%
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            <?php echo $pass_total > 0 ? "{$pass_count} of {$pass_total} evaluated passed" : 'No GPA records for this term'; ?>
                        </p>
                    </div>
                    <div class="shrink-0 select-none pointer-events-none" aria-hidden="true">
                        <i class="fas fa-arrow-trend-up text-5xl text-green-100"></i>
                    </div>
                </div>

                <!-- KPI 2: Irregular Students -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Irregular Students</span>
                        <div class="text-3xl font-extrabold <?php echo $irregular_count > 0 ? 'text-rose-600' : 'text-gray-900'; ?> mt-2 tracking-tight">
                            <?php echo (int)$irregular_count; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            <?php echo $irregular_count > 0 ? 'Active irregular course loads' : 'All students on regular track'; ?>
                        </p>
                    </div>
                    <div class="shrink-0 select-none pointer-events-none" aria-hidden="true">
                        <i class="fas fa-triangle-exclamation text-5xl text-red-100"></i>
                    </div>
                </div>

                <!-- KPI 3: Top Performers (Honors) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Top Performers (Honors)</span>
                        <div class="text-3xl font-extrabold text-amber-600 mt-2 tracking-tight">
                            <?php echo (int)$honors_count; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 mb-0">
                            GPA &le; 1.75 or Excellent standing
                        </p>
                    </div>
                    <div class="shrink-0 select-none pointer-events-none" aria-hidden="true">
                        <i class="fas fa-award text-5xl text-amber-100"></i>
                    </div>
                </div>
            </div>

            <!-- Responsive 2-Column Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
                <!-- Left Card: Performance Category Distribution Donut -->
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col">
                    <h3 class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3 mb-4 m-0 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <i class="fas fa-layer-group text-blue-600"></i>
                            <span>Performance Category Distribution</span>
                        </span>
                        <span class="text-xs font-medium text-gray-400">Seminary Standing</span>
                    </h3>

                    <div class="flex-1 flex flex-col sm:flex-row items-center justify-between gap-6">
                        <div class="relative w-full sm:w-1/2 h-72 flex items-center justify-center">
                            <canvas id="categoryChart"></canvas>
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span id="donutCenterTotal" class="text-3xl font-extrabold text-gray-900 leading-none"><?php echo (int)$total_evaluated; ?></span>
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mt-1">Evaluated</span>
                            </div>
                        </div>

                        <!-- Clean Interactive Legend -->
                        <div id="categoryLegend" class="w-full sm:w-1/2 space-y-2">
                            <?php
                            $cat_colors = ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444'];
                            $ci = 0;
                            foreach ($category_counts as $cat_label => $cat_cnt):
                                $pct = $total_evaluated > 0 ? round(($cat_cnt / $total_evaluated) * 100, 1) : 0;
                                $swatch = $cat_colors[$ci % count($cat_colors)];
                            ?>
                                <button type="button"
                                        data-legend-index="<?php echo $ci; ?>"
                                        style="width: 100%;"
                                        class="cat-legend-item flex items-center justify-between gap-3 px-3 py-2 rounded-lg bg-gray-50/70 hover:bg-gray-100 border border-gray-100 text-left transition cursor-pointer">
                                    <span class="flex items-center gap-2.5 min-w-0">
                                        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: <?php echo $swatch; ?>;"></span>
                                        <span class="legend-label text-xs font-semibold text-gray-700 truncate"><?php echo htmlspecialchars($cat_label); ?></span>
                                    </span>
                                    <span class="text-xs font-bold text-gray-900 whitespace-nowrap">
                                        <?php echo (int)$cat_cnt; ?> <span class="text-[11px] font-medium text-gray-400">(<?php echo $pct; ?>%)</span>
                                    </span>
                                </button>
                            <?php $ci++; endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- Right Card: Subject Failure Watchlist (Horizontal Bar Chart) -->
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col">
                    <div class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3 mb-4 flex flex-wrap items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <i class="fas fa-shield-exclamation text-rose-500"></i>
                            <span>Subject Failure Watchlist</span>
                        </span>
                        <div class="inline-flex items-center p-0.5 bg-gray-100 rounded-lg gap-0.5" role="group" aria-label="Watchlist Metric">
                            <button type="button" id="wlModeFailBtn" style="width: auto;" class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition cursor-pointer border-0 <?php echo $has_any_failure ? 'bg-white text-gray-900 shadow-2xs' : 'text-gray-500 hover:text-gray-800'; ?>">
                                Failure Rate %
                            </button>
                            <button type="button" id="wlModeAvgBtn" style="width: auto;" class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition cursor-pointer border-0 <?php echo !$has_any_failure ? 'bg-white text-gray-900 shadow-2xs' : 'text-gray-500 hover:text-gray-800'; ?>">
                                Lowest Avg
                            </button>
                        </div>
                    </div>

                    <?php if (empty($watchlist_subjects)): ?>
                        <div class="h-72 flex flex-col items-center justify-center text-center text-gray-400">
                            <i class="fas fa-book-open text-3xl text-gray-300 mb-2"></i>
                            <p class="text-xs font-semibold text-gray-500 m-0">No graded subject records for this term yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="h-72 relative">
                            <canvas id="subjectWatchlistChart"></canvas>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Academic Evaluations Overview (Full-Width Section) -->
            <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6" aria-labelledby="acadEvalOverviewHeading">
                <div class="border-b border-gray-100 pb-4 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 id="acadEvalOverviewHeading" class="text-base sm:text-lg font-bold text-gray-900 tracking-tight m-0 flex items-center gap-2">
                            <i class="fas fa-bullseye text-indigo-600"></i>
                            <span>Academic Evaluations Overview</span>
                        </h2>
                        <p class="text-xs text-gray-500 mt-1 mb-0">Average seminarian performance across academic criteria</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <span id="evalSummaryBadge" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $active_eval_data['evalCount'] > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <i class="fas fa-clipboard-check text-[11px]"></i>
                            <span id="evalCountText"><?php echo (int)$active_eval_data['evalCount']; ?> <?php echo (int)$active_eval_data['evalCount'] === 1 ? 'Evaluation' : 'Evaluations'; ?></span>
                            <?php if ($active_eval_data['evalCount'] > 0): ?>
                                <span id="evalOverallText" class="text-indigo-500">&middot; <?php echo number_format((float)$active_eval_data['overallAvg'], 2); ?> / 5.00</span>
                            <?php else: ?>
                                <span id="evalOverallText" class="hidden"></span>
                            <?php endif; ?>
                        </span>

                        <div class="flex items-center gap-2">
                            <label for="evalYearLevelFilter" class="sr-only">Filter evaluations by Year Level</label>
                            <select id="evalYearLevelFilter"
                                    aria-label="Filter evaluations by Year Level"
                                    class="px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-50 hover:bg-gray-100 border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition cursor-pointer">
                                <?php foreach ($eval_year_level_options as $yl_val => $yl_label): ?>
                                    <option value="<?php echo htmlspecialchars($yl_val); ?>" <?php echo $filter_eval_yl === $yl_val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yl_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
                    <!-- Left: Radar Chart (5-Point Scale) -->
                    <div class="flex flex-col">
                        <div class="h-80 relative w-full">
                            <canvas id="academicEvalRadarChart"></canvas>
                        </div>
                        <p id="evalEmptyNotice" class="text-center text-xs text-gray-400 mt-2 mb-0 <?php echo $active_eval_data['evalCount'] > 0 ? 'hidden' : ''; ?>">
                            No academic evaluations recorded for <span class="font-semibold text-gray-500"><?php echo htmlspecialchars($eval_year_level_options[$filter_eval_yl] ?? 'All Students'); ?></span> in AY <?php echo htmlspecialchars($filter_academic_year); ?> (<?php echo htmlspecialchars($filter_semester); ?> Sem).
                        </p>
                    </div>

                    <!-- Right: Criteria Breakdown Progress Bars -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wider text-gray-400 pb-1 border-b border-gray-100">
                            <span>Evaluation Criterion</span>
                            <span>Avg Score (5.00 Scale)</span>
                        </div>

                        <div id="evalCriteriaBreakdown" class="space-y-3.5">
                            <?php
                            $active_cnt = (int)$active_eval_data['evalCount'];
                            foreach ($eval_criteria_names as $idx => $crit_name):
                                $score = (float)($active_eval_data['scores'][$idx] ?? 0.0);
                                $pct = $active_cnt > 0 ? min(100, max(0, ($score / 5.0) * 100)) : 0;
                                $bar_color = afms_eval_bar_color($score, $active_cnt);
                            ?>
                                <div class="eval-criterion-row" data-criterion-index="<?php echo $idx; ?>">
                                    <div class="flex items-center justify-between gap-3 mb-1.5">
                                        <span class="text-xs font-semibold text-gray-700 truncate" title="<?php echo htmlspecialchars($crit_name); ?>">
                                            <?php echo htmlspecialchars($crit_name); ?>
                                        </span>
                                        <span class="eval-score-label text-xs font-bold tabular-nums whitespace-nowrap <?php echo $active_cnt > 0 ? 'text-gray-900' : 'text-gray-400'; ?>">
                                            <?php echo number_format($score, 2); ?> <span class="font-medium text-gray-400">/ 5.00</span>
                                        </span>
                                    </div>
                                    <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="eval-progress-bar h-full rounded-full transition-all duration-300 <?php echo $bar_color; ?>"
                                             style="width: <?php echo round($pct, 1); ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="pt-2 flex flex-wrap items-center gap-4 text-[11px] text-gray-500">
                            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-500"></span> &ge; 4.00 (Strong)</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-yellow-500"></span> 3.00 &ndash; 3.99 (Satisfactory)</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500"></span> &lt; 3.00 (Needs Support)</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            const categoryData = <?php echo json_encode(array_values($category_counts)); ?>;
            const categoryLabels = <?php echo json_encode(array_keys($category_counts)); ?>;
            const categoryColors = ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444'];
            const watchlistData = <?php echo json_encode($watchlist_subjects); ?>;
            const hasAnyFailure = <?php echo json_encode($has_any_failure); ?>;
            const evalCriteriaLabels = <?php echo json_encode($eval_criteria_names); ?>;
            const evalDatasetsByYearLevel = <?php echo json_encode($eval_datasets); ?>;
            const evalYearLevelLabels = <?php echo json_encode($eval_year_level_options); ?>;
            const initialEvalYearLevel = <?php echo json_encode($filter_eval_yl); ?>;
            const activeTermLabel = <?php echo json_encode('AY ' . $filter_academic_year . ' (' . $filter_semester . ' Sem)'); ?>;

            // 1. Performance Category Donut with Center Total & Interactive Legend
            const cctx = document.getElementById('categoryChart');
            const centerTotalEl = document.getElementById('donutCenterTotal');

            if (cctx) {
                const catChart = new Chart(cctx, {
                    type: 'doughnut',
                    data: {
                        labels: categoryLabels,
                        datasets: [{
                            data: categoryData,
                            backgroundColor: categoryColors,
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0f172a',
                                padding: 10,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function (ctx) {
                                        const val = Number(ctx.raw || 0);
                                        const visibleSum = ctx.dataset.data.reduce((acc, v, idx) => (
                                            catChart.getDataVisibility(idx) ? acc + Number(v || 0) : acc
                                        ), 0);
                                        const pct = visibleSum > 0 ? ((val / visibleSum) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': ' + val + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });

                document.querySelectorAll('#categoryLegend .cat-legend-item').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const idx = parseInt(this.getAttribute('data-legend-index') || '0', 10);
                        catChart.toggleDataVisibility(idx);
                        catChart.update();

                        const isVisible = catChart.getDataVisibility(idx);
                        this.classList.toggle('opacity-45', !isVisible);
                        const lbl = this.querySelector('.legend-label');
                        if (lbl) lbl.classList.toggle('line-through', !isVisible);

                        if (centerTotalEl) {
                            const sumVisible = categoryData.reduce((acc, v, i) => (
                                catChart.getDataVisibility(i) ? acc + Number(v || 0) : acc
                            ), 0);
                            centerTotalEl.textContent = String(sumVisible);
                        }
                    });
                });
            }

            // 2. Subject Failure Watchlist Horizontal Bar Chart
            const wctx = document.getElementById('subjectWatchlistChart');
            const failBtn = document.getElementById('wlModeFailBtn');
            const avgBtn = document.getElementById('wlModeAvgBtn');

            if (wctx && Array.isArray(watchlistData) && watchlistData.length > 0) {
                let currentMode = hasAnyFailure ? 'failure' : 'avg';

                function getWatchlistConfig(mode) {
                    const labels = watchlistData.map(item => item.code);
                    if (mode === 'failure') {
                        return {
                            labels,
                            values: watchlistData.map(item => item.failure_rate),
                            colors: watchlistData.map(item => item.failure_rate >= 30 ? '#ef4444' : (item.failure_rate > 0 ? '#f97316' : '#94a3b8')),
                            max: 100,
                            label: 'Failure Rate (%)'
                        };
                    }
                    return {
                        labels,
                        values: watchlistData.map(item => item.avg_grade),
                        colors: watchlistData.map(item => item.avg_grade < 75 ? '#ef4444' : (item.avg_grade < 82 ? '#f59e0b' : '#3b82f6')),
                        max: 100,
                        label: 'Average Grade'
                    };
                }

                const initCfg = getWatchlistConfig(currentMode);
                const wlChart = new Chart(wctx, {
                    type: 'bar',
                    data: {
                        labels: initCfg.labels,
                        datasets: [{
                            label: initCfg.label,
                            data: initCfg.values,
                            backgroundColor: initCfg.colors,
                            borderRadius: 6,
                            barThickness: 24
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0f172a',
                                padding: 10,
                                cornerRadius: 8,
                                callbacks: {
                                    title: function (items) {
                                        const idx = items[0]?.dataIndex ?? 0;
                                        const row = watchlistData[idx];
                                        return row ? (row.code + ' — ' + row.name) : '';
                                    },
                                    label: function (ctx) {
                                        const row = watchlistData[ctx.dataIndex];
                                        if (!row) return '';
                                        return [
                                            ' Failure Rate: ' + row.failure_rate + '% (' + row.failure_count + '/' + row.total_graded + ')',
                                            ' Average Grade: ' + Number(row.avg_grade).toFixed(2)
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                max: 100,
                                grid: { color: '#f1f5f9' },
                                ticks: {
                                    font: { size: 11 },
                                    callback: function (val) {
                                        return currentMode === 'failure' ? val + '%' : val;
                                    }
                                }
                            },
                            y: {
                                grid: { display: false },
                                ticks: {
                                    color: '#334155',
                                    font: { size: 11, weight: '600' }
                                }
                            }
                        }
                    }
                });

                function switchWatchlistMode(mode) {
                    currentMode = mode;
                    const cfg = getWatchlistConfig(mode);
                    wlChart.data.datasets[0].label = cfg.label;
                    wlChart.data.datasets[0].data = cfg.values;
                    wlChart.data.datasets[0].backgroundColor = cfg.colors;
                    wlChart.update();

                    if (failBtn && avgBtn) {
                        const isFail = (mode === 'failure');
                        failBtn.classList.toggle('bg-white', isFail);
                        failBtn.classList.toggle('text-gray-900', isFail);
                        failBtn.classList.toggle('shadow-2xs', isFail);
                        failBtn.classList.toggle('text-gray-500', !isFail);

                        avgBtn.classList.toggle('bg-white', !isFail);
                        avgBtn.classList.toggle('text-gray-900', !isFail);
                        avgBtn.classList.toggle('shadow-2xs', !isFail);
                        avgBtn.classList.toggle('text-gray-500', isFail);
                    }
                }

                failBtn?.addEventListener('click', () => switchWatchlistMode('failure'));
                avgBtn?.addEventListener('click', () => switchWatchlistMode('avg'));
            }

            // 3. Academic Evaluations Overview Radar Chart & Criteria Breakdown
            const evalCanvas = document.getElementById('academicEvalRadarChart');
            const evalFilterEl = document.getElementById('evalYearLevelFilter');
            const evalSummaryBadge = document.getElementById('evalSummaryBadge');
            const evalCountText = document.getElementById('evalCountText');
            const evalOverallText = document.getElementById('evalOverallText');
            const evalEmptyNotice = document.getElementById('evalEmptyNotice');
            const termFormEvalYearLevel = document.getElementById('termFormEvalYearLevel');
            const evalRows = document.querySelectorAll('#evalCriteriaBreakdown .eval-criterion-row');

            function getEvalBarColorClass(score, evalCount) {
                if (evalCount <= 0) return 'bg-gray-300';
                if (score >= 4.0) return 'bg-green-500';
                if (score >= 3.0) return 'bg-yellow-500';
                return 'bg-red-500';
            }

            function buildRadarFill(ctx, chartArea) {
                if (!chartArea) return 'rgba(79, 70, 229, 0.22)';
                const cx = (chartArea.left + chartArea.right) / 2;
                const cy = (chartArea.top + chartArea.bottom) / 2;
                const radius = Math.max(chartArea.right - chartArea.left, chartArea.bottom - chartArea.top) / 2;
                const grad = ctx.createRadialGradient(cx, cy, 8, cx, cy, radius);
                grad.addColorStop(0, 'rgba(59, 130, 246, 0.14)');
                grad.addColorStop(1, 'rgba(79, 70, 229, 0.34)');
                return grad;
            }

            let evalRadarChart = null;
            const initDataset = evalDatasetsByYearLevel[initialEvalYearLevel] || evalDatasetsByYearLevel.all || { evalCount: 0, overallAvg: 0, scores: [] };

            if (evalCanvas && Array.isArray(evalCriteriaLabels) && evalCriteriaLabels.length > 0) {
                const shortLabels = evalCriteriaLabels.map(lbl => {
                    if (lbl === 'Seminary Academic Performance') return 'Seminary Perf.';
                    if (lbl === 'Academic Responsibility') return 'Acad. Responsibility';
                    return lbl;
                });

                evalRadarChart = new Chart(evalCanvas, {
                    type: 'radar',
                    data: {
                        labels: shortLabels,
                        datasets: [{
                            label: evalYearLevelLabels[initialEvalYearLevel] || 'All Students',
                            data: initDataset.scores || [],
                            fill: true,
                            backgroundColor: function (context) {
                                const { ctx, chartArea } = context.chart;
                                return buildRadarFill(ctx, chartArea);
                            },
                            borderColor: '#4f46e5',
                            borderWidth: 2.5,
                            pointBackgroundColor: '#4f46e5',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#3b82f6'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0f172a',
                                padding: 10,
                                cornerRadius: 8,
                                callbacks: {
                                    title: function (items) {
                                        const idx = items[0]?.dataIndex ?? 0;
                                        return evalCriteriaLabels[idx] || '';
                                    },
                                    label: function (ctx) {
                                        const val = Number(ctx.raw || 0).toFixed(2);
                                        return ' Average Score: ' + val + ' / 5.00';
                                    }
                                }
                            }
                        },
                        scales: {
                            r: {
                                min: 0,
                                max: 5,
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    backdropColor: 'transparent',
                                    color: '#94a3b8',
                                    font: { size: 10, weight: '600' },
                                    callback: function (val) { return Number(val).toFixed(0); }
                                },
                                grid: {
                                    color: '#e2e8f0'
                                },
                                angleLines: {
                                    color: '#e2e8f0'
                                },
                                pointLabels: {
                                    color: '#334155',
                                    font: { size: 11, weight: '600' }
                                }
                            }
                        }
                    }
                });
            }

            function renderEvalYearLevel(ylKey) {
                const ds = evalDatasetsByYearLevel[ylKey] || { evalCount: 0, overallAvg: 0, scores: [] };
                const evalCount = Number(ds.evalCount || 0);
                const overallAvg = Number(ds.overallAvg || 0);
                const scores = Array.isArray(ds.scores) ? ds.scores : [];
                const ylLabel = evalYearLevelLabels[ylKey] || 'All Students';

                if (evalRadarChart) {
                    evalRadarChart.data.datasets[0].label = ylLabel;
                    evalRadarChart.data.datasets[0].data = scores;
                    evalRadarChart.update();
                }

                evalRows.forEach(function (row) {
                    const idx = parseInt(row.getAttribute('data-criterion-index') || '0', 10);
                    const score = Number(scores[idx] || 0);
                    const pct = evalCount > 0 ? Math.min(100, Math.max(0, (score / 5.0) * 100)) : 0;
                    const barColor = getEvalBarColorClass(score, evalCount);

                    const scoreEl = row.querySelector('.eval-score-label');
                    if (scoreEl) {
                        scoreEl.innerHTML = score.toFixed(2) + ' <span class="font-medium text-gray-400">/ 5.00</span>';
                        scoreEl.classList.toggle('text-gray-900', evalCount > 0);
                        scoreEl.classList.toggle('text-gray-400', evalCount <= 0);
                    }

                    const barEl = row.querySelector('.eval-progress-bar');
                    if (barEl) {
                        barEl.classList.remove('bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-gray-300');
                        barEl.classList.add(barColor);
                        barEl.style.width = pct.toFixed(1) + '%';
                    }
                });

                if (evalCountText) {
                    evalCountText.textContent = evalCount + (evalCount === 1 ? ' Evaluation' : ' Evaluations');
                }
                if (evalOverallText) {
                    if (evalCount > 0) {
                        evalOverallText.textContent = '· ' + overallAvg.toFixed(2) + ' / 5.00';
                        evalOverallText.classList.remove('hidden');
                    } else {
                        evalOverallText.classList.add('hidden');
                    }
                }
                if (evalSummaryBadge) {
                    evalSummaryBadge.classList.toggle('bg-indigo-50', evalCount > 0);
                    evalSummaryBadge.classList.toggle('text-indigo-700', evalCount > 0);
                    evalSummaryBadge.classList.toggle('bg-gray-100', evalCount <= 0);
                    evalSummaryBadge.classList.toggle('text-gray-500', evalCount <= 0);
                }
                if (evalEmptyNotice) {
                    evalEmptyNotice.classList.toggle('hidden', evalCount > 0);
                    const strongSpan = evalEmptyNotice.querySelector('span');
                    if (strongSpan) strongSpan.textContent = ylLabel;
                    if (evalCount <= 0) {
                        evalEmptyNotice.innerHTML = 'No academic evaluations recorded for <span class="font-semibold text-gray-500"></span> in ' + activeTermLabel + '.';
                        evalEmptyNotice.querySelector('span').textContent = ylLabel;
                    }
                }
                if (termFormEvalYearLevel) {
                    termFormEvalYearLevel.value = ylKey;
                }
                if (window.history && window.history.replaceState) {
                    const url = new URL(window.location.href);
                    if (ylKey === 'all') {
                        url.searchParams.delete('eval_year_level');
                    } else {
                        url.searchParams.set('eval_year_level', ylKey);
                    }
                    window.history.replaceState({}, '', url.toString());
                }
            }

            evalFilterEl?.addEventListener('change', function () {
                renderEvalYearLevel(this.value || 'all');
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
