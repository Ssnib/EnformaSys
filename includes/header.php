<?php
/**
 * EnformaSys Top Header / Navbar Component
 * Unified Floating Card with Role-Based Search Bar & Soft Aesthetics
 *
 * Theme Palette:
 * - Header Card Background: White (#FFFFFF) with soft shadow (shadow-soft)
 * - Primary Text (Title): Dirty brown (#59443F)
 * - Secondary Text (Subtitle): Mocha (#8B7168)
 * - Page Background: Light pink (#F8EEEE)
 * - Highlight & Accents: #D6A6A6 & #E8C7C5
 */

require_once __DIR__ . '/url.php';
if (!function_exists('get_header_user_label')) {
    @require_once __DIR__ . '/session_fix.php';
}

$headerRole = (string)($_SESSION['role'] ?? '');
$headerRoleLower = strtolower(trim($headerRole));

// Role-Based Access for Search Bar:
// ONLY renders if role is admin, registrar, cashier, or formator.
// Strictly hidden (blank/empty) for student or seminarian roles.
$canSearch = false;
$allowedSearchRoles = [
    'admin', 'dean', 'rector',
    'registrar',
    'cashier',
    'formator', 'academicformator', 'humanformator', 'spiritualdirector', 'pastoralformator'
];

if ($headerRoleLower !== 'student' && $headerRoleLower !== 'seminarian') {
    if (in_array($headerRoleLower, $allowedSearchRoles, true) ||
        strpos($headerRoleLower, 'admin') !== false ||
        strpos($headerRoleLower, 'formator') !== false) {
        $canSearch = true;
    }
}

// Display Variables
$headerTitle = isset($pageTitle) && (string)$pageTitle !== '' ? (string)$pageTitle : 'Dashboard';
$headerUserLabel = function_exists('get_header_user_label') ? get_header_user_label() : ($headerRole ?: 'User');
$headerUsername = (string)($_SESSION['username'] ?? '');
$headerGreetingName = !empty($headerUsername) ? $headerUsername : ($headerUserLabel ?: 'User');
if (!isset($academic_year_label) || $academic_year_label === '') {
    $academic_year_label = function_exists('get_current_academic_year') ? trim((string)get_current_academic_year()) : '';
    if ($academic_year_label === '') {
        $academic_year_label = '2026-2027';
    }
}

// Global Filter Variables: Capture GET parameters with fallback to active term
$selected_header_ay = isset($_GET['academic_year']) && trim((string)$_GET['academic_year']) !== ''
    ? trim((string)$_GET['academic_year'])
    : (isset($filter_ay) && $filter_ay !== '' ? $filter_ay : (isset($academic_year_label) && $academic_year_label !== '' ? $academic_year_label : (function_exists('get_active_academic_year') ? get_active_academic_year() : (function_exists('get_current_academic_year') ? get_current_academic_year() : '2026-2027'))));

$selected_header_sem = isset($_GET['semester']) && in_array(trim((string)$_GET['semester']), ['1st', '2nd', 'Summer'], true)
    ? trim((string)$_GET['semester'])
    : (isset($filter_sem) && in_array($filter_sem, ['1st', '2nd', 'Summer'], true) ? $filter_sem : (function_exists('get_active_semester') ? get_active_semester() : '1st'));

// Populate Academic Year options
$header_ay_list = [];
if (!empty($ay_options) && is_array($ay_options)) {
    $header_ay_list = $ay_options;
} else {
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $ay_res = $conn->query("SELECT label FROM academic_years WHERE archived_at IS NULL ORDER BY start_year DESC, end_year DESC");
        if ($ay_res) {
            while ($r = $ay_res->fetch_assoc()) {
                $lbl = trim((string)($r['label'] ?? ''));
                if ($lbl !== '' && !in_array($lbl, $header_ay_list, true)) {
                    $header_ay_list[] = $lbl;
                }
            }
        }
    }
}
if (empty($header_ay_list)) {
    $header_ay_list = ['2026-2027', '2025-2026', '2024-2025'];
}
if ($selected_header_ay !== '' && !in_array($selected_header_ay, $header_ay_list, true)) {
    array_unshift($header_ay_list, $selected_header_ay);
}
?>
<!-- Unified Top Navbar Card (z-40 so z-[99999] portals render cleanly above) -->
<header class="topbar print:hidden sticky top-0 lg:top-4 z-40 flex items-center justify-between gap-3 sm:gap-4 bg-white text-[#59443F] px-4 py-3 sm:px-6 sm:py-3.5 rounded-none lg:rounded-2xl border-b lg:border border-gray-200/80 shadow-sm <?= isset($headerMbClass) ? $headerMbClass : 'mb-4' ?>">
    <!-- Left Section: Page Title & Greeting Subtitle -->
    <div class="flex items-center gap-3 min-w-0">
        <button id="openSidebar" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 text-[#59443F] hover:bg-gray-100 transition shadow-2xs lg:hidden shrink-0" aria-label="Open sidebar">
            <i class="fa-solid fa-bars text-sm"></i>
        </button>
        <div class="min-w-0">
            <h1 class="text-base sm:text-lg font-extrabold text-[#59443F] font-heading leading-tight tracking-tight truncate">
                <?= htmlspecialchars($headerTitle) ?>
            </h1>
            <p class="text-xs font-medium text-[#8B7168] mt-0.5 truncate">
                Welcome, <?= htmlspecialchars($headerGreetingName) ?>
            </p>
        </div>
    </div>

    <!-- Center Section: Role-Based Search Bar (Visible ONLY to Admin, Registrar, Cashier, Formator) -->
    <div class="flex-1 max-w-xs sm:max-w-md mx-2 sm:mx-4 flex items-center justify-center">
        <?php if ($canSearch): ?>
        <div class="relative w-full">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-[#8B7168]"></i>
            <input type="text" 
                   id="topbarSearch" 
                   name="q" 
                   placeholder="Search records, students, courses..." 
                   class="w-full pl-10 pr-9 py-2 rounded-full bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] focus:bg-white transition-all shadow-inner" 
                   autocomplete="off">
            <kbd class="hidden sm:inline-flex absolute right-3.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-[#8B7168] bg-white px-1.5 py-0.5 rounded-full border border-[#E8C7C5]/60 shadow-2xs">/</kbd>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Section: Notification Bell + Academic Year & Semester Dropdown Filters -->
    <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
        <?php require __DIR__ . '/notification_bell.php'; ?>
        <form method="GET" action="" id="topbarFilterForm" class="flex items-center gap-2 sm:gap-2.5 shrink-0 m-0 p-0">
        <?php foreach ($_GET as $gk => $gv): ?>
            <?php if ($gk !== 'academic_year' && $gk !== 'semester' && is_scalar($gv)): ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$gk) ?>" value="<?= htmlspecialchars((string)$gv) ?>">
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Academic Year Filter -->
        <div class="relative">
            <select id="filterAcademicYear" name="academic_year" onchange="this.form.submit()" class="appearance-none bg-[#F8EEEE] hover:bg-white text-xs font-bold text-[#59443F] pl-3.5 sm:pl-4 pr-8 sm:pr-9 py-2 rounded-full border border-[#E8C7C5]/70 hover:border-[#D6A6A6] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] focus:bg-white shadow-2xs hover:shadow-xs cursor-pointer transition">
                <?php foreach ($header_ay_list as $ay): ?>
                    <option value="<?= htmlspecialchars($ay) ?>" <?= ($selected_header_ay === $ay) ? 'selected' : '' ?>>
                        A.Y. <?= htmlspecialchars($ay) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <i class="fa-solid fa-chevron-down text-[10px] text-[#8B7168] absolute right-3 sm:right-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        </div>

        <!-- Semester Filter -->
        <div class="relative">
            <select id="filterSemester" name="semester" onchange="this.form.submit()" class="appearance-none bg-[#F8EEEE] hover:bg-white text-xs font-bold text-[#59443F] pl-3.5 sm:pl-4 pr-8 sm:pr-9 py-2 rounded-full border border-[#E8C7C5]/70 hover:border-[#D6A6A6] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] focus:bg-white shadow-2xs hover:shadow-xs cursor-pointer transition">
                <option value="1st" <?= ($selected_header_sem === '1st') ? 'selected' : '' ?>>1st Semester</option>
                <option value="2nd" <?= ($selected_header_sem === '2nd') ? 'selected' : '' ?>>2nd Semester</option>
                <option value="Summer" <?= ($selected_header_sem === 'Summer') ? 'selected' : '' ?>>Summer Term</option>
            </select>
            <i class="fa-solid fa-chevron-down text-[10px] text-[#8B7168] absolute right-3 sm:right-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        </div>
    </form>
    </div>
</header>

<?php
// EnformaSys Flash Toast Notification Trigger
$activeToast = null;
if (!empty($_SESSION['toast_success'])) {
    $activeToast = ['msg' => (string)$_SESSION['toast_success'], 'type' => 'success'];
    unset($_SESSION['toast_success']);
} elseif (!empty($_SESSION['toast_error'])) {
    $activeToast = ['msg' => (string)$_SESSION['toast_error'], 'type' => 'error'];
    unset($_SESSION['toast_error']);
} elseif (!empty($_SESSION['toast_info'])) {
    $activeToast = ['msg' => (string)$_SESSION['toast_info'], 'type' => 'info'];
    unset($_SESSION['toast_info']);
} elseif (!empty($_SESSION['success'])) {
    $activeToast = ['msg' => (string)$_SESSION['success'], 'type' => 'success'];
    unset($_SESSION['success']);
} elseif (!empty($_SESSION['error'])) {
    $activeToast = ['msg' => (string)$_SESSION['error'], 'type' => 'error'];
    unset($_SESSION['error']);
}

if ($activeToast !== null): ?>
<script>
(function() {
    function fireToast() {
        if (typeof window.showToast === 'function') {
            window.showToast(<?= json_encode($activeToast['msg']) ?>, <?= json_encode($activeToast['type']) ?>);
        } else {
            setTimeout(fireToast, 50);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fireToast);
    } else {
        fireToast();
    }
})();
</script>
<?php endif; ?>

