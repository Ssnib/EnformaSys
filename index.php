<?php
require_once __DIR__ . '/includes/session_fix.php';
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
$role = $_SESSION['role'];

$is_dean = $role === 'Dean';
$is_registrar = $role === 'Registrar';
$is_admin = $is_dean || $is_registrar;
$is_student = $role === 'Student';
$is_cashier = $role === 'Cashier';
$is_shrine = $role === 'Shrine';
$is_academic_formator = $role === 'AcademicFormator' || $role === 'Academic Formator';
$is_formator = $is_academic_formator 
    || in_array($role, ['SpiritualDirector', 'HumanFormator', 'PastoralFormator', 'Formator'], true)
    || (stripos((string)$role, 'formator') !== false)
    || (stripos((string)$role, 'spiritual') !== false);

if ($is_cashier) {
    header('Location: modules/cashier_dashboard.php');
    exit;
}
if ($is_student) {
    header('Location: modules/student_view.php');
    exit;
}
if ($is_academic_formator) {
    header('Location: modules/afms_dashboard.php');
    exit;
}

// Fetch Statistics & Initialize Variables
$total_students = 0;
$pending_enrollments = 0;
$irregular_students_count = 0;
$pending_applicants_list = [];
$total_subjects = 0;
$academic_year_label = 'Not set';

$enrollment_status = '';
$total_units = 0;
$enrolled_subjects = 0;

// Explicit variable initialization to prevent undefined variable warnings
$recent_enrollments_display = [];
$activities = [];

$ay = trim((string)get_current_academic_year());
if ($ay !== '') {
    $academic_year_label = $ay;
}

if (!$is_student) {
    $t = $conn->query("SELECT COUNT(*) as count FROM seminarians");
    if ($t) {
        $total_students = (int)($t->fetch_assoc()['count'] ?? 0);
    }

    $has_subjects_tbl = false;
    $tbl_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects'");
    if ($tbl_check && (int)($tbl_check->fetch_assoc()['c'] ?? 0) > 0) {
        $has_subjects_tbl = true;
    }
    if ($has_subjects_tbl) {
        $has_archived_col = false;
        $col_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'archived_at'");
        if ($col_check && (int)($col_check->fetch_assoc()['c'] ?? 0) > 0) {
            $has_archived_col = true;
        }
        $s = $conn->query($has_archived_col ? "SELECT COUNT(*) as count FROM subjects WHERE archived_at IS NULL" : "SELECT COUNT(*) as count FROM subjects");
        if ($s) {
            $total_subjects = (int)($s->fetch_assoc()['count'] ?? 0);
        }
    } else {
        $s = $conn->query("SELECT COUNT(DISTINCT subject) as count FROM grades WHERE archived_at IS NULL");
        if ($s) {
            $total_subjects = (int)($s->fetch_assoc()['count'] ?? 0);
        }
    }
}

if ($is_admin || $is_formator) {
    $total_students = (int)($conn->query("SELECT COUNT(*) as count FROM seminarians")->fetch_assoc()['count'] ?? 0);
    $pending_enrollments = (int)($conn->query("SELECT COUNT(*) as count FROM applicants WHERE archived_at IS NULL AND (status = 'Pending' OR status IS NULL)")->fetch_assoc()['count'] ?? 0);

    $ir_res = $conn->query("SELECT COUNT(*) as c FROM seminarians WHERE academic_status = 'Irregular'");
    if ($ir_res) {
        $irregular_students_count = (int)($ir_res->fetch_assoc()['c'] ?? 0);
    }

    $has_app_program = false;
    $has_app_created_at = false;
    $chk_app_cols = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME IN ('program', 'created_at')");
    if ($chk_app_cols) {
        while ($c_row = $chk_app_cols->fetch_assoc()) {
            if ($c_row['COLUMN_NAME'] === 'program') $has_app_program = true;
            if ($c_row['COLUMN_NAME'] === 'created_at') $has_app_created_at = true;
        }
    }

    $prog_select = $has_app_program ? "a.program" : "NULL as program";
    $created_select = $has_app_created_at ? "a.created_at" : "NULL as created_at";
    $order_clause = $has_app_created_at ? "ORDER BY a.created_at DESC, a.id DESC" : "ORDER BY a.id DESC";

    $stmt_app = $conn->query("
        SELECT a.id, a.user_id, a.academic_year,
               {$prog_select},
               {$created_select},
               COALESCE(up.full_name, a.full_name, u.username) as display_name,
               u.username
        FROM applicants a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE a.archived_at IS NULL AND (a.status = 'Pending' OR a.status IS NULL)
        {$order_clause}
        LIMIT 5
    ");
    if ($stmt_app) {
        while ($row = $stmt_app->fetch_assoc()) {
            $pending_applicants_list[] = $row;
        }
    }

    $ay_res = $conn->query("SELECT school_year FROM grades WHERE archived_at IS NULL AND school_year IS NOT NULL AND school_year <> '' ORDER BY school_year DESC LIMIT 1");
    if ($ay_res && $ay_row = $ay_res->fetch_assoc()) {
        $academic_year_label = (string)($ay_row['school_year'] ?? 'Not set');
    }
    if ($academic_year_label === 'Not set') {
        $academic_year_label = $ay !== '' ? $ay : 'Not set';
    }
}

if ($is_cashier) {
    $ay_res = $conn->query("SELECT school_year FROM grades WHERE archived_at IS NULL AND school_year IS NOT NULL AND school_year <> '' ORDER BY school_year DESC LIMIT 1");
    if ($ay_res && $ay_row = $ay_res->fetch_assoc()) {
        $academic_year_label = (string)($ay_row['school_year'] ?? 'Not set');
    }
    if ($academic_year_label === 'Not set') {
        $academic_year_label = $ay !== '' ? $ay : 'Not set';
    }
}

if ($is_student) {
    $user_id = (int)$_SESSION['user'];
    $seminarian_id = null;
    $enrollment_status = 'Pending';
    $stmt_sem = $conn->prepare('SELECT id, enrollment_status FROM seminarians WHERE user_id = ? LIMIT 1');
    $stmt_sem->bind_param('i', $user_id);
    $stmt_sem->execute();
    $sem_res = $stmt_sem->get_result();
    if ($sem_res && $sem_row = $sem_res->fetch_assoc()) {
        $seminarian_id = (int)$sem_row['id'];
        $raw_es = trim((string)($sem_row['enrollment_status'] ?? ''));
        $enrollment_status = $raw_es !== '' ? $raw_es : 'Pending';
    }

    $payment_status_label = 'No Billing';
    $payment_balance = 0.0;

    if ($seminarian_id) {
        $stmt_units = $conn->prepare('SELECT COALESCE(SUM(units), 0) as count FROM grades WHERE seminarian_id = ? AND archived_at IS NULL');
        $stmt_units->bind_param('i', $seminarian_id);
        $stmt_units->execute();
        $total_units = (int)($stmt_units->get_result()->fetch_assoc()['count'] ?? 0);

        $stmt_subjects = $conn->prepare('SELECT COUNT(DISTINCT subject) as count FROM grades WHERE seminarian_id = ? AND archived_at IS NULL');
        $stmt_subjects->bind_param('i', $seminarian_id);
        $stmt_subjects->execute();
        $enrolled_subjects = (int)($stmt_subjects->get_result()->fetch_assoc()['count'] ?? 0);

        $tables_ok = true;
        foreach (['lodging_invoices', 'lodging_invoice_items', 'lodging_payment_allocations'] as $tbl) {
            $q = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($tbl) . "'");
            $tables_ok = $tables_ok && $q && (int)($q->fetch_assoc()['c'] ?? 0) > 0;
        }

        if ($tables_ok) {
            $stmt_billed = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
                FROM lodging_invoices i
                JOIN lodging_invoice_items it ON it.invoice_id = i.id
                WHERE i.seminarian_id = ?
                  AND i.archived_at IS NULL
                  AND i.status IN ('issued', 'partially_paid', 'paid')");
            $stmt_billed->bind_param('i', $seminarian_id);
            $stmt_billed->execute();
            $total_billed = (float)($stmt_billed->get_result()->fetch_assoc()['total'] ?? 0);

            $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(pa.amount),0) as total
                FROM lodging_payment_allocations pa
                JOIN lodging_invoices i ON pa.invoice_id = i.id
                WHERE i.seminarian_id = ?
                  AND i.archived_at IS NULL");
            $stmt_paid->bind_param('i', $seminarian_id);
            $stmt_paid->execute();
            $total_paid = (float)($stmt_paid->get_result()->fetch_assoc()['total'] ?? 0);

            $payment_balance = $total_billed - $total_paid;
            if ($total_billed <= 0) {
                $payment_status_label = 'No Billing';
                $payment_balance = 0.0;
            } elseif ($payment_balance <= 0.00001) {
                $payment_status_label = 'Paid';
                $payment_balance = 0.0;
            } else {
                $payment_status_label = 'Outstanding';
            }
        }
    }
}

$student_summaries = [];
$student_search_options = [];
if ($is_admin || $is_formator) {
    $has_user_profiles = false;
    $tbl_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'");
    if ($tbl_check && (int)($tbl_check->fetch_assoc()['c'] ?? 0) > 0) {
        $has_user_profiles = true;
    }

    $student_opt_query = $has_user_profiles ? "
        SELECT 
            u.username,
            COALESCE(up.full_name, u.username) as display_name
        FROM seminarians s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        ORDER BY display_name
    " : "
        SELECT 
            u.username,
            u.username as display_name
        FROM seminarians s
        JOIN users u ON s.user_id = u.id
        ORDER BY display_name
    ";
    $student_opt_res = $conn->query($student_opt_query);
    if ($student_opt_res) {
        while ($row = $student_opt_res->fetch_assoc()) {
            $student_search_options[] = [
                'username' => $row['username'],
                'display_name' => $row['display_name']
            ];
        }
    }

    $summary_query = $has_user_profiles ? "
        SELECT 
            u.username,
            COALESCE(up.full_name, u.username) as display_name,
            e.type, 
            AVG(e.score) as avg_score 
        FROM evaluations e 
        JOIN seminarians s ON e.seminarian_id = s.id 
        JOIN users u ON s.user_id = u.id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        GROUP BY u.username, display_name, e.type
    " : "
        SELECT 
            u.username,
            u.username as display_name,
            e.type, 
            AVG(e.score) as avg_score 
        FROM evaluations e 
        JOIN seminarians s ON e.seminarian_id = s.id 
        JOIN users u ON s.user_id = u.id 
        GROUP BY u.username, e.type
    ";
    $summary_res = $conn->query($summary_query);
    while ($row = $summary_res->fetch_assoc()) {
        if (!isset($student_summaries[$row['username']])) {
            $student_summaries[$row['username']] = ['display_name' => $row['display_name']];
        }
        $student_summaries[$row['username']][$row['type']] = $row['avg_score'];
    }

    // Also fetch Academic averages for each student
    $acad_query = "
        SELECT u.username, AVG(g.grade) as avg_grade
        FROM grades g
        JOIN seminarians s ON g.seminarian_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE g.archived_at IS NULL
        GROUP BY u.username
    ";
    $acad_res = $conn->query($acad_query);
    if ($acad_res) {
        while ($row = $acad_res->fetch_assoc()) {
            if (isset($student_summaries[$row['username']])) {
                $student_summaries[$row['username']]['Academic'] = $row['avg_grade'];
            }
        }
    }
}

// Fetch Recent Activities & Recent Enrollments
if ($is_admin || $is_formator) {
    $activities_query = "
        SELECT a.*, u.username 
        FROM activities a 
        JOIN users u ON a.user_id = u.id 
        ORDER BY a.timestamp DESC 
        LIMIT 5
    ";
    $activities_res = $conn->query($activities_query);
    if ($activities_res) {
        while ($row = $activities_res->fetch_assoc()) {
            $activities[] = $row;
        }
    }

    // Fetch 5 Most Recently Enrolled Seminarians for Actionable Table
    $recent_enrolled_res = $conn->query("
        SELECT s.id as seminarian_id,
               u.username,
               COALESCE(up.full_name, a.full_name, u.username) as display_name,
               COALESCE(NULLIF(s.year_level, ''), 'Propaedeutic') as year_level,
               COALESCE(s.admission_date, a.created_at) as date_enrolled
        FROM seminarians s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN applicants a ON a.user_id = u.id
        ORDER BY s.id DESC
        LIMIT 5
    ");
    if ($recent_enrolled_res) {
        while ($row = $recent_enrolled_res->fetch_assoc()) {
            $name = trim((string)$row['display_name']);
            $parts = preg_split('/\s+/', $name);
            $initials = '';
            if (count($parts) >= 2) {
                $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
            } else {
                $initials = strtoupper(substr($name, 0, 2));
            }

            $yl = (string)$row['year_level'];
            $badge_class = 'bg-slate-100 text-slate-700 border border-slate-200';
            if (stripos($yl, 'propaedeutic') !== false) {
                $badge_class = 'bg-emerald-50 text-emerald-700 border border-emerald-200/80';
            } elseif (stripos($yl, 'first') !== false || stripos($yl, '1st') !== false) {
                $badge_class = 'bg-blue-50 text-blue-700 border border-blue-200/80';
            } elseif (stripos($yl, 'second') !== false || stripos($yl, '2nd') !== false) {
                $badge_class = 'bg-indigo-50 text-indigo-700 border border-indigo-200/80';
            } elseif (stripos($yl, 'third') !== false || stripos($yl, '3rd') !== false) {
                $badge_class = 'bg-purple-50 text-purple-700 border border-purple-200/80';
            } elseif (stripos($yl, 'fourth') !== false || stripos($yl, '4th') !== false) {
                $badge_class = 'bg-amber-50 text-amber-800 border border-amber-200/80';
            }

            $date_str = '—';
            if (!empty($row['date_enrolled'])) {
                $date_str = date('M j, Y', strtotime($row['date_enrolled']));
            }

            $recent_enrollments_display[] = [
                'seminarian_id' => (int)$row['seminarian_id'],
                'name'          => $name,
                'username'      => (string)$row['username'],
                'year_level'    => $yl,
                'date_enrolled' => $date_str,
                'initials'      => $initials,
                'badge_class'   => $badge_class
            ];
        }
    }
}
?>
<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$suppressHeader = true;
$headerMbClass = 'mb-0';
require_once __DIR__ . '/includes/layout_app_start.php';
?>

        <!-- MASTER GRID: 2-Column Layout from the very top (Left ~70% / Right ~30%) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start font-['Inter',sans-serif]">

            <!-- LEFT COLUMN (lg:col-span-8): Top Header, Stat Cards, Chart, Performance Table -->
            <div class="col-span-1 lg:col-span-8 flex flex-col gap-6">

                <!-- 1. Top Header (Title, Search, Filters) -->
                <?php require __DIR__ . '/includes/header.php'; ?>

                <!-- 2. 3 Stat Cards in Single Horizontal Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Card 1: Enrolled Seminarians -->
                        <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Enrolled Seminarians</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-users text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                                    <?php echo (int)$total_students; ?>
                                </div>
                                <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">Total Enrolled</span>
                            </div>
                        </div>

                        <!-- Card 2: Irregular Students -->
                        <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Irregular Students</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-triangle-exclamation text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                                    <?php echo (int)$irregular_students_count; ?>
                                </div>
                                <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block truncate" title="<?php echo $irregular_students_count > 0 ? 'Requires Evaluation' : 'All Regular Standing'; ?>"><?php echo $irregular_students_count > 0 ? 'Requires Evaluation' : 'All Regular Standing'; ?></span>
                            </div>
                        </div>

                        <!-- Card 3: Total Subjects -->
                        <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-lg hover:shadow-xl transition-all duration-200 flex flex-col justify-between">
                            <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                                <span class="text-xs font-semibold text-[#8B7168] tracking-wider uppercase">Total Subjects</span>
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-sm shadow-xs shrink-0">
                                    <i class="fa-solid fa-book-open text-[#D6A6A6]"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl sm:text-3xl font-black text-[#59443F] leading-tight">
                                    <?php echo (int)$total_subjects; ?>
                                </div>
                                <span class="text-[11px] sm:text-xs font-medium text-[#8B7168] mt-1.5 block">Curriculum Offerings</span>
                            </div>
                        </div>
                    </div>

                <!-- 3. Enrollment by Year Level (Bar Chart) (Floating Bubble Card) -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <!-- Card Header -->
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-chart-column text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Enrollment by Year Level</h2>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                            AY <?php echo htmlspecialchars($academic_year_label); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#8B7168] font-medium">Population of seminarians across academic &amp; formation stages</p>
                                </div>
                            </div>

                            <!-- Total Population Indicator -->
                            <div class="flex items-center gap-1.5 self-start sm:self-center">
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 shadow-xs">
                                    <span class="w-2 h-2 rounded-full bg-[#D6A6A6]"></span> Total: <span id="totalEnrollmentCount">44</span> Enrolled
                                </span>
                            </div>
                        </div>

                        <!-- Card Body: Micro Stat Pills & Bar Chart Canvas -->
                        <div class="p-5 sm:p-6">
                            <!-- Stage Population Distribution Cards -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase">Propaedeutic</span>
                                        <span class="w-2 h-2 rounded-full bg-[#D6A6A6]"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline gap-1.5">
                                        <span class="text-xl font-black text-[#59443F]">8</span>
                                        <span class="text-xs font-semibold text-[#8B7168]">18%</span>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase">1st &amp; 2nd Year</span>
                                        <span class="w-2 h-2 rounded-full bg-[#E8C7C5]"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline gap-1.5">
                                        <span class="text-xl font-black text-[#59443F]">24</span>
                                        <span class="text-xs font-semibold text-[#8B7168]">55%</span>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase">3rd &amp; 4th Year</span>
                                        <span class="w-2 h-2 rounded-full bg-[#59443F]"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline gap-1.5">
                                        <span class="text-xl font-black text-[#59443F]">12</span>
                                        <span class="text-xs font-semibold text-[#8B7168]">27%</span>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/30">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase">Active Status</span>
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    </div>
                                    <div class="mt-1 flex items-baseline gap-1.5">
                                        <span class="text-xl font-black text-[#59443F]">100%</span>
                                        <span class="text-xs font-semibold text-emerald-700">Enrolled</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Canvas Container -->
                            <div class="relative w-full" style="height: 270px;">
                                <canvas id="enrollmentYearChart"></canvas>
                            </div>
                        </div>

                        <!-- Card Footer Legend -->
                        <div class="px-6 py-3.5 bg-[#F8EEEE]/40 border-t border-[#E8C7C5]/30 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-4 text-[#8B7168] text-xs font-medium">
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-md bg-[#D6A6A6]"></span> Propaedeutic</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-md bg-[#E8C7C5]"></span> Philosophy 1-2</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-md bg-[#59443F]"></span> Philosophy 3-4</span>
                            </div>
                            <a href="modules/registrar_analytics.php" class="text-xs font-bold text-[#59443F] hover:text-[#8B7168] transition inline-flex items-center gap-1">
                                Full Analytics <i class="fa-solid fa-arrow-right text-[10px] text-[#D6A6A6]"></i>
                            </a>
                        </div>
                    </div>

                    <!-- 4. Action Required / Alerts (Floating Bubble Card) -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-bell text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Action Required &amp; Alerts</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Administrative items needing Registrar attention</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 shadow-xs">
                                <span class="w-2 h-2 rounded-full bg-[#D6A6A6] animate-pulse"></span> 2 Alerts
                            </span>
                        </div>

                        <!-- Alert Notification List Items -->
                        <div class="p-5 sm:p-6 space-y-3">
                            <!-- Alert Item 1: Missing Prerequisite Grades -->
                            <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/50 hover:bg-[#F8EEEE] transition">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 mt-0.5">
                                        <i class="fa-solid fa-triangle-exclamation text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <h3 class="text-xs font-bold text-[#59443F]">Missing Prerequisite Grades</h3>
                                            <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 uppercase tracking-wider">
                                                High Priority
                                            </span>
                                        </div>
                                        <p class="text-xs text-[#8B7168] mt-1 leading-snug">
                                             <strong class="text-[#59443F]">3 students</strong> have unencoded prerequisite grades preventing semester course completion &amp; enlistment validation.
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="text-[10px] text-[#8B7168] font-medium">Philosophy Department</span>
                                            <a href="modules/afms_grades.php" class="inline-flex items-center gap-1 text-xs font-bold text-white bg-[#59443F] hover:bg-[#8B7168] px-3 py-1 rounded-xl shadow-soft transition">
                                                Resolve Grades <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Alert Item 2: Pending Admission Review -->
                            <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/50 hover:bg-[#F8EEEE] transition">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 mt-0.5">
                                        <i class="fa-solid fa-user-clock text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <h3 class="text-xs font-bold text-[#59443F]">Pending Admission Review</h3>
                                            <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 uppercase tracking-wider">
                                                Attention
                                            </span>
                                        </div>
                                        <p class="text-xs text-[#8B7168] mt-1 leading-snug">
                                            <strong class="text-[#59443F]"><?php echo max(1, $pending_enrollments); ?> pending applicant</strong> profile awaiting registrar document validation &amp; enrollment approval.
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="text-[10px] text-[#8B7168] font-medium">AY <?php echo htmlspecialchars($academic_year_label); ?></span>
                                            <a href="modules/applicants.php?filter=pending" class="inline-flex items-center gap-1 text-xs font-bold text-[#59443F] bg-[#E8C7C5] hover:bg-[#D6A6A6] px-3 py-1 rounded-xl transition border border-[#D6A6A6]/60">
                                                Review Admission <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Formation Performance Overview (Floating Bubble Card) -->
                    <?php if (!empty($student_summaries)) { ?>
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-chart-pie text-base text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Formation Performance Overview</h2>
                                    <p class="text-xs text-[#8B7168] font-medium">Recent 4-Pillar Evaluation Balance Matrix</p>
                                </div>
                            </div>
                            <div class="relative w-full sm:w-64">
                                <i class="fa-solid fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-[#8B7168]"></i>
                                <input type="text" id="studentSearch" class="w-full pl-9 pr-8 py-2 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Search seminarian... (/)" autocomplete="off">
                                <kbd class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-[#8B7168] bg-white px-1.5 py-0.5 rounded border border-[#E8C7C5]/60">/</kbd>
                                <div id="studentSearchList" class="typeahead-list" hidden></div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="performanceTable" class="w-full text-left text-xs">
                                <thead>
                                    <tr class="border-b border-[#E8C7C5]/30 bg-[#F8EEEE]/50 text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">
                                        <th class="py-3 px-5">Seminarian</th>
                                        <th class="py-3 px-5 text-center">4-Pillar Evaluation Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php foreach ($student_summaries as $username => $data) { 
                                        $pillar_scores = [
                                            'Academic' => $data['Academic'] ?? 0,
                                            'Spiritual' => $data['Spiritual'] ?? 0,
                                            'Human' => $data['Human'] ?? 0,
                                            'Pastoral' => $data['Pastoral'] ?? 0,
                                        ];
                                        
                                        $overall_components = [];
                                        foreach ($pillar_scores as $v) {
                                            if (is_numeric($v) && (float)$v > 0) {
                                                $overall_components[] = (float)$v;
                                            }
                                        }
                                        $overall_avg = 0;
                                        if (count($overall_components) > 0) {
                                            $overall_avg = array_sum($overall_components) / count($overall_components);
                                        }
                                        $overall_percent = 0;
                                        if ($overall_avg > 0) {
                                            $overall_percent = (int)round(($overall_avg / 5) * 100);
                                        }
                                    ?>
                                    <tr class="student-row hover:bg-[#F8EEEE]/40 transition" data-search="<?php echo htmlspecialchars(strtolower(($data['display_name'] ?? $username) . ' ' . $username)); ?>">
                                        <td class="student-name py-3.5 px-5 align-middle">
                                            <strong class="text-[#59443F] font-bold text-xs"><?php echo htmlspecialchars(($data['display_name'] ?? $username)); ?></strong>
                                            <div class="text-[11px] text-[#8B7168]"><?php echo htmlspecialchars($username); ?></div>
                                        </td>
                                        <td class="text-center py-3.5 px-5">
                                            <?php echo render_category_pie_chart($pillar_scores, $overall_percent, 140); ?>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <!-- RIGHT COLUMN (approx 30% width, lg:col-span-4): Calendar, Recent Enrollments, Recent Activities -->
                <div class="col-span-1 lg:col-span-4 flex flex-col gap-6">
                    <!-- 1. Academic Calendar Widget (Moved to the very top!) -->
                    <div id="academicCalendarWidget"></div>

                    <!-- 2. Recent Enrollments (Floating Bubble Card) -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <div class="p-5 pb-3 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-user-graduate text-xs text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">Recent Enrollments</h2>
                                    <p class="text-[10px] text-[#8B7168] font-medium">Latest enrolled seminarians</p>
                                </div>
                            </div>
                            <a href="modules/applicants.php?filter=enrolled" class="inline-flex items-center gap-1 text-[11px] font-bold text-[#59443F] hover:text-[#8B7168] transition">
                                View All <i class="fa-solid fa-arrow-right text-[9px] text-[#D6A6A6]"></i>
                            </a>
                        </div>

                        <!-- Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="border-b border-[#E8C7C5]/30 bg-[#F8EEEE]/50 text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">
                                        <th class="py-2.5 px-4">Name</th>
                                        <th class="py-2.5 px-3">Year Level</th>
                                        <th class="py-2.5 px-3">Date</th>
                                        <th class="py-2.5 px-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E8C7C5]/20">
                                    <?php if (!empty($recent_enrollments_display)): ?>
                                        <?php foreach ($recent_enrollments_display as $item): ?>
                                        <tr class="hover:bg-[#F8EEEE]/40 transition">
                                            <td class="py-2.5 px-4">
                                                <div class="flex items-center gap-2">
                                                    <div class="w-7 h-7 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center font-black text-[10px] shrink-0 border border-white shadow-2xs">
                                                        <?php echo htmlspecialchars($item['initials']); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="font-bold text-[#59443F] truncate text-xs"><?php echo htmlspecialchars($item['name']); ?></div>
                                                        <div class="text-[10px] text-[#8B7168]"><?php echo htmlspecialchars($item['username']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2.5 px-3 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                                    <?php echo htmlspecialchars($item['year_level']); ?>
                                                </span>
                                            </td>
                                            <td class="py-2.5 px-3 whitespace-nowrap text-[#8B7168] text-[10px]">
                                                <?php echo htmlspecialchars($item['date_enrolled']); ?>
                                            </td>
                                            <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                                <a href="modules/seminarian_profile.php?seminarian_id=<?php echo (int)$item['seminarian_id']; ?>" 
                                                   class="inline-flex items-center gap-1 text-[10px] font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5] px-2 py-1 rounded-lg transition border border-[#E8C7C5]/50"
                                                   title="View Seminarian Profile">
                                                    Profile <i class="fa-solid fa-arrow-up-right-from-square text-[8px] text-[#D6A6A6]"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="py-6 px-4 text-center text-xs text-[#8B7168] font-medium italic">
                                                No recent enrollments found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 3. Recent Activities Feed (Floating Bubble Card) -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                        <div class="p-5 pb-3 border-b border-[#E8C7C5]/30 flex items-center justify-between bg-white">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                    <i class="fa-solid fa-bell text-xs text-[#D6A6A6]"></i>
                                </div>
                                <div>
                                    <h2 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">Recent Activities</h2>
                                    <p class="text-[10px] text-[#8B7168] font-medium">System Audit Stream</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                Live Stream
                            </span>
                        </div>
                        <div class="p-5 space-y-2.5">
                            <?php if (!empty($activities)) { ?>
                                <?php foreach ($activities as $activity) { ?>
                                <div class="p-3 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#59443F]"><?php echo htmlspecialchars($activity['username']); ?></span>
                                        <span class="text-[10px] font-medium text-[#8B7168] shrink-0">
                                            <i class="far fa-clock mr-1 text-[#D6A6A6]"></i><?php echo date('M j, Y g:i A', strtotime($activity['timestamp'])); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#8B7168] mt-1"><?php echo htmlspecialchars($activity['action']); ?></p>
                                </div>
                                <?php } ?>
                            <?php } else { ?>
                                <p class="text-[#8B7168] text-xs text-center py-6 italic">No recent activities logged.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>

            </div>

            <script>
            if (document.getElementById('studentSearch')) {
                const input = document.getElementById('studentSearch');
                const list = document.getElementById('studentSearchList');
                const options = <?php echo json_encode($student_search_options); ?>;

                function applyFilter() {
                    const searchTerm = (input.value || '').toLowerCase();
                    const rows = document.querySelectorAll('.student-row');
                    rows.forEach(row => {
                        const haystack = (row.dataset.search || row.querySelector('.student-name').textContent).toLowerCase();
                        row.style.display = haystack.includes(searchTerm) ? '' : 'none';
                    });
                }

                function renderList(items) {
                    if (!list) return;
                    list.innerHTML = '';
                    items.slice(0, 8).forEach(function (s) {
                        const div = document.createElement('div');
                        div.className = 'typeahead-item';
                        const label = s.display_name || s.username || '';
                        const meta = (s.username && s.display_name && s.username !== s.display_name) ? s.username : '';
                        div.innerHTML = `<span>${label}</span>${meta ? `<span class="typeahead-meta">${meta}</span>` : '<span></span>'}`;
                        div.addEventListener('click', function () {
                            input.value = label;
                            list.hidden = true;
                            applyFilter();
                        });
                        list.appendChild(div);
                    });
                    list.hidden = items.length === 0;
                }

                function filterOptions() {
                    const q = (input.value || '').trim().toLowerCase();
                    if (!q) return options;
                    return options.filter(function (s) {
                        const hay = `${s.display_name || ''} ${s.username || ''}`.toLowerCase();
                        return hay.includes(q);
                    });
                }

                input.addEventListener('focus', function () {
                    renderList(filterOptions());
                });
                input.addEventListener('input', function () {
                    applyFilter();
                    renderList(filterOptions());
                });
                input.addEventListener('keyup', function (e) {
                    if (e.key === 'Escape' && list) list.hidden = true;
                });

                document.addEventListener('click', function (e) {
                    if (!list || list.hidden) return;
                    if (e.target === input || list.contains(e.target)) return;
                    list.hidden = true;
                });
            }
            </script>

<?php if (!$is_student && !$is_shrine && !$is_cashier): ?>
<!-- Chart.js CDN for Enrollment Analytics Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// ── Enrollment by Year Level Bar Chart Initialization ───────────
function initEnrollmentYearChart() {
    const canvas = document.getElementById('enrollmentYearChart');
    if (!canvas) return;

    if (typeof Chart === 'undefined') {
        console.warn('Chart.js library is not available.');
        return;
    }

    // Dummy Dataset for Registrar Visualization across formation stages
    // (Easily replaced with real PHP dynamic data: <?php echo json_encode($year_counts ?? []); ?>)
    const dummyYearLabels = [
        'Propaedeutic', 
        '1st Year Philo', 
        '2nd Year Philo', 
        '3rd Year Philo', 
        '4th Year Philo'
    ];
    
    const dummyPopulationData = [8, 14, 10, 7, 5];

    // Seminary-tailored modern color palette
    const barColors = [
        '#10b981', // Emerald for Propaedeutic
        '#3b82f6', // Royal Blue for 1st Year
        '#6366f1', // Indigo for 2nd Year
        '#8b5cf6', // Violet/Purple for 3rd Year
        '#0f172a'  // Deep Navy for 4th Year Senior
    ];

    const hoverColors = [
        '#059669',
        '#2563eb',
        '#4f46e5',
        '#7c3aed',
        '#1e293b'
    ];

    const ctx = canvas.getContext('2d');
    
    // Destroy previous chart instance if exists
    if (window._enrollmentChartInstance) {
        window._enrollmentChartInstance.destroy();
    }

    window._enrollmentChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dummyYearLabels,
            datasets: [{
                label: 'Enrolled Seminarians',
                data: dummyPopulationData,
                backgroundColor: barColors,
                hoverBackgroundColor: hoverColors,
                borderRadius: 8,
                borderSkipped: false,
                barThickness: 34,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleColor: '#ffffff',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed.y;
                            const total = dummyPopulationData.reduce((a, b) => a + b, 0);
                            const pct = Math.round((val / total) * 100);
                            return ` ${val} Seminarians (${pct}% of total population)`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#64748b',
                        font: {
                            family: "'Inter', sans-serif",
                            size: 11,
                            weight: '600'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f1f5f9',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: {
                            family: "'Inter', sans-serif",
                            size: 11
                        },
                        stepSize: 2
                    }
                }
            },
            animation: {
                duration: 900,
                easing: 'easeOutQuart'
            }
        }
    });
}
</script>
<script src="assets/academic_calendar.js?v=<?php echo file_exists(__DIR__ . '/assets/academic_calendar.js') ? filemtime(__DIR__ . '/assets/academic_calendar.js') : '1'; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Enrollment by Year Level Chart
    initEnrollmentYearChart();

    // Initialize Academic Calendar
    if (document.getElementById('academicCalendarWidget') && window.AcademicCalendar) {
        const isRegistrar = <?php echo ($role === 'Registrar' ? 'true' : 'false'); ?>;
        new AcademicCalendar('academicCalendarWidget', { 
            baseUrl: 'modules/ajax.php',
            timetableUrl: 'modules/timetable.php',
            // Role-based permissions: strictly Read-Only for Registrar
            readOnly: isRegistrar,
            selectable: !isRegistrar,
            canManage: <?php echo (($role !== 'Registrar' && ($is_dean || $is_shrine)) ? 'true' : 'false'); ?>,
            enableDummyData: true,
            // Disable create action on date click for Registrar
            dateClick: isRegistrar ? null : function(info) {
                window.location.href = "modules/timetable.php?prefill_date=" + (info.dateStr || info);
            }
        });
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/layout_app_end.php'; ?>
<?php exit; ?>
