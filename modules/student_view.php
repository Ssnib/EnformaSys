<?php
require_once __DIR__ . '/../includes/session_fix.php';
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access_requests.php';

$user_id = (int)$_SESSION['user'];

// 1. Seminarian Record & Basic Identity
$stmt = $conn->prepare("SELECT * FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$seminarian = $stmt->get_result()->fetch_assoc() ?: [];
$seminarian_id = (int)($seminarian['id'] ?? 0);

// Unread SOA notifications count for Ledger sidebar badge
$unread_soa_count = 0;
if ($seminarian_id > 0) {
    $stmt_soa = @$conn->prepare("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE seminarian_id = ? AND status IN ('Sent', 'Approved', 'Completed') AND is_read = 0");
    if ($stmt_soa) {
        $stmt_soa->bind_param('i', $seminarian_id);
        if ($stmt_soa->execute()) {
            $row_soa = $stmt_soa->get_result()->fetch_assoc();
            $unread_soa_count = (int)($row_soa['c'] ?? 0);
        }
        $stmt_soa->close();
    }
}

// Term-scoped permission check for viewing academic grades for the active term
$active_term_meta = capstone_access_resolve_active_term($conn);
$active_grade_ay = $active_term_meta['academic_year'];
$active_grade_sem = $active_term_meta['semester'];
$grade_perm = capstone_access_get_grade_view_permission($conn, $seminarian_id, $active_grade_ay, $active_grade_sem);
$can_view_grades = !empty($grade_perm['can_view_any']);

// Handle access request from dashboard if submitted
$grade_request_error = null;
$grade_request_success = null;
if (!$can_view_grades) {
    if (isset($_POST['request_grade_access'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed.');
        }
        $result = capstone_access_request_create($conn, $seminarian_id, 'Academic', $user_id, null, 'whole', null, $active_grade_ay, $active_grade_sem);
        if ($result['ok']) {
            $grade_request_success = "Request submitted for AY {$active_grade_ay} ({$active_grade_sem} Semester). Please wait for approval.";
        } else {
            $grade_request_error = (string)($result['error'] ?? 'Failed to submit request.');
        }
    }
    $pending_grade_request = capstone_access_request_get_pending($conn, $seminarian_id, 'Academic', $active_grade_ay, $active_grade_sem);
} else {
    $pending_grade_request = null;
}

// 2. Personal Profile Information (Full Name, Email, Contact)
$stmt_prof = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1");
$stmt_prof->bind_param("i", $user_id);
$stmt_prof->execute();
$profile = $stmt_prof->get_result()->fetch_assoc() ?: [];

// Fallback to applicants table if user_profiles is not yet populated
$stmt_app = $conn->prepare("SELECT * FROM applicants WHERE user_id = ? LIMIT 1");
$stmt_app->bind_param("i", $user_id);
$stmt_app->execute();
$applicant = $stmt_app->get_result()->fetch_assoc() ?: [];

$username = $_SESSION['username'] ?? 'Seminarian';
$seminarian_name = !empty($profile['full_name']) 
    ? $profile['full_name'] 
    : (!empty($applicant['full_name']) 
        ? $applicant['full_name'] 
        : (!empty($profile['first_name']) ? trim($profile['first_name'] . ' ' . ($profile['last_name'] ?? '')) : $username));

$first_name = !empty($profile['first_name']) 
    ? $profile['first_name'] 
    : (explode(' ', trim($seminarian_name))[0] ?? 'Seminarian');

$seminarian_email = !empty($profile['email_address']) 
    ? $profile['email_address'] 
    : (!empty($profile['email']) 
        ? $profile['email'] 
        : (!empty($applicant['contact']) && strpos($applicant['contact'], '@') !== false ? $applicant['contact'] : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username)) . '@seminary.edu.ph'));

$seminarian_year_level = !empty($seminarian['year_level']) ? $seminarian['year_level'] : 'Philosophy Year 1';
$seminarian_program = !empty($seminarian['program']) ? $seminarian['program'] : (!empty($applicant['program']) ? $applicant['program'] : 'Bachelor of Arts in Philosophy');
$seminarian_academic_year = !empty($seminarian['current_academic_year']) ? $seminarian['current_academic_year'] : get_current_academic_year();
$student_enrollment_status = trim((string)($seminarian['enrollment_status'] ?? ''));
if ($seminarian_id > 0) {
    $stmt_cur_enr = $conn->prepare("SELECT enrollment_status FROM enrollments WHERE student_id = ? AND academic_year = ? ORDER BY is_locked ASC, FIELD(semester, 'Summer', '2nd', '1st') ASC, id DESC LIMIT 1");
    if ($stmt_cur_enr) {
        $stmt_cur_enr->bind_param('is', $seminarian_id, $seminarian_academic_year);
        $stmt_cur_enr->execute();
        $cur_enr_row = $stmt_cur_enr->get_result()->fetch_assoc();
        if ($cur_enr_row && !empty($cur_enr_row['enrollment_status'])) {
            $student_enrollment_status = (string)$cur_enr_row['enrollment_status'];
        }
    }
}
if ($student_enrollment_status === '') {
    $student_enrollment_status = 'Pending';
}
$seminarian_initials = strtoupper(substr(trim($first_name), 0, 1) . substr(trim($profile['last_name'] ?? $username), 0, 1));
if (strlen($seminarian_initials) < 2) {
    $seminarian_initials = strtoupper(substr($seminarian_name, 0, 2));
}
$seminarian_avatar_url = get_seminarian_avatar_url($seminarian['profile_picture'] ?? null);
$seminarian_cooldown = get_seminarian_cooldown_status($seminarian['last_profile_update'] ?? null);

// 3. Academic Grades & Computed Average
$stmt_grades = $conn->prepare("SELECT * FROM grades WHERE seminarian_id = ? AND archived_at IS NULL ORDER BY date DESC");
$stmt_grades->bind_param("i", $seminarian_id);
$stmt_grades->execute();
$grades_res = $stmt_grades->get_result();

$stmt_acad_avg = $conn->prepare("SELECT AVG(grade) as avg_grade FROM grades WHERE seminarian_id = ? AND archived_at IS NULL");
$stmt_acad_avg->bind_param("i", $seminarian_id);
$stmt_acad_avg->execute();
$acad_avg_res = $stmt_acad_avg->get_result();
$academic_avg = 0.0;
if ($acad_avg_res) {
    $academic_avg = (float)($acad_avg_res->fetch_assoc()['avg_grade'] ?? 0);
}

// 4. Formative Evaluations & 4-Pillar Scores
$stmt_avg = $conn->prepare("SELECT type, AVG(score) as avg_score FROM evaluations WHERE seminarian_id = ? AND archived_at IS NULL GROUP BY type");
$stmt_avg->bind_param("i", $seminarian_id);
$stmt_avg->execute();
$averages_res = $stmt_avg->get_result();
$averages = [];
while ($row = $averages_res->fetch_assoc()) {
    $averages[$row['type']] = (float)$row['avg_score'];
}

$pillar_scores = [
    'Academic' => $academic_avg,
    'Spiritual' => (float)($averages['Spiritual'] ?? 0),
    'Human' => (float)($averages['Human'] ?? 0),
    'Pastoral' => (float)($averages['Pastoral'] ?? 0),
];

$overall_components = [];
foreach ($pillar_scores as $v) {
    if (is_numeric($v) && (float)$v > 0) {
        $overall_components[] = (float)$v;
    }
}
$overall_avg = 0.0;
if (count($overall_components) > 0) {
    $overall_avg = array_sum($overall_components) / count($overall_components);
}
$overall_percent = 0;
if ($overall_avg > 0) {
    $overall_percent = (int)round(($overall_avg / 5) * 100);
    if ($overall_percent < 0) $overall_percent = 0;
    if ($overall_percent > 100) $overall_percent = 100;
}

// 5. Financial Clearance & Account Balance
$student_balance = 0.0;
$clearance_status = 'Cleared';
if ($seminarian_id > 0) {
    $stmt_billed = $conn->prepare("SELECT COALESCE(SUM(it.amount),0) as total
        FROM lodging_invoices i
        JOIN lodging_invoice_items it ON it.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL
          AND i.status IN ('issued', 'partially_paid', 'paid')");
    $stmt_billed->bind_param('i', $seminarian_id);
    $stmt_billed->execute();
    $s_billed = (float)($stmt_billed->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(pa.amount),0) as total
        FROM lodging_payment_allocations pa
        JOIN lodging_invoices i ON pa.invoice_id = i.id
        WHERE i.seminarian_id = ?
          AND i.archived_at IS NULL");
    $stmt_paid->bind_param('i', $seminarian_id);
    $stmt_paid->execute();
    $s_paid = (float)($stmt_paid->get_result()->fetch_assoc()['total'] ?? 0);

    $student_balance = $s_billed - $s_paid;
    if ($student_balance > 0.01) {
        $clearance_status = '₱' . number_format($student_balance, 2) . ' Due';
    } else {
        $clearance_status = 'Cleared';
    }
}

// Enrolled units calculation for summary KPI card
$total_enrolled_units = 0;
if ($seminarian_id > 0) {
    $stmt_u = $conn->prepare("SELECT COALESCE(SUM(s.units), 0) AS total_units 
        FROM student_enrolled_subjects es 
        JOIN subjects s ON s.subject_id = es.subject_id 
        WHERE es.student_id = ?");
    if ($stmt_u) {
        $stmt_u->bind_param("i", $seminarian_id);
        $stmt_u->execute();
        $ru = $stmt_u->get_result()->fetch_assoc();
        $total_enrolled_units = (float)($ru['total_units'] ?? 0);
    }
}
if ($total_enrolled_units <= 0) {
    $total_enrolled_units = 11.0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <script>
    (function(){try{if(localStorage.getItem('enformasys_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');var m=document.querySelector('meta[name="color-scheme"]');if(m)m.setAttribute('content','dark');}}catch(e){}})();
    </script>
    <title>Seminarian Portal • EnformaSys</title>
    <!-- Modern Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"></noscript>
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
    <link rel="stylesheet" href="../css/motion.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/motion.css'); ?>">
    <link rel="stylesheet" href="../css/floating_theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/floating_theme.css'); ?>">
    <link id="enformasys-live-css-link" rel="stylesheet" href="../css/custom_live.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/custom_live.css'); ?>">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'portal-bg': '#F8EEEE',
                        'portal-card': '#FFFFFF',
                        'portal-brown': '#59443F',
                        'portal-mocha': '#8B7168',
                        'portal-rose': '#D6A6A6',
                        'portal-dusty': '#E8C7C5',
                        'portal-dusty-soft': '#F4E4E3',
                    },
                    boxShadow: {
                        'soft': '0 4px 20px -2px rgba(89, 68, 63, 0.06), 0 2px 6px -1px rgba(89, 68, 63, 0.03)',
                        'soft-lg': '0 12px 28px -4px rgba(89, 68, 63, 0.1), 0 4px 12px -2px rgba(89, 68, 63, 0.05)',
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-main: #F8EEEE;
            --bg-card: #FFFFFF;
            --text-primary: #59443F;
            --text-secondary: #8B7168;
            --color-rose: #D6A6A6;
            --color-dusty: #E8C7C5;
            --color-brown: #59443F;
        }
        body {
            background-color: var(--bg-canvas, #F8EEEE);
            color: var(--text-primary, #59443F);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4, h5, h6, .font-heading {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
        }
        /* Custom scrollbar matching soft theme */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(248, 238, 238, 0.7);
        }
        ::-webkit-scrollbar-thumb {
            background: #E8C7C5;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #D6A6A6;
        }
        @media (max-width: 991.98px) {
            #studentProfileWidget {
                display: none !important;
            }
        }
    </style>
</head>
<body class="student-portal-page bg-[#F8EEEE] text-[#59443F] min-h-screen">

    <!-- Mobile Top Navigation Bar (< 1024px) -->
    <header class="lg:hidden bg-white/95 backdrop-blur-md sticky top-0 z-40 border-b border-[#E8C7C5]/50 px-4 py-3 flex items-center justify-between shadow-xs">
        <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" type="button" class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] flex items-center justify-center border border-[#E8C7C5]/70 text-base" aria-label="Toggle Navigation">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="flex items-center gap-2">
                <img src="../assets/LOGO%20(2).jpg" alt="Logo" class="w-8 h-8 rounded-xl object-cover shadow-xs border border-[#E8C7C5]/50">
                <span class="font-heading font-extrabold text-[#59443F] text-base tracking-tight">EnformaSys</span>
            </div>
        </div>
        <div class="flex items-center gap-2 relative" id="mobileProfileMenuWrap">
            <button type="button"
                    data-theme-toggle="1"
                    class="enformasys-theme-toggle inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#F8EEEE] hover:bg-white text-[#59443F] border border-[#E8C7C5]/70 hover:border-[#D6A6A6] transition shadow-2xs hover:shadow-xs cursor-pointer shrink-0"
                    aria-label="Toggle dark mode"
                    aria-pressed="false"
                    title="Switch to dark mode">
                <i class="fa-regular fa-moon text-sm theme-icon-moon" aria-hidden="true"></i>
                <i class="fa-regular fa-sun text-sm theme-icon-sun" aria-hidden="true"></i>
                <span class="sr-only">Toggle color theme</span>
            </button>
            <?php require __DIR__ . '/../includes/notification_bell.php'; ?>
            <button id="mobileProfileMenuBtn"
                    type="button"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-controls="mobileProfilePopover"
                    aria-label="Open Profile Menu"
                    class="relative inline-flex items-center justify-center rounded-full focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition-transform active:scale-95 cursor-pointer">
                <?php if ($seminarian_avatar_url): ?>
                    <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($seminarian_name, ENT_QUOTES, 'UTF-8'); ?>" class="w-9 h-9 rounded-full object-cover border-2 border-[#E8C7C5] shadow-xs">
                <?php else: ?>
                    <span class="w-9 h-9 rounded-full bg-[#E8C7C5] text-[#59443F] font-black text-xs flex items-center justify-center border border-[#D6A6A6]/60 shadow-xs">
                        <?php echo htmlspecialchars($seminarian_initials); ?>
                    </span>
                <?php endif; ?>
                <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white" title="Active Seminarian"></span>
            </button>

            <!-- Mobile Header Profile Dropdown / Popover Menu -->
            <div id="mobileProfilePopover"
                 role="menu"
                 aria-labelledby="mobileProfileMenuBtn"
                 class="hidden absolute right-0 top-full mt-2.5 w-72 max-w-[calc(100vw-1.5rem)] bg-white rounded-3xl border border-[#E8C7C5]/80 shadow-soft-lg p-4 z-50 text-left">
                <div class="flex items-center gap-3 pb-3 border-b border-[#E8C7C5]/35">
                    <div class="w-11 h-11 rounded-2xl bg-[#E8C7C5] text-[#59443F] font-heading font-black text-sm flex items-center justify-center border border-[#D6A6A6]/50 shrink-0 overflow-hidden shadow-2xs">
                        <?php if ($seminarian_avatar_url): ?>
                            <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($seminarian_name, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo htmlspecialchars($seminarian_initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-heading font-extrabold text-sm text-[#59443F] leading-tight truncate">
                            <?php echo htmlspecialchars($seminarian_name); ?>
                        </div>
                        <div class="text-[11px] text-[#8B7168] font-medium truncate mt-0.5">
                            <?php echo htmlspecialchars($seminarian_email); ?>
                        </div>
                    </div>
                </div>

                <div class="mt-2.5 mb-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                        <i class="fa-solid fa-cross text-[9px] text-[#D6A6A6]"></i>
                        <span><?php echo htmlspecialchars($seminarian_year_level); ?></span>
                    </span>
                </div>

                <div class="space-y-1.5 text-xs">
                    <a href="student_edit_profile.php" role="menuitem" class="w-full py-2.5 px-3 rounded-xl bg-[#59443F] hover:bg-[#6E554F] text-white font-bold transition flex items-center gap-2 no-underline">
                        <i class="fa-solid fa-user-pen text-[11px] text-[#E8C7C5]"></i>
                        <span>Edit Profile</span>
                        <?php if ($seminarian_cooldown['is_active']): ?>
                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black bg-amber-500 text-white">
                                <i class="fa-solid fa-lock text-[8px]"></i> Cooldown
                            </span>
                        <?php endif; ?>
                    </a>
                    <div class="grid grid-cols-2 gap-1.5">
                        <a href="student_academic.php" role="menuitem" class="py-2 px-3 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] font-bold transition border border-[#E8C7C5]/50 flex items-center justify-center gap-1.5 no-underline">
                            <i class="fa-solid fa-graduation-cap text-[10px]"></i> My Grades
                        </a>
                        <a href="student_balance.php" role="menuitem" class="py-2 px-3 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] font-bold transition border border-[#E8C7C5]/50 flex items-center justify-center gap-1.5 no-underline">
                            <i class="fa-solid fa-receipt text-[10px]"></i> My Balance
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Sidebar Drawer Backdrop -->
    <div id="mobileDrawerBackdrop" class="fixed inset-0 bg-[#59443F]/40 backdrop-blur-xs z-50 hidden lg:hidden transition-opacity"></div>

    <!-- MAIN 3-COLUMN DASHBOARD CONTAINER -->
    <div class="max-w-[1780px] mx-auto p-3 sm:p-5 lg:p-6 min-h-screen">
        <div class="grid grid-cols-1 lg:grid-cols-[250px_1fr] xl:grid-cols-[250px_1fr_330px] gap-5 lg:gap-6 items-start">

            <!-- ========================================================================= -->
            <!-- COLUMN 1: LEFT SIDEBAR (NAVIGATION)                                       -->
            <!-- ========================================================================= -->
            <aside id="sidebarDrawer" class="fixed lg:sticky top-0 lg:top-6 left-0 h-full lg:h-[calc(100vh-3rem)] w-72 lg:w-full z-50 lg:z-10 bg-white rounded-none lg:rounded-[2rem] border-r lg:border border-[#E8C7C5]/50 p-5 flex flex-col justify-between shadow-soft -translate-x-full lg:translate-x-0 transition-transform duration-300 overflow-y-auto">
                <div>
                    <!-- 1. System Logo & Brand at Top -->
                    <div class="flex items-center justify-between pb-6 mb-6 border-b border-[#E8C7C5]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] p-1 border border-[#E8C7C5]/80 shadow-xs flex items-center justify-center overflow-hidden">
                                <img src="../assets/LOGO%20(2).jpg" alt="EnformaSys Logo" class="w-full h-full rounded-xl object-cover">
                            </div>
                            <div>
                                <h1 class="font-heading font-black text-lg text-[#59443F] tracking-tight leading-tight">EnformaSys</h1>
                                <p class="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase">Seminarian Portal</p>
                            </div>
                        </div>
                        <button id="closeDrawerBtn" type="button" class="lg:hidden text-[#8B7168] hover:text-[#59443F] text-lg p-1" aria-label="Close menu">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- 2. Clean Icon-Based Navigation Links -->
                    <nav class="space-y-1.5" aria-label="Main Navigation">
                        <!-- Dashboard (Active with Dusty Pink / Rose Pill) -->
                        <a href="student_view.php" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-bold text-sm bg-[#E8C7C5] text-[#59443F] shadow-xs transition-all duration-200">
                            <i class="fa-solid fa-house-chimney text-base text-[#59443F]"></i>
                            <span>Dashboard</span>
                        </a>

                        <!-- My Class -->
                        <a href="my_class.php" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] transition-all duration-200">
                            <i class="fa-solid fa-chalkboard-user text-base text-[#D6A6A6]"></i>
                            <span>My Class</span>
                        </a>

                        <!-- Grades -->
                        <a href="student_academic.php" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] transition-all duration-200">
                            <i class="fa-solid fa-graduation-cap text-base text-[#D6A6A6]"></i>
                            <span>Grades</span>
                        </a>

                        <!-- Ledger -->
                        <a href="student_balance.php" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] transition-all duration-200">
                            <i class="fa-solid fa-receipt text-base text-[#D6A6A6]"></i>
                            <span>Ledger</span>
                            <?php if ($unread_soa_count > 0): ?>
                                <span data-badge-for="my_balance" class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">
                                    <?php echo (int)$unread_soa_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>


                        <!-- Secondary Divider -->
                        <div class="pt-3 pb-1">
                            <span class="text-[10px] uppercase font-extrabold text-[#8B7168] tracking-widest px-4">EVALUATIONS</span>
                        </div>

                        <!-- Formation Evaluations -->
                        <a href="student_academic_evaluation.php" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] transition-all duration-200">
                            <i class="fa-solid fa-clipboard-check text-base text-[#D6A6A6]"></i>
                            <span>Evaluations</span>
                        </a>

                        <!-- Profile & Account Settings -->
                        <a href="student_edit_profile.php" data-nav-key="edit_profile" class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-[#59443F] hover:bg-[#F8EEEE] transition-all duration-200">
                            <i class="fa-solid fa-user-pen text-base text-[#D6A6A6]"></i>
                            <span>Profile</span>
                        </a>

                        <!-- Sign Out -->
                        <a href="../logout.php"
                       data-confirm="Sign out of EnformaSys? Any unsaved changes will be lost."
                       data-confirm-title="Sign Out"
                       data-confirm-text="Sign Out"
                       data-confirm-variant="warning"
                       data-confirm-loading="Signing out..."
                       class="flex items-center gap-3.5 px-4 py-3 rounded-2xl font-semibold text-sm text-[#8B7168] hover:text-rose-600 hover:bg-rose-50/60 transition-all duration-200">
                            <i class="fa-solid fa-arrow-right-from-bracket text-base text-[#8B7168]"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>

                <!-- 3. Dark 'Help/Support' Card at Bottom (#59443F Background with White Text) -->
                <div class="mt-6 p-4 rounded-2xl bg-[#59443F] text-white shadow-soft relative overflow-hidden">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-xl bg-white/15 text-[#E8C7C5] flex items-center justify-center shrink-0 text-sm">
                            <i class="fa-solid fa-circle-question"></i>
                        </div>
                        <div>
                            <h3 class="font-heading font-bold text-xs text-white">Need Guidance?</h3>
                            <p class="text-[11px] text-[#F8EEEE]/80 leading-relaxed mt-1">
                                Questions about enrollment or seminary clearances?
                            </p>
                            <a href="student_balance.php" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#D6A6A6] hover:bg-[#E8C7C5] text-[#59443F] transition-all shadow-xs">
                                <span>Get Support</span>
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </aside>


            <!-- ========================================================================= -->
            <!-- COLUMN 2: CENTER MAIN CONTENT (WIDE)                                      -->
            <!-- ========================================================================= -->
            <main class="w-full space-y-6">

                <!-- 1. Center Header Greeting -->
                <div class="bg-white rounded-[2rem] border border-[#E8C7C5]/50 p-6 sm:p-8 shadow-soft flex flex-col md:flex-row md:items-center md:justify-between gap-5 relative">
                    <!-- Subtle background decoration -->
                    <div class="absolute right-0 bottom-0 w-44 h-44 rounded-full bg-[#F8EEEE] pointer-events-none opacity-60 overflow-hidden"></div>
                    <div class="relative z-10">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 mb-2.5 flex-wrap">
                            <span class="w-2 h-2 rounded-full bg-[#D6A6A6] animate-pulse"></span>
                            <span>AY <?php echo htmlspecialchars($seminarian_academic_year); ?></span>
                            <span class="text-[#8B7168]">• <?php echo htmlspecialchars($seminarian_year_level); ?></span>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?php echo $student_enrollment_status === 'Enrolled' ? 'bg-green-100 text-green-700' : ($student_enrollment_status === 'Pending' || $student_enrollment_status === 'Partially Paid' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700'); ?>">
                                <?php echo htmlspecialchars($student_enrollment_status); ?>
                            </span>
                        </div>
                        <!-- Seminarian Name Placeholder -->
                        <h2 class="font-heading text-2xl sm:text-3xl font-black text-[#59443F] tracking-tight">
                            Hello, <?php echo htmlspecialchars($seminarian_name); ?> 👋
                        </h2>
                        <p class="text-sm font-medium text-[#8B7168] mt-1">
                            <?php if ($student_enrollment_status === 'Pending'): ?>
                                Enrollment is currently <strong>Pending</strong>. Complete at least &#8369;500.00 payment at the Cashier to activate Enrolled status.
                            <?php else: ?>
                                Let's check your academic progress today.
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Quick Academic Program Pill & Desktop Notifications -->
                    <div class="relative z-20 self-start md:self-center flex flex-col items-start md:items-end gap-2">
                        <div class="hidden lg:flex items-center gap-2">
                            <button id="enformasysThemeToggle"
                                    type="button"
                                    data-theme-toggle="1"
                                    class="enformasys-theme-toggle inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#F8EEEE] hover:bg-white text-[#59443F] border border-[#E8C7C5]/70 hover:border-[#D6A6A6] transition shadow-2xs hover:shadow-xs cursor-pointer shrink-0"
                                    aria-label="Toggle dark mode"
                                    aria-pressed="false"
                                    title="Switch to dark mode">
                                <i class="fa-regular fa-moon text-sm theme-icon-moon" aria-hidden="true"></i>
                                <i class="fa-regular fa-sun text-sm theme-icon-sun" aria-hidden="true"></i>
                                <span class="sr-only">Toggle color theme</span>
                            </button>
                            <?php require __DIR__ . '/../includes/notification_bell.php'; ?>
                        </div>
                        <div class="flex flex-col items-start md:items-end">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Program of Study</span>
                            <span class="font-heading font-extrabold text-xs sm:text-sm text-[#59443F] bg-[#F8EEEE] px-3.5 py-1.5 rounded-xl border border-[#E8C7C5]/60 mt-1">
                                <?php echo htmlspecialchars($seminarian_program); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 2. Academic Summary KPI Cards (4 Soft Modern Cards) -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                    <!-- Card 1: Enrolled Units -->
                    <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 p-4 shadow-soft hover:shadow-soft-lg transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">Total Units</span>
                            <div class="w-7 h-7 rounded-lg bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-xs">
                                <i class="fa-solid fa-layer-group"></i>
                            </div>
                        </div>
                        <div class="text-xl sm:text-2xl font-black text-[#59443F]">
                            <?php echo (float)$total_enrolled_units; ?>
                        </div>
                        <span class="text-[10px] font-semibold text-[#8B7168]">Current Term</span>
                    </div>

                    <!-- Card 2: Academic Avg -->
                    <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 p-4 shadow-soft hover:shadow-soft-lg transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">Academic Avg</span>
                            <div class="w-7 h-7 rounded-lg bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-xs">
                                <i class="<?php echo $can_view_grades ? 'fa-solid fa-award text-[#D6A6A6]' : 'fa-solid fa-lock text-amber-600'; ?>"></i>
                            </div>
                        </div>
                        <?php if ($can_view_grades): ?>
                            <div class="text-xl sm:text-2xl font-black text-[#59443F]">
                                <?php echo number_format($academic_avg, 2); ?>
                            </div>
                            <span class="text-[10px] font-semibold text-emerald-700">Official Standing</span>
                        <?php else: ?>
                            <div class="text-base sm:text-lg font-black text-[#8B7168] flex items-center gap-1.5 my-1">
                                <i class="fa-solid fa-lock text-xs text-amber-500"></i>
                                <span>Grades locked</span>
                            </div>
                            <span class="text-[10px] font-semibold text-amber-700">Permission Required</span>
                        <?php endif; ?>
                    </div>

                    <!-- Card 3: Formation Score -->
                    <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 p-4 shadow-soft hover:shadow-soft-lg transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">Formation</span>
                            <div class="w-7 h-7 rounded-lg bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-xs">
                                <i class="fa-solid fa-chart-pie"></i>
                            </div>
                        </div>
                        <div class="text-xl sm:text-2xl font-black text-[#59443F]">
                            <?php echo (int)$overall_percent; ?>%
                        </div>
                        <span class="text-[10px] font-semibold text-[#8B7168]">4-Pillar Score</span>
                    </div>

                    <!-- Card 4: Remaining Balance -->
                    <div class="bg-white rounded-2xl border border-[#E8C7C5]/50 p-4 shadow-soft hover:shadow-soft-lg transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-bold text-[#8B7168] tracking-wider">Remaining Balance</span>
                            <div class="w-7 h-7 rounded-lg bg-[#F8EEEE] text-[#59443F] flex items-center justify-center text-xs">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                        </div>
                        <div class="text-xl sm:text-2xl font-black text-[#59443F]">
                            ₱<?php echo number_format(max(0, (float)$student_balance), 2); ?>
                        </div>
                        <span class="text-[10px] font-semibold <?php echo $student_balance > 0.01 ? 'text-amber-700' : 'text-emerald-700'; ?>">
                            <?php echo $student_balance > 0.01 ? 'Outstanding Balance' : 'Account Cleared'; ?>
                        </span>
                    </div>
                </div>

                <?php if (!$can_view_grades): ?>
                    <!-- Grade Viewing Locked Banner & Request Action -->
                    <div class="bg-white rounded-2xl border border-amber-200/80 p-4 sm:p-5 shadow-soft flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200/70 text-base">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <div>
                                <h4 class="font-heading font-bold text-sm text-[#59443F]">Academic Grades Locked</h4>
                                <p class="text-xs text-[#8B7168] mt-0.5">
                                    Enrolled subjects are viewable, but official grades and GPA records require approval from your Academic Formator.
                                </p>
                                <?php if ($grade_request_success): ?>
                                    <div class="text-xs font-semibold text-emerald-700 mt-1 flex items-center gap-1">
                                        <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($grade_request_success); ?>
                                    </div>
                                <?php elseif ($grade_request_error): ?>
                                    <div class="text-xs font-semibold text-rose-700 mt-1 flex items-center gap-1">
                                        <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($grade_request_error); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="shrink-0 self-start sm:self-center">
                            <?php if ($pending_grade_request): ?>
                                <?php
                                $req_scope = (string)($pending_grade_request['request_scope'] ?? 'whole');
                                ?>
                                <a href="student_academic.php?academic_year=<?php echo urlencode($active_grade_ay); ?>&amp;semester=<?php echo urlencode($active_grade_sem); ?>#grade-request-form" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 shadow-xs hover:bg-white transition">
                                    <i class="fa-regular fa-clock text-amber-600"></i>
                                    <span>Pending Approval (<?php echo htmlspecialchars($active_grade_sem); ?> Sem &middot; <?php echo $req_scope === 'selected' ? 'Selected Subjects' : 'Whole Semester'; ?>)</span>
                                </a>
                            <?php else: ?>
                                <a href="student_academic.php?academic_year=<?php echo urlencode($active_grade_ay); ?>&amp;semester=<?php echo urlencode($active_grade_sem); ?>#grade-request-form" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-[#59443F] hover:bg-[#8B7168] text-white shadow-soft transition-all duration-200 cursor-pointer">
                                    <i class="fa-solid fa-key text-[#E8C7C5]"></i>
                                    <span>Request Grade Access (<?php echo htmlspecialchars($active_grade_sem); ?> Semester)</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 3. Formative 4-Pillar Evaluation Balance Overview -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-6 shadow-soft">
                    <div class="flex items-center justify-between gap-3 mb-5 pb-3 border-b border-[#E8C7C5]/30">
                        <div>
                            <h3 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight flex items-center gap-2">
                                <i class="fa-solid fa-cross text-[#D6A6A6]"></i>
                                <span>Formation &amp; Holistic Development</span>
                            </h3>
                            <p class="text-xs text-[#8B7168] font-medium">Ratings across Academic, Spiritual, Human, and Pastoral pillars</p>
                        </div>
                        <span class="text-xs font-bold text-[#59443F] bg-[#F8EEEE] px-3 py-1 rounded-xl border border-[#E8C7C5]/60">
                            Overall <?php echo (int)$overall_percent; ?>%
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                        <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold text-[#8B7168] uppercase tracking-wider block mb-1">Academic</span>
                            <?php if ($can_view_grades): ?>
                                <div class="text-xl font-black text-[#59443F]"><?php echo number_format($academic_avg, 2); ?></div>
                                <span class="text-[10px] text-[#8B7168]">GPA Scale</span>
                            <?php else: ?>
                                <div class="text-sm font-bold text-[#8B7168] flex items-center gap-1.5 my-1">
                                    <i class="fa-solid fa-lock text-xs text-amber-600"></i>
                                    <span>Grades locked</span>
                                </div>
                                <span class="text-[10px] text-amber-700">Approval Required</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold text-[#8B7168] uppercase tracking-wider block mb-1">Spiritual</span>
                            <div class="text-xl font-black text-[#59443F]"><?php echo number_format((float)($averages['Spiritual'] ?? 0), 2); ?></div>
                            <span class="text-[10px] text-[#8B7168]">Max 5.00</span>
                        </div>
                        <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold text-[#8B7168] uppercase tracking-wider block mb-1">Human</span>
                            <div class="text-xl font-black text-[#59443F]"><?php echo number_format((float)($averages['Human'] ?? 0), 2); ?></div>
                            <span class="text-[10px] text-[#8B7168]">Max 5.00</span>
                        </div>
                        <div class="p-4 rounded-2xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold text-[#8B7168] uppercase tracking-wider block mb-1">Pastoral</span>
                            <div class="text-xl font-black text-[#59443F]"><?php echo number_format((float)($averages['Pastoral'] ?? 0), 2); ?></div>
                            <span class="text-[10px] text-[#8B7168]">Max 5.00</span>
                        </div>
                    </div>
                </div>
            </main>


            <!-- ========================================================================= -->
            <!-- COLUMN 3: RIGHT SIDEBAR (WIDGETS)                                         -->
            <!-- ========================================================================= -->
            <aside class="w-full space-y-6">

                <!-- 1. Profile Widget (Top Right, hidden on mobile < 992px and moved to header avatar popover) -->
                <div id="studentProfileWidget" class="hidden lg:block bg-white rounded-3xl border border-[#E8C7C5]/50 p-6 shadow-soft text-center relative overflow-hidden">
                    <!-- Subtle top banner accent -->
                    <div class="h-16 -mx-6 -mt-6 bg-gradient-to-r from-[#E8C7C5] to-[#F8EEEE] border-b border-[#E8C7C5]/40"></div>
                    
                    <!-- Avatar Display (Picture with Fallback Initials) -->
                    <div class="relative -mt-9 mb-3 inline-block">
                        <div class="w-18 h-18 w-[72px] h-[72px] rounded-2xl bg-[#E8C7C5] text-[#59443F] font-heading font-black text-2xl flex items-center justify-center border-4 border-white shadow-soft mx-auto overflow-hidden">
                            <?php if ($seminarian_avatar_url): ?>
                                <img src="<?php echo htmlspecialchars($seminarian_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($seminarian_name, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?php echo htmlspecialchars($seminarian_initials); ?>
                            <?php endif; ?>
                        </div>
                        <span class="absolute bottom-1 right-1 w-4 h-4 rounded-full bg-emerald-500 border-2 border-white" title="Active Seminarian"></span>
                    </div>

                    <!-- Seminarian Name Placeholder -->
                    <h3 class="font-heading font-extrabold text-base text-[#59443F] tracking-tight">
                        <?php echo htmlspecialchars($seminarian_name); ?>
                    </h3>
                    <!-- Seminarian Email Placeholder -->
                    <p class="text-xs text-[#8B7168] font-medium truncate mt-0.5">
                        <?php echo htmlspecialchars($seminarian_email); ?>
                    </p>

                    <!-- Seminarian Stage Badge -->
                    <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                        <i class="fa-solid fa-cross text-[9px] text-[#D6A6A6]"></i>
                        <span><?php echo htmlspecialchars($seminarian_year_level); ?></span>
                    </div>

                    <!-- Quick Profile Actions -->
                    <div class="mt-4 pt-4 border-t border-[#E8C7C5]/30 space-y-2 text-xs">
                        <div class="grid grid-cols-2 gap-2">
                            <a href="student_academic.php" class="py-2 px-3 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] font-bold transition border border-[#E8C7C5]/50 flex items-center justify-center gap-1">
                                <i class="fa-solid fa-graduation-cap text-[10px]"></i> Grades
                            </a>
                            <a href="student_balance.php" class="py-2 px-3 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] font-bold transition border border-[#E8C7C5]/50 flex items-center justify-center gap-1">
                                <i class="fa-solid fa-receipt text-[10px]"></i> Balance
                            </a>
                        </div>
                        <a href="student_edit_profile.php" class="w-full py-2.5 px-3 rounded-2xl bg-[#59443F] hover:bg-[#6E554F] text-white font-bold transition shadow-soft flex items-center justify-center gap-2">
                            <i class="fa-solid fa-user-pen text-[11px] text-[#E8C7C5]"></i>
                            <span>Edit Profile</span>
                            <?php if ($seminarian_cooldown['is_active']): ?>
                                <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black bg-amber-500 text-white" title="<?php echo htmlspecialchars($seminarian_cooldown['badge_message'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid fa-lock text-[8px]"></i> Cooldown
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- 2. Clean, Minimalist Monthly Calendar Widget (Read-Only) -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-soft">
                    <!-- Calendar Header -->
                    <div class="flex items-center justify-between mb-3 gap-2">
                        <h4 id="calendarMonthYear" class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight">
                            October 2026
                        </h4>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <a href="timetable.php" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold transition border border-[#E8C7C5]/60 shadow-2xs" title="Open Full View Timetable">
                                <i class="fa-solid fa-calendar-days text-[10px] text-[#D6A6A6]"></i>
                                <span class="hidden sm:inline">Full View Timetable</span>
                            </a>
                            <div class="flex items-center gap-1">
                                <button id="calPrevBtn" type="button" class="w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Previous Month">
                                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                </button>
                                <button id="calNextBtn" type="button" class="w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Next Month">
                                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Category Filter Pills (Soft theme colors) -->
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 mb-3 scrollbar-none" id="studentCalFilterRow">
                        <button type="button" class="student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#E8C7C5] text-[#59443F] shadow-2xs" data-filter="All">All</button>
                        <button type="button" class="student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5]/60 hover:text-[#59443F]" data-filter="Academic">Academic</button>
                        <button type="button" class="student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5]/60 hover:text-[#59443F]" data-filter="Holidays">Holidays</button>
                        <button type="button" class="student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5]/60 hover:text-[#59443F]" data-filter="Events">Events</button>
                    </div>

                    <!-- 7-Column Day Names Header -->
                    <div class="grid grid-cols-7 gap-1 text-center mb-2">
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">M</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">W</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">F</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                    </div>

                    <!-- Calendar Grid of Days -->
                    <div id="calendarDaysGrid" class="grid grid-cols-7 gap-1 text-center text-xs">
                        <!-- Rendered dynamically by JavaScript -->
                    </div>

                    <!-- Read-Only Seminary Events Details Drawer -->
                    <div id="calendarEventsDrawer" class="mt-4 pt-3 border-t border-[#E8C7C5]/40 text-xs">
                        <div class="flex items-center justify-between mb-2">
                            <span id="calendarSelectedDateLabel" class="font-bold text-[#59443F] text-[11px] uppercase tracking-wider">Events</span>
                            <span class="text-[9px] font-semibold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded border border-[#E8C7C5]/40 flex items-center gap-1">
                                <i class="fa-solid fa-lock text-[8px] text-[#8B7168]"></i> Read-Only
                            </span>
                        </div>
                        <div id="calendarEventsList" class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                            <!-- Populated dynamically via AJAX -->
                        </div>
                    </div>
                </div>

            </aside>

        </div>
    </div>

    <!-- Calendar, Schedule & Mobile Drawer Script -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // Utility: HTML Escape
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // 1. Mobile Drawer Toggle Logic
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const closeDrawerBtn = document.getElementById('closeDrawerBtn');
        const sidebarDrawer = document.getElementById('sidebarDrawer');
        const backdrop = document.getElementById('mobileDrawerBackdrop');

        function openDrawer() {
            if (sidebarDrawer && backdrop) {
                sidebarDrawer.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            }
        }
        function closeDrawer() {
            if (sidebarDrawer && backdrop) {
                sidebarDrawer.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            }
        }
        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openDrawer);
        if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', closeDrawer);
        if (backdrop) backdrop.addEventListener('click', closeDrawer);

        // 2. Dynamic Calendar Events (Strictly Read-Only for Students)
        /**
         * Student Role Calendar Configuration:
         * - selectable: false (disables date-range selection and event creation drag)
         * - editable: false (disables event dragging and resizing)
         * - eventClick: views details in read-only drawer (no edit or delete forms)
         * - dateClick: inspects selected date events (strictly no creation popup)
         */
        const monthNames = ["January", "February", "March", "April", "May", "June",
                            "July", "August", "September", "October", "November", "December"];
        
        const now = new Date();
        let calYear = now.getFullYear();
        let calMonth = now.getMonth();
        const todayDate = now.getDate();
        const todayMonth = now.getMonth();
        const todayYear = now.getFullYear();
        let selectedDay = todayDate;
        let selectedDateStr = `${todayYear}-${String(todayMonth + 1).padStart(2, '0')}-${String(todayDate).padStart(2, '0')}`;

        const monthYearEl = document.getElementById('calendarMonthYear');
        const daysGridEl = document.getElementById('calendarDaysGrid');
        const prevBtn = document.getElementById('calPrevBtn');
        const nextBtn = document.getElementById('calNextBtn');
        const eventsDrawerEl = document.getElementById('calendarEventsDrawer');
        const eventsListEl = document.getElementById('calendarEventsList');
        const selectedDateLabelEl = document.getElementById('calendarSelectedDateLabel');

        let eventsByDate = {};
        let calFilter = 'All';

        function getFilteredEventsForDate(dateKey) {
            const list = eventsByDate[dateKey] || [];
            if (calFilter === 'All') return list;
            const filterLower = calFilter.toLowerCase();
            return list.filter(e => {
                const typeStr = (e.type || '').toLowerCase();
                const titleStr = (e.event || '').toLowerCase();
                if (filterLower === 'academic') {
                    return typeStr.includes('academic') || titleStr.includes('exam') || titleStr.includes('class') || titleStr.includes('enroll') || titleStr.includes('semester');
                }
                if (filterLower === 'holidays') {
                    return typeStr.includes('holiday') || titleStr.includes('holiday') || titleStr.includes('break') || titleStr.includes('feast');
                }
                if (filterLower === 'events') {
                    return typeStr.includes('event') || typeStr.includes('formation') || typeStr.includes('activity') || (!typeStr.includes('academic') && !typeStr.includes('holiday'));
                }
                return typeStr.includes(filterLower);
            });
        }

        async function fetchCalendarEvents(year, month) {
            try {
                const res = await fetch(`ajax.php?action=get_events_for_month&year=${year}&month=${month + 1}`);
                const data = await res.json();
                eventsByDate = {};
                if (data && data.events && Array.isArray(data.events)) {
                    data.events.forEach(ev => {
                        if (!eventsByDate[ev.date]) eventsByDate[ev.date] = [];
                        eventsByDate[ev.date].push(ev);
                    });
                }
            } catch (err) {
                console.warn('Failed to fetch calendar events:', err);
                eventsByDate = {};
            }
            renderCalendar();
            renderEventsDrawer(selectedDateStr);
        }

        function renderCalendar() {
            if (!monthYearEl || !daysGridEl) return;
            monthYearEl.textContent = `${monthNames[calMonth]} ${calYear}`;

            const firstDayIndex = new Date(calYear, calMonth, 1).getDay(); // 0 is Sunday
            const totalDaysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
            const totalDaysInPrevMonth = new Date(calYear, calMonth, 0).getDate();

            let gridHtml = '';

            // Leading days from previous month (disabled, read-only)
            for (let i = firstDayIndex - 1; i >= 0; i--) {
                const prevD = totalDaysInPrevMonth - i;
                gridHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${prevD}</div>`;
            }

            // Current month days
            for (let d = 1; d <= totalDaysInMonth; d++) {
                const isToday = (d === todayDate && calMonth === todayMonth && calYear === todayYear);
                const isSelected = (d === selectedDay);
                const dateKey = `${calYear}-${String(calMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const dayEvents = getFilteredEventsForDate(dateKey);
                const hasEvents = dayEvents.length > 0;

                let cellClass = "h-9 flex flex-col items-center justify-center rounded-xl cursor-pointer transition relative select-none ";
                if (isToday) {
                    cellClass += "bg-[#E8C7C5] text-[#59443F] font-black shadow-xs ring-1 ring-[#D6A6A6] ";
                } else if (isSelected) {
                    cellClass += "bg-[#F8EEEE] text-[#59443F] font-bold border border-[#E8C7C5] ";
                } else {
                    cellClass += "text-[#59443F] hover:bg-[#F8EEEE] font-semibold ";
                }

                // Tiny indicator dots for scheduled global events
                let dotsHtml = '';
                if (hasEvents) {
                    dotsHtml = `<div class="flex items-center justify-center gap-0.5 mt-0.5">` + 
                        dayEvents.slice(0, 3).map(() => `<span class="w-1 h-1 rounded-full ${isToday ? 'bg-[#59443F]' : 'bg-[#D6A6A6]'} inline-block"></span>`).join('') + 
                    `</div>`;
                }

                const tooltipText = hasEvents ? dayEvents.map(e => e.event).join(', ') : '';

                gridHtml += `
                    <div class="${cellClass}" data-day="${d}" data-date="${dateKey}" title="${escapeHtml(tooltipText)}">
                        <span class="text-xs leading-none">${d}</span>
                        <div class="h-1.5 flex items-center justify-center">${dotsHtml}</div>
                    </div>
                `;
            }

            // Trailing days to fill 7-column layout
            const renderedCount = firstDayIndex + totalDaysInMonth;
            const trailingCount = (7 - (renderedCount % 7)) % 7;
            for (let j = 1; j <= trailingCount; j++) {
                gridHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${j}</div>`;
            }

            daysGridEl.innerHTML = gridHtml;

            // Day click interaction (Read-Only: inspect events, never create)
            daysGridEl.querySelectorAll('[data-day]').forEach(cell => {
                cell.addEventListener('click', () => {
                    selectedDay = parseInt(cell.getAttribute('data-day'));
                    selectedDateStr = cell.getAttribute('data-date');
                    renderCalendar();
                    renderEventsDrawer(selectedDateStr);
                });
            });
        }

        function renderEventsDrawer(dateStr) {
            if (!eventsListEl) return;
            const dayEvents = getFilteredEventsForDate(dateStr);
            const dateObj = new Date(dateStr + 'T00:00:00');
            const formattedDate = !isNaN(dateObj.getTime())
                ? dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
                : dateStr;

            if (selectedDateLabelEl) {
                selectedDateLabelEl.textContent = `${formattedDate} Events`;
            }

            if (dayEvents.length === 0) {
                eventsListEl.innerHTML = `
                    <div class="p-2.5 rounded-xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/30 text-center text-[#8B7168] text-[11px] italic">
                        No seminary events scheduled for this day.
                    </div>
                `;
                return;
            }

            eventsListEl.innerHTML = dayEvents.map(ev => {
                let timeStr = ev.time ? ev.time.substring(0, 5) : 'All Day';
                let typeBadge = ev.type || 'Formation';
                return `
                    <div class="p-2.5 rounded-xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition">
                        <div class="flex items-center justify-between gap-1 mb-1">
                            <span class="font-bold text-xs text-[#59443F] truncate">${escapeHtml(ev.event)}</span>
                            <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 shrink-0">
                                ${escapeHtml(typeBadge)}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-[10px] text-[#8B7168]">
                            <span class="flex items-center gap-1">
                                <i class="fa-regular fa-clock text-[9px] text-[#D6A6A6]"></i>
                                ${escapeHtml(timeStr)}
                            </span>
                            ${ev.note ? `<span class="truncate max-w-[130px] italic">${escapeHtml(ev.note)}</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Category filter buttons interaction
        document.querySelectorAll('.student-cal-filter').forEach(btn => {
            btn.addEventListener('click', () => {
                calFilter = btn.getAttribute('data-filter') || 'All';
                document.querySelectorAll('.student-cal-filter').forEach(b => {
                    if (b.getAttribute('data-filter') === calFilter) {
                        b.className = 'student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#E8C7C5] text-[#59443F] shadow-2xs';
                    } else {
                        b.className = 'student-cal-filter px-2.5 py-0.5 rounded-full text-[10px] font-bold transition whitespace-nowrap cursor-pointer bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5]/60 hover:text-[#59443F]';
                    }
                });
                renderCalendar();
                renderEventsDrawer(selectedDateStr);
            });
        });

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                calMonth--;
                if (calMonth < 0) {
                    calMonth = 11;
                    calYear--;
                }
                fetchCalendarEvents(calYear, calMonth);
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                calMonth++;
                if (calMonth > 11) {
                    calMonth = 0;
                    calYear++;
                }
                fetchCalendarEvents(calYear, calMonth);
            });
        }

        // Initial calendar load
        fetchCalendarEvents(calYear, calMonth);

        // Mobile Header Avatar Profile Popover
        const profileWrap = document.getElementById('mobileProfileMenuWrap');
        const profileBtn = document.getElementById('mobileProfileMenuBtn');
        const profilePopover = document.getElementById('mobileProfilePopover');
        if (profileBtn && profilePopover) {
            function closeProfilePopover() {
                profilePopover.classList.add('hidden');
                profileBtn.setAttribute('aria-expanded', 'false');
            }
            profileBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isClosed = profilePopover.classList.contains('hidden');
                if (isClosed) {
                    profilePopover.classList.remove('hidden');
                    profileBtn.setAttribute('aria-expanded', 'true');
                } else {
                    closeProfilePopover();
                }
            });
            document.addEventListener('click', (e) => {
                if (profileWrap && !profileWrap.contains(e.target)) {
                    closeProfilePopover();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !profilePopover.classList.contains('hidden')) {
                    closeProfilePopover();
                    profileBtn.focus();
                }
            });
        }
    });
    </script>
    <script>
    (function () {
        if (window.__enformasysThemeInit) return;
        window.__enformasysThemeInit = true;
        var STORAGE_KEY = 'enformasys_theme';
        var fallbackTimer = null;

        function isDarkTheme() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }
        function updateToggleButtons(dark) {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
                btn.setAttribute('aria-label', label);
                btn.setAttribute('title', label);
            });
        }
        function applyTheme(theme, persist) {
            var dark = theme === 'dark';
            if (dark) document.documentElement.setAttribute('data-theme', 'dark');
            else document.documentElement.removeAttribute('data-theme');
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) meta.setAttribute('content', dark ? 'dark' : 'light');
            if (persist) {
                try { localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light'); } catch (_) {}
            }
            updateToggleButtons(dark);
            try {
                window.dispatchEvent(new CustomEvent('enformasys:themechange', { detail: { theme: dark ? 'dark' : 'light', isDark: dark } }));
            } catch (_) {}
        }
        function toggleWithTransition(event, triggerBtn) {
            var nextTheme = isDarkTheme() ? 'light' : 'dark';
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                applyTheme(nextTheme, true);
                return;
            }
            var x = window.innerWidth / 2;
            var y = window.innerHeight / 2;
            if (event && typeof event.clientX === 'number' && (event.clientX !== 0 || event.clientY !== 0)) {
                x = event.clientX;
                y = event.clientY;
            } else if (triggerBtn && triggerBtn.getBoundingClientRect) {
                var rect = triggerBtn.getBoundingClientRect();
                x = rect.left + rect.width / 2;
                y = rect.top + rect.height / 2;
            }
            if (typeof document.startViewTransition === 'function') {
                var endRadius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
                try {
                    var tr = document.startViewTransition(function () {
                        applyTheme(nextTheme, true);
                    });
                    tr.ready.then(function () {
                        document.documentElement.animate(
                            { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + endRadius + 'px at ' + x + 'px ' + y + 'px)'] },
                            { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
                        );
                    }).catch(function () {});
                    return;
                } catch (_) {}
            }
            if (fallbackTimer) clearTimeout(fallbackTimer);
            document.documentElement.classList.add('theme-animating');
            applyTheme(nextTheme, true);
            fallbackTimer = setTimeout(function () {
                document.documentElement.classList.remove('theme-animating');
                fallbackTimer = null;
            }, 340);
        }
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
            if (!btn) return;
            e.preventDefault();
            toggleWithTransition(e, btn);
        });
        window.addEventListener('storage', function (e) {
            if (e.key === STORAGE_KEY) applyTheme(e.newValue === 'dark' ? 'dark' : 'light', false);
        });
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { updateToggleButtons(isDarkTheme()); });
        } else {
            updateToggleButtons(isDarkTheme());
        }
        window.EnformaSysTheme = {
            isDark: isDarkTheme,
            set: function (theme) { applyTheme(theme, true); },
            toggle: function (ev, btn) { toggleWithTransition(ev, btn); }
        };
    })();
    </script>
    <script defer src="../assets/app_layout.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/app_layout.js'); ?>"></script>
<?php require __DIR__ . '/../includes/live_css_editor.php'; ?>
</body>
</html>
