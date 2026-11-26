<?php
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/global_modal.php';

$appName = 'EnformaSys';
$systemName = 'Littleway College Seminary';
$pageTitle = isset($pageTitle) ? (string)$pageTitle : $appName;
$activeNav = isset($activeNav) ? (string)$activeNav : '';
$role = (string)($_SESSION['role'] ?? '');

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

$nav = [];
if (!$is_shrine) {
    if ($is_spiritual_director) {
    } elseif ($is_human_formator) {
    } elseif ($is_pastoral_formator) {
    } elseif ($is_academic_formator) {
    } elseif ($is_cashier) {
        // Excluded: Cashier gets single entry point Dashboard below
    } elseif ($is_student) {
        // Excluded: Student gets custom single entry point Dashboard below
    } else {
        $nav[] = [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'fa-solid fa-gauge-high',
            'href' => capstone_url('index.php'),
        ];
    }
}

$pending_eval_badge = 0;
$pending_eval_access_badge = 0;
if ($is_academic_formator || $is_human_formator || $is_spiritual_director || $is_pastoral_formator) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/db.php';
    }
    require_once __DIR__ . '/eval_ui_helpers.php';
    require_once __DIR__ . '/access_requests.php';
    $eval_role_key = $is_academic_formator
        ? 'AcademicFormator'
        : ($is_human_formator ? 'HumanFormator' : ($is_spiritual_director ? 'SpiritualDirector' : 'PastoralFormator'));
    $pending_eval_badge = eval_count_pending_for_role($conn, $eval_role_key, (int)($_SESSION['user'] ?? 0));

    $eval_access_pillar = $is_academic_formator
        ? 'Academic'
        : ($is_human_formator ? 'Human' : ($is_spiritual_director ? 'Spiritual' : 'Pastoral'));
    $badge_ay = function_exists('get_active_academic_year') ? (string)get_active_academic_year() : (function_exists('get_current_academic_year') ? (string)get_current_academic_year() : '');
    $badge_sem_raw = function_exists('get_active_semester') ? (string)get_active_semester() : '1st';
    $badge_sem = function_exists('enformasys_normalize_semester') ? (enformasys_normalize_semester($badge_sem_raw) ?: '1st') : '1st';
    $badge_cache_key = "eval_access_pending_{$eval_access_pillar}_{$badge_ay}_{$badge_sem}";
    $pending_eval_access_badge = function_exists('enformasys_cached_badge_count')
        ? enformasys_cached_badge_count($badge_cache_key, static function () use ($conn, $eval_access_pillar, $badge_ay, $badge_sem): int {
            return capstone_access_count_pending_eval_requests($conn, $eval_access_pillar, $badge_ay, $badge_sem);
        })
        : capstone_access_count_pending_eval_requests($conn, $eval_access_pillar, $badge_ay, $badge_sem);
}

if ($can_manage) {
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
        $pending_grade_edits_count = function_exists('enformasys_cached_badge_count')
            ? enformasys_cached_badge_count('grade_edit_pending', static function () use ($conn): int {
                $r = @$conn->query("SELECT COUNT(*) as c FROM grade_edit_requests WHERE status = 'Pending'");
                return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            })
            : (int)(@$conn->query("SELECT COUNT(*) as c FROM grade_edit_requests WHERE status = 'Pending'")?->fetch_assoc()['c'] ?? 0);
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
} elseif ($is_cashier) {
    // Main: Dashboard (Single entry point)
    $nav[] = [
        'key' => 'cashier_dashboard',
        'label' => 'Dashboard',
        'icon' => 'fa-solid fa-gauge-high',
        'href' => capstone_url('modules/cashier_dashboard.php'),
    ];

    // Financial Management: (Collapsible Group)
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
        $sidebar_pending_soa_count = function_exists('enformasys_cached_badge_count')
            ? enformasys_cached_badge_count('cashier_soa_pending', static function () use ($conn): int {
                $r = @$conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE status = 'Pending'");
                return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            })
            : (int)(@$conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE status = 'Pending'")?->fetch_assoc()['c'] ?? 0);
    }

    // Documents & Reports: (Collapsible Group)
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
} elseif ($is_student) {
    require_once __DIR__ . '/seminarian_profile_helper.php';
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
        if (function_exists('enformasys_cached_badge_count')) {
            $approved_grades_count = enformasys_cached_badge_count("stu_grades_{$seminarian_id}", static function () use ($conn, $seminarian_id): int {
                $r = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE seminarian_id = {$seminarian_id} AND assignment_type = 'Academic' AND status = 'Approved' AND is_read = 0");
                return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            });
            $approved_evals_count = enformasys_cached_badge_count("stu_evals_{$seminarian_id}", static function () use ($conn, $seminarian_id): int {
                $r = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE seminarian_id = {$seminarian_id} AND assignment_type IN ('Spiritual', 'Human', 'Pastoral') AND status = 'Approved' AND is_read = 0");
                return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            });
            $approved_soa_count = enformasys_cached_badge_count("stu_soa_{$seminarian_id}", static function () use ($conn, $seminarian_id): int {
                $r = @$conn->query("SELECT COUNT(*) as c FROM cashier_soa_requests WHERE seminarian_id = {$seminarian_id} AND status IN ('Approved', 'Completed', 'Sent') AND is_read = 0");
                return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            });
        } else {
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
    if ($is_academic_formator) {
        // Query pending Academic Grade Requests count for dynamic notification badge
        $pending_grade_requests = 0;
        if (isset($conn) && $conn instanceof mysqli) {
            $pending_grade_requests = function_exists('enformasys_cached_badge_count')
                ? enformasys_cached_badge_count('afms_pending_grade_requests', static function () use ($conn): int {
                    $r = @$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE assignment_type = 'Academic' AND status = 'Pending'");
                    return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
                })
                : (int)(@$conn->query("SELECT COUNT(*) as c FROM seminarian_access_requests WHERE assignment_type = 'Academic' AND status = 'Pending'")?->fetch_assoc()['c'] ?? 0);
        }

        $nav[] = [
            'key' => 'afms_records_group',
            'label' => 'Academic Records',
            'icon' => 'fa-solid fa-users',
            'sub' => [
                [
                    'key' => 'afms_students',
                    'label' => 'Students',
                    'href' => capstone_url('modules/afms_students.php'),
                ],
                [
                    'key' => 'afms_grades',
                    'label' => 'Grade Management',
                    'href' => capstone_url('modules/afms_grades.php'),
                    'badge' => $pending_grade_requests,
                ],
                [
                    'key' => 'afms_reports',
                    'label' => 'Academic Reports',
                    'href' => capstone_url('modules/afms_reports.php'),
                ],
            ]
        ];
    }
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
    if ($is_academic_formator) {
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
                    'badge' => $pending_eval_badge,
                    'badge_class' => 'bg-rose-500 text-white',
                ],
                [
                    'key' => 'afe_seminarians',
                    'label' => 'Eval Access',
                    'icon' => 'fa-solid fa-eye',
                    'href' => capstone_url('modules/afe_seminarians.php'),
                    'badge' => $pending_eval_access_badge,
                    'badge_class' => 'bg-rose-500 text-white',
                ],
                [
                    'key' => 'afe_questions',
                    'label' => 'Eval Questions',
                    'icon' => 'fa-solid fa-list',
                    'href' => capstone_url('modules/afe_questions.php'),
                ],
            ],
        ];
    }
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
        'badge' => $pending_eval_badge,
        'badge_class' => 'bg-rose-500 text-white',
    ];
    $nav[] = [
        'key' => 'hfe_seminarians',
        'label' => 'Eval Access',
        'icon' => 'fa-solid fa-eye',
        'href' => capstone_url('modules/hfe_seminarians.php'),
        'badge' => $pending_eval_access_badge,
        'badge_class' => 'bg-rose-500 text-white',
    ];
    $nav[] = [
        'key' => 'hfe_questions',
        'label' => 'Human Qs',
        'icon' => 'fa-solid fa-list',
        'href' => capstone_url('modules/hfe_questions.php'),
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
    if ($is_spiritual_director) {
        $nav[] = [
            'key' => 'sfe_evaluations',
            'label' => 'Spiritual Forms',
            'icon' => 'fa-solid fa-clipboard-check',
            'href' => capstone_url('modules/sfe_evaluations.php'),
            'badge' => $pending_eval_badge,
            'badge_class' => 'bg-rose-500 text-white',
        ];
        $nav[] = [
            'key' => 'sfe_seminarians',
            'label' => 'Eval Access',
            'icon' => 'fa-solid fa-eye',
            'href' => capstone_url('modules/sfe_seminarians.php'),
            'badge' => $pending_eval_access_badge,
            'badge_class' => 'bg-rose-500 text-white',
        ];
        $nav[] = [
            'key' => 'sfe_questions',
            'label' => 'Spiritual Qs',
            'icon' => 'fa-solid fa-list',
            'href' => capstone_url('modules/sfe_questions.php'),
        ];
    }
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
        'badge' => $pending_eval_badge,
        'badge_class' => 'bg-rose-500 text-white',
    ];
    $nav[] = [
        'key' => 'pfe_seminarians',
        'label' => 'Eval Access',
        'icon' => 'fa-solid fa-eye',
        'href' => capstone_url('modules/pfe_seminarians.php'),
        'badge' => $pending_eval_access_badge,
        'badge_class' => 'bg-rose-500 text-white',
    ];
}

// Ensure dedicated logout link exists in sidebar for all roles
$has_logout = false;
foreach ($nav as $item) {
    if (($item['key'] ?? '') === 'logout') {
        $has_logout = true;
        break;
    }
}
if (!$has_logout) {
    $nav[] = [
        'key' => 'logout',
        'label' => 'Logout',
        'icon' => 'fa-solid fa-arrow-right-from-bracket',
        'href' => capstone_url('logout.php'),
    ];
}

$roleSubtitle = 'Littleway Seminary';
if ($is_student) {
    $roleSubtitle = 'Seminarian Portal';
} elseif ($is_registrar) {
    $roleSubtitle = 'Registrar Portal';
} elseif ($is_admin) {
    $roleSubtitle = 'Administration';
} elseif ($is_academic_formator) {
    $roleSubtitle = 'Academic Formator';
} elseif ($is_human_formator) {
    $roleSubtitle = 'Human Formator';
} elseif ($is_spiritual_director) {
    $roleSubtitle = 'Spiritual Director';
} elseif ($is_pastoral_formator) {
    $roleSubtitle = 'Pastoral Formator';
} elseif ($is_cashier) {
    $roleSubtitle = 'Cashier Portal';
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
    <title><?php echo htmlspecialchars($pageTitle === $appName ? $appName : ($pageTitle . ' - ' . $appName)); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(capstone_url('css/style.css')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(capstone_url('css/motion.css')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../css/motion.css'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(capstone_url('css/floating_theme.css')); ?>?v=<?php echo (int)@filemtime(__DIR__ . '/../css/floating_theme.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Sidebar Collapsible Accordion Dropdowns */
        .sidebar-dropdown-menu {
            display: grid;
            grid-template-rows: 0fr;
            transition: grid-template-rows 280ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sidebar-dropdown-menu.open {
            grid-template-rows: 1fr;
        }
        .sidebar-dropdown-inner {
            min-height: 0;
            overflow: hidden;
        }
        .sidebar-dropdown-toggle .fa-chevron-down {
            transition: transform 240ms cubic-bezier(0.16, 1, 0.3, 1), color 160ms ease;
        }
        @media (prefers-reduced-motion: reduce) {
            .sidebar-dropdown-menu,
            .sidebar-dropdown-toggle .fa-chevron-down {
                transition: none !important;
            }
        }
        :root {
            --bg-canvas: #f8f5f2;
            --surface-card: #ffffff;
            --surface-subtle: #fcfaf8;
            --brand-primary: #5c3a31;
            --brand-primary-hover: #462a23;
            --brand-accent: #b8860b;
            --border-hairline: rgba(92, 58, 49, 0.10);
            --shadow-card: 0 4px 20px -2px rgba(45, 27, 22, 0.05);
            --shadow-elevated: 0 12px 28px -6px rgba(45, 27, 22, 0.10);
            --bg-main: #f8f5f2;
            --bg-card: #ffffff;
            --text-primary: #3f2822;
            --text-secondary: #7b6158;
            --color-rose: #d6a6a6;
            --color-dusty: #e8c7c5;
            --color-brown: #5c3a31;
        }
        body {
            background-color: var(--bg-canvas) !important;
            color: var(--text-primary) !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif !important;
        }
        h1, h2, h3, h4, h5, h6, .font-heading {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif !important;
        }
        .shadow-soft {
            box-shadow: 0 4px 20px -2px rgba(89, 68, 63, 0.06), 0 2px 6px -1px rgba(89, 68, 63, 0.03) !important;
        }
        .shadow-soft-lg {
            box-shadow: 0 12px 28px -4px rgba(89, 68, 63, 0.1), 0 4px 12px -2px rgba(89, 68, 63, 0.05) !important;
        }
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
        @media print {
            body {
                background-color: #FFFFFF !important;
            }
            .shadow-soft, .shadow-soft-lg, .shadow-soft-xl {
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body class="bg-[#f8f5f2] text-[#3f2822] font-['Inter',sans-serif] antialiased min-h-screen print:bg-white print:shadow-none">
    <!-- Global Non-Blocking Progress Bar & Branded Preloader -->
    <div id="enformasys-top-progress" class="print:hidden" aria-hidden="true"></div>
    <div id="enformasys-preloader" class="print:hidden" role="status" aria-live="polite" aria-hidden="true">
        <div class="enformasys-preloader-ring">
            <div class="enformasys-preloader-spinner" aria-hidden="true"></div>
            <img src="<?php echo htmlspecialchars(capstone_url('assets/LOGO%20(2).jpg')); ?>" alt="EnformaSys Crest" class="enformasys-preloader-crest" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
            <span class="enformasys-preloader-fallback" style="display:none;" aria-hidden="true"><i class="fa-solid fa-church"></i></span>
        </div>
        <p id="enformasysPreloaderText" class="enformasys-preloader-text">Loading...</p>
    </div>

    <div id="sidebarBackdrop" class="print:hidden fixed inset-0 z-[1040] hidden bg-slate-900/45 backdrop-blur-xs lg:hidden transition-opacity" aria-hidden="true"></div>

    <aside id="sidebar" class="print:hidden -translate-x-full fixed z-[1050] lg:z-40 flex flex-col transition-all duration-300 inset-y-0 left-0 h-[100dvh] w-72 bg-white text-[#59443F] shadow-xl border-r border-gray-200/80 lg:translate-x-0 lg:fixed lg:top-0 lg:bottom-0 lg:left-0 lg:m-4 lg:h-[calc(100vh-2rem)] lg:w-64 lg:rounded-2xl lg:border lg:border-gray-200/80 lg:bg-white lg:shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-4 py-3.5 border-b border-gray-100 shrink-0 bg-white">
            <img src="<?php echo htmlspecialchars(capstone_url('assets/LOGO%20(2).jpg')); ?>" alt="Logo" class="h-10 w-10 rounded-xl object-cover shadow border border-[#E8C7C5]/60">
            <div class="sidebar-text min-w-0">
                <div class="max-w-[10.5rem] truncate text-sm font-bold leading-5 text-[#59443F] font-heading"><?php echo htmlspecialchars($appName); ?></div>
                <div class="max-w-[10.5rem] truncate text-xs font-semibold text-[#8B7168]"><?php echo htmlspecialchars($roleSubtitle); ?></div>
            </div>
            <button id="closeSidebar" type="button" class="ml-auto inline-flex h-10 w-10 items-center justify-center rounded-xl text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F] lg:hidden cursor-pointer shrink-0" aria-label="Close sidebar">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <nav class="flex-1 min-h-0 overflow-y-auto px-3 pt-3 pb-14 space-y-1.5" aria-label="Sidebar Navigation">
            <?php foreach ($nav as $item) { ?>
                <?php if (isset($item['type']) && $item['type'] === 'header') { ?>
                    <div class="pt-3 pb-1 px-3.5 text-[11px] font-bold uppercase tracking-[0.06em] text-[#7b6158]/75 sidebar-text">
                        <?php echo htmlspecialchars($item['label']); ?>
                    </div>
                <?php } elseif (isset($item['sub']) && is_array($item['sub'])) { 
                    $has_active_sub = false;
                    foreach ($item['sub'] as $sub) {
                        if ($activeNav !== '' && $activeNav === $sub['key']) {
                            $has_active_sub = true;
                            break;
                        }
                    }
                    $btn_class = $has_active_sub
                        ? 'sidebar-nav-active bg-[#E8C7C5]/60 text-[#5c3a31] font-bold shadow-xs'
                        : 'text-[#7b6158] hover:bg-[#faf6f3] hover:text-[#5c3a31]';
                ?>
                    <div class="sidebar-dropdown-group mb-1">
                        <button type="button" 
                                class="group sidebar-nav-item sidebar-dropdown-toggle w-full <?php echo $btn_class; ?> flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-200" 
                                aria-expanded="<?php echo $has_active_sub ? 'true' : 'false'; ?>">
                            <i class="<?php echo htmlspecialchars($item['icon']); ?> w-5 text-center text-base flex-shrink-0 <?php echo $has_active_sub ? 'text-[#5c3a31]' : 'text-[#7b6158] group-hover:text-[#5c3a31]'; ?> transition-colors"></i>
                            <span class="sidebar-text flex-1 text-left tracking-wide"><?php echo htmlspecialchars($item['label']); ?></span>
                            <?php 
                            $grp_badge = 0;
                            if (isset($item['sub']) && is_array($item['sub'])) {
                                foreach ($item['sub'] as $si) {
                                    if (!empty($si['badge']) && (int)$si['badge'] > 0) $grp_badge += (int)$si['badge'];
                                }
                            }
                            if ($grp_badge > 0 && !$has_active_sub): ?>
                                <span data-group-badge-for="<?php echo htmlspecialchars($item['key']); ?>" class="sidebar-text inline-flex items-center justify-center min-w-[1.2rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full bg-rose-500 text-white mr-1.5 shadow-xs">
                                    <?php echo $grp_badge; ?>
                                </span>
                            <?php endif; ?>
                            <i class="sidebar-text fa-solid fa-chevron-down text-xs transition-transform duration-300 ml-auto <?php echo $has_active_sub ? 'rotate-180 text-[#5c3a31]' : 'text-[#7b6158] group-hover:text-[#5c3a31]'; ?>"></i>
                        </button>
                        <div class="sidebar-dropdown-menu <?php echo $has_active_sub ? 'open' : ''; ?>">
                            <div class="sidebar-dropdown-inner">
                                <div class="sidebar-text bg-[#fcfaf8] border-l-2 border-[#E8C7C5] my-1 ml-4 pl-3 pr-2 py-1.5 space-y-1 rounded-xl">
                                    <?php foreach ($item['sub'] as $sub) { 
                                        $is_active = $activeNav !== '' && $activeNav === $sub['key'];
                                        $a_class = $is_active
                                            ? 'sidebar-nav-active bg-[#E8C7C5]/60 text-[#5c3a31] font-bold shadow-xs'
                                            : 'text-[#7b6158] hover:bg-white hover:text-[#5c3a31]';
                                        $icon_class = $is_active ? 'text-[#5c3a31]' : 'text-[#7b6158] group-hover:text-[#5c3a31]';
                                    ?>
                                        <a href="<?php echo htmlspecialchars($sub['href']); ?>" data-nav-key="<?php echo htmlspecialchars($sub['key']); ?>" class="group sidebar-nav-item flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-semibold transition-all duration-150 <?php echo $a_class; ?>">
                                            <i class="<?php echo htmlspecialchars($sub['icon'] ?? 'fa-solid fa-circle text-[6px]'); ?> text-xs w-4 text-center opacity-85 group-hover:opacity-100 <?php echo $icon_class; ?>"></i>
                                            <span class="truncate"><?php echo htmlspecialchars($sub['label']); ?></span>
                                            <?php 
                                            $sub_badge = isset($sub['badge']) ? (int)$sub['badge'] : ($sub['key'] === 'cashier_requests' && isset($sidebar_pending_soa_count) ? (int)$sidebar_pending_soa_count : ($sub['key'] === 'afms_grades' && isset($pending_grade_requests) ? (int)$pending_grade_requests : 0));
                                            if ($sub_badge > 0): ?>
                                                <span data-badge-for="<?php echo htmlspecialchars($sub['key']); ?>" class="ml-auto inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full bg-rose-500 text-white shadow-xs">
                                                    <?php echo $sub_badge; ?>
                                                </span>
                                            <?php endif; ?>
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } else { ?>
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
                            ? 'sidebar-nav-active bg-[#E8C7C5]/60 text-[#5c3a31] font-bold shadow-xs'
                            : ($is_logout 
                                ? 'text-[#7b6158] hover:bg-rose-50/70 hover:text-rose-600' 
                                : 'text-[#7b6158] hover:bg-[#faf6f3] hover:text-[#5c3a31]');
                        $icon_color = $is_active ? 'text-[#5c3a31]' : ($is_logout ? 'text-[#7b6158]' : 'text-[#7b6158] group-hover:text-[#5c3a31]');
                    ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" data-nav-key="<?php echo htmlspecialchars($item['key'] ?? ''); ?>" class="sidebar-nav-item <?php echo $a_class; ?> group flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-150">
                        <i class="<?php echo htmlspecialchars($item['icon']); ?> w-5 text-center text-base flex-shrink-0 <?php echo $icon_color; ?> transition-colors"></i>
                        <span class="sidebar-text tracking-wide"><?php echo htmlspecialchars($item['label']); ?></span>
                        <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): 
                            $badge_bg = !empty($item['badge_class']) ? $item['badge_class'] : 'bg-emerald-500 text-white';
                        ?>
                            <span data-badge-for="<?php echo htmlspecialchars($item['key'] ?? ''); ?>" class="sidebar-text ml-auto inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[10px] font-extrabold rounded-full <?php echo $badge_bg; ?> shadow-xs">
                                <?php echo (int)$item['badge']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php } ?>
            <?php } ?>
        </nav>

        <?php
        $sb_username = !empty($_SESSION['username']) ? (string)$_SESSION['username'] : (function_exists('get_header_user_label') ? get_header_user_label() : 'User');
        $sb_role_label = function_exists('get_header_user_label') ? get_header_user_label() : ($role ?: 'User');
        $sb_initial = strtoupper(substr($sb_username, 0, 1));
        $sb_avatar_url = null;
        if ($is_student && isset($_SESSION['user'])) {
            if (isset($_SESSION['cached_user_profile']) && is_array($_SESSION['cached_user_profile']) && array_key_exists('avatar_url', $_SESSION['cached_user_profile'])) {
                $sb_avatar_url = $_SESSION['cached_user_profile']['avatar_url'];
            } else {
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
                if (!isset($_SESSION['cached_user_profile']) || !is_array($_SESSION['cached_user_profile'])) {
                    $_SESSION['cached_user_profile'] = [];
                }
                $_SESSION['cached_user_profile']['avatar_url'] = $sb_avatar_url;
            }
        }
        ?>
        <!-- Compact Floating Bottom User Status & Quick Logout -->
        <div class="mt-auto shrink-0 bg-white relative z-10 border-t border-[#E8C7C5]/40 p-3">
            <div class="p-2 sm:p-2.5 rounded-2xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/50 flex items-center justify-between gap-2.5 transition-all hover:bg-[#F8EEEE]">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-10 h-10 rounded-full bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 flex items-center justify-center font-extrabold text-xs shrink-0 shadow-2xs overflow-hidden">
                        <?php if (!empty($sb_avatar_url)): ?>
                            <img id="sidebarUserAvatar" src="<?php echo htmlspecialchars($sb_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($sb_username, ENT_QUOTES, 'UTF-8'); ?>" class="w-10 h-10 rounded-full object-cover">
                        <?php else: ?>
                            <?php echo htmlspecialchars($sb_initial); ?>
                        <?php endif; ?>
                    </div>
                    <div class="sidebar-text min-w-0">
                        <div id="sidebarUsernameText" class="truncate text-xs font-bold text-[#59443F] leading-tight">
                            <?php echo htmlspecialchars($sb_username); ?>
                        </div>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <span class="text-[10px] font-semibold text-[#8B7168] truncate">
                                <?php echo htmlspecialchars($sb_role_label); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars(capstone_url('logout.php')); ?>" 
                   title="Sign Out" 
                   aria-label="Sign Out"
                   data-confirm="Sign out of EnformaSys? Any unsaved changes will be lost."
                   data-confirm-title="Sign Out"
                   data-confirm-text="Sign Out"
                   data-confirm-variant="warning"
                   data-confirm-loading="Signing out..."
                   class="sidebar-text inline-flex h-7 w-7 items-center justify-center rounded-xl text-[#8B7168] hover:text-rose-600 hover:bg-white transition shrink-0 shadow-2xs">
                    <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                </a>
            </div>
        </div>
    </aside>

    <div id="appShell" class="min-h-screen transition-[padding] duration-200 pt-0 lg:pt-4 lg:pl-[18rem] lg:pr-4 lg:pb-6 print:w-full print:m-0 print:p-0 print:bg-white print:shadow-none">
        <?php if (empty($suppressHeader)) { require __DIR__ . '/header.php'; } ?>

        <main class="px-4 lg:px-0 pt-5 pb-6 lg:pt-5 lg:pb-6 print:w-full print:m-0 print:p-0 print:bg-white print:shadow-none">
