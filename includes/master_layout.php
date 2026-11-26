<?php
/**
 * EnformaSys Master Layout Template
 * Standardized SaaS-style "Floating Bubble" Base Architecture
 *
 * Requirements:
 * - Light pink global background (#F8EEEE)
 * - Floating Sidebar container (rounded-[2rem], white background, soft shadow, margins)
 * - Clean Top Navbar (Page Title + User Profile/Badge, without search bar or header logout)
 * - Main Content Wrapper properly aligned next to the floating sidebar
 * - Dynamic Placeholders for:
 *     1. Sidebar Menu Links ($sidebarMenuFile, $sidebarMenuContent, or role-based sidebar_menu.php)
 *     2. Main Page Content ($pageContent file, closure, or buffered string)
 */

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/global_modal.php';
if (!function_exists('get_header_user_label')) {
    @require_once __DIR__ . '/session_fix.php';
}

$appName = 'EnformaSys';
$systemName = 'Littleway College Seminary';
$pageTitle = isset($pageTitle) ? (string)$pageTitle : $appName;
$activeNav = isset($activeNav) ? (string)$activeNav : '';
$role = (string)($_SESSION['role'] ?? '');

$userLabel = function_exists('get_header_user_label') ? get_header_user_label() : ($role ?: 'User');
$username = (string)($_SESSION['username'] ?? 'User');

$roleSubtitle = 'Littleway Seminary';
if ($role === 'Student') {
    $roleSubtitle = 'Seminarian Portal';
} elseif ($role === 'Registrar') {
    $roleSubtitle = 'Registrar Portal';
} elseif ($role === 'Dean' || $role === 'Rector') {
    $roleSubtitle = 'Administration';
} elseif ($role === 'AcademicFormator') {
    $roleSubtitle = 'Academic Formator';
} elseif ($role === 'HumanFormator') {
    $roleSubtitle = 'Human Formator';
} elseif ($role === 'SpiritualDirector') {
    $roleSubtitle = 'Spiritual Director';
} elseif ($role === 'PastoralFormator') {
    $roleSubtitle = 'Pastoral Formator';
} elseif ($role === 'Cashier') {
    $roleSubtitle = 'Cashier Portal';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')) ?>">
    <title><?= htmlspecialchars($pageTitle === $appName ? $appName : ($pageTitle . ' - ' . $appName)) ?></title>
    
    <!-- Modern Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"></noscript>
    
    <!-- Base & Motion Styles -->
    <link rel="stylesheet" href="<?= htmlspecialchars(capstone_url('css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(capstone_url('css/motion.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../css/motion.css') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(capstone_url('css/floating_theme.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../css/floating_theme.css') ?>">
    
    <!-- Icons & Utility Framework -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f8f5f2] text-[#3f2822] font-['Inter',sans-serif] antialiased min-h-screen">

    <!-- Mobile Drawer Backdrop -->
    <div id="sidebarBackdrop" class="fixed inset-0 z-[1040] hidden bg-slate-900/45 backdrop-blur-xs lg:hidden transition-opacity" aria-hidden="true"></div>

    <!-- ========================================================================= -->
    <!-- FLOATING SIDEBAR CONTAINER                                                -->
    <!-- ========================================================================= -->
    <aside id="sidebar" class="-translate-x-full fixed z-[1050] lg:z-40 flex flex-col transition-all duration-300 inset-y-0 left-0 h-[100dvh] w-72 bg-white text-[#59443F] shadow-xl border-r border-gray-200/80 lg:translate-x-0 lg:fixed lg:top-0 lg:bottom-0 lg:left-0 lg:m-4 lg:h-[calc(100vh-2rem)] lg:w-64 lg:rounded-2xl lg:border lg:border-gray-200/80 lg:bg-white lg:shadow-sm overflow-hidden">
        
        <!-- Logo & Brand Header -->
        <div class="flex items-center gap-3 px-4 py-3.5 border-b border-gray-100 shrink-0 bg-white">
            <img src="<?= htmlspecialchars(capstone_url('assets/LOGO%20(2).jpg')) ?>" alt="EnformaSys Logo" class="h-10 w-10 rounded-xl object-cover shadow border border-[#E8C7C5]/60">
            <div class="sidebar-text min-w-0">
                <div class="max-w-[10.5rem] truncate text-sm font-black leading-5 text-[#59443F] font-heading"><?= htmlspecialchars($appName) ?></div>
                <div class="max-w-[10.5rem] truncate text-xs font-semibold text-[#8B7168]"><?= htmlspecialchars($roleSubtitle) ?></div>
            </div>
            <button id="closeSidebar" type="button" class="ml-auto inline-flex h-10 w-10 items-center justify-center rounded-xl text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] lg:hidden cursor-pointer shrink-0" aria-label="Close sidebar">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <!-- ===================================================================== -->
        <!-- DYNAMIC PLACEHOLDER: SIDEBAR MENU LINKS                               -->
        <!-- Injects role-specific navigation links or a custom menu template file -->
        <!-- ===================================================================== -->
        <?php
        if (isset($sidebarMenuContent) && is_callable($sidebarMenuContent)) {
            call_user_func($sidebarMenuContent);
        } elseif (!empty($sidebarMenuFile) && file_exists($sidebarMenuFile)) {
            include $sidebarMenuFile;
        } else {
            include __DIR__ . '/sidebar_menu.php';
        }
        ?>
    </aside>

    <!-- ========================================================================= -->
    <!-- MAIN CONTENT WRAPPER                                                      -->
    <!-- Properly aligned next to the floating sidebar (lg:pl-[18rem])             -->
    <!-- ========================================================================= -->
    <div id="appShell" class="min-h-screen transition-[padding] duration-200 pt-0 lg:pt-4 lg:pl-[18rem] lg:pr-4 lg:pb-6">
        
        <!-- ===================================================================== -->
        <!-- CLEAN TOP NAVBAR                                                      -->
        <!-- Contains ONLY: Page Title and User Profile/Badge.                     -->
        <!-- Search bar and header logout buttons are intentionally excluded.      -->
        <!-- ===================================================================== -->
        <?php require __DIR__ . '/header.php'; ?>

        <!-- ===================================================================== -->
        <!-- DYNAMIC PLACEHOLDER: MAIN PAGE CONTENT                                -->
        <!-- Loads the page dashboard, form, or report data inside unified wrapper -->
        <!-- ===================================================================== -->
        <main class="px-4 lg:px-0 pt-5 pb-6 lg:pt-5 lg:pb-6">
            <?php
            if (isset($pageContent)) {
                if (is_callable($pageContent)) {
                    call_user_func($pageContent);
                } elseif (is_string($pageContent) && file_exists($pageContent)) {
                    include $pageContent;
                } elseif (is_string($pageContent)) {
                    echo $pageContent;
                }
            }
            ?>
        <?php if (isset($pageContent)): ?>
        </main>
    </div>

    <!-- Layout Scripts -->
    <script defer src="<?= htmlspecialchars(capstone_url('assets/app_layout.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/app_layout.js') ?>"></script>
    <script defer src="<?= htmlspecialchars(capstone_url('assets/ajax.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/ajax.js') ?>"></script>
</body>
</html>
<?php endif; ?>
