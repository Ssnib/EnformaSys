<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean', 'Student']);
include '../includes/db.php';

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
if ($evaluation_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$stmt = $conn->prepare("SELECT e.*, r.total_score, r.average_score, r.rating_label, r.recommendation,
        u.username as student_username, up.full_name, s.academic_view_enabled,
        fu.username as formator_username
    FROM academic_evaluations e
    LEFT JOIN academic_evaluation_results r ON r.evaluation_id = e.id
    JOIN seminarians s ON s.id = e.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    JOIN users fu ON fu.id = e.formator_id
    WHERE e.id = ? LIMIT 1");
$stmt->bind_param('i', $evaluation_id);
$stmt->execute();
$eval = $stmt->get_result()->fetch_assoc();
if (!$eval) {
    header('Location: ../index.php');
    exit;
}

if (($_SESSION['role'] ?? '') === 'AcademicFormator') {
    $uid = (int)($_SESSION['user'] ?? 0);
    if ((int)($eval['formator_id'] ?? 0) !== $uid) {
        header('Location: ../index.php');
        exit;
    }
}

if (($_SESSION['role'] ?? '') === 'Student') {
    $uid = (int)($_SESSION['user'] ?? 0);
    require_once __DIR__ . '/../includes/access_requests.php';
    $stmt_sem = $conn->prepare("SELECT id FROM seminarians WHERE user_id = ? LIMIT 1");
    $stmt_sem->bind_param('i', $uid);
    $stmt_sem->execute();
    $sem = $stmt_sem->get_result()->fetch_assoc();
    $perm = ($sem && (int)$sem['id'] === (int)$eval['student_id'])
        ? capstone_access_get_grade_view_permission($conn, (int)$sem['id'], (string)($eval['academic_year'] ?? ''), (string)($eval['semester'] ?? ''))
        : ['can_view_any' => false];
    if (!$sem || (int)$sem['id'] !== (int)$eval['student_id'] || empty($perm['can_view_any'])) {
        header('Location: ../index.php');
        exit;
    }
}

$display_name = trim((string)($eval['full_name'] ?? ''));
if ($display_name === '') $display_name = (string)($eval['student_username'] ?? '');

$questions = [];
$rating_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$stmt_q = $conn->prepare("SELECT q.id, q.category, q.question_text, q.sort_order, r.rating
    FROM academic_evaluation_questions q
    JOIN academic_evaluation_responses r ON r.question_id = q.id
    WHERE r.evaluation_id = ?
    ORDER BY q.sort_order ASC, q.id ASC");
$stmt_q->bind_param('i', $evaluation_id);
$stmt_q->execute();
$res_q = $stmt_q->get_result();
while ($row = $res_q->fetch_assoc()) {
    $questions[] = $row;
    $v = (int)($row['rating'] ?? 0);
    if ($v >= 1 && $v <= 5) $rating_counts[$v] += 1;
}

function afe_compute_category_avgs(array $rows): array {
    $cats = [];
    $order = [];
    foreach ($rows as $r) {
        $cat = (string)($r['category'] ?? '');
        $rating = (int)($r['rating'] ?? 0);
        if ($cat === '' || $rating < 1 || $rating > 5) continue;
        if (!isset($cats[$cat])) {
            $cats[$cat] = ['sum' => 0, 'count' => 0];
            $order[] = $cat;
        }
        $cats[$cat]['sum'] += $rating;
        $cats[$cat]['count'] += 1;
    }
    $labels = [];
    $values = [];
    foreach ($order as $cat) {
        $labels[] = $cat;
        $c = (int)($cats[$cat]['count'] ?? 0);
        $values[] = $c > 0 ? ((float)$cats[$cat]['sum'] / $c) : null;
    }
    return ['labels' => $labels, 'values' => $values];
}

function afe_get_semester_order_val(string $semester): int {
    $s = strtolower(trim($semester));
    if ($s === '1st') return 1;
    if ($s === '2nd') return 2;
    if ($s === 'summer') return 3;
    return 0;
}

$cat_data = afe_compute_category_avgs($questions);
$cat_labels = $cat_data['labels'];
$cat_values = $cat_data['values'];

$trend_labels = [];
$trend_values = [];
$student_id = (int)($eval['student_id'] ?? 0);
if ($student_id > 0) {
    $stmt_hist = $conn->prepare("SELECT e.evaluation_date, e.academic_year, e.semester, r.average_score
        FROM academic_evaluations e
        JOIN academic_evaluation_results r ON r.evaluation_id = e.id
        WHERE e.archived_at IS NULL AND e.student_id = ?
        ORDER BY e.evaluation_date ASC, e.id ASC
        LIMIT 24");
    $stmt_hist->bind_param('i', $student_id);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();
    while ($hr = $res_hist->fetch_assoc()) {
        $label = trim((string)($hr['academic_year'] ?? '')) . ' ' . trim((string)($hr['semester'] ?? ''));
        if (trim($label) === '') {
            $label = (string)($hr['evaluation_date'] ?? '');
        }
        $trend_labels[] = $label;
        $trend_values[] = $hr['average_score'] !== null ? (float)$hr['average_score'] : null;
    }
}

function afe_likert_label($v) {
    $n = (int)$v;
    if ($n === 5) return 'Outstanding';
    if ($n === 4) return 'Very Good';
    if ($n === 3) return 'Satisfactory';
    if ($n === 2) return 'Needs Improvement';
    if ($n === 1) return 'Poor';
    return '';
}
?>
<?php
require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$report_profile = eval_fetch_report_profile($conn, (int)($eval['student_id'] ?? 0));
$afe_categories = [];
foreach ($cat_labels as $idx => $lbl) {
    $afe_categories[] = [
        'label' => (string)$lbl,
        'score' => $cat_values[$idx] ?? null,
    ];
}
$pageTitle = 'Academic Evaluation Report';
$activeNav = 'afe_evaluations';
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

            <div class="no-print print:hidden flex flex-wrap items-center justify-between gap-3 mb-5">
                <?php if (($_SESSION['role'] ?? '') === 'AcademicFormator') { ?>
                    <a href="afe_evaluations.php" class="back-link print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-extrabold shadow-sm transition-colors" style="margin: 0;">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                <?php } else { ?>
                    <a href="student_academic_evaluation.php" class="back-link print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-extrabold shadow-sm transition-colors" style="margin: 0;">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                <?php } ?>
                <button type="button" onclick="window.print()" class="print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-[#59443F] hover:bg-[#463430] text-white text-xs font-extrabold shadow-sm transition-colors cursor-pointer" style="width: auto;">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>

            <?php
            eval_render_report_header_banner(
                'Academic Formation Evaluation Report',
                (string)$eval['academic_year'],
                (string)$eval['semester'],
                'Academic Formation Pillar',
                'fa-solid fa-graduation-cap'
            );
            ?>

            <div class="print:break-inside-avoid print:bg-white print:shadow-none">
                <?php
                eval_render_report_profile_card([
                    'name' => $display_name,
                    'student_no' => $report_profile['student_no'],
                    'username' => $report_profile['username'] ?: (string)($eval['student_username'] ?? ''),
                    'year_level' => $report_profile['year_level'],
                    'program' => $report_profile['program'],
                    'evaluation_date' => (string)$eval['evaluation_date'],
                    'evaluator_label' => 'Assigned Evaluator',
                    'evaluator_name' => (string)$eval['formator_username'],
                    'extra_items' => [
                        [
                            'label' => 'Academic GPA',
                            'value' => $eval['gpa'] === null ? '-' : number_format((float)$eval['gpa'], 2),
                            'icon' => 'fa-solid fa-award',
                        ],
                    ],
                ]);
                ?>
            </div>

            <div class="print:break-inside-avoid print:bg-white print:shadow-none">
                <?php
                $afe_interp = getFormationInterpretation($eval['average_score'] ?? null);
                eval_render_report_results_section([
                    'average_rating' => $eval['average_score'] ?? null,
                    'total_score' => $eval['total_score'] ?? null,
                    'total_score_label' => 'Total Score',
                    'question_count' => count($questions),
                    'interpretation_label' => 'Interpretation',
                    'interpretation_value' => (string)($afe_interp['label'] ?? ($eval['rating_label'] ?? '')),
                    'secondary_label' => 'Recommendation',
                    'secondary_value' => (string)($eval['recommendation'] ?? ''),
                ], $afe_categories);
                ?>
            </div>

            <?php
            eval_render_individual_analytics_section('academic', [
                'cat_labels' => $cat_labels,
                'cat_values' => $cat_values,
                'trend_labels' => $trend_labels,
                'trend_values' => $trend_values,
                'dist_labels' => ['1 Poor', '2 Needs Improvement', '3 Satisfactory', '4 Very Good', '5 Outstanding'],
                'dist_values' => [(int)$rating_counts[1], (int)$rating_counts[2], (int)$rating_counts[3], (int)$rating_counts[4], (int)$rating_counts[5]],
                'average_rating' => $eval['average_score'] ?? null,
                'total_score' => $eval['total_score'] ?? null,
            ]);
            ?>

            <?php if (($_SESSION['role'] ?? '') !== 'Student') { ?>
            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-list"></i> Questionnaire Responses</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 35%;">Category</th>
                                <th>Question</th>
                                <th style="width: 160px;">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($questions) === 0) { ?>
                                <tr><td colspan="3" style="color: var(--text-secondary);">No responses found.</td></tr>
                            <?php } ?>
                            <?php foreach ($questions as $q) { ?>
                                <tr class="print:break-inside-avoid">
                                    <td style="color: var(--text-secondary); font-weight: 800;"><?php echo htmlspecialchars((string)$q['category']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$q['question_text']); ?></td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo (int)$q['rating']; ?>/5</span>
                                        <span style="margin-left: 0.35rem; color: #64748b; font-weight: 800;"><?php echo htmlspecialchars(afe_likert_label((int)$q['rating'])); ?></span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php } ?>

            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-pen-to-square"></i> Assigned Evaluator Remarks</h3>
                </div>
                <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                    <div style="white-space: pre-wrap; color: #334155; font-weight: 700;"><?php echo htmlspecialchars(trim((string)($eval['remarks'] ?? '')) !== '' ? (string)$eval['remarks'] : '-'); ?></div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="../assets/simple_charts.js"></script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
