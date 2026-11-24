<?php
include '../includes/session_fix.php';
require_roles(['PastoralFormator']);
include '../includes/db.php';
include '../includes/pastoral_evaluation.php';
include '../includes/access_requests.php';

$formator_id = get_logged_in_user_id($conn);

$access_success = null;
$access_error = null;
if (isset($_POST['approve_access']) || isset($_POST['reject_access'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $rid = (int)($_POST['request_id'] ?? 0);
    if ($rid <= 0) {
        $access_error = 'Invalid request.';
    } else {
        $stmt_req = $conn->prepare("SELECT id, seminarian_id, assigned_user_id
            FROM seminarian_access_requests
            WHERE id = ? AND assignment_type = 'Pastoral' AND status = 'Pending'
            LIMIT 1");
        $stmt_req->bind_param('i', $rid);
        $stmt_req->execute();
        $req = $stmt_req->get_result()->fetch_assoc();
        if (!$req) {
            $access_error = 'Request not found.';
        } else {
            $assigned_user_id = $req['assigned_user_id'] !== null ? (int)$req['assigned_user_id'] : null;
            if ($assigned_user_id !== null && $assigned_user_id !== $formator_id) {
                $access_error = 'Request is not assigned to you.';
            } else {
                $seminarian_id = (int)$req['seminarian_id'];
                $note = trim((string)($_POST['resolution_note'] ?? ''));
                $conn->begin_transaction();
                try {
                    if (isset($_POST['approve_access'])) {
                        $stmt_upd = $conn->prepare("UPDATE seminarians SET pastoral_eval_view_enabled = 1 WHERE id = ?");
                        $stmt_upd->bind_param('i', $seminarian_id);
                        $stmt_upd->execute();

                        $stmt_res = $conn->prepare("UPDATE seminarian_access_requests
                            SET status = 'Approved',
                                assigned_user_id = COALESCE(assigned_user_id, ?),
                                resolved_by = ?,
                                resolved_at = NOW(),
                                resolution_note = NULLIF(?, '')
                            WHERE id = ?");
                        $stmt_res->bind_param('iisi', $formator_id, $formator_id, $note, $rid);
                        $stmt_res->execute();
                        $access_success = 'Access enabled.';
                    } else {
                        $stmt_res = $conn->prepare("UPDATE seminarian_access_requests
                            SET status = 'Rejected',
                                assigned_user_id = COALESCE(assigned_user_id, ?),
                                resolved_by = ?,
                                resolved_at = NOW(),
                                resolution_note = NULLIF(?, '')
                            WHERE id = ?");
                        $stmt_res->bind_param('iisi', $formator_id, $formator_id, $note, $rid);
                        $stmt_res->execute();
                        $access_success = 'Request rejected.';
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $access_error = 'Failed to update request.';
                }
            }
        }
    }
}

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = pfe_get_default_semester();

$ay_options = [$current_ay];
$ay_res = $conn->query("SELECT DISTINCT academic_year FROM pastoral_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$stmt_total = $conn->prepare("SELECT COUNT(*) as c FROM seminarians s JOIN users u ON u.id = s.user_id WHERE u.role = 'Student'");
$stmt_total->execute();
$total_students = (int)($stmt_total->get_result()->fetch_assoc()['c'] ?? 0);

$stmt_eval = $conn->prepare("SELECT COUNT(DISTINCT e.seminarian_id) as c
    FROM pastoral_evaluations e
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_eval->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_eval->execute();
$evaluated_students = (int)($stmt_eval->get_result()->fetch_assoc()['c'] ?? 0);
$pending_count = max(0, $total_students - $evaluated_students);
$completion_rate = $total_students > 0 ? ($evaluated_students / $total_students) * 100.0 : null;

$stmt_outstanding = $conn->prepare("SELECT COUNT(*) as c
    FROM pastoral_results r
    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ? AND r.average_rating >= 4.50");
$stmt_outstanding->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_outstanding->execute();
$outstanding = (int)($stmt_outstanding->get_result()->fetch_assoc()['c'] ?? 0);

$stmt_intervention = $conn->prepare("SELECT COUNT(*) as c
    FROM pastoral_results r
    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ? AND r.average_rating < 2.50");
$stmt_intervention->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_intervention->execute();
$needs_intervention = (int)($stmt_intervention->get_result()->fetch_assoc()['c'] ?? 0);

$avg_rating = null;
$stmt_avg = $conn->prepare("SELECT AVG(r.average_rating) as a
    FROM pastoral_results r
    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_avg->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_avg->execute();
$row_avg = $stmt_avg->get_result()->fetch_assoc();
if ($row_avg && $row_avg['a'] !== null) {
    $avg_rating = (float)$row_avg['a'];
}

$cat = [
    'Pastoral Ministry' => null,
    'Leadership' => null,
    'Communication Skills' => null,
    'Community Engagement' => null,
    'Apostolic Zeal' => null,
];
$stmt_cat = $conn->prepare("SELECT AVG(r.pastoral_ministry_avg) as pm,
        AVG(r.leadership_avg) as le,
        AVG(r.communication_avg) as co,
        AVG(r.community_engagement_avg) as ce,
        AVG(r.apostolic_zeal_avg) as az
    FROM pastoral_results r
    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_cat->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_cat->execute();
$row_cat = $stmt_cat->get_result()->fetch_assoc();
if ($row_cat) {
    $cat['Pastoral Ministry'] = $row_cat['pm'] !== null ? (float)$row_cat['pm'] : null;
    $cat['Leadership'] = $row_cat['le'] !== null ? (float)$row_cat['le'] : null;
    $cat['Communication Skills'] = $row_cat['co'] !== null ? (float)$row_cat['co'] : null;
    $cat['Community Engagement'] = $row_cat['ce'] !== null ? (float)$row_cat['ce'] : null;
    $cat['Apostolic Zeal'] = $row_cat['az'] !== null ? (float)$row_cat['az'] : null;
}

$stmt_recent = $conn->prepare("SELECT e.evaluation_id, e.evaluation_date, r.average_rating, r.interpretation,
        u.username, up.full_name
    FROM pastoral_evaluations e
    JOIN seminarians s ON s.id = e.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC
    LIMIT 8");
$stmt_recent->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_recent->execute();
$res_recent = $stmt_recent->get_result();
$recent = [];
while ($res_recent && ($row = $res_recent->fetch_assoc())) {
    $recent[] = $row;
}

$access_requests = [];
$stmt_ar = $conn->prepare("SELECT r.id, r.seminarian_id, r.message, r.requested_at,
        u.username, up.full_name
    FROM seminarian_access_requests r
    JOIN seminarians s ON s.id = r.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE r.assignment_type = 'Pastoral' AND r.status = 'Pending'
      AND (r.assigned_user_id = ? OR r.assigned_user_id IS NULL)
    ORDER BY r.requested_at DESC, r.id DESC
    LIMIT 15");
$stmt_ar->bind_param('i', $formator_id);
$stmt_ar->execute();
$res_ar = $stmt_ar->get_result();
while ($res_ar && ($row = $res_ar->fetch_assoc())) {
    $access_requests[] = $row;
}
?>
<?php
$pageTitle = 'Pastoral Formation Dashboard';
$activeNav = 'pfe_dashboard';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <!-- Main Content Area: Responsive 2-Column Grid Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start font-['Inter',sans-serif]">

            <!-- LEFT COLUMN (lg:col-span-2): Stat Cards & Main Dashboard Content -->
            <div class="lg:col-span-2 flex flex-col gap-6 min-w-0">

                <!-- 1. Horizontal Stat Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-5">
                <!-- Card 1: Evaluated Seminarians -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Evaluated</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-users text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= htmlspecialchars((string)((int)($evaluated_students ?? 0))) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">out of <?= htmlspecialchars((string)((int)($total_students ?? 0))) ?> Assigned · <?= htmlspecialchars((string)((int)($pending_count ?? 0))) ?> Pending</span>
                    </div>
                </div>

                <!-- Card 2: Outstanding -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Outstanding</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-award text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= htmlspecialchars((string)((int)($outstanding ?? 0))) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">Rating ≥ 4.50</span>
                    </div>
                </div>

                <!-- Card 3: Intervention Needed -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Intervention Needed</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-triangle-exclamation text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= htmlspecialchars((string)((int)($needs_intervention ?? 0))) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block"><?= ((int)($needs_intervention ?? 0)) > 0 ? 'Requires Follow-up' : 'All Clear' ?></span>
                    </div>
                </div>

                <!-- Card 4: Completion Rate -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Completion Rate</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-chart-pie text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= $completion_rate === null ? '—' : htmlspecialchars(number_format((float)$completion_rate, 0) . '%') ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">Active Term Progress</span>
                    </div>
                </div>
            </div>
                    <!-- Pastoral Competency Category Scores Card -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-solid fa-chart-simple text-base text-[#59443F]"></i>
                            </div>
                            <div>
                                <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Pastoral Competency Category Scores</h2>
                                <p class="text-xs text-[#8B7168] font-medium">Domain competency evaluation scores</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                            <?php foreach ($cat as $k => $v) { ?>
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition">
                                    <div class="text-xs font-semibold text-[#8B7168] truncate"><?php echo htmlspecialchars($k); ?></div>
                                    <div class="mt-1 text-xl font-black text-[#59443F]">
                                        <?php echo $v === null ? '—' : htmlspecialchars(number_format((float)$v, 2)); ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Recent Evaluations Table -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-clock-rotate-left text-base text-[#59443F]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Recent Evaluations</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Latest evaluated and recorded pastoral forms</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2.5 self-start sm:self-center flex-wrap sm:flex-nowrap">
                                <div class="relative w-full sm:w-48">
                                    <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-[#8B7168]"></i>
                                    <input type="text" id="pfeTableSearch" onkeyup="filterPfeTable(this.value)" class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Filter seminarians...">
                                </div>
                                <a href="pfe_evaluations.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-clipboard-check text-xs"></i> Evaluations
                                </a>
                                <a href="pfe_reports.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-file-lines text-xs"></i> Reports
                                </a>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="pfeRecentTable" class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                                        <th class="py-3 px-5 sm:px-6">Seminarian</th>
                                        <th class="py-3 px-4 text-right">Average</th>
                                        <th class="py-3 px-4">Interpretation</th>
                                        <th class="py-3 px-5 sm:px-6 text-right">Date</th>
                                        <th class="py-3 px-5 sm:px-6 text-right">Report</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (count($recent) === 0) { ?>
                                        <tr><td colspan="5" class="text-center py-10 px-4 text-[#8B7168] font-medium text-xs">No evaluations recorded yet.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($recent as $r) {
                                        $name = trim((string)($r['full_name'] ?? ''));
                                        if ($name === '') $name = (string)($r['username'] ?? '');
                                    ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-3.5 px-5 sm:px-6 font-bold text-[#59443F]"><?php echo htmlspecialchars($name); ?></td>
                                            <td class="py-3.5 px-4 text-right font-black text-[#59443F]"><?php echo $r['average_rating'] === null ? '—' : htmlspecialchars(number_format((float)$r['average_rating'], 2)); ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                                    <?php echo htmlspecialchars((string)($r['interpretation'] ?? '—')); ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-5 sm:px-6 text-right text-[#8B7168] text-[11px]"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
                                            <td class="py-3.5 px-5 sm:px-6 text-right">
                                                <a class="inline-flex items-center gap-1 px-3 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[11px] font-bold border border-[#E8C7C5]/60 transition shadow-2xs" href="pfe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>">
                                                    <i class="fa-solid fa-file-lines text-[10px]"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN (lg:col-span-1): Calendar Widget at top right, Access Requests below -->
                <div class="lg:col-span-1 flex flex-col gap-6 min-w-0">
                    <!-- 1. Global Academic Calendar Widget (At the very top!) -->
                    <div id="academicCalendarWidget"></div>

                    <!-- 2. Access Requests Card -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden">
                        <div class="p-5 pb-3 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-paper-plane text-xs text-[#59443F]"></i>
                                </div>
                                <div>
                                    <h3 class="font-heading font-extrabold text-sm text-[#59443F] leading-tight">Access Requests</h3>
                                    <p class="text-[10px] text-[#8B7168]">Seminarian viewing permissions</p>
                                </div>
                            </div>
                            <a href="pfe_seminarians.php" class="text-xs font-bold text-[#8B7168] hover:text-[#59443F] transition">
                                Manage
                            </a>
                        </div>

                        <?php if ($access_success) { ?>
                            <div class="p-3 mx-4 mt-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold">
                                <?php echo htmlspecialchars($access_success); ?>
                            </div>
                        <?php } ?>
                        <?php if ($access_error) { ?>
                            <div class="p-3 mx-4 mt-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold">
                                <?php echo htmlspecialchars($access_error); ?>
                            </div>
                        <?php } ?>

                        <div class="p-4 sm:p-5">
                            <?php if (count($access_requests) === 0) { ?>
                                <div class="text-center py-6 text-xs text-[#8B7168]">
                                    <i class="fa-solid fa-circle-check text-2xl text-[#E8C7C5] mb-2 block"></i>
                                    No pending access requests.
                                </div>
                            <?php } else { ?>
                                <div class="divide-y divide-[#E8C7C5]/30">
                                    <?php foreach ($access_requests as $r) {
                                        $name = trim((string)($r['full_name'] ?? ''));
                                        if ($name === '') $name = (string)($r['username'] ?? '');
                                    ?>
                                        <div class="py-3 first:pt-0 last:pb-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div>
                                                    <span class="font-bold text-xs text-[#59443F] block leading-snug"><?php echo htmlspecialchars($name); ?></span>
                                                    <span class="text-[10px] text-[#8B7168]">#<?php echo (int)$r['seminarian_id']; ?> · <?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['requested_at']))); ?></span>
                                                </div>
                                            </div>
                                            <?php if (!empty($r['message'])) { ?>
                                                <p class="text-[11px] text-[#8B7168] bg-[#F8EEEE]/60 rounded-xl p-2 my-1.5 border border-[#E8C7C5]/40 leading-relaxed"><?php echo htmlspecialchars((string)$r['message']); ?></p>
                                            <?php } ?>
                                            <div class="flex items-center gap-1.5 mt-2">
                                                <form method="post" class="inline m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" name="approve_access" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold border border-[#E8C7C5]/60 transition shadow-2xs">
                                                        <i class="fa-solid fa-check text-[9px]"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="post" class="inline m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" name="reject_access" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-white hover:bg-rose-50 text-rose-700 text-[10px] font-bold border border-rose-200 transition shadow-2xs" onclick="return confirm('Reject this request?');">
                                                        <i class="fa-solid fa-xmark text-[9px]"></i> Reject
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
        </div>

        <script src="<?php echo htmlspecialchars(capstone_url('assets/academic_calendar.js')); ?>?v=<?php echo file_exists(__DIR__ . '/../assets/academic_calendar.js') ? filemtime(__DIR__ . '/../assets/academic_calendar.js') : '1'; ?>"></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('academicCalendarWidget') && window.AcademicCalendar) {
                new AcademicCalendar('academicCalendarWidget', { 
                    baseUrl: 'ajax.php',
                    timetableUrl: 'timetable.php',
                    readOnly: true,
                    selectable: false,
                    canManage: false,
                    enableDummyData: true
                });
            }
        });

        function filterPfeTable(q) {
            q = (q || '').toLowerCase();
            document.querySelectorAll('#pfeRecentTable tbody tr').forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        }
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
