<?php
require_once __DIR__ . '/../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lodging.php';
require_once __DIR__ . '/../includes/soa_helper.php';
global $conn;

$user_id = (int)$_SESSION['user'];

// Strictly resolve the logged-in student's profile from session (never trust ?student_id)
$stmt_stu = $conn->prepare(
    "SELECT s.id as seminarian_id, COALESCE(up.full_name, u.username) as full_name, up.student_id as student_no,
            s.program, s.year_level, s.formation_status
     FROM seminarians s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN user_profiles up ON up.user_id = u.id
     WHERE s.user_id = ? LIMIT 1"
);
$stmt_stu->bind_param('i', $user_id);
$stmt_stu->execute();
$student = $stmt_stu->get_result()->fetch_assoc();

if (!$student) {
    header('Location: student_balance.php');
    exit;
}

$seminarian_id = (int)$student['seminarian_id'];

// Fetch all approved / sent SOA requests belonging ONLY to this seminarian
$approved_requests = [];
$stmt_req = $conn->prepare(
    "SELECT id, academic_year, semester, status, processed_at, requested_at
     FROM cashier_soa_requests
     WHERE seminarian_id = ?
       AND status IN ('Sent', 'Approved', 'Completed')
     ORDER BY COALESCE(processed_at, requested_at) DESC, id DESC"
);
if ($stmt_req) {
    $stmt_req->bind_param('i', $seminarian_id);
    $stmt_req->execute();
    $res_req = $stmt_req->get_result();
    while ($res_req && ($rr = $res_req->fetch_assoc())) {
        $approved_requests[] = $rr;
    }
}

if (count($approved_requests) === 0) {
    header('Location: student_balance.php?soa_unavailable=1');
    exit;
}

$req_ay = trim((string)($_GET['academic_year'] ?? ''));
$req_sem = trim((string)($_GET['semester'] ?? ''));

$matched_request = null;
if ($req_ay !== '' && $req_sem !== '') {
    foreach ($approved_requests as $ar) {
        if ((string)$ar['academic_year'] === $req_ay && (string)$ar['semester'] === $req_sem) {
            $matched_request = $ar;
            break;
        }
    }
    if (!$matched_request) {
        // Requested term has not been approved/sent for this student
        header('Location: student_balance.php?soa_unavailable=1');
        exit;
    }
} else {
    $matched_request = $approved_requests[0];
}

$academic_year = (string)$matched_request['academic_year'];
$semester = (string)$matched_request['semester'];

// Mark any unread SOA notification for this term as read
$stmt_read = $conn->prepare(
    "UPDATE cashier_soa_requests
     SET is_read = 1
     WHERE seminarian_id = ? AND academic_year = ? AND semester = ? AND status IN ('Sent', 'Approved', 'Completed') AND is_read = 0"
);
if ($stmt_read) {
    $stmt_read->bind_param('iss', $seminarian_id, $academic_year, $semester);
    $stmt_read->execute();
}

$soa = soa_load_statement_data($conn, $student, $academic_year, $semester, [
    'schedule_id' => 0,
    'sync_invoice' => false,
    'created_by' => $user_id,
]);

$pageTitle = 'Official Statement of Account';
$activeNav = 'my_balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
soa_render_styles();
?>
        <div class="content-body">
            <!-- Top Action Bar (Hidden in Print) -->
            <div class="no-print print:hidden flex flex-wrap items-center justify-between gap-3 mb-4 bg-white px-5 py-3.5 rounded-2xl border border-[#E8C7C5]/60 shadow-soft">
                <div class="flex flex-wrap items-center gap-3">
                    <a href="student_balance.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5]/60 text-[#59443F] text-xs font-bold transition">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Back to Ledger</span>
                    </a>
                    <?php if (count($approved_requests) > 1) { ?>
                        <form method="get" action="student_soa.php" class="inline-flex items-center gap-2 m-0">
                            <label class="text-xs font-bold text-[#8B7168]">Approved Term:</label>
                            <select name="term_selector" onchange="const parts = this.value.split('|'); window.location.href = 'student_soa.php?academic_year=' + encodeURIComponent(parts[0]) + '&semester=' + encodeURIComponent(parts[1]);" class="px-3 py-1.5 rounded-xl bg-[#F8EEEE] border border-[#E8C7C5]/70 text-xs font-bold text-[#59443F] focus:outline-none">
                                <?php foreach ($approved_requests as $ar) {
                                    $t_ay = (string)$ar['academic_year'];
                                    $t_sem = (string)$ar['semester'];
                                    $is_sel = ($t_ay === $academic_year && $t_sem === $semester);
                                    $sem_lbl = $t_sem === '2nd' ? '2nd Semester' : '1st Semester';
                                ?>
                                    <option value="<?php echo htmlspecialchars($t_ay . '|' . $t_sem); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                        AY <?php echo htmlspecialchars($t_ay); ?> • <?php echo htmlspecialchars($sem_lbl); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>
                    <?php } else { ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs font-bold">
                            <i class="fa-solid fa-circle-check text-emerald-600"></i>
                            <span>Approved &amp; Sent • AY <?php echo htmlspecialchars($academic_year); ?> (<?php echo $semester === '2nd' ? '2nd Semester' : '1st Semester'; ?>)</span>
                        </span>
                    <?php } ?>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 text-slate-600 border border-slate-200 text-xs font-semibold">
                        <i class="fa-solid fa-eye text-slate-500"></i>
                        <span>Digital View Only</span>
                    </span>
                </div>
            </div>

            <?php soa_render_document($soa); ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
