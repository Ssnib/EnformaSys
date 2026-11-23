<?php
/**
 * Dynamic Sidebar Navigation Placeholder
 * Renders role-based menu items using the modern SaaS "Floating Bubble" aesthetic.
 */
require_once __DIR__ . '/url.php';

$role = (string)($_SESSION['role'] ?? '');
$activeNav = isset($activeNav) ? (string)$activeNav : '';

$is_dean = $role === 'Dean' || $role === 'Admin';
$is_rector = $role === 'Rector' || $is_dean;
$is_admin = $is_dean || $role === 'Rector' || $role === 'Admin';
$is_registrar = $role === 'Registrar';
$can_manage = $is_admin || $is_registrar;
$is_academic_formator = $role === 'AcademicFormator';
$is_spiritual_director = $role === 'SpiritualDirector';
$is_human_formator = $role === 'HumanFormator';
$is_pastoral_formator = $role === 'PastoralFormator';
$is_cashier = $role === 'Cashier';
$is_shrine = $role === 'Shrine';
$is_student = $role === 'Student';

// Build navigation items if not already passed into the template
if (!isset($nav) || !is_array($nav) || empty($nav)) {
    $nav = [];

    if ($can_manage) {
        $nav[] = [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'href' => capstone_url('index.php'),
        ];

        // 1. Student Affairs (Collapsible)
        $student_affairs_sub = [
            [
                'key' => 'student_profiles',
                'label' => 'Student Profiles',
                'icon' => 'fa-solid fa-id-card',
                'href' => capstone_url('modules/student_profiles.php'),
            ],
        ];
        if ($is_registrar) {
            $student_affairs_sub[] = [
                'key' => 'enrollment_records',
                'label' => 'Enrollment',
                'icon' => 'fa-solid fa-clock-rotate-left',
                'href' => capstone_url('modules/enrollment_records.php'),
            ];
        }
        $student_affairs_sub[] = [
            'key' => 'applicants',
            'label' => ($role === 'Rector') ? 'Seminarians' : 'Manage Seminarian',
            'icon' => 'fa-solid fa-user-gear',
            'href' => capstone_url('modules/applicants.php'),
        ];
        $student_affairs_sub[] = [
            'key' => 'formation_summary',
            'label' => 'Formation Summary',
            'icon' => 'fa-solid fa-layer-group',
            'href' => capstone_url('modules/formation_summary.php'),
        ];
        $nav[] = [
            'key' => 'student_affairs_group',
            'label' => 'Student Affairs',
            'icon' => 'fa-solid fa-users',
            'sub' => $student_affairs_sub,
        ];

        // 2. Academics (Collapsible)
        $pending_grade_edits_count = 0;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            @require_once __DIR__ . '/db.php';
        }
        if (isset($conn) && $conn instanceof mysqli) {
            $res_pge = @$conn->query("SELECT COUNT(*) as c FROM grade_edit_requests WHERE status = 'Pending'");
            if ($res_pge) {
                $pending_grade_edits_count = (int)($res_pge->fetch_assoc()['c'] ?? 0);
            }
        }

        $academics_sub = [];
        if ($role !== 'Rector' && ($is_registrar || $is_dean)) {
            $academics_sub[] = [
                'key' => 'academic_years',
                'label' => 'Academic Years',
                'icon' => 'fa-solid fa-calendar-days',
                'href' => capstone_url('modules/academic_years.php'),
            ];
        }
        $academics_sub[] = [
            'key' => 'curriculum_master',
            'label' => 'Curriculum',
            'icon' => 'fa-solid fa-book-open',
            'href' => capstone_url('modules/curriculum_master.php'),
        ];
        if ($is_registrar || $is_dean || $role === 'Rector') {
            $academics_sub[] = [
                'key' => 'grade_edit_requests',
                'label' => 'Grade Edit Requests',
                'icon' => 'fa-solid fa-file-pen',
                'href' => capstone_url('modules/grade_edit_requests.php'),
                'badge' => $pending_grade_edits_count,
                'badge_class' => 'bg-rose-500 text-white',
            ];
        }
        $nav[] = [
            'key' => 'academics_group',
            'label' => 'Academics',
            'icon' => 'fa-solid fa-graduation-cap',
            'sub' => $academics_sub,
        ];

        // 3. Financial Management (Admin Only: Semester Fees)
        if ($is_admin) {
            $nav[] = [
                'key' => 'financial_management_group',
                'label' => 'Financial Management',
                'icon' => 'fa-solid fa-wallet',
                'sub' => [
                    [
                        'key' => 'cashier_fees',
                        'label' => 'Semester Fees',
                        'icon' => 'fa-solid fa-file-invoice-dollar',
                        'href' => capstone_url('modules/cashier_fees.php'),
                    ],
                ],
            ];
        }

        // 4. Registrar & Reports (Collapsible)
        $reports_sub = [];
        if ($is_registrar) {
            $reports_sub[] = [
                'key' => 'registrar_analytics',
                'label' => 'Registrar Analytics',
                'icon' => 'fa-solid fa-chart-pie',
                'href' => capstone_url('modules/registrar_analytics.php'),
            ];
        }
        if ($role === 'Rector') {
            $reports_sub[] = [
                'key' => 'enrolled_subjects_report',
                'label' => 'Enrolled Subjects',
                'icon' => 'fa-solid fa-file-lines',
                'href' => capstone_url('modules/enrolled_subjects_report.php'),
            ];
        }
        $reports_sub[] = [
            'key' => 'graduate_reports',
            'label' => 'Graduate Reports',
            'icon' => 'fa-solid fa-file-signature',
            'href' => capstone_url('modules/graduate_reports.php'),
        ];
        if ($is_admin) {
            $reports_sub[] = [
                'key' => 'admin_analytics',
                'label' => 'Admin Analytics',
                'icon' => 'fa-solid fa-chart-line',
                'href' => capstone_url('modules/admin_analytics.php'),
            ];
            if ($is_dean) {
                $reports_sub[] = [
                    'key' => 'migrations',
                    'label' => 'Migrations',
                    'icon' => 'fa-solid fa-database',
                    'href' => capstone_url('migrate.php'),
                ];
            }
        }
        $reports_sub[] = [
            'key' => 'backup_audit',
            'label' => 'Backup & Audit Trail',
            'icon' => 'fa-solid fa-shield-halved',
            'href' => capstone_url('modules/backup_audit.php'),
        ];
        $nav[] = [
            'key' => 'reports_group',
            'label' => ($role === 'Rector') ? 'Reports & Analytics' : 'Registrar & Reports',
            'icon' => 'fa-solid fa-chart-simple',
            'sub' => $reports_sub,
        ];

        // Dedicated Logout Link in Sidebar
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_cashier) {
        $nav[] = [
            'key' => 'cashier_dashboard',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'href' => capstone_url('modules/cashier_dashboard.php'),
        ];

        $nav[] = [
            'key' => 'financial_management_group',
            'label' => 'Financial Management',
            'icon' => 'fa-solid fa-wallet',
            'sub' => [
                [
                    'key' => 'cashier_accounts',
                    'label' => 'Accounts',
                    'icon' => 'fa-solid fa-users',
                    'href' => capstone_url('modules/cashier_accounts.php'),
                ],
                [
                    'key' => 'cashier_payments',
                    'label' => 'Payments',
                    'icon' => 'fa-solid fa-money-bill-wave',
                    'href' => capstone_url('modules/cashier_payments.php'),
                ],
            ],
        ];

        // Query pending SOA requests count for dynamic notification badge
        $sidebar_pending_soa_count = 0;
        if (isset($pending_soa_count)) {
            $sidebar_pending_soa_count = (int)$pending_soa_count;
        } elseif (isset($conn) && $conn instanceof mysqli) {
            $res_soa_c = @$conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE status = 'Pending'");
            if ($res_soa_c) {
                $sidebar_pending_soa_count = (int)($res_soa_c->fetch_assoc()['c'] ?? 0);
            }
        }

        $nav[] = [
            'key' => 'documents_reports_group',
            'label' => 'Documents & Reports',
            'icon' => 'fa-solid fa-folder-closed',
            'sub' => [
                [
                    'key' => 'cashier_requests',
                    'label' => 'SOA Requests',
                    'icon' => 'fa-solid fa-file-invoice',
                    'href' => capstone_url('modules/cashier_requests.php'),
                    'badge' => $sidebar_pending_soa_count,
                ],
                [
                    'key' => 'cashier_reports',
                    'label' => 'Reports',
                    'icon' => 'fa-solid fa-chart-pie',
                    'href' => capstone_url('modules/cashier_reports.php'),
                ],
            ],
        ];

        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_student) {
        // Resolve $seminarian_id for the logged-in student
        $seminarian_id = (int)($_SESSION['seminarian_id'] ?? 0);
        if ($seminarian_id <= 0 && isset($_SESSION['user'])) {
            $uid_tmp = (int)$_SESSION['user'];
            $s_res = @$conn->query("SELECT id FROM seminarians WHERE user_id = {$uid_tmp} LIMIT 1");
            if ($s_res && ($sr = $s_res->fetch_assoc())) {
                $seminarian_id = (int)$sr['id'];
                $_SESSION['seminarian_id'] = $seminarian_id;
            }
        }

        $approved_grades_count = 0;
        $approved_evals_count = 0;
        $approved_soa_count = 0;

        if ($seminarian_id > 0 && isset($conn) && $conn instanceof mysqli) {
            // 1. Approved Grade Requests count
            $q_grades = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE seminarian_id = {$seminarian_id} AND assignment_type = 'Academic' AND status = 'Approved' AND is_read = 0");
            if ($q_grades) {
                $approved_grades_count = (int)($q_grades->fetch_assoc()['c'] ?? 0);
            }

            // 2. Approved Evaluation Requests count (Spiritual, Human, Pastoral)
            $q_evals = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE seminarian_id = {$seminarian_id} AND assignment_type IN ('Spiritual', 'Human', 'Pastoral') AND status = 'Approved' AND is_read = 0");
            if ($q_evals) {
                $approved_evals_count = (int)($q_evals->fetch_assoc()['c'] ?? 0);
            }

            // 3. Approved / Completed SOA Requests count
            $q_soa = @$conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE seminarian_id = {$seminarian_id} AND status IN ('Approved', 'Completed', 'Sent') AND is_read = 0");
            if ($q_soa) {
                $approved_soa_count = (int)($q_soa->fetch_assoc()['c'] ?? 0);
            }
        }

        $nav[] = [
            'key' => 'my_progress',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-house-chimney',
            'href' => capstone_url('modules/student_view.php'),
        ];
        $nav[] = [
            'key' => 'my_class',
            'label' => 'My Class',
            'icon' => 'fa-solid fa-chalkboard-user',
            'href' => capstone_url('modules/my_class.php'),
        ];
        $nav[] = [
            'key' => 'my_academic',
            'label' => 'Grades',
            'icon' => 'fa-solid fa-graduation-cap',
            'href' => capstone_url('modules/student_academic.php'),
            'badge' => $approved_grades_count,
            'badge_class' => 'bg-emerald-500 text-white',
        ];
        $nav[] = [
            'key' => 'my_balance',
            'label' => 'Ledger',
            'icon' => 'fa-solid fa-receipt',
            'href' => capstone_url('modules/student_balance.php'),
            'badge' => $approved_soa_count,
            'badge_class' => 'bg-red-500 text-white',
        ];
        $nav[] = [
            'type' => 'header',
            'label' => 'EVALUATIONS',
        ];
        $nav[] = [
            'key' => 'evaluations',
            'label' => 'Evaluations',
            'icon' => 'fa-solid fa-clipboard-check',
            'href' => capstone_url('modules/student_academic_evaluation.php'),
            'badge' => $approved_evals_count,
            'badge_class' => 'bg-emerald-500 text-white',
        ];
        $nav[] = [
            'key' => 'edit_profile',
            'label' => 'Profile',
            'icon' => 'fa-solid fa-user-pen',
            'href' => capstone_url('modules/student_edit_profile.php'),
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_academic_formator) {
        $nav[] = [
            'key' => 'afms_dashboard',
            'label' => 'Academic Dashboard',
            'icon' => 'fa-solid fa-chart-column',
            'href' => capstone_url('modules/afms_dashboard.php'),
        ];
        // Query pending Academic Grade Requests count for dynamic notification badge
        $pending_grade_requests = 0;
        if (isset($conn) && $conn instanceof mysqli) {
            $res_pgr = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE assignment_type = 'Academic' AND status = 'Pending'");
            if ($res_pgr) {
                $pending_grade_requests = (int)($res_pgr->fetch_assoc()['c'] ?? 0);
            }
        }

        $nav[] = [
            'key' => 'afms_records_group',
            'label' => 'Academic Records',
            'icon' => 'fa-solid fa-users',
            'sub' => [
                [
                    'key' => 'afms_students',
                    'label' => 'Students',
                    'icon' => 'fa-solid fa-user-graduate',
                    'href' => capstone_url('modules/afms_students.php'),
                ],
                [
                    'key' => 'afms_grades',
                    'label' => 'Grade Management',
                    'icon' => 'fa-solid fa-file-pen',
                    'href' => capstone_url('modules/afms_grades.php'),
                    'badge' => $pending_grade_requests,
                ],
                [
                    'key' => 'afms_reports',
                    'label' => 'Academic Reports',
                    'icon' => 'fa-solid fa-file-lines',
                    'href' => capstone_url('modules/afms_reports.php'),
                ],
            ]
        ];
        $nav[] = [
            'key' => 'afms_analytics',
            'label' => 'Analytics',
            'icon' => 'fa-solid fa-chart-pie',
            'href' => capstone_url('modules/afms_analytics.php'),
        ];
        $nav[] = [
            'key' => 'curriculum_master',
            'label' => 'Curriculum',
            'icon' => 'fa-solid fa-book-open',
            'href' => capstone_url('modules/curriculum_master.php'),
        ];
        $nav[] = [
            'key' => 'afms_evaluations_group',
            'label' => 'Evaluations',
            'icon' => 'fa-solid fa-clipboard-check',
            'sub' => [
                [
                    'key' => 'afe_dashboard',
                    'label' => 'Academic Evaluation',
                    'icon' => 'fa-solid fa-clipboard-check',
                    'href' => capstone_url('modules/afe_dashboard.php'),
                ],
                [
                    'key' => 'afe_evaluations',
                    'label' => 'Evaluations',
                    'icon' => 'fa-solid fa-list-check',
                    'href' => capstone_url('modules/afe_evaluations.php'),
                ],
                [
                    'key' => 'afe_questions',
                    'label' => 'Eval Questions',
                    'icon' => 'fa-solid fa-list',
                    'href' => capstone_url('modules/afe_questions.php'),
                ],
            ],
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_human_formator) {
        $nav[] = [
            'key' => 'hfe_dashboard',
            'label' => 'Human Dashboard',
            'icon' => 'fa-solid fa-user-check',
            'href' => capstone_url('modules/hfe_dashboard.php'),
        ];
        $nav[] = [
            'key' => 'hfe_reports',
            'label' => 'Human Reports',
            'icon' => 'fa-solid fa-file-lines',
            'href' => capstone_url('modules/hfe_reports.php'),
        ];
        $nav[] = [
            'key' => 'hfe_evaluations',
            'label' => 'Human Forms',
            'icon' => 'fa-solid fa-clipboard-check',
            'href' => capstone_url('modules/hfe_evaluations.php'),
        ];
        $nav[] = [
            'key' => 'hfe_seminarians',
            'label' => 'Eval Access',
            'icon' => 'fa-solid fa-eye',
            'href' => capstone_url('modules/hfe_seminarians.php'),
        ];
        $nav[] = [
            'key' => 'hfe_questions',
            'label' => 'Human Qs',
            'icon' => 'fa-solid fa-list',
            'href' => capstone_url('modules/hfe_questions.php'),
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_spiritual_director) {
        $nav[] = [
            'key' => 'sfe_dashboard',
            'label' => 'Spiritual Dashboard',
            'icon' => 'fa-solid fa-hands-praying',
            'href' => capstone_url('modules/sfe_dashboard.php'),
        ];
        $nav[] = [
            'key' => 'sfe_reports',
            'label' => 'Spiritual Reports',
            'icon' => 'fa-solid fa-file-lines',
            'href' => capstone_url('modules/sfe_reports.php'),
        ];
        $nav[] = [
            'key' => 'sfe_evaluations',
            'label' => 'Spiritual Forms',
            'icon' => 'fa-solid fa-clipboard-check',
            'href' => capstone_url('modules/sfe_evaluations.php'),
        ];
        $nav[] = [
            'key' => 'sfe_seminarians',
            'label' => 'Eval Access',
            'icon' => 'fa-solid fa-eye',
            'href' => capstone_url('modules/sfe_seminarians.php'),
        ];
        $nav[] = [
            'key' => 'sfe_questions',
            'label' => 'Spiritual Qs',
            'icon' => 'fa-solid fa-list',
            'href' => capstone_url('modules/sfe_questions.php'),
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } elseif ($is_pastoral_formator) {
        $nav[] = [
            'key' => 'pfe_dashboard',
            'label' => 'Pastoral Dashboard',
            'icon' => 'fa-solid fa-hands-holding-circle',
            'href' => capstone_url('modules/pfe_dashboard.php'),
        ];
        $nav[] = [
            'key' => 'pfe_reports',
            'label' => 'Pastoral Reports',
            'icon' => 'fa-solid fa-file-lines',
            'href' => capstone_url('modules/pfe_reports.php'),
        ];
        $nav[] = [
            'key' => 'pfe_evaluations',
            'label' => 'Pastoral Forms',
            'icon' => 'fa-solid fa-clipboard-check',
            'href' => capstone_url('modules/pfe_evaluations.php'),
        ];
        $nav[] = [
            'key' => 'pfe_seminarians',
            'label' => 'Eval Access',
            'icon' => 'fa-solid fa-eye',
            'href' => capstone_url('modules/pfe_seminarians.php'),
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    } else {
        // Fallback navigation
        $nav[] = [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'href' => capstone_url('index.php'),
        ];
        $nav[] = [
            'key' => 'logout',
            'label' => 'Logout',
            'icon' => 'fa-solid fa-arrow-right-from-bracket',
            'href' => capstone_url('logout.php'),
        ];
    }
}
?>

<nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1.5" aria-label="Sidebar Navigation">
    <?php foreach ($nav as $item): ?>
        <?php if (isset($item['type']) && $item['type'] === 'header'): ?>
            <div class="pt-3 pb-1 px-3.5 text-[10px] font-extrabold uppercase tracking-widest text-[#8B7168]/75 sidebar-text">
                <?= htmlspecialchars($item['label']) ?>
            </div>
        <?php elseif (isset($item['sub']) && is_array($item['sub'])): 
            $has_active_sub = false;
            foreach ($item['sub'] as $sub) {
                if ($activeNav !== '' && $activeNav === $sub['key']) {
                    $has_active_sub = true;
                    break;
                }
            }
            $btn_class = $has_active_sub
                ? 'bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs'
                : 'text-[#8B7168] hover:bg-[#F8EEEE]/80 hover:text-[#59443F]';
        ?>
            <div class="sidebar-dropdown-group mb-1">
                <button type="button" 
                        class="group sidebar-dropdown-toggle w-full <?= $btn_class ?> flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-200" 
                        aria-expanded="<?= $has_active_sub ? 'true' : 'false' ?>">
                    <i class="<?= htmlspecialchars($item['icon']) ?> w-5 text-center text-base flex-shrink-0 <?= $has_active_sub ? 'text-[#59443F]' : 'text-[#8B7168] group-hover:text-[#59443F]' ?> transition-colors"></i>
                    <span class="sidebar-text flex-1 text-left tracking-wide"><?= htmlspecialchars($item['label']) ?></span>
                    <?php 
                    $grp_badge = 0;
                    if (isset($item['sub']) && is_array($item['sub'])) {
                        foreach ($item['sub'] as $si) {
                            if (!empty($si['badge']) && (int)$si['badge'] > 0) $grp_badge += (int)$si['badge'];
                        }
                    }
                    if ($grp_badge > 0 && !$has_active_sub): ?>
                        <span data-group-badge-for="<?= htmlspecialchars($item['key']) ?>" class="sidebar-text inline-flex items-center justify-center min-w-[1.2rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full bg-rose-500 text-white mr-1.5 shadow-xs">
                            <?= $grp_badge ?>
                        </span>
                    <?php endif; ?>
                    <i class="sidebar-text fa-solid fa-chevron-down text-xs transition-transform duration-300 ml-auto <?= $has_active_sub ? 'rotate-180 text-[#59443F]' : 'text-[#8B7168] group-hover:text-[#59443F]' ?>"></i>
                </button>
                <div class="sidebar-dropdown-menu <?= $has_active_sub ? 'open' : '' ?>">
                    <div class="sidebar-dropdown-inner">
                        <div class="sidebar-text bg-[#F8EEEE]/60 border-l-2 border-[#E8C7C5] my-1 ml-4 pl-3 pr-2 py-1.5 space-y-1 rounded-xl">
                            <?php foreach ($item['sub'] as $sub): 
                                $is_active = $activeNav !== '' && $activeNav === $sub['key'];
                                $a_class = $is_active
                                    ? 'bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs'
                                    : 'text-[#8B7168] hover:bg-white hover:text-[#59443F]';
                                $icon_class = $is_active ? 'text-[#59443F]' : 'text-[#8B7168] group-hover:text-[#59443F]';
                            ?>
                                <a href="<?= htmlspecialchars($sub['href']) ?>" data-nav-key="<?= htmlspecialchars($sub['key']) ?>" class="group flex items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-xs font-semibold transition-all duration-150 <?= $a_class ?>">
                                    <i class="<?= htmlspecialchars($sub['icon'] ?? 'fa-solid fa-circle text-[6px]') ?> text-xs w-4 text-center opacity-85 group-hover:opacity-100 <?= $icon_class ?>"></i>
                                    <span class="truncate"><?= htmlspecialchars($sub['label']) ?></span>
                                    <?php 
                                    $sub_badge = isset($sub['badge']) ? (int)$sub['badge'] : ($sub['key'] === 'cashier_requests' && isset($sidebar_pending_soa_count) ? (int)$sidebar_pending_soa_count : ($sub['key'] === 'afms_grades' && isset($pending_grade_requests) ? (int)$pending_grade_requests : 0));
                                    if ($sub_badge > 0): ?>
                                        <span data-badge-for="<?= htmlspecialchars($sub['key']) ?>" class="ml-auto inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full bg-rose-500 text-white shadow-xs">
                                            <?= $sub_badge ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php
                $is_active = false;
                if ($activeNav !== '') {
                    if ($activeNav === $item['key']) {
                        $is_active = true;
                    } elseif ($item['key'] === 'evaluations' && in_array($activeNav, ['evaluations', 'my_academic_eval', 'my_spiritual_eval', 'my_human_eval', 'my_pastoral_eval'], true)) {
                        $is_active = true;
                    } elseif ($item['key'] === 'my_academic' && in_array($activeNav, ['my_academic', 'grades'], true)) {
                        $is_active = true;
                    } elseif ($item['key'] === 'my_balance' && in_array($activeNav, ['my_balance', 'ledger'], true)) {
                        $is_active = true;
                    } elseif ($item['key'] === 'my_progress' && in_array($activeNav, ['my_progress', 'dashboard'], true)) {
                        $is_active = true;
                    } elseif ($item['key'] === 'my_class' && in_array($activeNav, ['my_class', 'classes', 'subjects'], true)) {
                        $is_active = true;
                    } elseif ($item['key'] === 'edit_profile' && in_array($activeNav, ['edit_profile', 'profile', 'my_account'], true)) {
                        $is_active = true;
                    }
                }
                $is_logout = ($item['key'] ?? '') === 'logout';
                $a_class = $is_active
                    ? 'bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs'
                    : ($is_logout 
                        ? 'text-[#8B7168] hover:bg-rose-50/60 hover:text-rose-600' 
                        : 'text-[#8B7168] hover:bg-[#F8EEEE]/80 hover:text-[#59443F]');
                $icon_color = $is_active ? 'text-[#59443F]' : ($is_logout ? 'text-[#8B7168]' : 'text-[#8B7168] group-hover:text-[#59443F]');
            ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" data-nav-key="<?= htmlspecialchars($item['key'] ?? '') ?>" class="<?= $a_class ?> group flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-150">
                <i class="<?= htmlspecialchars($item['icon']) ?> w-5 text-center text-base flex-shrink-0 <?= $icon_color ?> transition-colors"></i>
                <span class="sidebar-text tracking-wide"><?= htmlspecialchars($item['label']) ?></span>
                <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): 
                    $badge_bg = !empty($item['badge_class']) ? $item['badge_class'] : 'bg-emerald-500 text-white';
                ?>
                    <span data-badge-for="<?= htmlspecialchars($item['key'] ?? '') ?>" class="sidebar-text ml-auto inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full <?= $badge_bg ?> shadow-xs">
                        <?= (int)$item['badge'] ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<?php
$sb_username = !empty($_SESSION['username']) ? (string)$_SESSION['username'] : (function_exists('get_header_user_label') ? get_header_user_label() : 'User');
$sb_role_label = function_exists('get_header_user_label') ? get_header_user_label() : ($role ?: 'User');
$sb_initial = strtoupper(substr($sb_username, 0, 1));
$sb_avatar_url = null;
if (!empty($is_student) && isset($_SESSION['user'])) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        @require_once __DIR__ . '/db.php';
    }
    if (!function_exists('get_seminarian_avatar_url')) {
        @require_once __DIR__ . '/seminarian_profile_helper.php';
    }
    if (isset($conn) && $conn instanceof mysqli && function_exists('get_seminarian_avatar_url')) {
        $uid_tmp = (int)$_SESSION['user'];
        $stmt_sb_pic = $conn->prepare("SELECT profile_picture FROM seminarians WHERE user_id = ? LIMIT 1");
        if ($stmt_sb_pic) {
            $stmt_sb_pic->bind_param("i", $uid_tmp);
            $stmt_sb_pic->execute();
            $sp = $stmt_sb_pic->get_result()->fetch_assoc();
            $stmt_sb_pic->close();
            if ($sp) {
                $sb_avatar_url = get_seminarian_avatar_url($sp['profile_picture'] ?? null);
            }
        }
    }
}
?>
<!-- Compact Floating Bottom User Status & Quick Logout -->
<div class="mt-auto border-t border-[#E8C7C5]/40 p-3">
    <div class="p-2 sm:p-2.5 rounded-2xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/50 flex items-center justify-between gap-2.5 transition-all hover:bg-[#F8EEEE]">
        <div class="flex items-center gap-2.5 min-w-0">
            <div class="w-10 h-10 rounded-full bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 flex items-center justify-center font-extrabold text-xs shrink-0 shadow-2xs overflow-hidden">
                <?php if (!empty($sb_avatar_url)): ?>
                    <img id="sidebarUserAvatar" src="<?= htmlspecialchars($sb_avatar_url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($sb_username, ENT_QUOTES, 'UTF-8') ?>" class="w-10 h-10 rounded-full object-cover">
                <?php else: ?>
                    <?= htmlspecialchars($sb_initial) ?>
                <?php endif; ?>
            </div>
            <div class="sidebar-text min-w-0">
                <div id="sidebarUsernameText" class="truncate text-xs font-bold text-[#59443F] leading-tight">
                    <?= htmlspecialchars($sb_username) ?>
                </div>
                <div class="flex items-center gap-1.5 mt-0.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                    <span class="text-[10px] font-semibold text-[#8B7168] truncate">
                        <?= htmlspecialchars($sb_role_label) ?>
                    </span>
                </div>
            </div>
        </div>
        <a href="<?= htmlspecialchars(capstone_url('logout.php')) ?>" 
           title="Sign Out" 
           aria-label="Sign Out"
           class="sidebar-text inline-flex h-7 w-7 items-center justify-center rounded-xl text-[#8B7168] hover:text-rose-600 hover:bg-white transition shrink-0 shadow-2xs">
            <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
        </a>
    </div>
</div>

