<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$semester = isset($_GET['semester']) ? afms_normalize_semester_value((string)$_GET['semester']) : '';
if ($academic_year === '' || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
    header('Location: afms_reports.php');
    exit;
}

$year_level_options = eval_year_level_filter_options();
$filter_year_level = eval_normalize_year_level_option($_GET['year_level'] ?? '');
$yl_aliases = eval_year_level_sql_aliases($filter_year_level);

$standing_options = [
    'all' => 'All Standings',
    'passed' => 'Passed (≥ 88)',
    'below_standard' => 'Passed - Below Standard (75–87)',
    'failed' => 'Failed (≤ 74)',
    'pending' => 'Pending Grades',
];
$filter_standing = isset($_GET['standing']) ? strtolower(trim((string)$_GET['standing'])) : 'all';
if (!array_key_exists($filter_standing, $standing_options)) {
    $filter_standing = 'all';
}

$sql = "SELECT s.id AS student_id,
        sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status, sg.remarks,
        COALESCE(NULLIF(TRIM(e.year_level), ''), s.year_level) AS year_level,
        u.username,
        COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) AS full_name,
        COALESCE(NULLIF(TRIM(stu.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no
    FROM seminarians s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN enrollments e ON e.student_id = s.id AND e.academic_year = ? AND e.semester = ?
    LEFT JOIN student_grades sg ON sg.student_id = s.id AND sg.academic_year = ? AND sg.semester = ?
    WHERE u.role = 'Student'
      AND (
          e.id IS NOT NULL
          OR EXISTS (SELECT 1 FROM student_enrolled_subjects ses WHERE ses.student_id = s.id AND ses.academic_year = ? AND ses.semester = ? AND ses.status <> 'Dropped')
          OR sg.grade_id IS NOT NULL
      )";

$types = 'ssssss';
$params = [$academic_year, $semester, $academic_year, $semester, $academic_year, $semester];
if (!empty($yl_aliases)) {
    $yl_ph = implode(',', array_fill(0, count($yl_aliases), '?'));
    $sql .= " AND COALESCE(NULLIF(TRIM(e.year_level), ''), s.year_level) IN ({$yl_ph})";
    $types .= str_repeat('s', count($yl_aliases));
    foreach ($yl_aliases as $alias) {
        $params[] = $alias;
    }
}
$sql .= " ORDER BY COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$student_buckets = [];
while ($res && ($row = $res->fetch_assoc())) {
    $sid = (int)$row['student_id'];
    if (!isset($student_buckets[$sid])) {
        $student_buckets[$sid] = [
            'student_id' => $sid,
            'student_no' => (string)($row['student_no'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'year_level' => (string)($row['year_level'] ?? ''),
            'rows' => [],
        ];
    }
    if ($row['final_grade'] !== null || $row['final_computed_grade'] !== null || $row['status'] !== null) {
        $student_buckets[$sid]['rows'][] = $row;
    }
}

$total_passed = 0;
$total_below_standard = 0;
$total_failed = 0;
$total_pending = 0;
$report_rows = [];

foreach ($student_buckets as $entry) {
    $avg = !empty($entry['rows']) ? afms_compute_simple_final_average($entry['rows']) : null;
    if ($avg === null) {
        $standing_key = 'pending';
        $total_pending++;
        $eval = ['label' => 'Pending Grades', 'badge_class' => 'bg-slate-100 text-slate-600'];
    } else {
        $eval = afms_evaluate_single_final_grade($avg, false);
        $label = $eval['label'];
        if ($label === 'Passed') {
            $standing_key = 'passed';
            $total_passed++;
        } elseif ($label === 'Passed - Below Standard') {
            $standing_key = 'below_standard';
            $total_below_standard++;
        } else {
            $standing_key = 'failed';
            $total_failed++;
        }
    }

    if ($filter_standing !== 'all' && $standing_key !== $filter_standing) {
        continue;
    }

    $report_rows[] = [
        'student_id' => $entry['student_id'],
        'student_no' => $entry['student_no'],
        'full_name' => $entry['full_name'],
        'year_level' => $entry['year_level'] !== '' ? $entry['year_level'] : '—',
        'average' => $avg,
        'standing_key' => $standing_key,
        'remark' => $eval['label'],
        'badge_class' => $eval['badge_class'],
    ];
}

usort($report_rows, function (array $a, array $b) {
    if ($a['average'] === null && $b['average'] !== null) return 1;
    if ($a['average'] !== null && $b['average'] === null) return -1;
    if ($a['average'] !== null && $b['average'] !== null) {
        $cmp = $b['average'] <=> $a['average'];
        if ($cmp !== 0) return $cmp;
    }
    return strcmp($a['full_name'], $b['full_name']);
});
?>
<?php
$pageTitle = 'Standard Grade Standing Report';
$activeNav = 'afms_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="w-full max-w-6xl mx-auto space-y-5 print:max-w-none print:w-full print:m-0 print:p-0">
            <style>
                @media print {
                    header, #sidebar, #sidebarBackdrop, .topbar, .back-link, .no-print { display: none !important; }
                    #appShell, main { margin: 0 !important; padding: 0 !important; width: 100% !important; background: #fff !important; box-shadow: none !important; }
                    body { background: #fff !important; }
                    section, .standing-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
                }
            </style>

            <!-- Action & Quick Filter Bar (Hidden when printing) -->
            <div class="no-print flex flex-wrap items-center justify-between gap-3">
                <a href="afms_reports.php" class="back-link inline-flex items-center gap-2 text-sm font-semibold text-gray-600 hover:text-gray-900 no-underline m-0">
                    <i class="fas fa-arrow-left text-xs"></i>
                    <span>Back to Academic Reports</span>
                </a>

                <div class="flex flex-wrap items-center gap-2.5">
                    <form method="get" class="flex flex-wrap items-center gap-2 m-0 p-0 bg-transparent border-0 shadow-none">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <select name="year_level" onchange="this.form.submit()" aria-label="Year Level Filter"
                                class="h-9 px-3 text-xs font-semibold text-gray-700 bg-white border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900 cursor-pointer">
                            <option value="">All Year Levels</option>
                            <?php foreach ($year_level_options as $yl_opt): ?>
                                <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yl_opt === $filter_year_level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="standing" onchange="this.form.submit()" aria-label="Grade Standing Filter"
                                class="h-9 px-3 text-xs font-semibold text-gray-700 bg-white border !border-gray-200 !rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900 cursor-pointer">
                            <?php foreach ($standing_options as $st_val => $st_lbl): ?>
                                <option value="<?php echo htmlspecialchars($st_val); ?>" <?php echo $st_val === $filter_standing ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($st_lbl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <button type="button" onclick="window.print()" style="width: auto;"
                            class="h-9 px-4 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-xs font-semibold inline-flex items-center gap-2 border-0 cursor-pointer transition-colors">
                        <i class="fas fa-print"></i>
                        <span>Print</span>
                    </button>
                </div>
            </div>

            <!-- Report Header Card -->
            <section class="standing-card bg-white rounded-xl border border-gray-100 shadow-sm p-6 print:break-inside-avoid">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 m-0 flex items-center gap-2">
                            <i class="fas fa-clipboard-check text-teal-600"></i>
                            <span>Standard Grade Standing Report</span>
                        </h1>
                        <p class="text-xs sm:text-sm text-gray-500 mt-1 mb-0 font-medium">
                            AY <?php echo htmlspecialchars($academic_year); ?> &middot; <?php echo htmlspecialchars($semester); ?> Semester &middot;
                            <?php echo htmlspecialchars($filter_year_level !== '' ? $filter_year_level : 'All Year Levels'); ?> &middot;
                            <?php echo htmlspecialchars($standing_options[$filter_standing]); ?>
                        </p>
                    </div>
                    <div class="text-xs text-gray-500 font-semibold">
                        Standard: &ge; 88 Passed &middot; 75&ndash;87 Below Standard &middot; &le; 74 Failed
                    </div>
                </div>
            </section>

            <!-- Summary Counts (Passed / Below Standard / Failed) -->
            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 print:break-inside-avoid" aria-label="Standing Summary Counts">
                <div class="standing-card bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center justify-between gap-4">
                    <div>
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Total Passed (&ge; 88)</span>
                        <div class="text-2xl font-bold text-green-700 mt-1 tabular-nums"><?php echo (int)$total_passed; ?></div>
                    </div>
                    <span class="w-10 h-10 rounded-lg bg-green-100 text-green-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-circle-check"></i>
                    </span>
                </div>

                <div class="standing-card bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center justify-between gap-4">
                    <div>
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Total Below Standard (75&ndash;87)</span>
                        <div class="text-2xl font-bold text-orange-700 mt-1 tabular-nums"><?php echo (int)$total_below_standard; ?></div>
                    </div>
                    <span class="w-10 h-10 rounded-lg bg-orange-100 text-orange-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-circle-exclamation"></i>
                    </span>
                </div>

                <div class="standing-card bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center justify-between gap-4">
                    <div>
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 block">Total Failed (&le; 74)</span>
                        <div class="text-2xl font-bold text-red-700 mt-1 tabular-nums"><?php echo (int)$total_failed; ?></div>
                    </div>
                    <span class="w-10 h-10 rounded-lg bg-red-100 text-red-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-circle-xmark"></i>
                    </span>
                </div>
            </section>

            <!-- Detailed Standing Table -->
            <section class="standing-card bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-800 m-0">Seminarian Grade Standings</h2>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                        <?php echo count($report_rows); ?> <?php echo count($report_rows) === 1 ? 'Seminarian' : 'Seminarians'; ?>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-full border-collapse !mt-0 text-left">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th class="py-3.5 px-5 whitespace-nowrap">Student ID</th>
                                <th class="py-3.5 px-5 whitespace-nowrap">Full Name</th>
                                <th class="py-3.5 px-5 whitespace-nowrap">Year Level</th>
                                <th class="py-3.5 px-5 text-center whitespace-nowrap">Final Grade Average</th>
                                <th class="py-3.5 px-5 text-right whitespace-nowrap">Standing Remark</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php if (empty($report_rows)): ?>
                                <tr>
                                    <td colspan="5" class="py-10 px-5 text-center text-sm text-gray-500">
                                        No seminarians match the selected term, year level, and grade standing filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_rows as $r): ?>
                                    <tr class="hover:bg-gray-50/70 transition-colors print:break-inside-avoid">
                                        <td class="py-3.5 px-5 whitespace-nowrap font-mono text-xs font-semibold text-gray-700">
                                            <?php echo htmlspecialchars($r['student_no']); ?>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($r['full_name']); ?>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap text-gray-600">
                                            <?php echo htmlspecialchars($r['year_level']); ?>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap text-center font-bold text-gray-900 tabular-nums">
                                            <?php echo $r['average'] !== null ? htmlspecialchars(number_format((float)$r['average'], 2)) : '<span class="text-gray-400 font-medium">—</span>'; ?>
                                        </td>
                                        <td class="py-3.5 px-5 whitespace-nowrap text-right">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo htmlspecialchars($r['badge_class']); ?>">
                                                <?php echo htmlspecialchars($r['remark']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
