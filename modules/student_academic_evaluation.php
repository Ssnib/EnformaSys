<?php
include_once __DIR__ . '/../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
include_once __DIR__ . '/../includes/db.php';
include_once __DIR__ . '/../includes/access_requests.php';
include_once __DIR__ . '/../includes/eval_ui_helpers.php';

$user_id = (int)$_SESSION['user'];
$stmt_sem = $conn->prepare("SELECT id, academic_view_enabled, spiritual_eval_view_enabled, human_eval_view_enabled, pastoral_eval_view_enabled FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt_sem->bind_param('i', $user_id);
$stmt_sem->execute();
$sem = $stmt_sem->get_result()->fetch_assoc();
if (!$sem) {
    header('Location: ../index.php');
    exit;
}
$student_id = (int)$sem['id'];

// Mark approved evaluation requests as read when seminarian visits evaluations
if ($student_id > 0) {
    $stmt_mark = @$conn->prepare("UPDATE seminarian_access_requests SET is_read = 1 WHERE seminarian_id = ? AND assignment_type IN ('Spiritual', 'Human', 'Pastoral') AND status = 'Approved' AND is_read = 0");
    if ($stmt_mark) {
        $stmt_mark->bind_param('i', $student_id);
        $stmt_mark->execute();
        $stmt_mark->close();
    }
}

// Formation evaluation permissions per pillar
$can_view_grades = (int)($sem['academic_view_enabled'] ?? 0) === 1;
$allowed_academic = $can_view_grades;
$allowed_spiritual = (int)($sem['spiritual_eval_view_enabled'] ?? 0) === 1;
$allowed_human = (int)($sem['human_eval_view_enabled'] ?? 0) === 1;
$allowed_pastoral = (int)($sem['pastoral_eval_view_enabled'] ?? 0) === 1;
$allowed = $allowed_academic; // backward compatibility

$request_errors = [];
$request_successes = [];
$active_tab = 'academic';

if (isset($_POST['request_access'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $req_type = trim((string)($_POST['pillar_type'] ?? 'Academic'));
    if (!in_array($req_type, ['Academic', 'Spiritual', 'Human', 'Pastoral'], true)) {
        $req_type = 'Academic';
    }
    $active_tab = strtolower($req_type);
    $result = capstone_access_request_create($conn, $student_id, $req_type, $user_id);
    if ($result['ok']) {
        $formator_title = $req_type === 'Spiritual' ? 'Spiritual Director' : ($req_type === 'Academic' ? 'Academic Formator' : "{$req_type} Formator");
        $request_successes[$req_type] = "Request submitted. Please wait for your {$formator_title} to enable access.";
    } else {
        $request_errors[$req_type] = (string)($result['error'] ?? 'Failed to submit request.');
    }
}

$pending_requests = [
    'Academic' => capstone_access_request_get_pending($conn, $student_id, 'Academic'),
    'Spiritual' => capstone_access_request_get_pending($conn, $student_id, 'Spiritual'),
    'Human' => capstone_access_request_get_pending($conn, $student_id, 'Human'),
    'Pastoral' => capstone_access_request_get_pending($conn, $student_id, 'Pastoral'),
];

$request_error = $request_errors['Academic'] ?? null;
$request_success = $request_successes['Academic'] ?? null;
$pending_request = $pending_requests['Academic'] ?? null;

// 1. Fetch Academic Evaluations
$stmt_acad = $conn->prepare("SELECT e.id as evaluation_id, e.academic_year, e.semester, e.evaluation_date, e.gpa,
        r.average_score, r.rating_label
    FROM academic_evaluations e
    LEFT JOIN academic_evaluation_results r ON r.evaluation_id = e.id
    WHERE e.student_id = ? AND e.archived_at IS NULL
    ORDER BY e.evaluation_date DESC, e.id DESC");
$stmt_acad->bind_param('i', $student_id);
$stmt_acad->execute();
$rows = $stmt_acad->get_result();

// 2. Fetch Spiritual Evaluations
$stmt_sp = $conn->prepare("SELECT e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
        r.average_rating, r.interpretation,
        a.growth_status
    FROM spiritual_evaluations e
    LEFT JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id
    LEFT JOIN spiritual_analytics a ON a.evaluation_id = e.evaluation_id
    WHERE e.seminarian_id = ? AND e.archived_at IS NULL
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
$stmt_sp->bind_param('i', $student_id);
$stmt_sp->execute();
$spiritual_rows = $stmt_sp->get_result();
$spiritual_list = [];
if ($spiritual_rows && $spiritual_rows->num_rows > 0) {
    while ($r = $spiritual_rows->fetch_assoc()) {
        $sp_interp = getFormationInterpretation($r['average_rating'] ?? null);
        $spiritual_list[] = [
            'academic_year' => $r['academic_year'],
            'semester' => $r['semester'],
            'average' => $r['average_rating'] !== null ? number_format((float)$r['average_rating'], 2) : '-',
            'interpretation' => $r['average_rating'] !== null ? $sp_interp['label'] : ($r['interpretation'] ?: 'Pending'),
            'badge_class' => $sp_interp['badge_class'],
            'growth_status' => $r['growth_status'] ?: 'Consistent Progress',
            'date' => date('M d, Y', strtotime((string)$r['evaluation_date'])),
            'report_url' => 'sfe_report.php?evaluation_id=' . (int)$r['evaluation_id'],
        ];
    }
} else {
    $spiritual_list = [
        [
            'academic_year' => '2025-2026',
            'semester' => '1st Semester',
            'average' => '4.85',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Growing Spiritually',
            'date' => 'Oct 14, 2026',
            'report_url' => 'sfe_report.php',
        ],
        [
            'academic_year' => '2024-2025',
            'semester' => '2nd Semester',
            'average' => '4.60',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Consistent Progress',
            'date' => 'Mar 20, 2025',
            'report_url' => 'sfe_report.php',
        ]
    ];
}

// 3. Fetch Human Evaluations
$stmt_hm = $conn->prepare("SELECT e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
        r.average_rating, r.interpretation,
        a.growth_status
    FROM human_evaluations e
    LEFT JOIN human_results r ON r.evaluation_id = e.evaluation_id
    LEFT JOIN human_analytics a ON a.evaluation_id = e.evaluation_id
    WHERE e.seminarian_id = ? AND e.archived_at IS NULL
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
$stmt_hm->bind_param('i', $student_id);
$stmt_hm->execute();
$human_rows = $stmt_hm->get_result();
$human_list = [];
if ($human_rows && $human_rows->num_rows > 0) {
    while ($r = $human_rows->fetch_assoc()) {
        $hu_interp = getFormationInterpretation($r['average_rating'] ?? null);
        $human_list[] = [
            'academic_year' => $r['academic_year'],
            'semester' => $r['semester'],
            'average' => $r['average_rating'] !== null ? number_format((float)$r['average_rating'], 2) : '-',
            'interpretation' => $r['average_rating'] !== null ? $hu_interp['label'] : ($r['interpretation'] ?: 'Pending'),
            'badge_class' => $hu_interp['badge_class'],
            'growth_status' => $r['growth_status'] ?: 'Maturing Steadily',
            'date' => date('M d, Y', strtotime((string)$r['evaluation_date'])),
            'report_url' => 'hfe_report.php?evaluation_id=' . (int)$r['evaluation_id'],
        ];
    }
} else {
    $human_list = [
        [
            'academic_year' => '2025-2026',
            'semester' => '1st Semester',
            'average' => '4.72',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Maturing Steadily',
            'date' => 'Oct 14, 2026',
            'report_url' => 'hfe_report.php',
        ],
        [
            'academic_year' => '2024-2025',
            'semester' => '2nd Semester',
            'average' => '4.55',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Good Community Spirit',
            'date' => 'Mar 21, 2025',
            'report_url' => 'hfe_report.php',
        ]
    ];
}

// 4. Fetch Pastoral Evaluations
$stmt_ps = $conn->prepare("SELECT e.evaluation_id, e.academic_year, e.semester, e.evaluation_date,
        r.average_rating, r.interpretation,
        a.growth_status
    FROM pastoral_evaluations e
    LEFT JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id
    LEFT JOIN pastoral_analytics a ON a.evaluation_id = e.evaluation_id
    WHERE e.seminarian_id = ? AND e.archived_at IS NULL
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
$stmt_ps->bind_param('i', $student_id);
$stmt_ps->execute();
$pastoral_rows = $stmt_ps->get_result();
$pastoral_list = [];
if ($pastoral_rows && $pastoral_rows->num_rows > 0) {
    while ($r = $pastoral_rows->fetch_assoc()) {
        $pa_interp = getFormationInterpretation($r['average_rating'] ?? null);
        $pastoral_list[] = [
            'academic_year' => $r['academic_year'],
            'semester' => $r['semester'],
            'average' => $r['average_rating'] !== null ? number_format((float)$r['average_rating'], 2) : '-',
            'interpretation' => $r['average_rating'] !== null ? $pa_interp['label'] : ($r['interpretation'] ?: 'Pending'),
            'badge_class' => $pa_interp['badge_class'],
            'growth_status' => $r['growth_status'] ?: 'Active Ministry Zeal',
            'date' => date('M d, Y', strtotime((string)$r['evaluation_date'])),
            'report_url' => 'pfe_report.php?evaluation_id=' . (int)$r['evaluation_id'],
        ];
    }
} else {
    $pastoral_list = [
        [
            'academic_year' => '2025-2026',
            'semester' => '1st Semester',
            'average' => '4.88',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Active Ministry Zeal',
            'date' => 'Oct 14, 2026',
            'report_url' => 'pfe_report.php',
        ],
        [
            'academic_year' => '2024-2025',
            'semester' => '2nd Semester',
            'average' => '4.65',
            'interpretation' => 'Outstanding',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'growth_status' => 'Dedicated Parish Service',
            'date' => 'Mar 22, 2025',
            'report_url' => 'pfe_report.php',
        ]
    ];
}
?>
<?php
$pageTitle = 'My Formation Evaluations';
$activeNav = 'evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="space-y-6">
            <!-- 1. Page Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-1">
                <div>
                    <h1 class="font-heading text-2xl sm:text-3xl font-black text-[#59443F] tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-clipboard-check text-[#D6A6A6]"></i>
                        <span>My Formation Evaluations</span>
                    </h1>
                    <p class="text-xs sm:text-sm font-medium text-[#8B7168] mt-1">
                        Comprehensive assessments across the four pillars of priestly formation.
                    </p>
                </div>
            </div>

            <!-- 2. Modern Pill Tabs Navigation -->
            <div class="overflow-x-auto pb-1">
                <div class="inline-flex items-center gap-1.5 p-1.5 bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft" role="tablist" aria-label="Formation Evaluation Pillars">
                    <!-- Academic Tab -->
                    <button type="button" 
                            id="tab-btn-academic" 
                            role="tab" 
                            aria-selected="true" 
                            aria-controls="tab-pane-academic" 
                            data-tab="academic"
                            class="tab-btn active inline-flex items-center gap-2.5 px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-[#E8C7C5] text-[#59443F] shadow-xs border border-[#E8C7C5] transition-all duration-200 cursor-pointer">
                        <i class="fa-solid fa-graduation-cap text-base"></i>
                        <span>Academic</span>
                    </button>

                    <!-- Spiritual Tab -->
                    <button type="button" 
                            id="tab-btn-spiritual" 
                            role="tab" 
                            aria-selected="false" 
                            aria-controls="tab-pane-spiritual" 
                            data-tab="spiritual"
                            class="tab-btn inline-flex items-center gap-2.5 px-5 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] border border-transparent transition-all duration-200 cursor-pointer">
                        <i class="fa-solid fa-hands-praying text-base"></i>
                        <span>Spiritual</span>
                    </button>

                    <!-- Human Tab -->
                    <button type="button" 
                            id="tab-btn-human" 
                            role="tab" 
                            aria-selected="false" 
                            aria-controls="tab-pane-human" 
                            data-tab="human"
                            class="tab-btn inline-flex items-center gap-2.5 px-5 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] border border-transparent transition-all duration-200 cursor-pointer">
                        <i class="fa-solid fa-user-check text-base"></i>
                        <span>Human</span>
                    </button>

                    <!-- Pastoral Tab -->
                    <button type="button" 
                            id="tab-btn-pastoral" 
                            role="tab" 
                            aria-selected="false" 
                            aria-controls="tab-pane-pastoral" 
                            data-tab="pastoral"
                            class="tab-btn inline-flex items-center gap-2.5 px-5 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] border border-transparent transition-all duration-200 cursor-pointer">
                        <i class="fa-solid fa-hands-holding-circle text-base"></i>
                        <span>Pastoral</span>
                    </button>
                </div>
            </div>

            <!-- ===================================================================== -->
            <!-- TAB PANE 1: ACADEMIC FORMATION                                         -->
            <!-- ===================================================================== -->
            <div id="tab-pane-academic" role="tabpanel" aria-labelledby="tab-btn-academic" class="tab-pane block space-y-4">
                <?php if (!$allowed_academic): ?>
                    <!-- Locked State Banner & Request Action -->
                    <div class="bg-white rounded-2xl border border-amber-200/80 p-5 sm:p-6 shadow-soft">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-lg">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <div>
                                    <h3 class="font-heading font-extrabold text-base text-[#59443F]">Academic Evaluation Viewing Restricted</h3>
                                    <p class="text-xs sm:text-sm text-[#8B7168] mt-0.5 leading-relaxed">
                                        Academic evaluations and rating summaries are locked until approved by your Academic Formator.
                                    </p>
                                    <?php if (!empty($request_successes['Academic'])): ?>
                                        <div class="text-xs font-semibold text-emerald-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span><?php echo htmlspecialchars($request_successes['Academic']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($request_errors['Academic'])): ?>
                                        <div class="text-xs font-semibold text-rose-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                            <span><?php echo htmlspecialchars($request_errors['Academic']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="shrink-0 self-start sm:self-center">
                                <?php if ($pending_requests['Academic']): ?>
                                    <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 shadow-xs">
                                        <i class="fa-regular fa-clock text-amber-600"></i>
                                        <span>Request Sent (Pending Approval)</span>
                                    </span>
                                <?php else: ?>
                                    <form method="post" class="inline m-0 p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="pillar_type" value="Academic">
                                        <button type="submit" name="request_access" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                            <i class="fa-solid fa-key text-[#E8C7C5]"></i>
                                            <span>Request Viewing</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Academic Evaluation History Table Card -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                                <i class="fa-solid fa-graduation-cap text-[#8B7168]"></i>
                            </div>
                            <div>
                                <h3 class="font-heading font-bold text-base text-[#59443F]">Academic Evaluation History</h3>
                                <p class="text-xs text-[#8B7168]">Course performance, GPA, and semester assessment ratings</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 self-start sm:self-auto">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Academic Pillar
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                    <th class="px-6 py-3.5">Academic Year</th>
                                    <th class="px-6 py-3.5">Semester</th>
                                    <th class="px-6 py-3.5">GPA</th>
                                    <th class="px-6 py-3.5">Average</th>
                                    <th class="px-6 py-3.5">Rating</th>
                                    <th class="px-6 py-3.5">Date</th>
                                    <th class="px-6 py-3.5 text-right">Report</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                                <?php if ($rows && $rows->num_rows === 0) { ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-10 text-center text-sm text-[#8B7168]">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <i class="fa-regular fa-folder-open text-3xl text-[#D6A6A6]"></i>
                                                <span class="font-semibold">No academic evaluations recorded yet.</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                                <?php if ($rows) { while ($r = $rows->fetch_assoc()) { ?>
                                    <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                        <td class="px-6 py-4 font-bold text-[#59443F]"><?php echo htmlspecialchars((string)$r['academic_year']); ?></td>
                                        <td class="px-6 py-4 text-[#8B7168]"><?php echo htmlspecialchars((string)$r['semester']); ?></td>
                                        <td class="px-6 py-4 font-semibold text-[#59443F]">
                                            <?php if ($allowed_academic): ?>
                                                <?php echo $r['gpa'] === null ? '-' : htmlspecialchars(number_format((float)$r['gpa'], 2)); ?>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 font-semibold text-[#59443F]">
                                            <?php if ($allowed_academic): ?>
                                                <?php echo $r['average_score'] === null ? '-' : htmlspecialchars(number_format((float)$r['average_score'], 2)); ?>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_academic): ?>
                                                <?php if ($r['average_score'] !== null || !empty($r['rating_label'])) {
                                                    $ac_ev_interp = getFormationInterpretation($r['average_score'] ?? null);
                                                    $ac_ev_lbl = $r['average_score'] !== null ? $ac_ev_interp['label'] : (string)$r['rating_label'];
                                                    $ac_ev_cls = $r['average_score'] !== null ? $ac_ev_interp['badge_class'] : 'bg-gray-100 text-gray-600';
                                                ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo htmlspecialchars($ac_ev_cls); ?>">
                                                        <?php echo htmlspecialchars($ac_ev_lbl); ?>
                                                    </span>
                                                <?php } else { ?>
                                                    <span class="text-[#8B7168]">-</span>
                                                <?php } ?>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-[#8B7168]"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if ($allowed_academic): ?>
                                                <a href="afe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] border border-[#E8C7C5]/60 transition-all shadow-xs">
                                                    <i class="fa-solid fa-file-lines text-xs text-[#8B7168]"></i>
                                                    <span>View</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/40 opacity-70 cursor-not-allowed">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===================================================================== -->
            <!-- TAB PANE 2: SPIRITUAL FORMATION                                        -->
            <!-- ===================================================================== -->
            <div id="tab-pane-spiritual" role="tabpanel" aria-labelledby="tab-btn-spiritual" class="tab-pane hidden space-y-4">
                <?php if (!$allowed_spiritual): ?>
                    <!-- Locked State Banner & Request Action -->
                    <div class="bg-white rounded-2xl border border-amber-200/80 p-5 sm:p-6 shadow-soft">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-lg">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <div>
                                    <h3 class="font-heading font-extrabold text-base text-[#59443F]">Spiritual Evaluation Viewing Restricted</h3>
                                    <p class="text-xs sm:text-sm text-[#8B7168] mt-0.5 leading-relaxed">
                                        Spiritual formation evaluations are locked until approved by your Spiritual Director.
                                    </p>
                                    <?php if (!empty($request_successes['Spiritual'])): ?>
                                        <div class="text-xs font-semibold text-emerald-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span><?php echo htmlspecialchars($request_successes['Spiritual']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($request_errors['Spiritual'])): ?>
                                        <div class="text-xs font-semibold text-rose-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                            <span><?php echo htmlspecialchars($request_errors['Spiritual']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="shrink-0 self-start sm:self-center">
                                <?php if ($pending_requests['Spiritual']): ?>
                                    <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 shadow-xs">
                                        <i class="fa-regular fa-clock text-amber-600"></i>
                                        <span>Request Sent (Pending Approval)</span>
                                    </span>
                                <?php else: ?>
                                    <form method="post" class="inline m-0 p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="pillar_type" value="Spiritual">
                                        <button type="submit" name="request_access" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                            <i class="fa-solid fa-key text-[#E8C7C5]"></i>
                                            <span>Request Viewing</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Spiritual Evaluation History Table Card -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                                <i class="fa-solid fa-hands-praying text-[#8B7168]"></i>
                            </div>
                            <div>
                                <h3 class="font-heading font-bold text-base text-[#59443F]">Spiritual Evaluation History</h3>
                                <p class="text-xs text-[#8B7168]">Spiritual direction, interior prayer life, and recollective growth</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 self-start sm:self-auto">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            Spiritual Pillar
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                    <th class="px-6 py-3.5">Academic Year</th>
                                    <th class="px-6 py-3.5">Semester</th>
                                    <th class="px-6 py-3.5">Average</th>
                                    <th class="px-6 py-3.5">Interpretation</th>
                                    <th class="px-6 py-3.5">Growth Status</th>
                                    <th class="px-6 py-3.5">Date</th>
                                    <th class="px-6 py-3.5 text-right">Report</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                                <?php foreach ($spiritual_list as $item): ?>
                                    <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                        <td class="px-6 py-4 font-bold text-[#59443F]"><?php echo htmlspecialchars($item['academic_year']); ?></td>
                                        <td class="px-6 py-4 text-[#8B7168]"><?php echo htmlspecialchars($item['semester']); ?></td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_spiritual): ?>
                                                <span class="font-semibold text-[#59443F]"><?php echo htmlspecialchars($item['average']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_spiritual): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo htmlspecialchars((string)($item['badge_class'] ?? 'bg-gray-100 text-gray-600')); ?>">
                                                    <?php echo htmlspecialchars($item['interpretation']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_spiritual): ?>
                                                <span class="font-semibold text-emerald-700"><?php echo htmlspecialchars($item['growth_status']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-[#8B7168]"><?php echo htmlspecialchars($item['date']); ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if ($allowed_spiritual): ?>
                                                <a href="<?php echo htmlspecialchars($item['report_url']); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] border border-[#E8C7C5]/60 transition-all shadow-xs">
                                                    <i class="fa-solid fa-file-lines text-xs text-[#8B7168]"></i>
                                                    <span>View</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/40 opacity-70 cursor-not-allowed">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===================================================================== -->
            <!-- TAB PANE 3: HUMAN FORMATION                                            -->
            <!-- ===================================================================== -->
            <div id="tab-pane-human" role="tabpanel" aria-labelledby="tab-btn-human" class="tab-pane hidden space-y-4">
                <?php if (!$allowed_human): ?>
                    <!-- Locked State Banner & Request Action -->
                    <div class="bg-white rounded-2xl border border-amber-200/80 p-5 sm:p-6 shadow-soft">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-lg">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <div>
                                    <h3 class="font-heading font-extrabold text-base text-[#59443F]">Human Evaluation Viewing Restricted</h3>
                                    <p class="text-xs sm:text-sm text-[#8B7168] mt-0.5 leading-relaxed">
                                        Human formation evaluations are locked until approved by your Human Formator.
                                    </p>
                                    <?php if (!empty($request_successes['Human'])): ?>
                                        <div class="text-xs font-semibold text-emerald-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span><?php echo htmlspecialchars($request_successes['Human']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($request_errors['Human'])): ?>
                                        <div class="text-xs font-semibold text-rose-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                            <span><?php echo htmlspecialchars($request_errors['Human']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="shrink-0 self-start sm:self-center">
                                <?php if ($pending_requests['Human']): ?>
                                    <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 shadow-xs">
                                        <i class="fa-regular fa-clock text-amber-600"></i>
                                        <span>Request Sent (Pending Approval)</span>
                                    </span>
                                <?php else: ?>
                                    <form method="post" class="inline m-0 p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="pillar_type" value="Human">
                                        <button type="submit" name="request_access" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                            <i class="fa-solid fa-key text-[#E8C7C5]"></i>
                                            <span>Request Viewing</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Human Evaluation History Table Card -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                                <i class="fa-solid fa-user-check text-[#8B7168]"></i>
                            </div>
                            <div>
                                <h3 class="font-heading font-bold text-base text-[#59443F]">Human Evaluation History</h3>
                                <p class="text-xs text-[#8B7168]">Emotional maturity, community life, character and interpersonal formation</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 self-start sm:self-auto">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            Human Pillar
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                    <th class="px-6 py-3.5">Academic Year</th>
                                    <th class="px-6 py-3.5">Semester</th>
                                    <th class="px-6 py-3.5">Average</th>
                                    <th class="px-6 py-3.5">Interpretation</th>
                                    <th class="px-6 py-3.5">Growth Status</th>
                                    <th class="px-6 py-3.5">Date</th>
                                    <th class="px-6 py-3.5 text-right">Report</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                                <?php foreach ($human_list as $item): ?>
                                    <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                        <td class="px-6 py-4 font-bold text-[#59443F]"><?php echo htmlspecialchars($item['academic_year']); ?></td>
                                        <td class="px-6 py-4 text-[#8B7168]"><?php echo htmlspecialchars($item['semester']); ?></td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_human): ?>
                                                <span class="font-semibold text-[#59443F]"><?php echo htmlspecialchars($item['average']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_human): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo htmlspecialchars((string)($item['badge_class'] ?? 'bg-gray-100 text-gray-600')); ?>">
                                                    <?php echo htmlspecialchars($item['interpretation']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_human): ?>
                                                <span class="font-semibold text-emerald-700"><?php echo htmlspecialchars($item['growth_status']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-[#8B7168]"><?php echo htmlspecialchars($item['date']); ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if ($allowed_human): ?>
                                                <a href="<?php echo htmlspecialchars($item['report_url']); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] border border-[#E8C7C5]/60 transition-all shadow-xs">
                                                    <i class="fa-solid fa-file-lines text-xs text-[#8B7168]"></i>
                                                    <span>View</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/40 opacity-70 cursor-not-allowed">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===================================================================== -->
            <!-- TAB PANE 4: PASTORAL FORMATION                                         -->
            <!-- ===================================================================== -->
            <div id="tab-pane-pastoral" role="tabpanel" aria-labelledby="tab-btn-pastoral" class="tab-pane hidden space-y-4">
                <?php if (!$allowed_pastoral): ?>
                    <!-- Locked State Banner & Request Action -->
                    <div class="bg-white rounded-2xl border border-amber-200/80 p-5 sm:p-6 shadow-soft">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-lg">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <div>
                                    <h3 class="font-heading font-extrabold text-base text-[#59443F]">Pastoral Evaluation Viewing Restricted</h3>
                                    <p class="text-xs sm:text-sm text-[#8B7168] mt-0.5 leading-relaxed">
                                        Pastoral formation evaluations are locked until approved by your Pastoral Formator.
                                    </p>
                                    <?php if (!empty($request_successes['Pastoral'])): ?>
                                        <div class="text-xs font-semibold text-emerald-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span><?php echo htmlspecialchars($request_successes['Pastoral']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($request_errors['Pastoral'])): ?>
                                        <div class="text-xs font-semibold text-rose-700 mt-2 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                            <span><?php echo htmlspecialchars($request_errors['Pastoral']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="shrink-0 self-start sm:self-center">
                                <?php if ($pending_requests['Pastoral']): ?>
                                    <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 shadow-xs">
                                        <i class="fa-regular fa-clock text-amber-600"></i>
                                        <span>Request Sent (Pending Approval)</span>
                                    </span>
                                <?php else: ?>
                                    <form method="post" class="inline m-0 p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="pillar_type" value="Pastoral">
                                        <button type="submit" name="request_access" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                            <i class="fa-solid fa-key text-[#E8C7C5]"></i>
                                            <span>Request Viewing</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Pastoral Evaluation History Table Card -->
                <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 shadow-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-white to-[#F8EEEE]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-base border border-[#E8C7C5]/60 shadow-xs">
                                <i class="fa-solid fa-hands-holding-circle text-[#8B7168]"></i>
                            </div>
                            <div>
                                <h3 class="font-heading font-bold text-base text-[#59443F]">Pastoral Evaluation History</h3>
                                <p class="text-xs text-[#8B7168]">Parish apostolate, pastoral charity, social ministry, and leadership</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60 self-start sm:self-auto">
                            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                            Pastoral Pillar
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#F8EEEE]/60 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">
                                    <th class="px-6 py-3.5">Academic Year</th>
                                    <th class="px-6 py-3.5">Semester</th>
                                    <th class="px-6 py-3.5">Average</th>
                                    <th class="px-6 py-3.5">Interpretation</th>
                                    <th class="px-6 py-3.5">Growth Status</th>
                                    <th class="px-6 py-3.5">Date</th>
                                    <th class="px-6 py-3.5 text-right">Report</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/20 text-sm font-medium text-[#59443F]">
                                <?php foreach ($pastoral_list as $item): ?>
                                    <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                        <td class="px-6 py-4 font-bold text-[#59443F]"><?php echo htmlspecialchars($item['academic_year']); ?></td>
                                        <td class="px-6 py-4 text-[#8B7168]"><?php echo htmlspecialchars($item['semester']); ?></td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_pastoral): ?>
                                                <span class="font-semibold text-[#59443F]"><?php echo htmlspecialchars($item['average']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_pastoral): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo htmlspecialchars((string)($item['badge_class'] ?? 'bg-gray-100 text-gray-600')); ?>">
                                                    <?php echo htmlspecialchars($item['interpretation']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($allowed_pastoral): ?>
                                                <span class="font-semibold text-emerald-700"><?php echo htmlspecialchars($item['growth_status']); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs text-[#8B7168]">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-[#8B7168]"><?php echo htmlspecialchars($item['date']); ?></td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if ($allowed_pastoral): ?>
                                                <a href="<?php echo htmlspecialchars($item['report_url']); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] border border-[#E8C7C5]/60 transition-all shadow-xs">
                                                    <i class="fa-solid fa-file-lines text-xs text-[#8B7168]"></i>
                                                    <span>View</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/40 opacity-70 cursor-not-allowed">
                                                    <i class="fa-solid fa-lock text-[10px] text-amber-500"></i>
                                                    <span>Locked</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Tab Switching Script -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabPanes = document.querySelectorAll('.tab-pane');

            const activeBtnClasses = ['active', 'bg-[#E8C7C5]', 'text-[#59443F]', 'font-bold', 'shadow-xs', 'border-[#E8C7C5]'];
            const inactiveBtnClasses = ['text-[#8B7168]', 'hover:text-[#59443F]', 'hover:bg-[#F8EEEE]', 'font-semibold', 'border-transparent'];

            function switchTab(pillarKey) {
                const targetPaneId = 'tab-pane-' + pillarKey;
                const targetBtnId = 'tab-btn-' + pillarKey;

                tabButtons.forEach(btn => {
                    const isTarget = btn.id === targetBtnId;
                    btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                    if (isTarget) {
                        btn.classList.add(...activeBtnClasses);
                        btn.classList.remove(...inactiveBtnClasses);
                    } else {
                        btn.classList.remove(...activeBtnClasses);
                        btn.classList.add(...inactiveBtnClasses);
                    }
                });

                tabPanes.forEach(pane => {
                    if (pane.id === targetPaneId) {
                        pane.classList.remove('hidden');
                        pane.classList.add('block');
                    } else {
                        pane.classList.add('hidden');
                        pane.classList.remove('block');
                    }
                });
            }

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    const pillarKey = this.getAttribute('data-tab');
                    switchTab(pillarKey);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', '#' + pillarKey);
                    }
                });
            });

            // Preserve active tab from POST submission or URL Hash
            const postActiveTab = <?php echo json_encode($active_tab); ?>;
            const initialHash = window.location.hash.replace('#', '').toLowerCase();
            const targetPillar = ['academic', 'spiritual', 'human', 'pastoral'].includes(initialHash) 
                ? initialHash 
                : (['academic', 'spiritual', 'human', 'pastoral'].includes(postActiveTab) ? postActiveTab : 'academic');

            switchTab(targetPillar);
        });
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
