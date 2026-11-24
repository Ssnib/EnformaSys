<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator']);
include '../includes/db.php';
include '../includes/academic_evaluation.php';
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
            WHERE id = ? AND assignment_type = 'Academic' AND status = 'Pending'
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
                        $stmt_res = $conn->prepare("UPDATE seminarian_access_requests
                            SET status = 'Approved',
                                assigned_user_id = COALESCE(assigned_user_id, ?),
                                resolved_by = ?,
                                resolved_at = NOW(),
                                resolution_note = NULLIF(?, '')
                            WHERE id = ?");
                        $stmt_res->bind_param('iisi', $formator_id, $formator_id, $note, $rid);
                        $stmt_res->execute();
                        $access_success = 'Access enabled for requested term.';
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
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = afe_get_default_semester();

$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM academic_evaluations WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM gpa_records WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$total_students = 0;
$stmt_total = $conn->prepare("SELECT COUNT(*) as c FROM seminarians s JOIN users u ON u.id = s.user_id WHERE u.role = 'Student'");
$stmt_total->execute();
$total_students = (int)($stmt_total->get_result()->fetch_assoc()['c'] ?? 0);

$stmt_eval = $conn->prepare("SELECT COUNT(DISTINCT e.student_id) as c
    FROM academic_evaluations e
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_eval->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_eval->execute();
$evaluated_students = (int)($stmt_eval->get_result()->fetch_assoc()['c'] ?? 0);

$pending = max(0, $total_students - $evaluated_students);
$pending_count = $pending;

$avg_rating = null;
$stmt_avg = $conn->prepare("SELECT AVG(r.average_score) as a
    FROM academic_evaluation_results r
    JOIN academic_evaluations e ON e.id = r.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?");
$stmt_avg->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_avg->execute();
$row_avg = $stmt_avg->get_result()->fetch_assoc();
if ($row_avg && $row_avg['a'] !== null) {
    $avg_rating = (float)$row_avg['a'];
}

$top = [];
$stmt_top = $conn->prepare("SELECT e.id as evaluation_id, e.student_id, r.average_score, r.rating_label,
        u.username, up.full_name
    FROM academic_evaluation_results r
    JOIN academic_evaluations e ON e.id = r.evaluation_id
    JOIN seminarians s ON s.id = e.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?
    ORDER BY r.average_score DESC, e.evaluation_date DESC
    LIMIT 5");
$stmt_top->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_top->execute();
$res_top = $stmt_top->get_result();
while ($r = $res_top->fetch_assoc()) {
    $top[] = $r;
}

$recent = [];
$stmt_recent = $conn->prepare("SELECT e.id as evaluation_id, e.evaluation_date, e.gpa, r.average_score, r.rating_label,
        u.username, up.full_name
    FROM academic_evaluations e
    LEFT JOIN academic_evaluation_results r ON r.evaluation_id = e.id
    JOIN seminarians s ON s.id = e.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?
    ORDER BY e.evaluation_date DESC, e.id DESC
    LIMIT 10");
$stmt_recent->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_recent->execute();
$res_recent = $stmt_recent->get_result();
while ($r = $res_recent->fetch_assoc()) {
    $recent[] = $r;
}

$access_requests = [];
$hasSarTermCols = function_exists('capstone_access_has_term_columns') && capstone_access_has_term_columns($conn);
$sarTermCols = $hasSarTermCols ? 'r.academic_year, r.semester, ' : '';
$stmt_ar = $conn->prepare("SELECT r.id, r.seminarian_id, {$sarTermCols}r.message, r.requested_at,
        u.username, up.full_name
    FROM seminarian_access_requests r
    JOIN seminarians s ON s.id = r.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE r.assignment_type = 'Academic' AND r.status = 'Pending'
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
$pageTitle = 'Academic Evaluation Dashboard';
$activeNav = 'afe_dashboard';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <!-- Main Content Area: Responsive 2-Column Grid Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start font-['Inter',sans-serif]">

            <!-- LEFT COLUMN (lg:col-span-2): Stat Cards & Main Dashboard Content -->
            <div class="lg:col-span-2 flex flex-col gap-6 min-w-0">

                <!-- 1. 3 Floating Stat Cards in Single Horizontal Row -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <!-- Card 1: Evaluated Seminarians -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Evaluated Seminarians</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-users text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= htmlspecialchars((string)((int)($evaluated_students ?? 0))) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">out of <?= htmlspecialchars((string)((int)($total_students ?? 0))) ?> Students</span>
                    </div>
                </div>

                <!-- Card 2: Pending Evaluations -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Pending Evaluations</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-hourglass-half text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= htmlspecialchars((string)((int)($pending_count ?? 0))) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block"><?= ((int)($pending_count ?? 0)) > 0 ? 'Requires Evaluation' : 'All Clear' ?></span>
                    </div>
                </div>

                <!-- Card 3: Average Rating -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                    <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                        <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Average Rating</span>
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                            <i class="fa-solid fa-star text-[#59443F]"></i>
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                            <?= $avg_rating === null ? '—' : htmlspecialchars(number_format((float)$avg_rating, 2)) ?>
                        </div>
                        <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">Term Academic Score</span>
                    </div>
                </div>
            </div>
                    <!-- Top Performing Seminarians Table -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-trophy text-base text-[#59443F]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Top Performing Seminarians</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Highest academic formation scores</p>
                                </div>
                            </div>
                            <a href="afe_evaluations.php?academic_year=<?php echo htmlspecialchars(urlencode($filter_academic_year)); ?>&semester=<?php echo htmlspecialchars(urlencode($filter_semester)); ?>" 
                               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs self-start sm:self-center">
                                View All Records
                            </a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                                        <th class="py-3 px-5 sm:px-6">Seminarian</th>
                                        <th class="py-3 px-4 text-right">Average</th>
                                        <th class="py-3 px-5 sm:px-6 text-right">Rating</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (count($top) === 0) { ?>
                                        <tr><td colspan="3" class="text-center py-10 px-4 text-[#8B7168] font-medium text-xs">No evaluations recorded yet.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($top as $t) {
                                        $name = trim((string)($t['full_name'] ?? ''));
                                        if ($name === '') $name = (string)($t['username'] ?? '');
                                    ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-3.5 px-5 sm:px-6 font-bold text-[#59443F]"><?php echo htmlspecialchars($name); ?></td>
                                            <td class="py-3.5 px-4 text-right font-black text-[#59443F]"><?php echo htmlspecialchars(number_format((float)$t['average_score'], 2)); ?></td>
                                            <td class="py-3.5 px-5 sm:px-6 text-right">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                                    <?php echo htmlspecialchars((string)$t['rating_label']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
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
                                    <p class="text-xs text-[#8B7168] font-medium">Latest evaluated and posted student forms</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2.5 self-start sm:self-center flex-wrap sm:flex-nowrap">
                                <div class="relative w-full sm:w-48">
                                    <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-[#8B7168]"></i>
                                    <input type="text" id="afeTableSearch" onkeyup="filterAfeTable(this.value)" class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Filter records...">
                                </div>
                                <a href="afe_evaluation_form.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-plus text-xs"></i> New Evaluation
                                </a>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="afeRecentTable" class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                                        <th class="py-3 px-5 sm:px-6">Seminarian</th>
                                        <th class="py-3 px-4 text-right">GPA</th>
                                        <th class="py-3 px-4 text-right">Average</th>
                                        <th class="py-3 px-5 sm:px-6 text-right">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (count($recent) === 0) { ?>
                                        <tr><td colspan="4" class="text-center py-10 px-4 text-[#8B7168] font-medium text-xs">No evaluations recorded yet.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($recent as $r) {
                                        $name = trim((string)($r['full_name'] ?? ''));
                                        if ($name === '') $name = (string)($r['username'] ?? '');
                                    ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-3.5 px-5 sm:px-6">
                                                <a href="afe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>" class="font-bold text-[#59443F] hover:text-[#8B7168] transition">
                                                    <?php echo htmlspecialchars($name); ?>
                                                </a>
                                            </td>
                                            <td class="py-3.5 px-4 text-right font-semibold text-[#8B7168]"><?php echo $r['gpa'] === null ? '—' : htmlspecialchars(number_format((float)$r['gpa'], 2)); ?></td>
                                            <td class="py-3.5 px-4 text-right font-black text-[#59443F]"><?php echo $r['average_score'] === null ? '—' : htmlspecialchars(number_format((float)$r['average_score'], 2)); ?></td>
                                            <td class="py-3.5 px-5 sm:px-6 text-right text-[#8B7168] text-[11px]"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
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
                                    <h2 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">Access Requests</h2>
                                    <p class="text-[10px] text-[#8B7168] font-medium">Evaluation access requests</p>
                                </div>
                            </div>
                            <a href="afe_seminarians.php" class="inline-flex items-center gap-1 px-3 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[11px] font-bold border border-[#E8C7C5]/60 transition shadow-2xs">
                                Manage All
                            </a>
                        </div>
                        <div class="p-4 sm:p-5">
                            <?php if ($access_success) { ?>
                                <div class="mb-3 p-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center gap-2">
                                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                                    <span><?php echo htmlspecialchars($access_success); ?></span>
                                </div>
                            <?php } ?>
                            <?php if ($access_error) { ?>
                                <div class="mb-3 p-3 rounded-2xl bg-[#E8C7C5]/40 border border-[#D6A6A6] text-[#59443F] text-xs font-semibold flex items-center gap-2">
                                    <i class="fa-solid fa-triangle-exclamation text-rose-600"></i>
                                    <span><?php echo htmlspecialchars($access_error); ?></span>
                                </div>
                            <?php } ?>
                            <?php if (count($access_requests) === 0) { ?>
                                <div class="text-center py-6 text-[#8B7168] text-xs font-medium">
                                    <i class="fa-solid fa-inbox text-xl mb-1.5 block text-[#D6A6A6]"></i>
                                    No pending access requests.
                                </div>
                            <?php } else { ?>
                                <div class="space-y-3">
                                    <?php foreach ($access_requests as $r) {
                                        $name = trim((string)($r['full_name'] ?? ''));
                                        if ($name === '') $name = (string)($r['username'] ?? '');
                                    ?>
                                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/50 flex flex-col gap-2">
                                            <div class="flex items-center justify-between gap-2">
                                                <strong class="text-xs text-[#59443F] font-extrabold truncate"><?php echo htmlspecialchars($name); ?></strong>
                                                <span class="text-[10px] text-[#8B7168] shrink-0"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['requested_at']))); ?></span>
                                            </div>
                                            <?php if (!empty($r['academic_year']) || !empty($r['semester'])) { ?>
                                                <div class="text-[10px] font-bold text-amber-800 bg-amber-50 border border-amber-200/80 px-2 py-0.5 rounded-full self-start">
                                                    AY <?php echo htmlspecialchars((string)($r['academic_year'] ?? '')); ?> &middot; <?php echo htmlspecialchars((string)($r['semester'] ?? '')); ?> Sem
                                                </div>
                                            <?php } ?>
                                            <p class="text-xs text-[#8B7168] leading-snug">
                                                <?php echo htmlspecialchars((string)($r['message'] ?? '')); ?>
                                            </p>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
        </div>

        <script defer src="<?php echo htmlspecialchars(capstone_url('assets/academic_calendar.js')); ?>?v=<?php echo file_exists(__DIR__ . '/../assets/academic_calendar.js') ? filemtime(__DIR__ . '/../assets/academic_calendar.js') : '1'; ?>"></script>
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

        function filterAfeTable(q) {
            q = (q || '').toLowerCase();
            document.querySelectorAll('#afeRecentTable tbody tr').forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        }
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
