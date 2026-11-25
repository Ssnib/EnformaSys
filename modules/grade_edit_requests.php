<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'Rector', 'AcademicFormator']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';

$role = (string)($_SESSION['role'] ?? '');
$current_user_id = (int)($_SESSION['user'] ?? 0);
$can_resolve = in_array($role, ['Registrar', 'Dean', 'Rector'], true);

$success_msg = '';
$error_msg = '';

// Handle POST actions: Approve or Reject request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Security token validation failed. Please refresh and try again.';
    } elseif (!$can_resolve) {
        $error_msg = 'Unauthorized: Only the Registrar, Dean, or Rector can resolve grade edit requests.';
    } else {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $action = (string)$_POST['action'];

        if ($req_id <= 0) {
            $error_msg = 'Invalid request identifier.';
        } else {
            $stmt_find = $conn->prepare("SELECT * FROM grade_edit_requests WHERE id = ? LIMIT 1");
            $stmt_find->bind_param('i', $req_id);
            $stmt_find->execute();
            $found_req = $stmt_find->get_result()->fetch_assoc();

            if (!$found_req) {
                $error_msg = 'Grade edit request not found.';
            } elseif ($found_req['status'] !== 'Pending') {
                $error_msg = 'This request has already been processed (' . htmlspecialchars($found_req['status']) . ').';
            } else {
                $decision = ($action === 'approve_request') ? 'Approved' : 'Rejected';
                $r_stu = (int)$found_req['student_id'];
                $r_sub = (int)$found_req['subject_id'];
                $r_ay = (string)$found_req['academic_year'];
                $r_sem = (string)$found_req['semester'];

                $conn->begin_transaction();
                try {
                    $stmt_up = $conn->prepare("UPDATE grade_edit_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $stmt_up->bind_param('sii', $decision, $current_user_id, $req_id);
                    $stmt_up->execute();

                    if ($decision === 'Approved') {
                        // Unlock grades in student_grades table
                        if ($r_sub > 0) {
                            $stmt_unl = $conn->prepare("UPDATE student_grades SET is_locked = 0, updated_at = NOW() WHERE student_id = ? AND subject_id = ? AND academic_year = ? AND semester = ?");
                            $stmt_unl->bind_param('iiss', $r_stu, $r_sub, $r_ay, $r_sem);
                        } else {
                            $stmt_unl = $conn->prepare("UPDATE student_grades SET is_locked = 0, updated_at = NOW() WHERE student_id = ? AND academic_year = ? AND semester = ?");
                            $stmt_unl->bind_param('iss', $r_stu, $r_ay, $r_sem);
                        }
                        $stmt_unl->execute();
                    }

                    $conn->commit();
                    $success_msg = ($decision === 'Approved')
                        ? "Grade edit request #{$req_id} approved. Grades have been successfully unlocked for editing."
                        : "Grade edit request #{$req_id} was rejected.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = 'Error resolving request: ' . $e->getMessage();
                }
            }
        }
    }
}

// Filter parameters
$status_filter = trim((string)($_GET['status'] ?? 'all'));
$allowed_statuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array(strtolower($status_filter), $allowed_statuses, true)) {
    $status_filter = 'all';
}
$search_query = trim((string)($_GET['q'] ?? ''));

// Count requests by status for summary cards and badges
$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$res_c = $conn->query("SELECT status, COUNT(*) as c FROM grade_edit_requests GROUP BY status");
if ($res_c) {
    while ($rc = $res_c->fetch_assoc()) {
        $st = strtolower($rc['status']);
        $cnt = (int)$rc['c'];
        if (isset($counts[$st])) {
            $counts[$st] = $cnt;
        }
        $counts['all'] += $cnt;
    }
}

// Build query for requests list
$where_clauses = ["1=1"];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_clauses[] = "LOWER(ger.status) = ?";
    $types .= 's';
    $params[] = strtolower($status_filter);
}

if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $where_clauses[] = "(
        up_stu.full_name LIKE ? OR
        stu.full_name LIKE ? OR
        stu.student_no LIKE ? OR
        up_stu.student_id LIKE ? OR
        u_stu.username LIKE ? OR
        sub.subject_code LIKE ? OR
        sub.subject_name LIKE ? OR
        up_req.full_name LIKE ? OR
        u_req.username LIKE ? OR
        ger.reason LIKE ?
    )";
    $types .= 'ssssssssss';
    for ($i = 0; $i < 10; $i++) {
        $params[] = $like;
    }
}

$where_sql = implode(' AND ', $where_clauses);

$sql = "SELECT ger.id, ger.student_id, ger.subject_id, ger.academic_year, ger.semester, ger.reason, ger.status,
               ger.created_at, ger.reviewed_at,
               COALESCE(NULLIF(TRIM(up_stu.full_name),''), NULLIF(TRIM(stu.full_name),''), u_stu.username, CONCAT('Student #', s.id)) AS student_name,
               COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up_stu.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no,
               s.program, s.year_level,
               sub.subject_code, sub.subject_name, sub.units,
               COALESCE(NULLIF(TRIM(up_req.full_name),''), u_req.username, 'Formator') AS requester_name,
               u_req.role AS requester_role,
               COALESCE(NULLIF(TRIM(up_rev.full_name),''), u_rev.username) AS reviewer_name
        FROM grade_edit_requests ger
        JOIN seminarians s ON s.id = ger.student_id
        JOIN users u_stu ON u_stu.id = s.user_id
        LEFT JOIN students stu ON stu.user_id = u_stu.id
        LEFT JOIN user_profiles up_stu ON up_stu.user_id = u_stu.id
        LEFT JOIN subjects sub ON sub.subject_id = ger.subject_id
        JOIN users u_req ON u_req.id = ger.requested_by
        LEFT JOIN user_profiles up_req ON up_req.user_id = u_req.id
        LEFT JOIN users u_rev ON u_rev.id = ger.reviewed_by
        LEFT JOIN user_profiles up_rev ON up_rev.user_id = u_rev.id
        WHERE {$where_sql}
        ORDER BY FIELD(ger.status, 'Pending', 'Approved', 'Rejected'), ger.created_at DESC, ger.id DESC";

$stmt_list = $conn->prepare($sql);
if ($types !== '') {
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind);
}
$stmt_list->execute();
$requests_result = $stmt_list->get_result();
$requests = [];
while ($row = $requests_result->fetch_assoc()) {
    $requests[] = $row;
}

$pageTitle = 'Grade Edit Requests';
$activeNav = 'grade_edit_requests';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>

<div class="content-body w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- Top Breadcrumb / Title Bar -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-bold text-[#8B7168] mb-1">
                <span>Academics</span>
                <i class="fa-solid fa-chevron-right text-[10px] opacity-70"></i>
                <span class="text-[#59443F]">Grade Edit Requests</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#59443F] tracking-tight flex items-center gap-2.5">
                <i class="fa-solid fa-file-pen text-[#59443F]/80"></i>
                Grade Edit Requests
            </h1>
            <p class="text-xs sm:text-sm text-[#8B7168] font-medium mt-0.5">
                Review and approve/reject locked grade unlocking requests submitted by Academic Formators.
            </p>
        </div>
    </div>

    <!-- Flash Alerts -->
    <?php if ($success_msg !== ''): ?>
        <div class="flex items-center gap-3 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold shadow-xs">
            <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
            <span><?php echo htmlspecialchars($success_msg); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error_msg !== ''): ?>
        <div class="flex items-center gap-3 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold shadow-xs">
            <i class="fa-solid fa-circle-exclamation text-rose-600 text-base"></i>
            <span><?php echo htmlspecialchars($error_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary Statistics Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- All Requests -->
        <a href="?status=all" class="block p-4 sm:p-5 rounded-3xl bg-white border border-[#D6A6A6]/40 shadow-lg hover:shadow-xl transition-all <?php echo $status_filter === 'all' ? 'ring-2 ring-[#59443F]' : ''; ?>">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-[#8B7168]">Total Requests</span>
                <span class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-sm shadow-2xs">
                    <i class="fa-solid fa-folder-open"></i>
                </span>
            </div>
            <div class="mt-3 text-2xl sm:text-3xl font-extrabold text-[#59443F]">
                <?php echo $counts['all']; ?>
            </div>
            <div class="text-[11px] font-semibold text-[#8B7168] mt-1">
                All submitted requests
            </div>
        </a>

        <!-- Pending Review -->
        <a href="?status=pending" class="block p-4 sm:p-5 rounded-3xl bg-white border border-rose-200 shadow-lg hover:shadow-xl transition-all <?php echo $status_filter === 'pending' ? 'ring-2 ring-rose-500' : ''; ?>">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-rose-700 flex items-center gap-1.5">
                    <?php if ($counts['pending'] > 0): ?>
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                        </span>
                    <?php endif; ?>
                    Pending Review
                </span>
                <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-sm shadow-2xs">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </span>
            </div>
            <div class="mt-3 text-2xl sm:text-3xl font-extrabold text-rose-700">
                <?php echo $counts['pending']; ?>
            </div>
            <div class="text-[11px] font-semibold text-rose-600/80 mt-1">
                Awaiting your approval
            </div>
        </a>

        <!-- Approved -->
        <a href="?status=approved" class="block p-4 sm:p-5 rounded-3xl bg-white border border-emerald-200 shadow-lg hover:shadow-xl transition-all <?php echo $status_filter === 'approved' ? 'ring-2 ring-emerald-500' : ''; ?>">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-emerald-700">Approved</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm shadow-2xs">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
            </div>
            <div class="mt-3 text-2xl sm:text-3xl font-extrabold text-emerald-700">
                <?php echo $counts['approved']; ?>
            </div>
            <div class="text-[11px] font-semibold text-emerald-600/80 mt-1">
                Grades unlocked
            </div>
        </a>

        <!-- Rejected -->
        <a href="?status=rejected" class="block p-4 sm:p-5 rounded-3xl bg-white border border-slate-200 shadow-lg hover:shadow-xl transition-all <?php echo $status_filter === 'rejected' ? 'ring-2 ring-slate-500' : ''; ?>">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-600">Rejected</span>
                <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center text-sm shadow-2xs">
                    <i class="fa-solid fa-circle-xmark"></i>
                </span>
            </div>
            <div class="mt-3 text-2xl sm:text-3xl font-extrabold text-slate-700">
                <?php echo $counts['rejected']; ?>
            </div>
            <div class="text-[11px] font-semibold text-slate-500 mt-1">
                Declined requests
            </div>
        </a>
    </div>

    <!-- Main Container: Filter Bar & Requests Table -->
    <div class="rounded-3xl bg-white border border-[#D6A6A6]/40 shadow-lg overflow-hidden">
        
        <!-- Toolbar: Tabs & Search -->
        <div class="p-4 sm:p-5 border-b border-[#F8EEEE] flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 bg-[#FAF5F4]/60">
            <!-- Filter Tabs -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
                <a href="?status=all<?php echo $search_query !== '' ? '&q=' . urlencode($search_query) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-all <?php echo $status_filter === 'all' ? 'bg-[#59443F] text-white shadow-xs' : 'bg-white text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] border border-[#D6A6A6]/40'; ?>">
                    All (<?php echo $counts['all']; ?>)
                </a>
                <a href="?status=pending<?php echo $search_query !== '' ? '&q=' . urlencode($search_query) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-all <?php echo $status_filter === 'pending' ? 'bg-rose-500 text-white shadow-xs' : 'bg-white text-rose-700 hover:bg-rose-50 border border-rose-200'; ?>">
                    Pending (<?php echo $counts['pending']; ?>)
                </a>
                <a href="?status=approved<?php echo $search_query !== '' ? '&q=' . urlencode($search_query) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-all <?php echo $status_filter === 'approved' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-white text-emerald-700 hover:bg-emerald-50 border border-emerald-200'; ?>">
                    Approved (<?php echo $counts['approved']; ?>)
                </a>
                <a href="?status=rejected<?php echo $search_query !== '' ? '&q=' . urlencode($search_query) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition-all <?php echo $status_filter === 'rejected' ? 'bg-slate-700 text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200'; ?>">
                    Rejected (<?php echo $counts['rejected']; ?>)
                </a>
            </div>

            <!-- Search Input -->
            <form method="GET" action="" class="flex items-center gap-2">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="relative w-full md:w-72">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#8B7168]"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>"
                           placeholder="Search student, subject, or reason..."
                           class="w-full pl-8 pr-3 py-1.5 text-xs font-medium rounded-xl border border-[#D6A6A6]/60 bg-white text-[#59443F] placeholder-[#8B7168]/60 focus:outline-none focus:ring-2 focus:ring-[#59443F]/20">
                </div>
                <?php if ($search_query !== ''): ?>
                    <a href="?status=<?php echo htmlspecialchars($status_filter); ?>" class="px-2.5 py-1.5 text-xs font-bold text-[#8B7168] hover:text-[#59443F]" title="Clear search">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
                <button type="submit" class="px-3 py-1.5 text-xs font-bold rounded-xl bg-[#59443F] text-white hover:bg-[#43322E] shadow-2xs transition-all">
                    Search
                </button>
            </form>
        </div>

        <!-- Requests Table -->
        <?php if (empty($requests)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-[#F8EEEE] text-[#8B7168] mx-auto flex items-center justify-center text-2xl mb-3 shadow-2xs">
                    <i class="fa-solid fa-inbox"></i>
                </div>
                <h3 class="text-base font-bold text-[#59443F]">No Grade Edit Requests Found</h3>
                <p class="text-xs text-[#8B7168] mt-1 max-w-sm mx-auto">
                    <?php if ($search_query !== ''): ?>
                        No requests matched your search query "<strong><?php echo htmlspecialchars($search_query); ?></strong>". Try clearing your search filters.
                    <?php else: ?>
                        There are currently no requests under the <strong><?php echo htmlspecialchars(ucfirst($status_filter)); ?></strong> category.
                    <?php endif; ?>
                </p>
                <?php if ($search_query !== '' || $status_filter !== 'all'): ?>
                    <a href="?status=all" class="inline-flex items-center gap-1.5 mt-4 px-4 py-2 text-xs font-bold rounded-xl bg-[#59443F] text-white hover:bg-[#43322E] shadow-2xs">
                        View All Requests
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-[#59443F]">
                    <thead class="bg-[#FAF5F4] text-[#8B7168] font-bold uppercase text-[10px] tracking-wider border-b border-[#F8EEEE]">
                        <tr>
                            <th class="py-3 px-4">Req # & Date</th>
                            <th class="py-3 px-4">Requested By</th>
                            <th class="py-3 px-4">Student</th>
                            <th class="py-3 px-4">Subject Scope</th>
                            <th class="py-3 px-4">Term</th>
                            <th class="py-3 px-4">Reason / Notes</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <?php if ($can_resolve): ?>
                                <th class="py-3 px-4 text-right">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F8EEEE]">
                        <?php foreach ($requests as $req): 
                            $status = (string)$req['status'];
                            $is_pending = ($status === 'Pending');
                            $is_approved = ($status === 'Approved');
                            $is_rejected = ($status === 'Rejected');
                            $is_whole_sem = empty($req['subject_id']) || (int)$req['subject_id'] === 0;
                        ?>
                            <tr class="hover:bg-[#FAF5F4]/40 transition-colors">
                                <!-- Req ID & Date -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-extrabold text-[#59443F]">
                                        #<?php echo (int)$req['id']; ?>
                                    </div>
                                    <div class="text-[11px] text-[#8B7168]">
                                        <?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?>
                                    </div>
                                </td>

                                <!-- Formator / Requester -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-[#59443F]">
                                        <?php echo htmlspecialchars($req['requester_name']); ?>
                                    </div>
                                    <span class="inline-block px-2 py-0.5 text-[10px] font-semibold rounded-md bg-[#F8EEEE] text-[#59443F] border border-[#D6A6A6]/40">
                                        <?php echo htmlspecialchars($req['requester_role']); ?>
                                    </span>
                                </td>

                                <!-- Student Details -->
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-[#59443F]">
                                        <?php echo htmlspecialchars($req['student_name']); ?>
                                    </div>
                                    <div class="text-[11px] text-[#8B7168] flex items-center gap-1.5 flex-wrap">
                                        <span class="font-mono"><?php echo htmlspecialchars($req['student_no']); ?></span>
                                        <span>&bull;</span>
                                        <span><?php echo htmlspecialchars($req['year_level'] ?: 'Seminarian'); ?></span>
                                    </div>
                                    <a href="<?php echo htmlspecialchars(capstone_url('modules/afms_student_record.php?id=' . (int)$req['student_id'])); ?>" target="_blank" class="inline-flex items-center gap-1 text-[10px] font-bold text-[#8B7168] hover:text-[#59443F] underline mt-0.5">
                                        View Student Profile <i class="fa-solid fa-arrow-up-right-from-square text-[8px]"></i>
                                    </a>
                                </td>

                                <!-- Subject Scope -->
                                <td class="py-3.5 px-4">
                                    <?php if ($is_whole_sem): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 font-bold text-[11px]">
                                            <i class="fa-solid fa-layer-group text-[10px]"></i> Whole Semester Grades
                                        </span>
                                    <?php else: ?>
                                        <div class="font-bold text-[#59443F]">
                                            <?php echo htmlspecialchars($req['subject_code']); ?>
                                        </div>
                                        <div class="text-[11px] text-[#8B7168] truncate max-w-xs" title="<?php echo htmlspecialchars($req['subject_name']); ?>">
                                            <?php echo htmlspecialchars($req['subject_name']); ?>
                                        </div>
                                        <?php if (!empty($req['units'])): ?>
                                            <div class="text-[10px] text-[#8B7168] font-semibold">
                                                <?php echo (float)$req['units']; ?> units
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Term -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-[#59443F]">
                                        AY <?php echo htmlspecialchars($req['academic_year']); ?>
                                    </div>
                                    <div class="text-[11px] text-[#8B7168]">
                                        <?php echo htmlspecialchars($req['semester']); ?> Semester
                                    </div>
                                </td>

                                <!-- Reason -->
                                <td class="py-3.5 px-4">
                                    <div class="p-2.5 rounded-xl bg-[#F8EEEE]/80 border border-[#D6A6A6]/30 text-xs text-[#59443F] font-medium max-w-xs sm:max-w-sm whitespace-pre-wrap">
                                        <i class="fa-solid fa-quote-left text-[10px] text-[#8B7168] mr-1"></i><?php echo htmlspecialchars($req['reason'] ?: 'No explanation provided.'); ?><i class="fa-solid fa-quote-right text-[10px] text-[#8B7168] ml-1"></i>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <?php if ($is_pending): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs">
                                            <i class="fa-solid fa-clock-rotate-left text-[10px]"></i> Pending
                                        </span>
                                    <?php elseif ($is_approved): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">
                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Approved
                                        </span>
                                        <?php if (!empty($req['reviewer_name'])): ?>
                                            <div class="text-[10px] text-[#8B7168] mt-1">
                                                by <?php echo htmlspecialchars($req['reviewer_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs">
                                            <i class="fa-solid fa-circle-xmark text-[10px]"></i> Rejected
                                        </span>
                                        <?php if (!empty($req['reviewer_name'])): ?>
                                            <div class="text-[10px] text-[#8B7168] mt-1">
                                                by <?php echo htmlspecialchars($req['reviewer_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <?php if ($can_resolve): ?>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        <?php if ($is_pending): ?>
                                            <div class="inline-flex items-center gap-1.5 justify-end">
                                                <!-- Approve Form -->
                                                <form method="POST" action="" data-confirm="Approve grade edit request #<?php echo (int)$req['id']; ?>? This will unlock the grades for editing." data-confirm-title="Approve Grade Edit Request" data-confirm-text="Approve & Unlock" data-confirm-variant="primary" data-confirm-loading="Approving..." class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="approve_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center gap-1 shadow-2xs transition-all hover:scale-[1.02]">
                                                        <i class="fa-solid fa-check text-[10px]"></i>
                                                        <span>Approve</span>
                                                    </button>
                                                </form>

                                                <!-- Reject Form -->
                                                <form method="POST" action="" data-confirm="Reject grade edit request #<?php echo (int)$req['id']; ?>? The grades will remain locked." data-confirm-title="Reject Grade Edit Request" data-confirm-text="Reject" data-confirm-variant="danger" data-confirm-loading="Rejecting..." class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="reject_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-white hover:bg-rose-50 text-rose-700 border border-rose-300 font-bold text-xs flex items-center gap-1 shadow-2xs transition-all hover:scale-[1.02]">
                                                        <i class="fa-solid fa-xmark text-[10px]"></i>
                                                        <span>Reject</span>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[11px] text-[#8B7168] italic font-semibold">Resolved</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
