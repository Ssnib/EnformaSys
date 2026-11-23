<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
$formula = isset($_GET['formula']) ? trim((string)$_GET['formula']) : 'stored';
if (!in_array($formula, ['stored', 'weighted', 'simple'], true)) {
    $formula = 'stored';
}
$formula_labels = [
    'stored' => 'Stored Final Grades',
    'weighted' => 'Weighted (30% Prelim / 30% Midterm / 40% Final)',
    'simple' => 'Simple Average ((Prelim + Midterm + Final) / 3)',
];

if ($student_id <= 0 || $academic_year === '' || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
    header('Location: afms_reports.php');
    exit;
}

$stmt_profile = $conn->prepare("SELECT s.id AS student_id, s.year_level, s.formation_stage, s.academic_status, s.enrollment_status, s.academic_remarks,
        u.username, COALESCE(NULLIF(TRIM(up.full_name), ''), u.username) AS display_name,
        COALESCE(NULLIF(TRIM(up.student_id), ''), '') AS student_no
    FROM seminarians s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE s.id = ? AND u.role = 'Student'
    LIMIT 1");
$stmt_profile->bind_param('i', $student_id);
$stmt_profile->execute();
$profile = $stmt_profile->get_result()->fetch_assoc();
if (!$profile) {
    header('Location: afms_reports.php');
    exit;
}
$display_name = trim((string)($profile['display_name'] ?? ''));

$stmt_grades = $conn->prepare("SELECT sub.subject_code, sub.subject_name, sub.units,
        sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status, sg.remarks
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    WHERE sg.student_id = ? AND sg.academic_year = ? AND sg.semester = ?
    ORDER BY sub.subject_code ASC");
$stmt_grades->bind_param('iss', $student_id, $academic_year, $semester);
$stmt_grades->execute();
$grade_rows = [];
$grades_res = $stmt_grades->get_result();
while ($grades_res && ($r = $grades_res->fetch_assoc())) {
    $r['resolved_final_grade'] = afms_resolve_subject_final_grade($r, $formula);
    $grade_rows[] = $r;
}

$subject_labels = [];
$subject_values = [];
$status_counts = [];
foreach ($grade_rows as $r) {
    $subject_labels[] = (string)($r['subject_code'] ?? '');
    $subject_values[] = $r['resolved_final_grade'];
    $st = trim((string)($r['status'] ?? ''));
    if ($st === '') $st = 'Unknown';
    if (!isset($status_counts[$st])) $status_counts[$st] = 0;
    $status_counts[$st] += 1;
}

$term_general_avg = afms_compute_general_average_from_rows($grade_rows, $formula);

// Fetch all student_grades across terms for Cumulative General Average & General Average Trend
$stmt_all_grades = $conn->prepare("SELECT sg.academic_year, sg.semester, sub.units,
        sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    WHERE sg.student_id = ?
    ORDER BY sg.academic_year ASC, FIELD(sg.semester, '1st', '2nd', 'Summer') ASC");
$stmt_all_grades->bind_param('i', $student_id);
$stmt_all_grades->execute();
$all_grade_rows = [];
$terms_bucket = [];
$res_all = $stmt_all_grades->get_result();
while ($res_all && ($ar = $res_all->fetch_assoc())) {
    $all_grade_rows[] = $ar;
    $tkey = trim((string)($ar['academic_year'] ?? '')) . '|' . trim((string)($ar['semester'] ?? ''));
    if (!isset($terms_bucket[$tkey])) {
        $terms_bucket[$tkey] = [
            'label' => trim((string)($ar['academic_year'] ?? '')) . ' ' . trim((string)($ar['semester'] ?? '')),
            'ay' => trim((string)($ar['academic_year'] ?? '')),
            'sem' => trim((string)($ar['semester'] ?? '')),
            'rows' => [],
        ];
    }
    $terms_bucket[$tkey]['rows'][] = $ar;
}

$cumulative_general_avg = afms_compute_general_average_from_rows($all_grade_rows, $formula);
$performance_standing = $term_general_avg !== null ? (string)(afms_performance_category($term_general_avg) ?? '-') : '-';

$trend_labels = [];
$trend_values = [];
$prev_term_avg = null;
$cur_key = $academic_year . '|' . $semester;
$last_seen_avg = null;
foreach ($terms_bucket as $tkey => $tb) {
    $t_avg = afms_compute_general_average_from_rows($tb['rows'], $formula);
    if ($t_avg !== null) {
        $trend_labels[] = $tb['label'];
        $trend_values[] = $t_avg;
        if ($tkey === $cur_key) {
            $prev_term_avg = $last_seen_avg;
        }
        $last_seen_avg = $t_avg;
    }
}

$growth_status = '-';
$growth_percentage = null;
if ($term_general_avg !== null && $prev_term_avg !== null && $prev_term_avg > 0) {
    $diff = $term_general_avg - $prev_term_avg;
    $growth_percentage = round(($diff / $prev_term_avg) * 100, 2);
    if ($diff >= 1.0) {
        $growth_status = 'Growing';
    } elseif ($diff <= -1.0) {
        $growth_status = 'Declining';
    } else {
        $growth_status = 'Maintaining';
    }
} else {
    $stmt_prog = $conn->prepare("SELECT growth_status, growth_percentage FROM academic_progress WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
    $stmt_prog->bind_param('iss', $student_id, $academic_year, $semester);
    $stmt_prog->execute();
    $prog = $stmt_prog->get_result()->fetch_assoc() ?: null;
    if ($prog && !empty($prog['growth_status'])) {
        $growth_status = (string)$prog['growth_status'];
        $growth_percentage = isset($prog['growth_percentage']) && $prog['growth_percentage'] !== null ? (float)$prog['growth_percentage'] : null;
    }
}
?>
<?php
$pageTitle = 'Individual Grade Report';
$activeNav = 'afms_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body max-w-6xl mx-auto print:w-full print:max-w-none print:absolute print:top-0 print:left-0 print:m-0 print:p-0 print:bg-white print:shadow-none">
            <style>
                @media print {
                    header, #sidebar, #sidebarBackdrop, .topbar, .back-link, .no-print { display: none !important; }
                    #appShell, main { margin: 0 !important; padding: 0 !important; width: 100% !important; background: #fff !important; box-shadow: none !important; }
                    body { background: #fff !important; }
                    .content { margin: 0 !important; padding: 0 !important; }
                    .content-body { margin: 0 !important; padding: 0 !important; max-width: none !important; width: 100% !important; background: #fff !important; box-shadow: none !important; }
                    .card, .data-card, section { box-shadow: none !important; background: #fff !important; border: 1px solid #e2e8f0 !important; }
                }
            </style>

            <div class="no-print print:hidden" style="display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem;">
                <a href="afms_reports.php" class="back-link print:hidden" style="margin: 0;"><i class="fas fa-arrow-left"></i> Back</a>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <form method="get" style="display: inline-flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0; background: transparent; border: none; box-shadow: none;">
                        <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <label for="indFormulaSelect" style="font-size: 0.75rem; font-weight: 700; color: #64748b; margin: 0;">Formula:</label>
                        <select id="indFormulaSelect" name="formula" onchange="this.form.submit()" style="width: auto; margin: 0; padding: 0.4rem 0.65rem; font-size: 0.8rem; border-radius: 8px; border: 1px solid #cbd5e1;">
                            <option value="stored" <?php echo $formula === 'stored' ? 'selected' : ''; ?>>Stored Final Grades</option>
                            <option value="weighted" <?php echo $formula === 'weighted' ? 'selected' : ''; ?>>Weighted (30-30-40)</option>
                            <option value="simple" <?php echo $formula === 'simple' ? 'selected' : ''; ?>>Simple Average</option>
                        </select>
                    </form>
                    <button type="button" onclick="window.print()" class="print:hidden" style="width: auto; padding: 0.55rem 0.9rem;"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>

            <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 0;">
                <h1 style="margin: 0; font-size: 1.35rem; font-weight: 900; color: #0f172a;">Individual Grade Report</h1>
                <div style="margin-top: 0.35rem; color: #475569; font-weight: 800;">
                    <?php echo htmlspecialchars($academic_year); ?> &middot; <?php echo htmlspecialchars($semester); ?> Semester &middot; <?php echo htmlspecialchars($formula_labels[$formula]); ?>
                </div>
            </div>

            <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <h3 style="margin-bottom: 0.75rem;"><i class="fas fa-user"></i> Student Information</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1.5rem;">
                    <div><span style="color:#64748b; font-weight: 900;">Student ID:</span> <?php echo $profile['student_no'] !== '' ? htmlspecialchars((string)$profile['student_no']) : (int)$student_id; ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Name:</span> <?php echo htmlspecialchars($display_name); ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Year Level:</span> <?php echo htmlspecialchars((string)($profile['year_level'] ?? '')); ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Formation Stage:</span> <?php echo htmlspecialchars((string)($profile['formation_stage'] ?? '')); ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Academic Status:</span> <?php echo htmlspecialchars((string)($profile['academic_status'] ?? '')); ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Enrollment Status:</span> <?php echo htmlspecialchars((string)($profile['enrollment_status'] ?? '')); ?></div>
                </div>
            </div>

            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-table"></i> Semester Grades</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Prelim</th>
                                <th>Midterm</th>
                                <th>Final</th>
                                <th>Final Grade</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($grade_rows) === 0) { ?>
                                <tr><td colspan="8" style="color: var(--text-secondary);">No grade records.</td></tr>
                            <?php } ?>
                            <?php foreach ($grade_rows as $row) { ?>
                                <tr class="print:break-inside-avoid">
                                    <td>
                                        <div style="font-weight: 900;"><?php echo htmlspecialchars((string)$row['subject_code']); ?></div>
                                        <div style="color: var(--text-secondary); font-size: 0.85rem;"><?php echo htmlspecialchars((string)$row['subject_name']); ?></div>
                                    </td>
                                    <td><span class="badge badge-primary"><?php echo (float)$row['units'] == (int)$row['units'] ? (int)$row['units'] : number_format((float)$row['units'], 1); ?></span></td>
                                    <td><?php echo $row['prelim_grade'] === null ? '-' : htmlspecialchars(number_format((float)$row['prelim_grade'], 2)); ?></td>
                                    <td><?php echo $row['midterm_grade'] === null ? '-' : htmlspecialchars(number_format((float)$row['midterm_grade'], 2)); ?></td>
                                    <td><?php echo $row['final_grade'] === null ? '-' : htmlspecialchars(number_format((float)$row['final_grade'], 2)); ?></td>
                                    <td style="font-weight: 800; color: #0f172a;">
                                        <?php echo $row['resolved_final_grade'] !== null ? htmlspecialchars(number_format((float)$row['resolved_final_grade'], 2)) : '-'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$row['status']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['remarks'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <h3 style="margin-bottom: 0.75rem;"><i class="fas fa-graduation-cap"></i> Final Grade Summary</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1.5rem;">
                    <div><span style="color:#64748b; font-weight: 900;">Term General Average:</span> <?php echo $term_general_avg !== null ? htmlspecialchars(number_format((float)$term_general_avg, 2)) : '-'; ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Cumulative General Average:</span> <?php echo $cumulative_general_avg !== null ? htmlspecialchars(number_format((float)$cumulative_general_avg, 2)) : '-'; ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Performance Standing:</span> <?php echo htmlspecialchars($performance_standing); ?></div>
                    <div><span style="color:#64748b; font-weight: 900;">Growth Indicator:</span> <?php echo htmlspecialchars($growth_status); ?><?php echo $growth_percentage !== null ? " (" . ($growth_percentage > 0 ? '+' : '') . htmlspecialchars(number_format((float)$growth_percentage, 2)) . "%)" : ''; ?></div>
                </div>
            </div>

            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-chart-simple"></i> Individual Analytics</h3>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; padding: 1rem;">
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="font-weight: 900; color: #0f172a; margin-bottom: 0.75rem;">Final Grades by Subject (This Term)</div>
                        <div style="height: 300px;">
                            <canvas id="afmsSubjectsChart"></canvas>
                        </div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="font-weight: 900; color: #0f172a; margin-bottom: 0.75rem;">General Average Trend</div>
                        <div style="height: 300px;">
                            <canvas id="afmsTrendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0 1rem 1rem 1rem;">
                    <div style="font-weight: 900; color: #0f172a; margin-bottom: 0.75rem;">Status Distribution (This Term)</div>
                    <div style="height: 300px;">
                        <canvas id="afmsStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <h3 style="margin-bottom: 0.75rem;"><i class="fas fa-message"></i> Formator Remarks</h3>
                <div style="white-space: pre-wrap; color: #334155; font-weight: 700;"><?php echo htmlspecialchars((string)($profile['academic_remarks'] ?? '')); ?></div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            const subjectLabels = <?php echo json_encode($subject_labels); ?>;
            const subjectValues = <?php echo json_encode(array_map(function ($v) { return $v === null ? null : round((float)$v, 2); }, $subject_values)); ?>;
            const trendLabels = <?php echo json_encode($trend_labels); ?>;
            const trendValues = <?php echo json_encode(array_map(function ($v) { return $v === null ? null : round((float)$v, 2); }, $trend_values)); ?>;
            const statusLabels = <?php echo json_encode(array_keys($status_counts)); ?>;
            const statusValues = <?php echo json_encode(array_values($status_counts)); ?>;

            const subjEl = document.getElementById('afmsSubjectsChart');
            if (subjEl) {
                new Chart(subjEl, {
                    type: 'bar',
                    data: {
                        labels: subjectLabels,
                        datasets: [{
                            label: 'Final Grade',
                            data: subjectValues,
                            backgroundColor: '#0f766e',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { suggestedMin: 65, suggestedMax: 100, ticks: { stepSize: 5 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }

            const trendEl = document.getElementById('afmsTrendChart');
            if (trendEl) {
                new Chart(trendEl, {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [{
                            label: 'General Average',
                            data: trendValues,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.15)',
                            fill: true,
                            tension: 0.25,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { suggestedMin: 65, suggestedMax: 100, ticks: { stepSize: 5 } }
                        },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            const statusEl = document.getElementById('afmsStatusChart');
            if (statusEl) {
                new Chart(statusEl, {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusValues,
                            backgroundColor: ['#10b981','#3b82f6','#f59e0b','#f97316','#ef4444','#64748b'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
