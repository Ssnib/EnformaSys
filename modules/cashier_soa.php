<?php
require_once '../includes/session_fix.php';
require_roles(['Cashier']);
require_once '../includes/db.php';
require_once '../includes/lodging.php';
require_once '../includes/soa_helper.php';
global $conn;

$current_ay = get_current_academic_year();
$ay_options = get_active_academic_years();
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

// Include any academic years present in invoices or payments
$ay_extra_res = $conn->query("(SELECT DISTINCT academic_year FROM lodging_invoices WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM lodging_payments WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_extra_res) {
    while ($r = $ay_extra_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

// Accept student_id, seminarian_id, or id
$raw_student_id = (int)($_GET['student_id'] ?? $_GET['seminarian_id'] ?? $_GET['id'] ?? 0);
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$requested_schedule_id = (int)($_GET['schedule_id'] ?? 0);

// Fetch student profile first (supports both seminarians.id and user_id)
$student = null;
if ($raw_student_id > 0) {
    $stmt = $conn->prepare(
        "SELECT s.id as seminarian_id, COALESCE(up.full_name, u.username) as full_name, up.student_id as student_no,
                s.program, s.year_level, s.formation_status
         FROM seminarians s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN user_profiles up ON up.user_id = u.id
         WHERE s.id = ? OR s.user_id = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $raw_student_id, $raw_student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
}

if (!$student) {
    header('Location: cashier_accounts.php');
    exit;
}

$student_id = (int)$student['seminarian_id'];
$prog_snap = (string)($student['program'] ?? '');
$yl_snap = (string)($student['year_level'] ?? '');

// Default academic year if not specified or empty
if ($academic_year === '') {
    $stmt_last_ay = $conn->prepare("SELECT academic_year FROM lodging_invoices WHERE seminarian_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY issue_date DESC, id DESC LIMIT 1");
    $stmt_last_ay->bind_param('i', $student_id);
    $stmt_last_ay->execute();
    $last_ay_row = $stmt_last_ay->get_result()->fetch_assoc();
    if ($last_ay_row && !empty($last_ay_row['academic_year'])) {
        $academic_year = (string)$last_ay_row['academic_year'];
    } else {
        $academic_year = $current_ay;
    }
} elseif ($academic_year !== 'all' && !in_array($academic_year, $ay_options, true)) {
    if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
        $academic_year = $current_ay;
    }
}

// Default semester: '1st', '2nd', or 'all'
if ($semester === '') {
    $semester = '1st';
} elseif (!in_array($semester, ['1st', '2nd', 'all'], true)) {
    $semester = '1st';
}

$semester_label = 'First Semester';
if ($semester === '2nd') {
    $semester_label = 'Second Semester';
} elseif ($semester === 'all') {
    $semester_label = 'All Semesters / Full Year';
}

// Rice fee quantity / text based on semester
$rice_sacks = ($semester === '2nd') ? '2 sacks' : '1 sack';
if ($semester === 'all') {
    $rice_sacks = '3 sacks (1st & 2nd Semesters)';
}

// ----------------------------------------------------------------------------
// 1. QUERY ALL ACTIVE FEE SCHEDULES FOR DYNAMIC PRICING & SELECTOR
// ----------------------------------------------------------------------------
$all_schedules = [];
$res_sched_list = $conn->query(
    "SELECT fs.id, fs.academic_year, fs.semester, fs.program, fs.year_level,
            COALESCE(SUM(fi.amount), 0) as total_amount,
            COUNT(fi.id) as items_count
     FROM cashier_fee_schedules fs
     LEFT JOIN cashier_fee_items fi ON fi.schedule_id = fs.id
     WHERE fs.archived_at IS NULL AND fs.is_active = 1
     GROUP BY fs.id
     ORDER BY fs.academic_year DESC, fs.semester ASC, fs.id DESC"
);
if ($res_sched_list) {
    while ($srow = $res_sched_list->fetch_assoc()) {
        $all_schedules[] = $srow;
    }
}

// ----------------------------------------------------------------------------
// 2. LOAD COMPLETE OFFICIAL SOA DATA VIA SHARED HELPER
// ----------------------------------------------------------------------------
$selected_schedule_id = $requested_schedule_id;
$soa = soa_load_statement_data($conn, $student, $academic_year, $semester, [
    'schedule_id' => $requested_schedule_id,
    'sync_invoice' => true,
    'created_by' => (int)($_SESSION['user'] ?? 1),
]);
$selected_schedule_id = (int)$soa['selected_schedule_id'];
?>
<?php
$pageTitle = 'Statement of Account';
$activeNav = 'cashier_accounts';
require_once __DIR__ . '/../includes/layout_app_start.php';
soa_render_styles();
?>

        <div class="content-body">
            <!-- Navigation & Fee Schedule Filter Toolbar -->
            <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; background:#ffffff; padding:0.85rem 1.25rem; border:1px solid #e2e8f0; border-radius:10px;">
                <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                    <a class="back-link" style="margin:0;" href="cashier_accounts.php?academic_year=<?php echo urlencode($academic_year === 'all' ? $current_ay : $academic_year); ?>&semester=<?php echo urlencode($semester === 'all' ? '1st' : $semester); ?>">
                        <i class="fas fa-arrow-left"></i> Back to Accounts
                    </a>
                    <form method="get" action="cashier_soa.php" style="display:inline-flex; align-items:center; gap:0.5rem; margin:0; flex-wrap:wrap;">
                        <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                        
                        <label style="font-size:0.8rem; font-weight:700; color:#475569;">Term:</label>
                        <select name="academic_year" style="padding:0.35rem 0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; background:#f8fafc;" onchange="this.form.submit()">
                            <option value="all" <?php echo $academic_year === 'all' ? 'selected' : ''; ?>>All Academic Years</option>
                            <?php foreach ($ay_options as $ay_opt) { ?>
                                <option value="<?php echo htmlspecialchars($ay_opt); ?>" <?php echo $academic_year === $ay_opt ? 'selected' : ''; ?>>
                                    AY <?php echo htmlspecialchars($ay_opt); ?>
                                </option>
                            <?php } ?>
                        </select>

                        <select name="semester" style="padding:0.35rem 0.65rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; background:#f8fafc;" onchange="this.form.submit()">
                            <option value="all" <?php echo $semester === 'all' ? 'selected' : ''; ?>>All Semesters / Full Year</option>
                            <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>

                        <!-- Dynamic Fee Schedule Selector -->
                        <label style="font-size:0.8rem; font-weight:700; color:#0f766e; margin-left: 0.35rem;">
                            <i class="fas fa-file-invoice-dollar"></i> Fee Schedule:
                        </label>
                        <select name="schedule_id" style="padding:0.35rem 0.65rem; border:1.5px solid #0f766e; border-radius:6px; font-size:0.85rem; background:#f0fdfa; font-weight: 700; color: #115e59;" onchange="this.form.submit()">
                            <option value="0">-- Auto-Matched Active Schedule --</option>
                            <?php foreach ($all_schedules as $sched_item) { 
                                $s_id = (int)$sched_item['id'];
                                $s_label = 'AY ' . $sched_item['academic_year'] . ' ' . $sched_item['semester'] . ' Sem';
                                if (!empty($sched_item['program'])) {
                                    $s_label .= ' (' . $sched_item['program'] . ')';
                                } else {
                                    $s_label .= ' (Standard)';
                                }
                                $s_label .= ' — P ' . number_format((float)$sched_item['total_amount'], 2);
                            ?>
                                <option value="<?php echo $s_id; ?>" <?php echo $selected_schedule_id === $s_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s_label); ?>
                                </option>
                            <?php } ?>
                        </select>

                        <noscript><button type="submit" style="padding:0.35rem 0.75rem; background:#1e3a5f; color:#fff; border:none; border-radius:6px; font-size:0.85rem;">Apply</button></noscript>
                    </form>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" onclick="window.print()" style="background:#1e3a5f; color:#fff; font-weight:700; padding:0.5rem 1.15rem; border-radius:6px; border:none; cursor:pointer; font-size:0.875rem;">
                        <i class="fas fa-print"></i> Print Official Receipt / SOA
                    </button>
                </div>
            </div>

            <?php soa_render_document($soa); ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
