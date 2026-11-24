<?php
include '../includes/session_fix.php';
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../index.php');
    exit;
}
include '../includes/db.php';

$user_id = (int)$_SESSION['user'];

$stmt = $conn->prepare('SELECT id FROM seminarians WHERE user_id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$seminarian = $stmt->get_result()->fetch_assoc();
$seminarian_id = (int)($seminarian['id'] ?? 0);

// Fetch newly sent/approved SOA notifications before marking them as read (keeps sidebar badge synced)
$newly_sent_soas = [];
if ($seminarian_id > 0) {
    $stmt_new_soa = @$conn->prepare(
        "SELECT id, academic_year, semester, status, processed_at, requested_at
         FROM cashier_soa_requests
         WHERE seminarian_id = ?
           AND status IN ('Approved', 'Completed', 'Sent')
           AND is_read = 0
         ORDER BY COALESCE(processed_at, requested_at) DESC, id DESC"
    );
    if ($stmt_new_soa) {
        $stmt_new_soa->bind_param('i', $seminarian_id);
        if ($stmt_new_soa->execute()) {
            $res_new_soa = $stmt_new_soa->get_result();
            while ($res_new_soa && ($nsr = $res_new_soa->fetch_assoc())) {
                $newly_sent_soas[] = $nsr;
            }
        }
        $stmt_new_soa->close();
    }
    if (!empty($newly_sent_soas)) {
        $stmt_mark_soa = @$conn->prepare("UPDATE cashier_soa_requests SET is_read = 1 WHERE seminarian_id = ? AND status IN ('Approved', 'Completed', 'Sent') AND is_read = 0");
        if ($stmt_mark_soa) {
            $stmt_mark_soa->bind_param('i', $seminarian_id);
            $stmt_mark_soa->execute();
            $stmt_mark_soa->close();
        }
    }
}

$current_ay = get_current_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

// Resolve active term (Academic Year & Semester) from session/DB default, then unlocked enrollment
$active_ay = get_current_academic_year();
$active_sem = function_exists('get_active_semester') ? enformasys_normalize_semester(get_active_semester()) : '1st';
if (!in_array($active_sem, ['1st', '2nd'], true)) {
    $active_sem = '1st';
}
if ($seminarian_id > 0) {
    $stmt_enr = $conn->prepare("SELECT academic_year, semester FROM enrollments WHERE student_id = ? AND academic_year = ? ORDER BY is_locked ASC, (semester = ?) DESC, FIELD(semester, '1st', '2nd') ASC, id DESC LIMIT 1");
    if ($stmt_enr) {
        $stmt_enr->bind_param('iss', $seminarian_id, $current_ay, $active_sem);
        $stmt_enr->execute();
        $erow = $stmt_enr->get_result()->fetch_assoc();
        if ($erow) {
            if (!empty($erow['academic_year']) && in_array((string)$erow['academic_year'], $ay_options, true)) {
                $active_ay = (string)$erow['academic_year'];
            }
            $erow_sem = enformasys_normalize_semester((string)($erow['semester'] ?? ''));
            if (in_array($erow_sem, ['1st', '2nd'], true)) {
                $active_sem = $erow_sem;
            }
        }
    }
}

$selected_ay = trim((string)($_POST['academic_year'] ?? $_GET['academic_year'] ?? $active_ay));
if (!in_array($selected_ay, $ay_options, true)) {
    $selected_ay = $active_ay;
}
$selected_sem = enformasys_normalize_semester((string)($_POST['semester'] ?? $_GET['semester'] ?? $active_sem));
if (!in_array($selected_sem, ['1st', '2nd'], true)) {
    $selected_sem = $active_sem;
}

$req_success = null;
$req_info = null;

if (isset($_POST['request_soa']) && $seminarian_id > 0) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $ay = $selected_ay;
    $sem = $selected_sem;

    $stmt_ex = $conn->prepare(
        "SELECT status FROM cashier_soa_requests
         WHERE seminarian_id = ? AND academic_year = ? AND semester = ?
           AND status IN ('Pending', 'Processing', 'Sent', 'Approved', 'Completed')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt_ex->bind_param('iss', $seminarian_id, $ay, $sem);
    $stmt_ex->execute();
    $ex_row = $stmt_ex->get_result()->fetch_assoc();
    if ($ex_row) {
        $ex_status = (string)($ex_row['status'] ?? '');
        if (in_array($ex_status, ['Sent', 'Approved', 'Completed'], true)) {
            $req_info = 'Your Official Statement of Account for AY ' . $ay . ' (' . ($sem === '2nd' ? 'Second' : 'First') . ' Semester) is already approved and available below.';
        } else {
            $req_info = 'Your SOA request for AY ' . $ay . ' (' . ($sem === '2nd' ? 'Second' : 'First') . ' Semester) is already pending Cashier approval.';
        }
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO cashier_soa_requests (seminarian_id, academic_year, semester, status, is_read, requested_by) VALUES (?, ?, ?, 'Pending', 0, ?)");
        $stmt_ins->bind_param('issi', $seminarian_id, $ay, $sem, $user_id);
        $stmt_ins->execute();
        $req_success = 'SOA request submitted to the Cashier Office. You will be notified once it is approved and ready to view.';
    }
}

// Fetch ALL SOA requests across all terms (ordered most recent term to oldest)
$term_requests_map = [];
$approved_soas = [];
$pending_soas = [];
$approved_years = [];

if ($seminarian_id > 0) {
    $stmt_all = $conn->prepare(
        "SELECT id, academic_year, semester, status, requested_at, processed_at
         FROM cashier_soa_requests
         WHERE seminarian_id = ?
         ORDER BY academic_year DESC, FIELD(semester, '2nd', '1st') ASC, COALESCE(processed_at, requested_at) DESC, id DESC"
    );
    if ($stmt_all) {
        $stmt_all->bind_param('i', $seminarian_id);
        $stmt_all->execute();
        $res_all = $stmt_all->get_result();
        while ($res_all && ($row = $res_all->fetch_assoc())) {
            $key = (string)$row['academic_year'] . '|' . (string)$row['semester'];
            if (!isset($term_requests_map[$key])) {
                $term_requests_map[$key] = [
                    'id' => (int)$row['id'],
                    'academic_year' => (string)$row['academic_year'],
                    'semester' => (string)$row['semester'],
                    'status' => (string)$row['status'],
                    'requested_at' => (string)($row['requested_at'] ?? ''),
                    'processed_at' => (string)($row['processed_at'] ?? ''),
                ];
            }
        }
    }

    foreach ($term_requests_map as $tr) {
        if (in_array($tr['status'], ['Sent', 'Approved', 'Completed'], true)) {
            $approved_soas[] = $tr;
            if ($tr['academic_year'] !== '' && !in_array($tr['academic_year'], $approved_years, true)) {
                $approved_years[] = $tr['academic_year'];
            }
        } elseif (in_array($tr['status'], ['Pending', 'Processing'], true)) {
            $pending_soas[] = $tr;
        }
    }
}

// Check if the logged-in seminarian already has ANY request (Pending, Processing, Sent, Approved, Completed) for the current active term
$active_term_key = $active_ay . '|' . $active_sem;
$active_term_req = $term_requests_map[$active_term_key] ?? null;
$active_term_status = $active_term_req ? (string)$active_term_req['status'] : '';
$has_active_term_request = ($active_term_req !== null && in_array($active_term_status, ['Pending', 'Processing', 'Sent', 'Approved', 'Completed'], true));
$is_active_term_pending = in_array($active_term_status, ['Pending', 'Processing'], true);
$active_sem_label = $active_sem === '2nd' ? 'Second Semester' : 'First Semester';

$pageTitle = 'Ledger';
$activeNav = 'my_balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <!-- Top Header -->
            <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-white text-[#59443F] border border-[#E8C7C5]/60 shadow-xs mb-2">
                        <span class="w-2 h-2 rounded-full bg-[#D6A6A6] animate-pulse"></span>
                        <span>Active Term: <strong class="font-extrabold text-[#59443F]">AY <?php echo htmlspecialchars($active_ay); ?> • <?php echo htmlspecialchars($active_sem_label); ?></strong></span>
                    </div>
                    <h1 class="font-heading font-black text-2xl sm:text-3xl text-[#59443F] tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-receipt text-[#D6A6A6]"></i>
                        <span>Ledger</span>
                    </h1>
                    <p class="text-xs sm:text-sm font-medium text-[#8B7168] mt-1">
                        Request your Official Statement of Account (SOA) and view approved statements across terms.
                    </p>
                </div>
            </div>

            <?php if ($seminarian_id <= 0) { ?>
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-8 sm:p-12 shadow-soft text-center max-w-xl mx-auto my-8">
                    <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-4 border border-amber-200/60 text-xl">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <h3 class="font-heading font-extrabold text-lg text-[#59443F] mb-1.5">No Seminarian Profile Linked</h3>
                    <p class="text-xs sm:text-sm text-[#8B7168] leading-relaxed">
                        Your user account is not currently linked to an active seminarian record. Please contact the Registrar or Cashier Office to configure your student profile.
                    </p>
                </div>
            <?php } else { ?>

            <!-- Newly Sent SOA Notification Banner (shown once upon unread Cashier approval) -->
            <?php if (!empty($newly_sent_soas)) {
                $latest_soa = $newly_sent_soas[0];
                $latest_ay = (string)($latest_soa['academic_year'] ?? $active_ay);
                $latest_sem_raw = (string)($latest_soa['semester'] ?? '1st');
                $latest_sem_label = $latest_sem_raw === '2nd' ? 'Second Semester' : 'First Semester';
                $student_soa_url = 'student_soa.php?academic_year=' . urlencode($latest_ay) . '&semester=' . urlencode($latest_sem_raw);
            ?>
                <div id="soaSentNotificationBanner" class="mb-6 p-4 sm:p-5 rounded-3xl bg-emerald-50/90 border border-emerald-200 shadow-soft flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start sm:items-center gap-3.5">
                        <div class="w-10 h-10 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 text-base shadow-xs">
                            <i class="fa-solid fa-envelope-open-text"></i>
                        </div>
                        <div>
                            <div class="text-xs font-extrabold uppercase tracking-wider text-emerald-800">Statement of Account Ready</div>
                            <div class="text-sm font-semibold text-emerald-950 mt-0.5">
                                Your Statement of Account request for AY <?php echo htmlspecialchars($latest_ay); ?> (<?php echo htmlspecialchars($latest_sem_label); ?>) has been approved and sent by the Cashier.
                                <?php if (count($newly_sent_soas) > 1): ?>
                                    <span class="text-xs font-bold text-emerald-700 ml-1">(+<?php echo count($newly_sent_soas) - 1; ?> more update<?php echo count($newly_sent_soas) - 1 > 1 ? 's' : ''; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a href="<?php echo htmlspecialchars($student_soa_url); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition">
                            <i class="fa-solid fa-eye"></i>
                            <span>View Official SOA</span>
                        </a>
                        <button type="button" onclick="document.getElementById('soaSentNotificationBanner')?.remove()" class="p-2 rounded-xl text-emerald-700 hover:bg-emerald-100 transition cursor-pointer" title="Dismiss notification">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            <?php } ?>

            <!-- Unapproved / Unavailable SOA Notice (if redirected cleanly) -->
            <?php if (isset($_GET['soa_unavailable'])) { ?>
                <div class="mb-6 p-4 sm:p-5 rounded-3xl bg-amber-50/90 border border-amber-200 shadow-soft flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shrink-0 text-base shadow-xs">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <div class="text-xs font-extrabold uppercase tracking-wider text-amber-900">Statement of Account Not Yet Available</div>
                        <div class="text-sm font-medium text-amber-900 mt-0.5">
                            Your requested Statement of Account is either still pending Cashier approval or does not exist for that term.
                        </div>
                    </div>
                </div>
            <?php } ?>

            <!-- Submission Confirmation / Info Alert -->
            <?php if ($req_success !== null) { ?>
                <div class="mb-6 p-4 sm:p-5 rounded-3xl bg-white border border-emerald-200/80 shadow-soft flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0 text-base border border-emerald-200/60">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-emerald-900">Statement Request Submitted</div>
                        <div class="text-sm font-medium text-emerald-800 mt-0.5"><?php echo htmlspecialchars($req_success); ?></div>
                    </div>
                </div>
            <?php } elseif ($req_info !== null) { ?>
                <div class="mb-6 p-4 sm:p-5 rounded-3xl bg-white border border-amber-200/80 shadow-soft flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 text-base border border-amber-200/60">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-amber-900">Request Status Notice</div>
                        <div class="text-sm font-medium text-amber-800 mt-0.5"><?php echo htmlspecialchars($req_info); ?></div>
                    </div>
                </div>
            <?php } ?>

            <!-- Compact Pending Status Indicator (Shown instead of Request Card when active term is Pending) -->
            <?php if ($has_active_term_request && $is_active_term_pending) { ?>
                <div id="pendingSoaStatusIndicator" class="mb-6 p-4 sm:p-5 rounded-3xl bg-amber-50/90 border border-amber-200 shadow-soft flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3.5">
                        <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 border border-amber-200 flex items-center justify-center shrink-0 text-base">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div>
                            <div class="text-xs font-extrabold uppercase tracking-wider text-amber-900">Pending Cashier Approval</div>
                            <p class="text-sm font-semibold text-amber-950 mt-0.5">
                                Your SOA request for AY <?php echo htmlspecialchars($active_ay); ?> (<?php echo htmlspecialchars($active_sem_label); ?>) is pending Cashier approval.
                            </p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 border border-amber-300 self-start sm:self-auto shrink-0">
                        <i class="fa-solid fa-clock text-[10px]"></i>
                        <span>Pending</span>
                    </span>
                </div>
            <?php } ?>

            <!-- Request Statement of Account (SOA) Card — Hidden Once Requested for Active Term -->
            <?php if (!$has_active_term_request) { ?>
                <div id="requestSoaCard" class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-6 sm:p-7 shadow-soft mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 mb-5 border-b border-[#E8C7C5]/30">
                        <div>
                            <h3 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] flex items-center gap-2.5">
                                <i class="fa-solid fa-file-lines text-[#D6A6A6]"></i>
                                <span>Request Statement of Account (SOA)</span>
                            </h3>
                            <p class="text-xs font-medium text-[#8B7168] mt-1">
                                Submit a formal request to the Cashier Office for your official Statement of Account for the active term.
                            </p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                            <i class="fa-solid fa-building-columns text-[10px] text-[#D6A6A6]"></i>
                            <span>Cashier Office</span>
                        </span>
                    </div>

                    <form method="post" id="soaRequestForm" class="grid grid-cols-1 sm:grid-cols-12 gap-4 items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="sm:col-span-5">
                            <label for="soaAcademicYear" class="block text-xs font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">Academic Year</label>
                            <select id="soaAcademicYear" name="academic_year" class="w-full px-4 py-2.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/70 text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition" required>
                                <?php foreach ($ay_options as $ay) { ?>
                                    <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $active_ay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="sm:col-span-4">
                            <label for="soaSemester" class="block text-xs font-bold uppercase tracking-wider text-[#8B7168] mb-1.5">Semester</label>
                            <select id="soaSemester" name="semester" class="w-full px-4 py-2.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/70 text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition" required>
                                <option value="1st" <?php echo $active_sem === '1st' ? 'selected' : ''; ?>>First Semester</option>
                                <option value="2nd" <?php echo $active_sem === '2nd' ? 'selected' : ''; ?>>Second Semester</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <button type="submit" name="request_soa" class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-[#D6A6A6] hover:bg-[#E8C7C5] text-[#59443F] text-sm font-bold shadow-soft hover:shadow-soft-lg hover:-translate-y-0.5 transition duration-200 cursor-pointer" onclick="return confirm('Submit SOA request to Cashier?');">
                                <i class="fa-solid fa-paper-plane text-xs"></i>
                                <span>Submit Request</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php } ?>

            <!-- Persistent Approved Statements / SOA History Card (All Terms, Filterable) -->
            <div id="approvedSoaPersistentCard" class="bg-white rounded-3xl border border-emerald-200/80 p-6 sm:p-7 shadow-soft mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 mb-5 border-b border-emerald-100">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center justify-center shrink-0 text-lg">
                            <i class="fa-solid fa-file-circle-check"></i>
                        </div>
                        <div>
                            <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase tracking-wider bg-emerald-100 text-emerald-800 mb-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                                <span>Official Statement of Account — Available</span>
                            </div>
                            <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F]">
                                Approved Statements / SOA History
                            </h2>
                        </div>
                    </div>

                    <?php if (!empty($approved_soas)) { ?>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <select id="historyYearFilter" aria-label="Filter by Academic Year" class="px-3.5 py-2 rounded-xl bg-[#F8EEEE] border border-[#E8C7C5]/70 text-xs font-bold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                <option value="all">All Years</option>
                                <?php foreach ($approved_years as $yr) { ?>
                                    <option value="<?php echo htmlspecialchars($yr); ?>">AY <?php echo htmlspecialchars($yr); ?></option>
                                <?php } ?>
                            </select>
                            <select id="historySemesterFilter" aria-label="Filter by Semester" class="px-3.5 py-2 rounded-xl bg-[#F8EEEE] border border-[#E8C7C5]/70 text-xs font-bold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                <option value="all">All Semesters</option>
                                <option value="1st">First Semester</option>
                                <option value="2nd">Second Semester</option>
                            </select>
                        </div>
                    <?php } ?>
                </div>

                <?php if (empty($approved_soas)) { ?>
                    <div class="py-8 px-4 text-center rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                        <i class="fa-solid fa-file-invoice text-2xl text-[#D6A6A6] mb-2"></i>
                        <p class="text-sm font-bold text-[#59443F]">No Approved Statements Yet</p>
                        <p class="text-xs font-medium text-[#8B7168] mt-1">
                            Once the Cashier Office approves and sends your Statement of Account request, it will remain available here across all terms.
                        </p>
                    </div>
                <?php } else { ?>
                    <div id="approvedSoaList" class="space-y-3">
                        <?php foreach ($approved_soas as $asoa) {
                            $a_ay = (string)$asoa['academic_year'];
                            $a_sem = (string)$asoa['semester'];
                            $a_sem_label = $a_sem === '2nd' ? 'Second Semester' : 'First Semester';
                            $a_url = 'student_soa.php?academic_year=' . urlencode($a_ay) . '&semester=' . urlencode($a_sem);
                            $a_date_raw = !empty($asoa['processed_at']) ? $asoa['processed_at'] : $asoa['requested_at'];
                            $a_date_fmt = $a_date_raw !== '' ? date('M d, Y', strtotime($a_date_raw)) : '—';
                        ?>
                            <div class="approved-soa-item p-4 sm:p-5 rounded-2xl bg-emerald-50/60 border border-emerald-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-4" data-ay="<?php echo htmlspecialchars($a_ay); ?>" data-sem="<?php echo htmlspecialchars($a_sem); ?>">
                                <div class="flex items-start sm:items-center gap-3.5">
                                    <div class="w-10 h-10 rounded-xl bg-white text-emerald-700 border border-emerald-200 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-file-lines"></i>
                                    </div>
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-heading font-extrabold text-sm sm:text-base text-[#59443F]">
                                                AY <?php echo htmlspecialchars($a_ay); ?> • <?php echo htmlspecialchars($a_sem_label); ?>
                                            </span>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-600 text-white">
                                                <i class="fa-solid fa-check text-[10px]"></i>
                                                <span><?php echo htmlspecialchars($asoa['status']); ?></span>
                                            </span>
                                        </div>
                                        <p class="text-xs font-medium text-[#8B7168] mt-1">
                                            Approved &amp; released on <?php echo htmlspecialchars($a_date_fmt); ?> — live-updated throughout the term.
                                        </p>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($a_url); ?>" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-bold shadow-xs transition shrink-0">
                                    <i class="fa-solid fa-eye"></i>
                                    <span>View Official SOA</span>
                                </a>
                            </div>
                        <?php } ?>
                    </div>
                    <div id="approvedSoaEmptyFilterMsg" class="hidden py-6 px-4 text-center rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40 text-xs sm:text-sm font-semibold text-[#8B7168]">
                        No approved statements match the selected Academic Year or Semester filter.
                    </div>
                <?php } ?>
            </div>

            <?php } ?>

    <script>
    (function () {
        const yearFilter = document.getElementById('historyYearFilter');
        const semFilter = document.getElementById('historySemesterFilter');
        const list = document.getElementById('approvedSoaList');
        const emptyMsg = document.getElementById('approvedSoaEmptyFilterMsg');
        if (!yearFilter || !semFilter || !list) return;

        function applyFilters() {
            const targetYear = yearFilter.value;
            const targetSem = semFilter.value;
            const items = list.querySelectorAll('.approved-soa-item');
            let visibleCount = 0;

            items.forEach(function (item) {
                const ay = item.getAttribute('data-ay') || '';
                const sem = item.getAttribute('data-sem') || '';
                const matchYear = (targetYear === 'all' || ay === targetYear);
                const matchSem = (targetSem === 'all' || sem === targetSem);
                if (matchYear && matchSem) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            if (emptyMsg) {
                emptyMsg.classList.toggle('hidden', visibleCount > 0);
            }
        }

        yearFilter.addEventListener('change', applyFilters);
        semFilter.addEventListener('change', applyFilters);
    })();
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
