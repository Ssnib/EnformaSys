<?php
require_once '../includes/session_fix.php';
require_roles(['Cashier']);
require_once '../includes/db.php';

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

$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$semester = enformasys_normalize_semester((string)($_GET['semester'] ?? ''));
$year_level = trim((string)($_GET['year_level'] ?? ''));

if ($academic_year === '') {
    $academic_year = $current_ay;
}
if (!in_array($academic_year, $ay_options, true) && !preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
    $academic_year = $current_ay;
}
if (!in_array($semester, ['1st', '2nd'], true)) {
    $active_sem_default = function_exists('get_active_semester') ? enformasys_normalize_semester(get_active_semester()) : '1st';
    $semester = in_array($active_sem_default, ['1st', '2nd'], true) ? $active_sem_default : '1st';
}

$allowed_year_levels = [
    '',
    'Propaedeutic Stage',
    'First Year',
    'Second Year',
    'Third Year',
    'Fourth Year',
    'Completed',
];
if (!in_array($year_level, $allowed_year_levels, true)) {
    $year_level = '';
}

$students = [];
$sql_students = "SELECT s.id as seminarian_id,
        COALESCE(up.full_name, u.username) as full_name,
        up.student_id as student_no,
        s.program, s.year_level, s.formation_status,
        COALESCE(e.enrollment_status, s.enrollment_status) as enrollment_status
    FROM seminarians s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN enrollments e ON e.student_id = s.id AND e.academic_year = ? AND e.semester = ?
    WHERE s.enrollment_status IN ('Enrolled', 'Pending', 'Partially Paid')
      AND (s.year_level <> 'Completed' OR s.year_level IS NULL OR s.year_level = '')";
$types = 'ss';
$params = [$academic_year, $semester];
if ($year_level !== '') {
    $sql_students .= " AND s.year_level = ?";
    $types .= 's';
    $params[] = $year_level;
}
$sql_students .= " ORDER BY full_name ASC";
$stmt_students = $conn->prepare($sql_students);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_students, 'bind_param'], $bind);
}
$stmt_students->execute();
$res_students = $stmt_students->get_result();
while ($res_students && ($r = $res_students->fetch_assoc())) {
    $students[] = $r;
}

include_once __DIR__ . '/../includes/lodging.php';

$balances = [];
$stat_total_students = count($students);
$stat_total_billed = 0.0;
$stat_total_paid = 0.0;
$stat_total_balance = 0.0;
$stat_count_due = 0;
$stat_count_cleared = 0;

foreach ($students as $st) {
    $sid = (int)($st['seminarian_id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }

    $bal_info = lodging_get_seminarian_balance($conn, $sid, $academic_year, $semester);
    $billed = (float)$bal_info['total_due'];
    $paid = (float)$bal_info['total_paid'];

    $bal = round($billed - $paid, 2);
    $due = max(0.0, $bal);
    $balances[$sid] = [
        'billed' => $billed,
        'paid' => $paid,
        'balance' => $due,
        'credit' => $bal < 0 ? abs($bal) : 0.0,
        'previous_balance' => (float)($bal_info['previous_balance'] ?? 0),
        'current_term_due' => (float)($bal_info['current_term_due'] ?? 0),
    ];

    $stat_total_billed += $billed;
    $stat_total_paid += $paid;
    $stat_total_balance += $due;
    if ($due > 0.0001) {
        $stat_count_due++;
    } else {
        $stat_count_cleared++;
    }
}
?>
<?php
$pageTitle = 'Seminarian Accounts';
$activeNav = 'cashier_accounts';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <style>
        .minimal-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .minimal-toolbar {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.85rem;
            background: #ffffff;
        }
        .search-container {
            position: relative;
            flex: 1 1 280px;
            max-width: 380px;
        }
        .search-container i {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.85rem;
            pointer-events: none;
        }
        .search-container input {
            width: 100%;
            box-sizing: border-box;
            padding: 0.5rem 2.2rem 0.5rem 2.25rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.875rem;
            background: #f8fafc;
            color: #0f172a;
            transition: all 0.15s ease;
        }
        .search-container input:focus {
            background: #ffffff;
            border-color: #0f172a;
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.08);
        }
        .search-kbd {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            background: #e2e8f0;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            pointer-events: none;
        }
        .pill-group {
            display: inline-flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 8px;
            gap: 2px;
        }
        .pill-btn {
            border: none;
            background: transparent;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .pill-btn:hover {
            color: #0f172a;
        }
        .pill-btn.active {
            background: #ffffff;
            color: #0f172a;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .minimal-select {
            padding: 0.45rem 0.7rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.825rem;
            background: #ffffff;
            color: #1e293b;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .minimal-select:focus {
            border-color: #0f172a;
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.08);
        }
        .minimal-table {
            width: 100%;
            border-collapse: collapse;
        }
        .minimal-table th {
            padding: 0.75rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }
        .minimal-table td {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .minimal-table tr:hover td {
            background: #fbfcfd;
        }
        .num-col {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
        }
        .status-due {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .status-partial {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .status-cleared {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .action-btn-pay {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.38rem 0.8rem;
            background: #0f172a;
            color: #ffffff;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid rgba(245, 158, 11, 0.35);
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.1);
            transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .action-btn-pay:hover {
            background: #1e293b;
            color: #ffffff;
            border-color: #f59e0b;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.2);
        }
        .action-btn-pay i {
            color: #f59e0b;
        }
        .action-btn-soa {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.38rem 0.75rem;
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .action-btn-soa:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        /* Slide-over Drawer Transition */
        #quickPayDrawer.open {
            right: 0 !important;
        }
        #drawerBackdrop.open {
            display: block !important;
            opacity: 1 !important;
        }
        #drawerSubmitBtn:hover {
            background: #1e293b !important;
            border-color: #f59e0b !important;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3) !important;
        }
        </style>

        <div class="content-body" style="padding: 1.5rem 2rem;">
            <!-- Page Title & Metrics Header Strip -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin: 0 0 0.25rem 0; font-size: 1.75rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.6rem;">
                        <i class="fas fa-users" style="color: #0f172a;"></i> Seminarian Accounts
                    </h1>
                    <p style="margin: 0; color: #64748b; font-size: 0.875rem;">
                        Term billing records, outstanding balances, and fast collection desk
                    </p>
                </div>

                <!-- KPI Metric Pills (Instant high-level glance) -->
                <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center;">
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.825rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <span style="color: #64748b; font-weight: 600;">Enrolled:</span>
                        <strong style="color: #0f172a; margin-left: 0.25rem;" id="statTotalStudents"><?php echo $stat_total_students; ?></strong>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #fed7aa; padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.825rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <span style="color: #9a3412; font-weight: 600;">With Balance:</span>
                        <strong style="color: #c2410c; margin-left: 0.25rem;" id="statWithBalance"><?php echo $stat_count_due; ?></strong>
                        <span style="color: #9a3412; opacity: 0.8; font-size: 0.75rem;">(₱<span id="statTotalDue"><?php echo number_format($stat_total_balance, 2); ?></span>)</span>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #bbf7d0; padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.825rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <span style="color: #166534; font-weight: 600;">Cleared:</span>
                        <strong style="color: #15803d; margin-left: 0.25rem;" id="statCleared"><?php echo $stat_count_cleared; ?></strong>
                    </div>
                </div>
            </div>

            <div id="ajaxMessage"></div>

            <!-- Unified Minimalist Data Card (No separate "Filters" card!) -->
            <div class="minimal-card">
                <!-- Clean Integrated Toolbar -->
                <div class="minimal-toolbar">
                    <!-- Live Omnibox Search -->
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="accountSearch" placeholder="Search name or ID... (Press '/' to focus)" autocomplete="off">
                        <kbd class="search-kbd">/</kbd>
                    </div>

                    <!-- 1-Click Segmented Status Filter Pills -->
                    <div class="pill-group" id="statusPills">
                        <button type="button" class="pill-btn active" data-filter="all">All (<span id="pillCountAll"><?php echo $stat_total_students; ?></span>)</button>
                        <button type="button" class="pill-btn" data-filter="due">Has Balance (<span id="pillCountDue"><?php echo $stat_count_due; ?></span>)</button>
                        <button type="button" class="pill-btn" data-filter="cleared">Cleared (<span id="pillCountCleared"><?php echo $stat_count_cleared; ?></span>)</button>
                    </div>

                    <!-- Auto-updating Term & Year Level Selects (Zero-Click 'Apply' Elimination!) -->
                    <form id="accountsFilterForm" style="display: flex; align-items: center; gap: 0.5rem; margin: 0; flex-wrap: wrap;">
                        <select name="academic_year" id="filterAy" class="minimal-select" title="Change Academic Year">
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>

                        <select name="semester" id="filterSem" class="minimal-select" title="Change Semester">
                            <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>

                        <select name="year_level" id="filterYearLevel" class="minimal-select" title="Filter by Year Level">
                            <option value="">All Year Levels</option>
                            <?php foreach (array_slice($allowed_year_levels, 1) as $yl) { ?>
                                <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $yl === $year_level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($yl); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </form>
                </div>

                <!-- Clean Table with consolidated typography -->
                <div style="overflow-x: auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th style="text-align: left; width: 34%;">Seminarian</th>
                                <th style="text-align: right; width: 15%;">Billed</th>
                                <th style="text-align: right; width: 15%;">Paid</th>
                                <th style="text-align: right; width: 16%;">Balance</th>
                                <th style="text-align: center; width: 10%;">Status</th>
                                <th style="text-align: right; width: 10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="accountsTbody">
                            <?php if (count($students) === 0) { ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 2rem;">No enrolled seminarians found.</td></tr>
                            <?php } else { foreach ($students as $st) {
                                $sid = (int)($st['seminarian_id'] ?? 0);
                                $b = $balances[$sid] ?? ['billed' => 0, 'paid' => 0, 'balance' => 0, 'credit' => 0];
                                $bal = (float)$b['balance'];
                                $has_balance = $bal > 0.0001;
                                $sno = trim((string)($st['student_no'] ?? ''));
                                $sno_text = $sno !== '' ? $sno : 'ID: ' . $sid;
                                $meta_parts = [];
                                if (!empty($st['program'])) $meta_parts[] = $st['program'];
                                if (!empty($st['year_level'])) $meta_parts[] = $st['year_level'];
                                $meta_str = implode(' • ', $meta_parts);
                            ?>
                                <tr class="account-row"
                                    data-sid="<?php echo $sid; ?>"
                                    data-name="<?php echo htmlspecialchars((string)($st['full_name'] ?? '')); ?>"
                                    data-student-no="<?php echo htmlspecialchars($sno_text); ?>"
                                    data-meta="<?php echo htmlspecialchars($meta_str); ?>"
                                    data-balance="<?php echo $bal; ?>"
                                    data-has-balance="<?php echo $has_balance ? '1' : '0'; ?>">
                                    <td class="account-name">
                                        <div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                                            <span><?php echo htmlspecialchars((string)($st['full_name'] ?? '')); ?></span>
                                            <?php if (($st['enrollment_status'] ?? '') === 'Pending'): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700">Pending Payment</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: #64748b; margin-top: 1px;">
                                            <span>[<?php echo htmlspecialchars($sno_text); ?>]</span>
                                            <?php if ($meta_str !== '') { ?>
                                                <span>• <?php echo htmlspecialchars($meta_str); ?></span>
                                            <?php } ?>
                                        </div>
                                    </td>
                                    <td class="num-col" style="color: #475569;">
                                        ₱<?php echo htmlspecialchars(number_format((float)$b['billed'], 2)); ?>
                                        <?php if (!empty($b['previous_balance']) && (float)$b['previous_balance'] > 0.0001) { ?>
                                            <div style="font-size:0.7rem; color:#dc2626; font-weight:600;"><i class="fas fa-clock-rotate-left"></i> Incl. ₱<?php echo htmlspecialchars(number_format((float)$b['previous_balance'], 2)); ?> prev</div>
                                        <?php } ?>
                                    </td>
                                    <td class="num-col" style="color: #047857;">
                                        ₱<?php echo htmlspecialchars(number_format((float)$b['paid'], 2)); ?>
                                    </td>
                                    <td class="num-col" style="color: <?php echo $has_balance ? '#b45309' : '#059669'; ?>; font-weight: 700;">
                                        ₱<?php echo htmlspecialchars(number_format($bal, 2)); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php
                                            $is_partial_row = $has_balance && ((float)$b['paid'] > 0.0001);
                                            $badge_cls = $has_balance ? ($is_partial_row ? 'status-partial' : 'status-due') : 'status-cleared';
                                            $badge_text = $has_balance ? ($is_partial_row ? 'Partially Paid' : 'Due') : 'Fully Paid';
                                        ?>
                                        <span class="status-badge <?php echo $badge_cls; ?>">
                                            <?php echo $badge_text; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="action-btn-pay btn-open-drawer"
                                                data-sid="<?php echo $sid; ?>"
                                                data-name="<?php echo htmlspecialchars((string)($st['full_name'] ?? '')); ?>"
                                                data-student-no="<?php echo htmlspecialchars($sno_text); ?>"
                                                data-meta="<?php echo htmlspecialchars($meta_str); ?>"
                                                data-balance="<?php echo $bal; ?>"
                                                title="Quick Pay in slide-over drawer">
                                            <i class="fas fa-bolt"></i> Pay
                                        </button>
                                        <a class="action-btn-soa" href="cashier_soa.php?student_id=<?php echo $sid; ?>&academic_year=<?php echo urlencode($academic_year); ?>&semester=<?php echo urlencode($semester); ?>" target="_blank" title="Statement of Account">
                                            SOA
                                        </a>
                                    </td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- In-Context Quick Payment Slide-Over Drawer (0 Clicks away from checkout!) -->
        <div id="drawerBackdrop" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45); backdrop-filter: blur(2px); z-index: 1100; transition: opacity 0.2s ease;"></div>
        <div id="quickPayDrawer" style="position: fixed; top: 0; right: -460px; width: 440px; max-width: 95vw; height: 100vh; background: #ffffff; box-shadow: -8px 0 24px rgba(0,0,0,0.15); z-index: 1200; display: flex; flex-direction: column; transition: right 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            <!-- Drawer Header -->
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                <div>
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-bolt" style="color: #f59e0b;"></i> Quick Payment
                    </h3>
                    <div id="drawerStudentMeta" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Loading student info...</div>
                </div>
                <button type="button" id="closeDrawerBtn" style="background: none; border: none; font-size: 1.25rem; color: #64748b; cursor: pointer; padding: 4px 8px; border-radius: 6px;" title="Close (Esc)">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Drawer Scrollable Content -->
            <div style="flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem;">
                <form id="drawerPaymentForm">
                    <input type="hidden" name="seminarian_id" id="drawerSeminarianId">
                    <input type="hidden" name="academic_year" id="drawerAcademicYear">
                    <input type="hidden" name="semester" id="drawerSemester">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div id="drawerMessage" style="margin-bottom: 0.75rem;"></div>

                    <!-- Balance Banner -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.75rem; text-transform: uppercase; color: #166534; font-weight: 700;">Total Balance Due</div>
                            <div id="drawerBalanceDue" style="font-size: 1.35rem; font-weight: 900; color: #15803d;">₱0.00</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; color: #0369a1; font-weight: 700;">Remaining After Pay</div>
                            <div id="drawerNewBalanceDisplay" style="font-size: 1.25rem; font-weight: 900; color: #0284c7;">₱0.00</div>
                        </div>
                        <span id="drawerTermBadge" style="font-size: 0.75rem; background: #dcfce7; color: #166534; font-weight: 700; padding: 3px 8px; border-radius: 4px;">1st Sem</span>
                    </div>

                    <!-- Live Assessment Breakdown Grid (Current Term, Previous Balance, Total Paid, Remaining) -->
                    <div id="drawerBreakdownBanner" style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.45rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.6rem 0.75rem; margin-bottom: 0.75rem; font-size: 0.75rem;">
                        <div>
                            <span style="color:#64748b; display:block; font-size:0.68rem; text-transform:uppercase; font-weight:700;">Current Fees</span>
                            <strong id="drawerCurFeesDisplay" style="color:#0f172a; font-weight:800;">₱0.00</strong>
                        </div>
                        <div>
                            <span style="color:#991b1b; display:block; font-size:0.68rem; text-transform:uppercase; font-weight:700;">Previous Balance</span>
                            <strong id="drawerPrevBalDisplay" style="color:#b91c1c; font-weight:800;">₱0.00</strong>
                        </div>
                        <div>
                            <span style="color:#047857; display:block; font-size:0.68rem; text-transform:uppercase; font-weight:700;">Total Paid</span>
                            <strong id="drawerPaidDisplay" style="color:#059669; font-weight:800;">₱0.00</strong>
                        </div>
                        <div>
                            <span style="color:#065f46; display:block; font-size:0.68rem; text-transform:uppercase; font-weight:700;">Remaining Balance</span>
                            <strong id="drawerRemSummaryDisplay" style="color:#065f46; font-weight:900;">₱0.00</strong>
                        </div>
                    </div>

                    <!-- Live Overpayment Alert in Drawer -->
                    <div id="drawerOverpaymentAlert" style="display:none; margin-bottom: 0.75rem; padding: 0.65rem 0.85rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 6px; color: #991b1b; font-weight: 700; font-size: 0.8rem;"></div>

                    <!-- OR Number & Payment Method -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.25rem;">OR Number <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="or_number" id="drawerOrNumber" required style="width: 100%; box-sizing: border-box; padding: 0.45rem 0.65rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700;">
                            <div id="drawerOrFeedback" style="font-size: 0.75rem; margin-top: 2px;"></div>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.25rem;">Payment Method <span style="color:#ef4444;">*</span></label>
                            <select name="method" id="drawerMethod" style="width: 100%; box-sizing: border-box; padding: 0.45rem 0.65rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <option value="cash">Cash</option>
                                <option value="bank_deposit">Bank Deposit</option>
                                <option value="online_transfer">Online Transfer</option>
                                <option value="check">Check</option>
                            </select>
                        </div>
                    </div>

                    <!-- Payer Name / Sponsor -->
                    <div style="margin-bottom: 0.85rem;">
                        <label for="drawerPayerName" style="font-size: 0.75rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.25rem;">Payer Name / Sponsor</label>
                        <input type="text" name="payer_name" id="drawerPayerName" value="" placeholder="Enter payer or sponsor name" style="width: 100%; box-sizing: border-box; padding: 0.45rem 0.65rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700;">
                    </div>

                    <!-- Dynamic Fee Checklist Items -->
                    <div style="margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.4rem;">
                            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #475569;">Fees Being Paid</label>
                        </div>
                        <div id="drawerFeesList" style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 180px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.5rem; background: #fafafa;">
                            <div style="text-align: center; color: #94a3b8; font-size: 0.8rem; padding: 1rem;"><i class="fas fa-spinner fa-spin"></i> Loading fees...</div>
                        </div>
                    </div>

                    <!-- Totals, Amount Tendered & Denomination Chips -->
                    <div style="background: #0f172a; color: #ffffff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="margin-bottom: 0.65rem;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.25rem;">
                                <label for="drawerAmountInput" style="font-size: 0.75rem; text-transform: uppercase; color: #cbd5e1; font-weight: 700; margin: 0;">Amount to Pay (₱):</label>
                                <span id="drawerTotalPaidDisplay" style="font-size: 0.75rem; font-weight: 700; color: #fef08a;">Fees: ₱0.00</span>
                            </div>
                            <input type="number" name="amount" id="drawerAmountInput" step="0.01" min="0" placeholder="0.00" value="0.00" required style="width: 100%; box-sizing: border-box; padding: 0.45rem 0.65rem; font-size: 1.15rem; font-weight: 900; border-radius: 6px; border: 2px solid #fef08a; background: #ffffff; color: #064e3b;">
                        </div>

                        <div style="margin-bottom: 0.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.25rem;">
                                <label for="drawerAmountTendered" style="font-size: 0.75rem; text-transform: uppercase; color: #cbd5e1; font-weight: 700; margin: 0;">Cash Tendered (₱):</label>
                                <span style="font-size: 0.7rem; color: #a7f3d0;">Amount received</span>
                            </div>
                            <input type="number" name="amount_tendered" id="drawerAmountTendered" step="0.01" min="0" placeholder="0.00" style="width: 100%; box-sizing: border-box; padding: 0.45rem 0.65rem; font-size: 1.15rem; font-weight: 900; border-radius: 6px; border: 2px solid #38bdf8; background: #ffffff; color: #0f172a;">
                            <div id="drawerInsufficientWarning" class="hidden mt-1.5 text-xs font-semibold text-red-700 bg-red-50 border border-red-200 rounded px-2.5 py-1" style="display:none;" role="alert">
                                <i class="fas fa-exclamation-circle mr-1"></i>Insufficient cash tendered
                            </div>
                        </div>

                        <!-- 1-Click Cash Denomination Chips -->
                        <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-bottom: 0.5rem;" id="drawerDenominations">
                            <button type="button" class="drawer-denom-btn" data-mode="exact" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.22); color: #fff; border: 1px solid rgba(255,255,255,0.3); border-radius: 4px; cursor: pointer; font-weight: 700;">Exact</button>
                            <button type="button" class="drawer-denom-btn" data-mode="add" data-val="500" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; cursor: pointer;">+500</button>
                            <button type="button" class="drawer-denom-btn" data-mode="add" data-val="1000" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; cursor: pointer;">+1k</button>
                            <button type="button" class="drawer-denom-btn" data-mode="set" data-val="1000" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; cursor: pointer;">₱1k</button>
                            <button type="button" class="drawer-denom-btn" data-mode="set" data-val="5000" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; cursor: pointer;">₱5k</button>
                            <button type="button" class="drawer-denom-btn" data-mode="set" data-val="10000" style="padding: 2px 7px; font-size: 0.75rem; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; cursor: pointer;">₱10k</button>
                        </div>

                        <div id="drawerChangeBox" class="hidden bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg p-3 font-semibold" style="display: none; justify-content: space-between; align-items: center; margin-top: 0.5rem;">
                            <div>
                                <span style="font-size: 0.75rem; text-transform: uppercase; color: #047857; font-weight: 800; display:block;">Change (Sukli):</span>
                                <span id="drawerChangeStatus" style="font-size: 0.7rem; color: #059669;"></span>
                            </div>
                            <input type="text" id="drawerChangeInput" readonly value="" style="background:#ffffff; color:#047857; font-size:1.15rem; font-weight:900; padding:0.3rem 0.6rem; border-radius:6px; border:1.5px solid #34d399; width:130px; box-sizing:border-box; text-align:right; cursor:not-allowed;">
                        </div>
                    </div>

                    <!-- Action Button in Drawer -->
                    <button type="submit" id="drawerSubmitBtn" style="width: 100%; padding: 0.75rem; background: #0f172a; color: #ffffff; font-weight: 800; font-size: 0.95rem; border: 1px solid rgba(245, 158, 11, 0.45); border-radius: 8px; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 0.5rem; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.25); transition: all 0.15s ease;">
                        <i class="fas fa-print" style="color: #f59e0b;"></i> <span>Save & Print Receipt</span> <kbd style="font-size: 0.7rem; background: rgba(255,255,255,0.18); color: #f8fafc; padding: 2px 6px; border-radius: 4px;">Ctrl+Enter / F2</kbd>
                    </button>

                    <div style="display:flex; justify-content:center; align-items:center; gap:0.85rem; flex-wrap:wrap; margin-top: 0.75rem;">
                        <a id="drawerPrintSoaLink" href="#" target="_blank" style="display:inline-flex; align-items:center; gap:0.35rem; padding:0.4rem 0.85rem; font-size: 0.8rem; font-weight: 700; color: #ffffff; background: #1e3a5f; border-radius: 6px; text-decoration: none;">
                            <i class="fas fa-print"></i> Print SOA
                        </a>
                        <a id="drawerFullPageLink" href="#" target="_blank" style="font-size: 0.8rem; color: #64748b; text-decoration: underline;">Open Full Payment Page ↗</a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function () {
            const searchInput = document.getElementById('accountSearch');
            const statusPills = document.querySelectorAll('.pill-btn');
            const filterForm = document.getElementById('accountsFilterForm');
            const filterAy = document.getElementById('filterAy');
            const filterSem = document.getElementById('filterSem');
            const filterYearLevel = document.getElementById('filterYearLevel');
            const tbody = document.getElementById('accountsTbody');
            const msg = document.getElementById('ajaxMessage');

            // KPI Stat Elements
            const statTotalStudents = document.getElementById('statTotalStudents');
            const statWithBalance = document.getElementById('statWithBalance');
            const statTotalDue = document.getElementById('statTotalDue');
            const statCleared = document.getElementById('statCleared');
            const pillCountAll = document.getElementById('pillCountAll');
            const pillCountDue = document.getElementById('pillCountDue');
            const pillCountCleared = document.getElementById('pillCountCleared');

            // Quick Pay Drawer Elements
            const drawer = document.getElementById('quickPayDrawer');
            const backdrop = document.getElementById('drawerBackdrop');
            const closeDrawerBtn = document.getElementById('closeDrawerBtn');
            const drawerForm = document.getElementById('drawerPaymentForm');
            const drawerStudentMeta = document.getElementById('drawerStudentMeta');
            const drawerSeminarianId = document.getElementById('drawerSeminarianId');
            const drawerAcademicYear = document.getElementById('drawerAcademicYear');
            const drawerSemester = document.getElementById('drawerSemester');
            const drawerBalanceDue = document.getElementById('drawerBalanceDue');
            const drawerTermBadge = document.getElementById('drawerTermBadge');
            const drawerOrNumber = document.getElementById('drawerOrNumber');
            const drawerOrFeedback = document.getElementById('drawerOrFeedback');
            const drawerPayerName = document.getElementById('drawerPayerName');
            const drawerFeesList = document.getElementById('drawerFeesList');
            const drawerTotalPaidDisplay = document.getElementById('drawerTotalPaidDisplay');
            const drawerAmountInput = document.getElementById('drawerAmountInput');
            const drawerAmountTendered = document.getElementById('drawerAmountTendered');
            const drawerInsufficientWarning = document.getElementById('drawerInsufficientWarning');
            const drawerChangeBox = document.getElementById('drawerChangeBox');
            const drawerChangeInput = document.getElementById('drawerChangeInput');
            const drawerChangeStatus = document.getElementById('drawerChangeStatus');
            const drawerFullPageLink = document.getElementById('drawerFullPageLink');
            const drawerMessage = document.getElementById('drawerMessage');

            let currentFilter = 'all';
            let drawerUserModifiedTendered = false;
            let drawerUserModifiedAmount = false;

            function formatCurrency(val) {
                return Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function escapeHtml(str) {
                return String(str || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setMessage(type, text) {
                if (!msg) return;
                msg.innerHTML = '';
                if (!text) return;
                const p = document.createElement('p');
                p.className = type === 'error' ? 'error' : 'success';
                p.textContent = text;
                msg.appendChild(p);
            }

            // Keyboard shortcut: '/' to focus search, 'Escape' to close drawer
            window.addEventListener('keydown', function (e) {
                if (e.key === '/' && document.activeElement !== searchInput && !drawer.classList.contains('open')) {
                    e.preventDefault();
                    searchInput.focus();
                } else if (e.key === 'Escape' && drawer.classList.contains('open')) {
                    closeDrawer();
                } else if ((e.ctrlKey && e.key === 'Enter') || e.key === 'F2') {
                    if (drawer.classList.contains('open')) {
                        e.preventDefault();
                        if (drawerForm.requestSubmit) {
                            drawerForm.requestSubmit();
                        } else {
                            document.getElementById('drawerSubmitBtn').click();
                        }
                    }
                }
            });

            // Filter table rows locally by search and status pill
            function applyLocalFilters() {
                const query = (searchInput.value || '').toLowerCase().trim();
                const rows = document.querySelectorAll('.account-row');
                rows.forEach(function (row) {
                    const name = (row.dataset.name || '').toLowerCase();
                    const sno = (row.dataset.studentNo || '').toLowerCase();
                    const meta = (row.dataset.meta || '').toLowerCase();
                    const hasBal = row.dataset.hasBalance === '1';

                    const matchesQuery = query === '' || name.includes(query) || sno.includes(query) || meta.includes(query);
                    let matchesStatus = true;
                    if (currentFilter === 'due') {
                        matchesStatus = hasBal;
                    } else if (currentFilter === 'cleared') {
                        matchesStatus = !hasBal;
                    }

                    if (matchesQuery && matchesStatus) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', applyLocalFilters);
            }

            // Status Filter Pills (1 Click, Instant)
            statusPills.forEach(function (pill) {
                pill.addEventListener('click', function () {
                    statusPills.forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.dataset.filter || 'all';
                    applyLocalFilters();
                });
            });

            // Auto-trigger loadAccounts on dropdown changes (Zero-click 'Apply'!)
            if (filterAy) filterAy.addEventListener('change', loadAccounts);
            if (filterSem) filterSem.addEventListener('change', loadAccounts);
            if (filterYearLevel) filterYearLevel.addEventListener('change', loadAccounts);

            function updateMetrics(rows) {
                let countAll = rows.length;
                let countDue = 0;
                let countCleared = 0;
                let sumDue = 0.0;

                rows.forEach(function (r) {
                    const bal = parseFloat(r.balance || 0);
                    if (bal > 0.0001) {
                        countDue++;
                        sumDue += bal;
                    } else {
                        countCleared++;
                    }
                });

                if (statTotalStudents) statTotalStudents.textContent = countAll;
                if (statWithBalance) statWithBalance.textContent = countDue;
                if (statTotalDue) statTotalDue.textContent = formatCurrency(sumDue);
                if (statCleared) statCleared.textContent = countCleared;
                if (pillCountAll) pillCountAll.textContent = countAll;
                if (pillCountDue) pillCountDue.textContent = countDue;
                if (pillCountCleared) pillCountCleared.textContent = countCleared;
            }

            function renderRows(rows, ay, sem) {
                tbody.innerHTML = '';
                if (!rows || rows.length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 2rem;">No enrolled seminarians found.</td>';
                    tbody.appendChild(tr);
                    updateMetrics([]);
                    return;
                }

                updateMetrics(rows);

                rows.forEach(function (r) {
                    const sid = Number(r.seminarian_id || 0);
                    const bal = Number(r.balance || 0);
                    const hasBal = bal > 0.0001;
                    const sno = (r.student_no || '').trim() || ('ID: ' + sid);
                    const name = r.full_name || '';
                    const metaParts = [];
                    if (r.program) metaParts.push(r.program);
                    if (r.year_level) metaParts.push(r.year_level);
                    const metaStr = metaParts.join(' • ');

                    const tr = document.createElement('tr');
                    tr.className = 'account-row';
                    tr.dataset.sid = sid;
                    tr.dataset.name = name;
                    tr.dataset.studentNo = sno;
                    tr.dataset.meta = metaStr;
                    tr.dataset.balance = bal;
                    tr.dataset.hasBalance = hasBal ? '1' : '0';

                    const isPartial = hasBal && (parseFloat(r.paid || 0) > 0.0001);
                    const badgeCls = hasBal ? (isPartial ? 'status-partial' : 'status-due') : 'status-cleared';
                    const badgeText = hasBal ? (isPartial ? 'Partially Paid' : 'Due') : 'Fully Paid';
                    const balColor = hasBal ? '#b45309' : '#059669';

                    const soaUrl = 'cashier_soa.php?student_id=' + sid + '&academic_year=' + encodeURIComponent(ay || '') + '&semester=' + encodeURIComponent(sem || '');

                    let prevBalHint = '';
                    if (parseFloat(r.previous_balance || 0) > 0.0001) {
                        prevBalHint = '<div style="font-size: 0.7rem; color: #dc2626; font-weight: 600;"><i class="fas fa-clock-rotate-left"></i> Incl. ₱' + formatCurrency(r.previous_balance) + ' prev</div>';
                    }

                    const pendingPill = (r.enrollment_status === 'Pending')
                        ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700">Pending Payment</span>'
                        : '';

                    tr.innerHTML =
                        '<td class="account-name">' +
                            '<div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;"><span>' + escapeHtml(name) + '</span>' + pendingPill + '</div>' +
                            '<div style="font-size: 0.8rem; color: #64748b; margin-top: 1px;">' +
                                '<span>[' + escapeHtml(sno) + ']</span> ' +
                                (metaStr ? '<span>• ' + escapeHtml(metaStr) + '</span>' : '') +
                            '</div>' +
                        '</td>' +
                        '<td class="num-col" style="color: #475569;">₱' + formatCurrency(r.billed) + prevBalHint + '</td>' +
                        '<td class="num-col" style="color: #047857;">₱' + formatCurrency(r.paid) + '</td>' +
                        '<td class="num-col" style="color: ' + balColor + '; font-weight: 700;">₱' + formatCurrency(bal) + '</td>' +
                        '<td style="text-align: center;"><span class="status-badge ' + badgeCls + '">' + badgeText + '</span></td>' +
                        '<td style="text-align: right; white-space: nowrap;">' +
                            '<button type="button" class="action-btn-pay btn-open-drawer" ' +
                                'data-sid="' + sid + '" ' +
                                'data-name="' + escapeHtml(name) + '" ' +
                                'data-student-no="' + escapeHtml(sno) + '" ' +
                                'data-meta="' + escapeHtml(metaStr) + '" ' +
                                'data-balance="' + bal + '" ' +
                                'title="Quick Pay in slide-over drawer">' +
                                '<i class="fas fa-bolt"></i> Pay' +
                            '</button> ' +
                            '<a class="action-btn-soa" href="' + soaUrl + '" target="_blank" title="Statement of Account">SOA</a>' +
                        '</td>';

                    tbody.appendChild(tr);
                });

                applyLocalFilters();
            }

            async function loadAccounts() {
                if (!window.CapstoneAjax) return;
                setMessage('', '');
                try {
                    const ay = filterAy ? filterAy.value : '';
                    const sem = filterSem ? filterSem.value : '';
                    const yl = filterYearLevel ? filterYearLevel.value : '';
                    const data = await window.CapstoneAjax.get('ajax.php', { action: 'cashier_accounts_list', academic_year: ay, semester: sem, year_level: yl });
                    renderRows(data.accounts || [], data.academic_year || ay, data.semester || sem);
                } catch (err) {
                    setMessage('error', err && err.message ? err.message : 'Failed to load accounts.');
                }
            }

            // Quick Pay Drawer Functionality
            function openDrawer(sid, name, sno, meta, bal) {
                drawerUserModifiedTendered = false;
                drawerUserModifiedAmount = false;
                drawerMessage.innerHTML = '';
                const alertElem = document.getElementById('drawerOverpaymentAlert');
                if (alertElem) {
                    alertElem.style.display = 'none';
                    alertElem.innerHTML = '';
                }
                drawerSeminarianId.value = sid;
                const curAy = filterAy ? filterAy.value : '<?php echo htmlspecialchars($academic_year); ?>';
                const curSem = filterSem ? filterSem.value : '<?php echo htmlspecialchars($semester); ?>';
                drawerAcademicYear.value = curAy;
                drawerSemester.value = curSem;

                drawerStudentMeta.innerHTML = '<strong>' + escapeHtml(name) + '</strong> (' + escapeHtml(sno) + ' • ' + escapeHtml(meta) + ')';
                if (drawerPayerName) {
                    drawerPayerName.value = '';
                }
                drawerBalanceDue.textContent = '₱' + formatCurrency(bal);
                drawerBalanceDue.dataset.rawBalance = bal;
                const newBalElem = document.getElementById('drawerNewBalanceDisplay');
                if (newBalElem) newBalElem.textContent = '₱' + formatCurrency(bal);
                drawerTermBadge.textContent = curAy + ' • ' + curSem + ' Sem';
                drawerFullPageLink.href = <?php echo json_encode(capstone_url('modules/cashier_payments.php')); ?> + '?seminarian_id=' + sid + '&academic_year=' + encodeURIComponent(curAy) + '&semester=' + encodeURIComponent(curSem);
                const drawerPrintSoaLink = document.getElementById('drawerPrintSoaLink');
                if (drawerPrintSoaLink) {
                    drawerPrintSoaLink.href = <?php echo json_encode(capstone_url('modules/cashier_soa.php')); ?> + '?student_id=' + sid + '&academic_year=' + encodeURIComponent(curAy) + '&semester=' + encodeURIComponent(curSem);
                }

                drawer.classList.add('open');
                backdrop.classList.add('open');

                // Load Fees and Auto-fill OR
                loadDrawerData(sid, curAy, curSem);
            }

            function closeDrawer() {
                drawer.classList.remove('open');
                backdrop.classList.remove('open');
            }

            closeDrawerBtn.addEventListener('click', closeDrawer);
            backdrop.addEventListener('click', closeDrawer);

            async function loadDrawerData(sid, ay, sem) {
                drawerFeesList.innerHTML = '<div style="text-align: center; color: #94a3b8; font-size: 0.8rem; padding: 1rem;"><i class="fas fa-spinner fa-spin"></i> Loading fees...</div>';

                // 1. Suggest OR
                try {
                    const orRes = await fetch('ajax.php?action=suggest_or_number');
                    const orData = await orRes.json();
                    if (orData && orData.suggested) {
                        drawerOrNumber.value = orData.suggested;
                        drawerOrFeedback.innerHTML = '<span style="color:#059669; font-weight:700;"><i class="fas fa-check-circle"></i> Available Next OR</span>';
                    }
                } catch (err) {
                    drawerOrNumber.value = '';
                }

                // 2. Load Fees
                try {
                    const feeRes = await fetch('ajax.php?action=get_student_fees&seminarian_id=' + encodeURIComponent(sid) + '&academic_year=' + encodeURIComponent(ay) + '&semester=' + encodeURIComponent(sem));
                    const feeData = await feeRes.json();
                    if (feeData && feeData.ok) {
                        const trueBal = parseFloat(feeData.remaining_balance !== undefined ? feeData.remaining_balance : (feeData.total_due || 0));
                        const prevBal = parseFloat(feeData.previous_balance || 0);
                        const curFees = parseFloat(feeData.current_term_due || 0);
                        const totalPaid = parseFloat(feeData.total_paid || 0);

                        drawerBalanceDue.textContent = '₱' + formatCurrency(trueBal);
                        drawerBalanceDue.dataset.rawBalance = trueBal;

                        const curFeesDisplay = document.getElementById('drawerCurFeesDisplay');
                        const prevBalDisplay = document.getElementById('drawerPrevBalDisplay');
                        const paidDisplay = document.getElementById('drawerPaidDisplay');
                        const remSummaryDisplay = document.getElementById('drawerRemSummaryDisplay');
                        if (curFeesDisplay) curFeesDisplay.textContent = '₱' + formatCurrency(curFees);
                        if (prevBalDisplay) prevBalDisplay.textContent = '₱' + formatCurrency(prevBal);
                        if (paidDisplay) paidDisplay.textContent = '₱' + formatCurrency(totalPaid);
                        if (remSummaryDisplay) remSummaryDisplay.textContent = '₱' + formatCurrency(trueBal);

                        renderDrawerFees(feeData.fees || []);
                    } else {
                        drawerFeesList.innerHTML = '<div style="color: #ef4444; font-size: 0.8rem; padding: 0.5rem;">Failed to load fees: ' + escapeHtml(feeData.error || 'Unknown error') + '</div>';
                    }
                } catch (err) {
                    drawerFeesList.innerHTML = '<div style="color: #ef4444; font-size: 0.8rem; padding: 0.5rem;">Failed to communicate with server.</div>';
                }
            }

            function renderDrawerFees(fees) {
                drawerFeesList.innerHTML = '';
                if (!fees || fees.length === 0) {
                    drawerFeesList.innerHTML = '<div style="color: #64748b; font-size: 0.8rem; padding: 0.5rem; text-align: center;">No term invoice items found.</div>';
                    recalcDrawerTotals();
                    return;
                }

                fees.forEach(function (f) {
                    const row = document.createElement('div');
                    row.style.display = 'flex';
                    row.style.justifyContent = 'space-between';
                    row.style.alignItems = 'center';
                    row.style.padding = '0.35rem 0.5rem';
                    row.style.borderRadius = '6px';
                    row.style.fontSize = '0.8rem';

                    const isPrevBal = Boolean(f.is_previous_balance);
                    const particular = escapeHtml(f.particular || '');
                    const remBal = typeof f.item_remaining_balance !== 'undefined' ? parseFloat(f.item_remaining_balance) : parseFloat(f.amount || 0);
                    const origAmt = typeof f.original_amount !== 'undefined' ? parseFloat(f.original_amount) : remBal;
                    const isZero = (remBal <= 0.0001);
                    const readonlyAttr = isZero ? 'readonly' : '';
                    const readonlyStyle = isZero ? 'background:#f1f5f9; color:#64748b; cursor:not-allowed;' : '';

                    if (isPrevBal) {
                        row.style.background = isZero ? '#f8fafc' : '#fff1f2';
                        row.style.border = isZero ? '1px solid #e2e8f0' : '2px solid #ef4444';
                    } else if (isZero) {
                        row.style.background = '#f8fafc';
                        row.style.border = '1px solid #e2e8f0';
                    } else {
                        row.style.background = '#ffffff';
                        row.style.border = '1px solid #cbd5e1';
                    }

                    let badge = '';
                    if (isPrevBal) {
                        if (isZero) {
                            badge = '<span style="font-size: 0.65rem; background: #e2e8f0; color: #475569; padding: 1px 5px; border-radius: 3px; margin-left: 4px; font-weight: 600;"><i class="fas fa-check" style="color: #059669; font-size: 0.6rem;"></i> Cleared</span>';
                        } else {
                            badge = '<span style="font-size: 0.65rem; background: #fee2e2; color: #991b1b; padding: 1px 5px; border-radius: 3px; margin-left: 4px; font-weight: 700;"><i class="fas fa-clock-rotate-left"></i> Bal: ₱' + formatCurrency(remBal) + '</span>';
                        }
                    } else if (isZero) {
                        if (origAmt > 0) {
                            badge = '<span style="font-size: 0.65rem; background: #e2e8f0; color: #475569; padding: 1px 5px; border-radius: 3px; margin-left: 4px; font-weight: 600;"><i class="fas fa-check" style="color: #059669; font-size: 0.6rem;"></i> Paid (₱0.00)</span>';
                        } else {
                            badge = '<span style="font-size: 0.65rem; background: #f1f5f9; color: #64748b; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">(₱0.00)</span>';
                        }
                    } else {
                        badge = '<span style="font-size: 0.65rem; background: #fef3c7; color: #92400e; padding: 1px 5px; border-radius: 3px; margin-left: 4px; font-weight: 600;">Bal: ₱' + formatCurrency(remBal) + '</span>';
                    }

                    const labelContent = isPrevBal
                        ? '<span style="display:flex; align-items:center; gap:0.25rem; flex-wrap:wrap; color:#991b1b; font-weight:800;"><i class="fas fa-history" style="color:#ef4444;"></i> ' + particular + badge + '</span>'
                        : '<span style="display:flex; align-items:center; gap:0.25rem; flex-wrap:wrap;">' + particular + badge + '</span>';

                    row.innerHTML =
                        '<label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; flex: 1; font-weight: 600; margin: 0;">' +
                            '<input type="checkbox" name="particulars_select[]" value="' + particular + '" class="drawer-fee-cb" style="cursor: pointer;">' +
                            labelContent +
                        '</label>' +
                        '<div style="display: flex; align-items: center; width: 100px;">' +
                            '<span style="font-size: 0.75rem; color: #64748b; margin-right: 2px;">₱</span>' +
                            '<input type="number" name="particular_amount[]" class="drawer-fee-amt" step="0.01" min="0" value="" placeholder="0.00" disabled ' + readonlyAttr + ' style="width: 100%; box-sizing: border-box; padding: 0.25rem; font-weight: 700; border: 1px solid ' + (isPrevBal && !isZero ? '#ef4444' : '#cbd5e1') + '; border-radius: 4px; text-align: right; font-size: 0.8rem; ' + readonlyStyle + '">' +
                        '</div>';

                    const cb = row.querySelector('.drawer-fee-cb');
                    const amtInput = row.querySelector('.drawer-fee-amt');

                    cb.addEventListener('change', function () {
                        amtInput.disabled = !this.checked;
                        if (this.checked && !isZero) {
                            if (amtInput.value === '' || parseFloat(amtInput.value) === 0) {
                                amtInput.value = remBal.toFixed(2);
                            }
                            amtInput.focus();
                        } else if (!this.checked) {
                            amtInput.value = '';
                        }
                        recalcDrawerTotals();
                    });
                    if (!isZero) {
                        amtInput.addEventListener('input', recalcDrawerTotals);
                    }

                    drawerFeesList.appendChild(row);
                });

                recalcDrawerTotals();
            }

            function recalcDrawerTotals() {
                let sum = 0.0;
                drawerFeesList.querySelectorAll('.drawer-fee-cb:checked').forEach(function (cb) {
                    const row = cb.closest('div');
                    if (row) {
                        const amt = row.querySelector('.drawer-fee-amt');
                        sum += parseFloat(amt ? amt.value : '0') || 0;
                    }
                });

                sum = Math.round(sum * 100) / 100;
                const currentBal = parseFloat(drawerBalanceDue.dataset.rawBalance || '0') || 0;

                if (!drawerUserModifiedAmount && drawerAmountInput) {
                    drawerAmountInput.value = sum > 0 ? sum.toFixed(2) : '0.00';
                }

                const amountToPay = Math.round((parseFloat(drawerAmountInput ? drawerAmountInput.value : '0') || 0) * 100) / 100;

                if (!drawerUserModifiedTendered && drawerAmountTendered) {
                    drawerAmountTendered.value = amountToPay > 0 ? amountToPay.toFixed(2) : '';
                }

                const tenderedStr = drawerAmountTendered ? drawerAmountTendered.value.trim() : '';
                const hasTendered = tenderedStr !== '';
                const tendered = hasTendered ? Math.round((parseFloat(tenderedStr) || 0) * 100) / 100 : 0.0;
                const isInsufficient = hasTendered && (tendered < (amountToPay - 0.001));
                const change = (hasTendered && !isInsufficient)
                    ? Math.max(0, Math.round((tendered - amountToPay) * 100) / 100)
                    : 0.0;
                const effectivePayment = amountToPay;

                if (drawerInsufficientWarning) {
                    if (isInsufficient) {
                        drawerInsufficientWarning.classList.remove('hidden');
                        drawerInsufficientWarning.style.display = 'block';
                    } else {
                        drawerInsufficientWarning.classList.add('hidden');
                        drawerInsufficientWarning.style.display = 'none';
                    }
                }
                if (drawerAmountTendered) {
                    drawerAmountTendered.style.borderColor = isInsufficient ? '#ef4444' : '#38bdf8';
                }

                if (drawerChangeBox) {
                    if (hasTendered && change > 0.001) {
                        drawerChangeBox.classList.remove('hidden');
                        drawerChangeBox.style.display = 'flex';
                        if (drawerChangeInput) {
                            drawerChangeInput.value = '₱ ' + formatCurrency(change);
                        }
                        if (drawerChangeStatus) {
                            drawerChangeStatus.textContent = 'Sukli: ₱ ' + formatCurrency(change);
                        }
                    } else {
                        drawerChangeBox.classList.add('hidden');
                        drawerChangeBox.style.display = 'none';
                        if (drawerChangeInput) {
                            drawerChangeInput.value = '';
                        }
                        if (drawerChangeStatus) {
                            drawerChangeStatus.textContent = '';
                        }
                    }
                }

                if (drawerTotalPaidDisplay) {
                    drawerTotalPaidDisplay.textContent = '₱' + formatCurrency(effectivePayment);
                    drawerTotalPaidDisplay.style.color = '#fef08a';
                }

                const newBal = Math.max(0, Math.round((currentBal - effectivePayment) * 100) / 100);
                const newBalElem = document.getElementById('drawerNewBalanceDisplay');
                if (newBalElem) {
                    newBalElem.textContent = '₱' + formatCurrency(newBal);
                }

                const alertElem = document.getElementById('drawerOverpaymentAlert');
                const submitBtn = document.getElementById('drawerSubmitBtn');
                const exceedsBalance = effectivePayment > (currentBal + 0.005) && currentBal > 0;
                const shouldDisableSubmit = exceedsBalance || isInsufficient;

                if (exceedsBalance) {
                    if (alertElem) {
                        alertElem.style.display = 'block';
                        alertElem.style.background = '#fee2e2';
                        alertElem.style.border = '1px solid #ef4444';
                        alertElem.style.color = '#991b1b';
                        alertElem.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Payment amount (₱' + formatCurrency(effectivePayment) + ') exceeds remaining balance of ₱' + formatCurrency(currentBal) + '.';
                    }
                } else if (isInsufficient) {
                    if (alertElem) {
                        alertElem.style.display = 'block';
                        alertElem.style.background = '#fee2e2';
                        alertElem.style.border = '1px solid #ef4444';
                        alertElem.style.color = '#991b1b';
                        alertElem.innerHTML = '<i class="fas fa-exclamation-circle"></i> Insufficient cash tendered (₱' + formatCurrency(tendered) + ' < ₱' + formatCurrency(amountToPay) + ').';
                    }
                } else if (alertElem) {
                    alertElem.style.display = 'none';
                    alertElem.innerHTML = '';
                }

                if (submitBtn) {
                    submitBtn.disabled = shouldDisableSubmit;
                    submitBtn.style.opacity = shouldDisableSubmit ? '0.5' : '1';
                    submitBtn.style.cursor = shouldDisableSubmit ? 'not-allowed' : 'pointer';
                }

                return effectivePayment;
            }

            if (drawerAmountInput) {
                drawerAmountInput.addEventListener('input', function () {
                    drawerUserModifiedAmount = true;
                    recalcDrawerTotals();
                });
            }

            if (drawerAmountTendered) {
                drawerAmountTendered.addEventListener('input', function () {
                    drawerUserModifiedTendered = true;
                    recalcDrawerTotals();
                });
            }

            // Drawer Denominations Click Handler
            document.querySelectorAll('.drawer-denom-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const mode = this.dataset.mode;
                    const due = parseFloat(drawerAmountInput.value || '0') || 0;
                    let cur = parseFloat(drawerAmountTendered.value || '0') || 0;

                    if (mode === 'exact') {
                        drawerUserModifiedTendered = false;
                        drawerAmountTendered.value = due > 0 ? due.toFixed(2) : '';
                    } else if (mode === 'add') {
                        drawerUserModifiedTendered = true;
                        const addVal = parseFloat(this.dataset.val || '0');
                        const base = cur > 0 ? cur : due;
                        drawerAmountTendered.value = (base + addVal).toFixed(2);
                    } else if (mode === 'set') {
                        drawerUserModifiedTendered = true;
                        const setVal = parseFloat(this.dataset.val || '0');
                        drawerAmountTendered.value = setVal.toFixed(2);
                    }
                    recalcDrawerTotals();
                });
            });

            // Delegate Click for Pay Button in Table
            tbody.addEventListener('click', function (e) {
                const btn = e.target.closest('.btn-open-drawer');
                if (btn) {
                    e.preventDefault();
                    const sid = btn.dataset.sid;
                    const name = btn.dataset.name;
                    const sno = btn.dataset.studentNo;
                    const meta = btn.dataset.meta;
                    const bal = parseFloat(btn.dataset.balance || '0');
                    openDrawer(sid, name, sno, meta, bal);
                }
            });

            // Submit Drawer Payment
            drawerForm.addEventListener('submit', async function (e) {
                if (!window.CapstoneAjax) return;
                e.preventDefault();
                drawerMessage.innerHTML = '';

                const checkedDrawerFees = drawerFeesList ? drawerFeesList.querySelectorAll('.drawer-fee-cb:checked').length : 0;
                const totalDrawerFeeOptions = drawerFeesList ? drawerFeesList.querySelectorAll('.drawer-fee-cb').length : 0;
                if (totalDrawerFeeOptions > 0 && checkedDrawerFees === 0) {
                    alert('Please select at least one fee particular being paid.');
                    return;
                }

                const currentBal = parseFloat(drawerBalanceDue.dataset.rawBalance || '0') || 0;
                const effectivePayment = recalcDrawerTotals();
                if (effectivePayment <= 0) {
                    alert('Please select at least one fee item with an amount greater than 0.');
                    return;
                }

                // Overpayment Validation: Check if Effective Payment Amount > Current Remaining Balance
                if (effectivePayment > (currentBal + 0.005)) {
                    alert('Payment amount exceeds the current remaining balance. Please enter an amount equal to or less than ₱' + formatCurrency(currentBal) + '.');
                    return;
                }

                const tenderedStr = drawerAmountTendered ? drawerAmountTendered.value.trim() : '';
                const tendered = tenderedStr !== '' ? (parseFloat(tenderedStr) || 0) : 0;
                const sum = parseFloat(drawerAmountInput.value || '0');
                if (tenderedStr !== '' && tendered < (sum - 0.001)) {
                    alert('Insufficient cash tendered. Cash received must be at least equal to ₱' + formatCurrency(sum) + '.');
                    return;
                }

                try {
                    const fd = new FormData(drawerForm);
                    fd.append('action', 'cashier_record_payment');
                    const resp = await window.CapstoneAjax.post('ajax.php?action=cashier_record_payment', fd);

                    const msg = resp.upgraded_to_enrolled
                        ? 'Payment recorded. Seminarian status automatically updated to Enrolled! Opening receipt...'
                        : 'Payment OR ' + (fd.get('or_number') || '') + ' recorded successfully! Opening receipt...';
                    setMessage('success', msg);

                    if (resp.receipt_url) {
                        const printUrl = resp.receipt_url + (resp.receipt_url.includes('?') ? '&' : '?') + 'auto_print=1';
                        window.open(printUrl, '_blank');
                    }

                    closeDrawer();
                    await loadAccounts();
                } catch (err) {
                    drawerMessage.innerHTML = '<p class="error" style="margin: 0; font-size: 0.85rem;">' + escapeHtml(err && err.message ? err.message : 'Failed to record payment.') + '</p>';
                }
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
