<?php
include '../includes/session_fix.php';
require_roles(['SpiritualDirector', 'Registrar', 'Dean', 'Student']);
include '../includes/db.php';
include '../includes/spiritual_evaluation.php';

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
if ($evaluation_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$stmt = $conn->prepare("SELECT e.*, r.total_score, r.average_rating, r.interpretation,
        r.prayer_life_avg, r.sacramental_participation_avg, r.spiritual_discipline_avg, r.relationship_vocation_avg, r.christian_virtues_avg,
        a.previous_score, a.current_score, a.growth_status, a.growth_percentage,
        u.username as student_username, up.full_name, s.spiritual_eval_view_enabled,
        du.username as director_username
    FROM spiritual_evaluations e
    LEFT JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id
    LEFT JOIN spiritual_analytics a ON a.evaluation_id = e.evaluation_id
    JOIN seminarians s ON s.id = e.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    JOIN users du ON du.id = e.spiritual_director_id
    WHERE e.evaluation_id = ? LIMIT 1");
$stmt->bind_param('i', $evaluation_id);
$stmt->execute();
$eval = $stmt->get_result()->fetch_assoc();
if (!$eval) {
    header('Location: ../index.php');
    exit;
}

if (($_SESSION['role'] ?? '') === 'SpiritualDirector') {
    $uid = (int)($_SESSION['user'] ?? 0);
    if ((int)($eval['spiritual_director_id'] ?? 0) !== $uid) {
        header('Location: ../index.php');
        exit;
    }

    $stmt_as = $conn->prepare("SELECT 1 FROM seminarian_assignments WHERE seminarian_id = ? AND assignment_type = 'Spiritual' AND assigned_user_id = ? AND active = 1 LIMIT 1");
    $sid = (int)($eval['seminarian_id'] ?? 0);
    $stmt_as->bind_param('ii', $sid, $uid);
    $stmt_as->execute();
    $as_res = $stmt_as->get_result();
    if (!$as_res || $as_res->num_rows === 0) {
        header('Location: ../index.php');
        exit;
    }
}

if (($_SESSION['role'] ?? '') === 'Student') {
    $uid = (int)($_SESSION['user'] ?? 0);
    $stmt_sem = $conn->prepare("SELECT id, spiritual_eval_view_enabled FROM seminarians WHERE user_id = ? LIMIT 1");
    $stmt_sem->bind_param('i', $uid);
    $stmt_sem->execute();
    $sem = $stmt_sem->get_result()->fetch_assoc();
    if (
        !$sem
        || (int)$sem['id'] !== (int)$eval['seminarian_id']
        || (int)($sem['spiritual_eval_view_enabled'] ?? 0) !== 1
    ) {
        header('Location: ../index.php');
        exit;
    }
}

$display_name = trim((string)($eval['full_name'] ?? ''));
if ($display_name === '') $display_name = (string)($eval['student_username'] ?? '');

$remarks = [
    'strengths' => '',
    'areas_for_growth' => '',
    'challenges' => '',
    'recommendations' => '',
    'formation_plan' => '',
    'follow_up_schedule' => '',
];
$stmt_rem = $conn->prepare("SELECT strengths, areas_for_growth, challenges, recommendations, formation_plan, follow_up_schedule FROM spiritual_remarks WHERE evaluation_id = ? LIMIT 1");
$stmt_rem->bind_param('i', $evaluation_id);
$stmt_rem->execute();
$rem = $stmt_rem->get_result()->fetch_assoc();
if ($rem) {
    foreach ($remarks as $k => $v) {
        $remarks[$k] = (string)($rem[$k] ?? '');
    }
}

$responses = [];
$rating_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$stmt_q = $conn->prepare("SELECT q.category, q.question_text, q.sort_order, r.rating
    FROM spiritual_questions q
    JOIN spiritual_responses r ON r.question_id = q.question_id
    WHERE r.evaluation_id = ?
    ORDER BY q.sort_order ASC, q.question_id ASC");
$stmt_q->bind_param('i', $evaluation_id);
$stmt_q->execute();
$res_q = $stmt_q->get_result();
while ($row = $res_q->fetch_assoc()) {
    $responses[] = $row;
    $v = (int)($row['rating'] ?? 0);
    if ($v >= 1 && $v <= 5) $rating_counts[$v] += 1;
}

$cat_labels = [
    'Prayer Life',
    'Sacramental Life',
    'Spiritual Discipline',
    'Vocation Awareness',
    'Christian Virtues',
];
$cat_values = [
    $eval['prayer_life_avg'] !== null ? (float)$eval['prayer_life_avg'] : null,
    $eval['sacramental_participation_avg'] !== null ? (float)$eval['sacramental_participation_avg'] : null,
    $eval['spiritual_discipline_avg'] !== null ? (float)$eval['spiritual_discipline_avg'] : null,
    $eval['relationship_vocation_avg'] !== null ? (float)$eval['relationship_vocation_avg'] : null,
    $eval['christian_virtues_avg'] !== null ? (float)$eval['christian_virtues_avg'] : null,
];

$trend_labels = [];
$trend_values = [];
$seminarian_id = (int)($eval['seminarian_id'] ?? 0);
if ($seminarian_id > 0) {
    $stmt_hist = $conn->prepare("SELECT e.evaluation_date, e.academic_year, e.semester, r.average_rating
        FROM spiritual_evaluations e
        JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id
        WHERE e.archived_at IS NULL AND e.seminarian_id = ?
        ORDER BY e.evaluation_date ASC, e.evaluation_id ASC
        LIMIT 24");
    $stmt_hist->bind_param('i', $seminarian_id);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();
    while ($hr = $res_hist->fetch_assoc()) {
        $label = trim((string)($hr['academic_year'] ?? '')) . ' ' . trim((string)($hr['semester'] ?? ''));
        if (trim($label) === '') {
            $label = (string)($hr['evaluation_date'] ?? '');
        }
        $trend_labels[] = $label;
        $trend_values[] = $hr['average_rating'] !== null ? (float)$hr['average_rating'] : null;
    }
}

$growth_status = (string)($eval['growth_status'] ?? '');
if ($growth_status === 'Growing') $growth_status = 'Growing Spiritually';
?>
<?php
require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$report_profile = eval_fetch_report_profile($conn, (int)($eval['seminarian_id'] ?? 0));
$sfe_categories = [];
foreach ($cat_labels as $idx => $lbl) {
    $sfe_categories[] = [
        'label' => (string)$lbl,
        'score' => $cat_values[$idx] ?? null,
    ];
}
$growth_pct_sub = '';
if (isset($eval['growth_percentage']) && $eval['growth_percentage'] !== null && is_numeric($eval['growth_percentage'])) {
    $growth_pct_sub = sprintf('%+.1f%% vs previous', (float)$eval['growth_percentage']);
}
$pageTitle = 'Spiritual Evaluation Report';
$activeNav = 'sfe_reports';
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
                <?php if (($_SESSION['role'] ?? '') === 'Student') { ?>
                    <a href="student_spiritual_evaluation.php" class="back-link print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-extrabold shadow-sm transition-colors" style="margin: 0;">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                <?php } else { ?>
                    <a href="sfe_reports.php" class="back-link print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-extrabold shadow-sm transition-colors" style="margin: 0;">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                <?php } ?>
                <button type="button" onclick="window.print()" class="print:hidden inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-[#59443F] hover:bg-[#463430] text-white text-xs font-extrabold shadow-sm transition-colors cursor-pointer" style="width: auto;">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>

            <?php
            eval_render_report_header_banner(
                'Individual Spiritual Formation Report',
                (string)$eval['academic_year'],
                (string)$eval['semester'],
                'Spiritual Formation Pillar',
                'fa-solid fa-dove'
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
                    'evaluator_label' => 'Spiritual Director',
                    'evaluator_name' => (string)$eval['director_username'],
                ]);
                ?>
            </div>

            <div class="print:break-inside-avoid print:bg-white print:shadow-none">
                <?php
                $sfe_interp = getFormationInterpretation($eval['average_rating'] ?? null);
                eval_render_report_results_section([
                    'average_rating' => $eval['average_rating'] ?? null,
                    'total_score' => $eval['total_score'] ?? null,
                    'total_score_label' => 'Total Score',
                    'question_count' => count($responses),
                    'interpretation_label' => 'Interpretation',
                    'interpretation_value' => (string)($sfe_interp['label'] ?? ($eval['interpretation'] ?? '')),
                    'secondary_label' => 'Growth Status',
                    'secondary_value' => $growth_status,
                    'secondary_subtext' => $growth_pct_sub,
                ], $sfe_categories);
                ?>
            </div>

            <?php
            eval_render_individual_analytics_section('spiritual', [
                'cat_labels' => $cat_labels,
                'cat_values' => $cat_values,
                'trend_labels' => $trend_labels,
                'trend_values' => $trend_values,
                'dist_labels' => ['1 Poor', '2 Needs Improvement', '3 Satisfactory', '4 Very Good', '5 Outstanding'],
                'dist_values' => [(int)$rating_counts[1], (int)$rating_counts[2], (int)$rating_counts[3], (int)$rating_counts[4], (int)$rating_counts[5]],
                'average_rating' => $eval['average_rating'] ?? null,
                'total_score' => $eval['total_score'] ?? null,
            ]);
            ?>

            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-list"></i> TBI Responses</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 260px;">Category</th>
                                <th>Question</th>
                                <th style="width: 120px;">Score</th>
                                <th style="width: 180px;">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($responses as $q) { ?>
                                <tr class="print:break-inside-avoid">
                                    <td style="font-weight: 900; color: #0f172a;"><?php echo htmlspecialchars((string)$q['category']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$q['question_text']); ?></td>
                                    <td><span class="badge badge-primary"><?php echo (int)$q['rating']; ?></span></td>
                                    <td style="color: #475569; font-weight: 800;"><?php echo htmlspecialchars(sfe_likert_label((int)$q['rating'])); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="data-card print:bg-white print:shadow-none print:break-inside-avoid" style="margin-top: 1rem;">
                <div class="data-card-header print:bg-white">
                    <h3><i class="fas fa-message"></i> Spiritual Director Remarks</h3>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Spiritual Strengths</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['strengths']); ?></div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Areas for Growth</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['areas_for_growth']); ?></div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Spiritual Challenges Observed</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['challenges']); ?></div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Recommendations</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['recommendations']); ?></div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Formation Plan</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['formation_plan']); ?></div>
                    </div>
                    <div class="card print:bg-white print:shadow-none print:break-inside-avoid" style="margin: 0;">
                        <div style="color:#64748b; font-weight: 900;">Follow-Up Schedule</div>
                        <div style="white-space: pre-wrap; color: #334155; font-weight: 700; margin-top: 0.5rem;"><?php echo htmlspecialchars((string)$remarks['follow_up_schedule']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="../assets/simple_charts.js"></script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
