<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';

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

if ($academic_year === '' || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
    header('Location: afms_reports.php');
    exit;
}

// Fetch all student_grades for active students cleanly to compute Term & Cumulative General Averages
$stmt_term = $conn->prepare("SELECT sg.student_id, sub.units, sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status, sg.remarks,
        u.username, COALESCE(NULLIF(TRIM(up.full_name), ''), u.username) AS display_name, s.academic_status
    FROM student_grades sg
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    JOIN seminarians s ON s.id = sg.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE sg.academic_year = ? AND sg.semester = ? AND u.role = 'Student'");
$stmt_term->bind_param('ss', $academic_year, $semester);
$stmt_term->execute();
$res_term = $stmt_term->get_result();

$students_term_rows = [];
while ($res_term && ($row = $res_term->fetch_assoc())) {
    $sid = (int)$row['student_id'];
    if (!isset($students_term_rows[$sid])) {
        $students_term_rows[$sid] = [
            'student_id' => $sid,
            'display_name' => (string)$row['display_name'],
            'academic_status' => (string)($row['academic_status'] ?? 'Regular'),
            'rows' => [],
            'has_failing_subject' => false,
            'has_incomplete' => false,
        ];
    }
    $students_term_rows[$sid]['rows'][] = $row;

    $resolved = afms_resolve_subject_final_grade($row, $formula);
    $st_label = strtolower(trim((string)($row['status'] ?? '')));
    if ($st_label === 'incomplete') {
        $students_term_rows[$sid]['has_incomplete'] = true;
    }
    if ($resolved !== null && $resolved < 75.00 && $st_label !== 'dropped') {
        $students_term_rows[$sid]['has_failing_subject'] = true;
    }
}

// Fetch all student_grades across all terms for Cumulative General Average
$all_rows_by_student = [];
if (!empty($students_term_rows)) {
    $sids = array_keys($students_term_rows);
    $ph = implode(',', array_fill(0, count($sids), '?'));
    $stmt_all = $conn->prepare("SELECT sg.student_id, sub.units, sg.prelim_grade, sg.midterm_grade, sg.final_grade, sg.final_computed_grade, sg.status
        FROM student_grades sg
        JOIN subjects sub ON sub.subject_id = sg.subject_id
        WHERE sg.student_id IN ({$ph})");
    $stmt_all->bind_param(str_repeat('i', count($sids)), ...$sids);
    $stmt_all->execute();
    $res_all = $stmt_all->get_result();
    while ($res_all && ($ar = $res_all->fetch_assoc())) {
        $asid = (int)$ar['student_id'];
        $all_rows_by_student[$asid][] = $ar;
    }
}

$ranking = [];
foreach ($students_term_rows as $sid => $entry) {
    $term_avg = afms_compute_general_average_from_rows($entry['rows'], $formula);
    if ($term_avg === null) {
        continue;
    }
    $cum_avg = afms_compute_general_average_from_rows($all_rows_by_student[$sid] ?? [], $formula);
    $category = afms_performance_category($term_avg) ?? 'Evaluated';

    $ranking[] = [
        'student_id' => $sid,
        'display_name' => $entry['display_name'],
        'academic_status' => $entry['academic_status'],
        'term_avg' => $term_avg,
        'cumulative_avg' => $cum_avg,
        'performance_category' => $category,
        'has_failing_subject' => $entry['has_failing_subject'],
        'has_incomplete' => $entry['has_incomplete'],
    ];
}

// Highest General Average ranks #1
usort($ranking, function (array $a, array $b) {
    $cmp = $b['term_avg'] <=> $a['term_avg'];
    if ($cmp !== 0) return $cmp;
    return strcmp($a['display_name'], $b['display_name']);
});

$top = array_slice($ranking, 0, 10);
$needs = [];
foreach ($ranking as $r) {
    $ir = (string)($r['academic_status'] ?? '') === 'Irregular';
    $low_avg = (float)$r['term_avg'] < 75.00;
    if ($low_avg || $ir || !empty($r['has_failing_subject']) || !empty($r['has_incomplete'])) {
        $needs[] = $r;
    }
    if (count($needs) >= 10) break;
}
?>
<?php
$pageTitle = 'Academic Performance Report';
$activeNav = 'afms_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <style>
                @media print {
                    #sidebar, #sidebarBackdrop, .topbar, .back-link, .no-print { display: none !important; }
                    body { background: #fff !important; }
                    .content { margin: 0 !important; padding: 0 !important; }
                    .content-body { padding: 0 !important; }
                    .card, .data-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
                }
            </style>

            <div class="no-print" style="display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem;">
                <a href="afms_reports.php" class="back-link" style="margin: 0;"><i class="fas fa-arrow-left"></i> Back</a>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <form method="get" style="display: inline-flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0; background: transparent; border: none; box-shadow: none;">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <label for="perfFormulaSelect" style="font-size: 0.75rem; font-weight: 700; color: #64748b; margin: 0;">Formula:</label>
                        <select id="perfFormulaSelect" name="formula" onchange="this.form.submit()" style="width: auto; margin: 0; padding: 0.4rem 0.65rem; font-size: 0.8rem; border-radius: 8px; border: 1px solid #cbd5e1;">
                            <option value="stored" <?php echo $formula === 'stored' ? 'selected' : ''; ?>>Stored Final Grades</option>
                            <option value="weighted" <?php echo $formula === 'weighted' ? 'selected' : ''; ?>>Weighted (30-30-40)</option>
                            <option value="simple" <?php echo $formula === 'simple' ? 'selected' : ''; ?>>Simple Average</option>
                        </select>
                    </form>
                    <button type="button" onclick="window.print()" style="width: auto; padding: 0.55rem 0.9rem;"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>

            <div class="card" style="margin-top: 0;">
                <h1 style="margin: 0; font-size: 1.35rem; font-weight: 900; color: #0f172a;">Academic Performance Report</h1>
                <div style="margin-top: 0.35rem; color: #475569; font-weight: 800;">
                    <?php echo htmlspecialchars($academic_year); ?> &middot; <?php echo htmlspecialchars($semester); ?> Semester &middot; <?php echo htmlspecialchars($formula_labels[$formula]); ?>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem; align-items: start;">
                <div class="data-card" style="margin: 0;">
                    <div class="data-card-header">
                        <h3><i class="fas fa-trophy"></i> Top Performing Seminarians</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Student</th>
                                    <th>General Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($top) === 0) { ?>
                                    <tr><td colspan="3" style="color: var(--text-secondary);">No final grade data.</td></tr>
                                <?php } ?>
                                <?php foreach ($top as $i => $r) { ?>
                                    <tr>
                                        <td><span class="badge badge-primary"><?php echo (int)($i + 1); ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($r['display_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars(number_format((float)$r['term_avg'], 2)); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="data-card" style="margin: 0;">
                    <div class="data-card-header">
                        <h3><i class="fas fa-hand-holding-medical"></i> Needing Intervention</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>General Average</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($needs) === 0) { ?>
                                    <tr><td colspan="3" style="color: var(--text-secondary);">No flagged students.</td></tr>
                                <?php } ?>
                                <?php foreach ($needs as $r) {
                                    $ir = (string)($r['academic_status'] ?? '') === 'Irregular';
                                    $fail = !empty($r['has_failing_subject']) || (float)$r['term_avg'] < 75.00;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($r['display_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars(number_format((float)$r['term_avg'], 2)); ?></td>
                                        <td>
                                            <?php
                                            if ($fail) {
                                                echo "<span class='badge badge-danger'>At Risk / Failing</span>";
                                            } elseif ($ir) {
                                                echo "<span class='badge badge-danger'>Irregular</span>";
                                            } else {
                                                echo "<span class='badge badge-warning'>Incomplete Grades</span>";
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="data-card" style="margin-top: 1rem;">
                <div class="data-card-header">
                    <h3><i class="fas fa-list-ol"></i> Final Grade Rankings</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Term General Average</th>
                                <th>Cumulative Average</th>
                                <th>Category</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ranking) === 0) { ?>
                                <tr><td colspan="5" style="color: var(--text-secondary);">No ranking data.</td></tr>
                            <?php } ?>
                            <?php foreach ($ranking as $i => $r) { ?>
                                <tr>
                                    <td><?php echo (int)($i + 1); ?></td>
                                    <td><strong><?php echo htmlspecialchars($r['display_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars(number_format((float)$r['term_avg'], 2)); ?></td>
                                    <td><?php echo $r['cumulative_avg'] === null ? '-' : htmlspecialchars(number_format((float)$r['cumulative_avg'], 2)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['performance_category'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
