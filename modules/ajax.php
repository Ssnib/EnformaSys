<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function ajax_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function ajax_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === '') {
    ajax_error('Missing action.', 400);
}

try {
    if ($action === 'upcoming_events_notifications') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Authentication required.', 401);
        }
        require_once __DIR__ . '/../includes/timetable_helpers.php';
        $uid = (int)($_SESSION['user'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');
        $data = timetable_get_upcoming_notifications($conn, $uid, $role, 7);
        $alerts = [];
        $alerts_unread = 0;
        if (in_array($role, ['AcademicFormator', 'Academic Formator'], true)) {
            require_once __DIR__ . '/../includes/academic_module.php';
            $alert_data = afms_get_system_alerts($conn, $uid, 25);
            $alerts = $alert_data['alerts'] ?? [];
            $alerts_unread = (int)($alert_data['unread_count'] ?? 0);
        }
        $data['alerts'] = $alerts;
        $data['alerts_unread'] = $alerts_unread;
        $data['total_unread'] = (int)($data['unread_count'] ?? 0) + $alerts_unread;
        ajax_ok($data);
    }

    if ($action === 'mark_academic_alert_read') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Authentication required.', 401);
        }
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }
        $role = (string)($_SESSION['role'] ?? '');
        if (!in_array($role, ['AcademicFormator', 'Academic Formator'], true)) {
            ajax_error('Forbidden.', 403);
        }
        require_once __DIR__ . '/../includes/academic_module.php';
        $uid = (int)($_SESSION['user'] ?? 0);
        $nid = (int)($_POST['notification_id'] ?? 0);
        $marked = afms_mark_system_alerts_read($conn, $uid, $nid);
        if (function_exists('enformasys_invalidate_dashboard_cache')) {
            enformasys_invalidate_dashboard_cache();
        }
        $alert_data = afms_get_system_alerts($conn, $uid, 25);
        ajax_ok([
            'marked' => $marked,
            'alerts' => $alert_data['alerts'] ?? [],
            'alerts_unread' => (int)($alert_data['unread_count'] ?? 0),
        ]);
    }

    if ($action === 'mark_upcoming_events_read') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Authentication required.', 401);
        }
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }
        require_once __DIR__ . '/../includes/timetable_helpers.php';
        $uid = (int)($_SESSION['user'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        $raw_ids = $_POST['event_ids'] ?? '';
        $event_ids = is_string($raw_ids) ? json_decode($raw_ids, true) : (is_array($raw_ids) ? $raw_ids : []);
        if (!is_array($event_ids) || count($event_ids) === 0) {
            $cur = timetable_get_upcoming_notifications($conn, $uid, $role, 7);
            $event_ids = array_column($cur['events'] ?? [], 'id');
        }
        $marked = timetable_mark_notifications_read($conn, $uid, $event_ids);
        $alerts_marked = 0;
        if (!empty($_POST['include_alerts']) && in_array($role, ['AcademicFormator', 'Academic Formator'], true)) {
            require_once __DIR__ . '/../includes/academic_module.php';
            $alerts_marked = afms_mark_system_alerts_read($conn, $uid, 0);
        }
        if (function_exists('enformasys_invalidate_dashboard_cache')) {
            enformasys_invalidate_dashboard_cache();
        }
        ajax_ok(['marked' => $marked, 'alerts_marked' => $alerts_marked, 'unread_count' => 0, 'alerts_unread' => 0]);
    }

    if ($action === 'timetable_check_conflict') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Authentication required.', 401);
        }
        require_roles(['Dean', 'Shrine']);
        require_once __DIR__ . '/../includes/timetable_helpers.php';

        $date = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
        $start_time = trim((string)($_GET['time'] ?? $_POST['time'] ?? ''));
        $end_time = trim((string)($_GET['end_time'] ?? $_POST['end_time'] ?? ''));
        $exclude_id = (int)($_GET['exclude_id'] ?? $_POST['exclude_id'] ?? 0);
        $location = trim((string)($_GET['location'] ?? $_POST['location'] ?? ''));

        if ($date === '' || $start_time === '' || $end_time === '') {
            ajax_ok(['conflict' => false]);
        }

        $conf = timetable_find_conflict($conn, $date, $start_time, $end_time, $exclude_id, $location);
        if ($conf !== null) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'conflict' => true,
                'invalid_range' => !empty($conf['invalid_range']),
                'error' => (string)$conf['message'],
                'conflicting_event' => $conf,
            ]);
            exit;
        }
        ajax_ok(['conflict' => false]);
    }

    if ($action === 'student_mark_notification_read') {
        require_roles(['Student']);
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            ajax_error('POST method required.', 405);
        }
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }
        $uid = (int)($_SESSION['user'] ?? 0);
        $sid = 0;
        $stmt_sem = $conn->prepare("SELECT id FROM seminarians WHERE user_id = ? LIMIT 1");
        if ($stmt_sem) {
            $stmt_sem->bind_param('i', $uid);
            $stmt_sem->execute();
            if ($row = $stmt_sem->get_result()->fetch_assoc()) {
                $sid = (int)$row['id'];
            }
            $stmt_sem->close();
        }
        if ($sid <= 0) {
            ajax_error('Seminarian record not found.', 404);
        }
        $target = trim((string)($_POST['type'] ?? 'all'));
        $allowed_targets = ['grades', 'my_academic', 'evaluations', 'soa', 'ledger', 'my_balance', 'all'];
        if (!in_array($target, $allowed_targets, true)) {
            ajax_error('Invalid notification type.', 400);
        }
        if ($target === 'grades' || $target === 'my_academic' || $target === 'all') {
            $stmt_upd = $conn->prepare("UPDATE seminarian_access_requests SET is_read = 1 WHERE seminarian_id = ? AND assignment_type = 'Academic' AND status = 'Approved' AND is_read = 0");
            if ($stmt_upd) {
                $stmt_upd->bind_param('i', $sid);
                $stmt_upd->execute();
                $stmt_upd->close();
            }
        }
        if ($target === 'evaluations' || $target === 'all') {
            $stmt_upd = $conn->prepare("UPDATE seminarian_access_requests SET is_read = 1 WHERE seminarian_id = ? AND assignment_type IN ('Spiritual', 'Human', 'Pastoral') AND status = 'Approved' AND is_read = 0");
            if ($stmt_upd) {
                $stmt_upd->bind_param('i', $sid);
                $stmt_upd->execute();
                $stmt_upd->close();
            }
        }
        if ($target === 'soa' || $target === 'ledger' || $target === 'my_balance' || $target === 'all') {
            $stmt_upd = $conn->prepare("UPDATE cashier_soa_requests SET is_read = 1 WHERE seminarian_id = ? AND status IN ('Approved', 'Completed', 'Sent') AND is_read = 0");
            if ($stmt_upd) {
                $stmt_upd->bind_param('i', $sid);
                $stmt_upd->execute();
                $stmt_upd->close();
            }
        }
        ajax_ok(['type' => $target, 'cleared' => true]);
    }

    if ($action === 'cashier_payments_list') {
        require_roles(['Cashier']);
        $current_ay = get_current_academic_year();
        $ay_options = get_active_academic_years();
        if (!in_array($current_ay, $ay_options, true)) {
            array_unshift($ay_options, $current_ay);
        }

        $ay = trim((string)($_GET['academic_year'] ?? $current_ay));
        $sem = trim((string)($_GET['semester'] ?? '1st'));
        if (!in_array($ay, $ay_options, true)) $ay = $current_ay;
        if (!in_array($sem, ['1st', '2nd'], true)) $sem = '1st';

        $payments = [];
        $stmt = $conn->prepare(
            "SELECT p.id, p.payment_date, p.or_number, p.payer_type, p.amount, p.amount_tendered, p.method, p.remaining_balance, p.overpayment,\n"
            . "  COALESCE(up.full_name, u.username) as full_name,\n"
            . "  (\n"
            . "    SELECT GROUP_CONCAT(pi.particular ORDER BY pi.sort_order ASC, pi.id ASC SEPARATOR ', ')\n"
            . "    FROM cashier_payment_items pi\n"
            . "    WHERE pi.payment_id = p.id AND pi.amount > 0.0001\n"
            . "  ) as particulars_summary\n"
            . "FROM lodging_payments p\n"
            . "JOIN seminarians s ON s.id = p.seminarian_id\n"
            . "JOIN users u ON u.id = s.user_id\n"
            . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
            . "WHERE p.academic_year = ? AND p.semester = ?\n"
            . "ORDER BY p.payment_date DESC, p.id DESC\n"
            . "LIMIT 100"
        );
        $stmt->bind_param('ss', $ay, $sem);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $payments[] = [
                'id' => (int)($r['id'] ?? 0),
                'payment_date' => (string)($r['payment_date'] ?? ''),
                'or_number' => (string)($r['or_number'] ?? ''),
                'full_name' => (string)($r['full_name'] ?? ''),
                'payer_type' => (string)($r['payer_type'] ?? ''),
                'particulars' => (string)($r['particulars_summary'] ?? ''),
                'amount' => (float)($r['amount'] ?? 0),
                'amount_tendered' => isset($r['amount_tendered']) && $r['amount_tendered'] !== null ? (float)$r['amount_tendered'] : (float)($r['amount'] ?? 0),
                'method' => (string)($r['method'] ?? ''),
                'remaining_balance' => (float)($r['remaining_balance'] ?? 0),
                'change' => (float)($r['overpayment'] ?? 0),
            ];
        }
        ajax_ok(['payments' => $payments]);
    }

    if ($action === 'cashier_record_payment') {
        require_roles(['Cashier']);
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }

        require_once __DIR__ . '/../includes/lodging.php';

        $current_ay = get_current_academic_year();
        $ay_options = get_active_academic_years();
        if (!in_array($current_ay, $ay_options, true)) {
            array_unshift($ay_options, $current_ay);
        }

        $payer_types = [
            'Parents',
            'Parish Sponsors',
            'Diocese',
            'Benefactors',
            'Foundations',
            'Individual Sponsors',
            'Other',
        ];
        $methods = [
            'cash' => 'Cash',
            'bank_deposit' => 'Bank Deposit',
            'online_transfer' => 'Online Transfer',
            'check' => 'Check',
            'other' => 'Other',
        ];
        $particular_options = [
            'Boarding Fee',
            'Tuition Fee',
            'Transportation Fee',
            'Computer Fee',
            'Miscellaneous Fee',
            'Other Charges',
            'Previous Balance',
            'Semester Fees',
            'Other',
        ];

        $seminarian_id = (int)($_POST['seminarian_id'] ?? 0);
        $academic_year = trim((string)($_POST['academic_year'] ?? $current_ay));
        $semester = trim((string)($_POST['semester'] ?? '1st'));
        $or_number = trim((string)($_POST['or_number'] ?? ''));
        $payment_date = (string)($_POST['payment_date'] ?? date('Y-m-d'));
        $amount = (float)($_POST['amount'] ?? 0);
        $method = trim((string)($_POST['method'] ?? 'cash'));
        $reference_no = trim((string)($_POST['reference_no'] ?? ''));
        $payer_type = trim((string)($_POST['payer_type'] ?? ''));
        $payer_name = trim((string)($_POST['payer_name'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        $selected_amount = (float)($_POST['amount'] ?? 0);
        $tendered_raw = isset($_POST['amount_tendered']) && trim((string)$_POST['amount_tendered']) !== '' ? (float)$_POST['amount_tendered'] : null;

        if ($tendered_raw !== null && $tendered_raw < ($selected_amount - 0.005)) {
            ajax_error('Insufficient cash tendered. Cash received must be at least equal to Amount to Pay.', 400);
        }

        $actual_payment = round($selected_amount, 2);
        if ($tendered_raw !== null) {
            $amount_tendered = round($tendered_raw, 2);
            $change_given = max(0.0, round($amount_tendered - $actual_payment, 2));
        } else {
            $amount_tendered = $actual_payment;
            $change_given = 0.0;
        }
        $amount = $actual_payment;
        $is_partial = false;

        $particulars_select_arr = $_POST['particulars_select'] ?? [];
        $particulars_other_arr = $_POST['particulars_other'] ?? [];
        $particular_amount_arr = $_POST['particular_amount'] ?? [];
        if (!is_array($particulars_select_arr)) $particulars_select_arr = [];
        if (!is_array($particulars_other_arr)) $particulars_other_arr = [];
        if (!is_array($particular_amount_arr)) $particular_amount_arr = [];

        $payment_items = [];
        $max_rows = max(count($particulars_select_arr), count($particular_amount_arr), count($particulars_other_arr));
        for ($i = 0; $i < $max_rows; $i++) {
            $sel = trim((string)($particulars_select_arr[$i] ?? ''));
            $other_txt = trim((string)($particulars_other_arr[$i] ?? ''));
            $amt = (float)($particular_amount_arr[$i] ?? 0);
            if ($sel === '' && $other_txt === '' && !isset($particular_amount_arr[$i])) {
                continue;
            }
            $particular = $sel === 'Other' ? $other_txt : $sel;
            if ($particular === '') {
                continue;
            }
            if ($amt < 0) {
                ajax_error('Particular amount cannot be negative.', 400);
            }
            $payment_items[] = ['particular' => $particular, 'amount' => $amt];
        }

        if ($seminarian_id <= 0) {
            ajax_error('Please select a seminarian.', 400);
        }
        if ($or_number === '') {
            ajax_error('Official Receipt Number is required.', 400);
        }
        if (!in_array($academic_year, $ay_options, true)) {
            ajax_error('Invalid Academic Year.', 400);
        }
        if (!in_array($semester, ['1st', '2nd'], true)) {
            ajax_error('Invalid semester.', 400);
        }
        if ($actual_payment <= 0) {
            ajax_error('Amount must be greater than 0.', 400);
        }
        if (!array_key_exists($method, $methods)) {
            ajax_error('Invalid payment method.', 400);
        }
        if ($payer_type !== '' && !in_array($payer_type, $payer_types, true)) {
            ajax_error('Invalid payer type.', 400);
        }

        $stmt_or = $conn->prepare("SELECT COUNT(*) as c FROM lodging_payments WHERE or_number = ?");
        $stmt_or->bind_param('s', $or_number);
        $stmt_or->execute();
        $exists = (int)($stmt_or->get_result()->fetch_assoc()['c'] ?? 0);
        if ($exists > 0) {
            ajax_error('Official Receipt Number already exists.', 400);
        }

        // Ensure term invoice exists for this seminarian and academic term
        lodging_ensure_student_invoice($conn, $seminarian_id, $academic_year, $semester);

        // Fetch current dynamic balance before this payment
        $bal_info = lodging_get_seminarian_balance($conn, $seminarian_id, $academic_year, $semester);
        $previous_balance = (float)$bal_info['remaining_balance'];
        $total_amount_due = (float)$bal_info['total_due'];
        $total_payments_made = (float)$bal_info['total_paid'];

        // Overpayment Validation: Check if Actual Payment Amount > Current Remaining Balance
        if ($actual_payment > ($previous_balance + 0.005)) {
            $formatted_bal = number_format($previous_balance, 2);
            ajax_error("Payment amount exceeds the current remaining balance. Please enter an amount equal to or less than ₱{$formatted_bal}.", 400);
        }

        $conn->begin_transaction();
        try {
            $received_by = (int)($_SESSION['user'] ?? 0);

            // Resolve exact itemized particulars & amounts from selected items / unpaid FIFO fees
            $distributed_payment_items = cashier_allocate_payment_particulars(
                $conn,
                $seminarian_id,
                $academic_year,
                $semester,
                $actual_payment,
                $payment_items
            );

            $notes_summary = [];
            foreach ($distributed_payment_items as $it) {
                $clean_part = trim((string)preg_replace('/\s*\([^)]*\)/', '', (string)($it['particular'] ?? '')));
                if ($it['amount'] > 0.0001 && $clean_part !== '' && !in_array($clean_part, $notes_summary, true)) {
                    $notes_summary[] = $clean_part;
                }
            }
            $notes_value = implode(', ', $notes_summary);

            $stmt = $conn->prepare(
                "INSERT INTO lodging_payments (or_number, payer_type, payer_name, remarks, seminarian_id, payment_date, academic_year, semester, amount, amount_tendered, method, reference_no, notes, received_by)\n"
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $payer_type_db = $payer_type !== '' ? $payer_type : null;
            $payer_name_db = $payer_name !== '' ? $payer_name : null;
            $remarks_db = $remarks !== '' ? $remarks : null;
            $reference_db = $reference_no !== '' ? $reference_no : null;
            $notes_db = $notes_value !== '' ? $notes_value : null;
            $stmt->bind_param('ssssisssddsssi', $or_number, $payer_type_db, $payer_name_db, $remarks_db, $seminarian_id, $payment_date, $academic_year, $semester, $actual_payment, $amount_tendered, $method, $reference_db, $notes_db, $received_by);
            $stmt->execute();
            $payment_id = (int)$conn->insert_id;

            if ($payment_id > 0) {
                $stmt_item = $conn->prepare("INSERT INTO cashier_payment_items (payment_id, particular, amount, sort_order) VALUES (?, ?, ?, ?)");
                $sort_idx = 0;
                foreach ($distributed_payment_items as $it) {
                    if ($it['amount'] > 0 || stripos($it['particular'], 'rice') !== false) {
                        $p_part = trim((string)preg_replace('/\s*\([^)]*\)/', '', (string)$it['particular']));
                        $p_amt = (float)$it['amount'];
                        $stmt_item->bind_param('isdi', $payment_id, $p_part, $p_amt, $sort_idx);
                        $stmt_item->execute();
                        $sort_idx++;
                    }
                }
                lodging_allocate_payment($conn, $payment_id, $seminarian_id, $actual_payment);
            }

            // Dynamic Balance Computation:
            // Exact Partial Payment Deduction: New Balance = Old Balance - Amount Tendered
            $new_total_payments = round($total_payments_made + $actual_payment, 2);
            $remaining_balance = max(0.0, round($previous_balance - $actual_payment, 2));

            if ($payment_id > 0) {
                $stmt_upd = $conn->prepare("UPDATE lodging_payments SET previous_balance = ?, remaining_balance = ?, overpayment = ? WHERE id = ?");
                $stmt_upd->bind_param('dddi', $previous_balance, $remaining_balance, $change_given, $payment_id);
                $stmt_upd->execute();
            }

            $sync_info = cashier_sync_enrollment_after_payment($conn, $seminarian_id, $academic_year, $semester);

            $conn->commit();
            log_activity((int)($_SESSION['user'] ?? 0), "Recorded payment OR {$or_number} for seminarian ID {$seminarian_id}");
            ajax_ok([
                'payment_id' => $payment_id,
                'receipt_url' => 'cashier_receipt.php?payment_id=' . (int)$payment_id,
                'change' => $change_given,
                'previous_balance' => $previous_balance,
                'remaining_balance' => $remaining_balance,
                'total_amount_due' => $total_amount_due,
                'total_payments_made' => $new_total_payments,
                'term_paid' => $sync_info['term_paid'],
                'upgraded_to_enrolled' => $sync_info['upgraded_to_enrolled'],
                'enrollment_status' => $sync_info['enrollment_status'],
            ]);
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ($e->getCode() === 1062) {
                ajax_error('Official Receipt Number already exists. Another transaction may have used it simultaneously.', 409);
            }
            ajax_error('Failed to record payment: ' . $e->getMessage(), 500);
        } catch (Exception $e) {
            $conn->rollback();
            ajax_error('Failed to record payment: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'suggest_or_number') {
        require_roles(['Cashier']);
        $stmt = $conn->query("SELECT or_number FROM lodging_payments ORDER BY id DESC LIMIT 50");
        $max_num = 0;
        $prefix = 'OR-';
        $has_prefix = false;
        $digits_len = 4;
        while ($stmt && ($r = $stmt->fetch_assoc())) {
            $or = trim((string)($r['or_number'] ?? ''));
            if (preg_match('/^([A-Za-z_-]+)?(\d+)$/', $or, $m)) {
                $num = (int)$m[2];
                if ($num > $max_num) {
                    $max_num = $num;
                    if (!empty($m[1])) {
                        $prefix = $m[1];
                        $has_prefix = true;
                    }
                    $digits_len = max(strlen($m[2]), 4);
                }
            }
        }
        $next_num = $max_num + 1;
        $suggested = ($has_prefix ? $prefix : 'OR-') . str_pad((string)$next_num, $digits_len, '0', STR_PAD_LEFT);
        ajax_ok(['suggested' => $suggested]);
    }

    if ($action === 'check_or_number') {
        require_roles(['Cashier']);
        $or = trim((string)($_GET['or_number'] ?? $_POST['or_number'] ?? ''));
        if ($or === '') {
            ajax_ok(['taken' => false, 'or_number' => '']);
        }
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM lodging_payments WHERE or_number = ?");
        $stmt->bind_param('s', $or);
        $stmt->execute();
        $taken = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        ajax_ok(['taken' => $taken, 'or_number' => $or]);
    }

    if ($action === 'get_student_fees') {
        require_roles(['Cashier']);
        $seminarian_id = (int)($_GET['seminarian_id'] ?? $_POST['seminarian_id'] ?? 0);
        $current_ay = get_current_academic_year();
        $ay_options = get_active_academic_years();
        if (!in_array($current_ay, $ay_options, true)) {
            array_unshift($ay_options, $current_ay);
        }
        $academic_year = trim((string)($_GET['academic_year'] ?? $_POST['academic_year'] ?? $current_ay));
        $semester = trim((string)($_GET['semester'] ?? $_POST['semester'] ?? '1st'));

        if ($seminarian_id <= 0) {
            ajax_error('Seminarian ID is required.', 400);
        }

        $stmt_s = $conn->prepare(
            "SELECT s.id, s.program, s.year_level, COALESCE(up.full_name, u.username) as full_name, up.student_id\n"
            . "FROM seminarians s\n"
            . "JOIN users u ON u.id = s.user_id\n"
            . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
            . "WHERE s.id = ? LIMIT 1"
        );
        $stmt_s->bind_param('i', $seminarian_id);
        $stmt_s->execute();
        $student = $stmt_s->get_result()->fetch_assoc();
        if (!$student) {
            ajax_error('Seminarian not found.', 404);
        }

        require_once __DIR__ . '/../includes/lodging.php';
        $breakdown = lodging_get_student_fee_breakdown($conn, $seminarian_id, $academic_year, $semester);

        ajax_ok([
            'fees' => $breakdown['fees'],
            'previous_balance' => $breakdown['previous_balance'],
            'previous_balance_remaining' => $breakdown['previous_balance_remaining'] ?? $breakdown['previous_balance'],
            'current_term_due' => $breakdown['current_term_due'] ?? 0.0,
            'total_due' => $breakdown['total_due'],
            'total_paid' => $breakdown['total_paid'],
            'remaining_balance' => $breakdown['remaining_balance'] ?? ($breakdown['total_due'] - $breakdown['total_paid']),
            'student' => $student,
        ]);
    }

    if ($action === 'get_academic_access_requests') {
        require_roles(['AcademicFormator', 'Registrar', 'Dean']);
        require_once __DIR__ . '/../includes/access_requests.php';
        $hasTermCols = capstone_access_has_term_columns($conn);
        $termSelect = $hasTermCols ? 'r.academic_year, r.semester, ' : '';
        $requests = [];
        $stmt_par = $conn->query("SELECT r.id, r.seminarian_id, {$termSelect}r.message, r.request_scope, r.requested_subjects, r.requested_at,
                u.username, up.full_name,
                COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no
            FROM seminarian_access_requests r
            JOIN seminarians s ON s.id = r.seminarian_id
            JOIN users u ON u.id = s.user_id
            LEFT JOIN students stu ON stu.user_id = u.id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE r.assignment_type = 'Academic' AND r.status = 'Pending'
            ORDER BY r.requested_at DESC, r.id DESC");
        if ($stmt_par) {
            while ($row = $stmt_par->fetch_assoc()) {
                $sname = trim((string)($row['full_name'] ?? ''));
                if ($sname === '') {
                    $sname = (string)($row['username'] ?? 'Seminarian');
                }
                $rsubs = [];
                if (!empty($row['requested_subjects'])) {
                    $decoded = json_decode((string)$row['requested_subjects'], true);
                    if (is_array($decoded)) {
                        $rsubs = $decoded;
                    }
                }
                $reqAy = trim((string)($row['academic_year'] ?? ''));
                $reqSem = capstone_access_normalize_semester((string)($row['semester'] ?? ''));
                $termLabel = ($reqAy !== '' && $reqSem !== '')
                    ? ('AY ' . $reqAy . ' · ' . $reqSem . ' Sem')
                    : ($reqAy !== '' ? ('AY ' . $reqAy) : 'Active Term');
                $requests[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'seminarian_id' => (int)($row['seminarian_id'] ?? 0),
                    'academic_year' => $reqAy,
                    'semester' => $reqSem,
                    'term_label' => $termLabel,
                    'display_name' => $sname,
                    'student_no' => (string)($row['student_no'] ?? ''),
                    'message' => (string)($row['message'] ?? ''),
                    'request_scope' => (string)($row['request_scope'] ?? 'whole'),
                    'requested_subjects_list' => $rsubs,
                    'requested_at' => (string)($row['requested_at'] ?? ''),
                    'requested_at_formatted' => !empty($row['requested_at']) ? date('M d, Y - h:i A', strtotime((string)$row['requested_at'])) : '',
                ];
            }
        }
        ajax_ok(['requests' => $requests, 'pending_count' => count($requests)]);
    }

    if ($action === 'cashier_soa_requests_list') {
        require_roles(['Cashier']);
        $status = trim((string)($_GET['status'] ?? 'Pending'));
        $allowed_status = ['Pending', 'Processing', 'Sent', 'Completed'];
        if (!in_array($status, $allowed_status, true)) $status = 'Pending';

        $requests = [];
        $stmt = $conn->prepare(
            "SELECT r.id, r.seminarian_id, r.academic_year, r.semester, r.status, r.requested_at,\n"
            . "  COALESCE(up.full_name, u.username) as full_name\n"
            . "FROM cashier_soa_requests r\n"
            . "JOIN seminarians s ON s.id = r.seminarian_id\n"
            . "JOIN users u ON u.id = s.user_id\n"
            . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
            . "WHERE r.status = ?\n"
            . "ORDER BY r.requested_at DESC, r.id DESC\n"
            . "LIMIT 200"
        );
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $requests[] = [
                'id' => (int)($r['id'] ?? 0),
                'seminarian_id' => (int)($r['seminarian_id'] ?? 0),
                'academic_year' => (string)($r['academic_year'] ?? ''),
                'semester' => (string)($r['semester'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'requested_at' => (string)($r['requested_at'] ?? ''),
                'full_name' => (string)($r['full_name'] ?? ''),
            ];
        }
        $counts = ['Pending' => 0, 'Processing' => 0, 'Sent' => 0, 'Completed' => 0];
        $res_c = $conn->query("SELECT status, COUNT(*) as c FROM cashier_soa_requests GROUP BY status");
        while ($res_c && ($rc = $res_c->fetch_assoc())) {
            $st = (string)($rc['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int)$rc['c'];
            }
        }
        ajax_ok(['requests' => $requests, 'counts' => $counts]);
    }

    if ($action === 'cashier_soa_requests_update') {
        require_roles(['Cashier']);
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }

        $allowed_status = ['Pending', 'Processing', 'Sent', 'Completed'];
        $req_id = (int)($_POST['request_id'] ?? 0);
        $new_status = trim((string)($_POST['new_status'] ?? ''));
        if ($req_id <= 0 || !in_array($new_status, $allowed_status, true)) {
            ajax_error('Invalid request.', 400);
        }

        $processed_by = (int)($_SESSION['user'] ?? 0);
        $processed_at = in_array($new_status, ['Sent', 'Completed'], true) ? date('Y-m-d H:i:s') : null;
        if ($new_status === 'Sent') {
            $stmt = $conn->prepare("UPDATE cashier_soa_requests SET status = ?, processed_by = ?, processed_at = COALESCE(?, processed_at), is_read = 0 WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE cashier_soa_requests SET status = ?, processed_by = ?, processed_at = COALESCE(?, processed_at) WHERE id = ?");
        }
        $stmt->bind_param('sisi', $new_status, $processed_by, $processed_at, $req_id);
        $stmt->execute();
        $stmt->close();
        if (function_exists('log_activity')) {
            log_activity($processed_by, "Updated Cashier SOA request #{$req_id} status to {$new_status}", 'cashier_soa_requests', $req_id, 'status', null, $new_status);
        }

        $counts = ['Pending' => 0, 'Processing' => 0, 'Sent' => 0, 'Completed' => 0];
        $res_c = $conn->query("SELECT status, COUNT(*) as c FROM cashier_soa_requests GROUP BY status");
        while ($res_c && ($rc = $res_c->fetch_assoc())) {
            $st = (string)($rc['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int)$rc['c'];
            }
        }
        ajax_ok(['status' => $new_status, 'counts' => $counts]);
    }

    if ($action === 'cashier_accounts_list') {
        require_roles(['Cashier']);
        $current_ay = get_current_academic_year();
        $ay_options = get_active_academic_years();
        if (!in_array($current_ay, $ay_options, true)) {
            array_unshift($ay_options, $current_ay);
        }

        $academic_year = trim((string)($_GET['academic_year'] ?? $current_ay));
        $semester = trim((string)($_GET['semester'] ?? '1st'));
        $year_level = trim((string)($_GET['year_level'] ?? ''));

        if (!in_array($academic_year, $ay_options, true)) $academic_year = $current_ay;
        if (!in_array($semester, ['1st', '2nd'], true)) $semester = '1st';

        $allowed_year_levels = [
            '',
            'Propaedeutic Stage',
            'First Year',
            'Second Year',
            'Third Year',
            'Fourth Year',
            'Completed',
        ];
        if (!in_array($year_level, $allowed_year_levels, true)) $year_level = '';

        $sql = "SELECT s.id as seminarian_id,
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
            $sql .= " AND s.year_level = ?";
            $types .= 's';
            $params[] = $year_level;
        }
        $sql .= " ORDER BY full_name ASC";

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $bind = [];
            $bind[] = $types;
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        require_once __DIR__ . '/../includes/lodging.php';
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $sid = (int)($r['seminarian_id'] ?? 0);
            $bal_info = lodging_get_seminarian_balance($conn, $sid, $academic_year, $semester);
            $billed = (float)$bal_info['total_due'];
            $paid = (float)$bal_info['total_paid'];
            $bal = round($billed - $paid, 2);
            $rows[] = [
                'seminarian_id' => $sid,
                'full_name' => (string)($r['full_name'] ?? ''),
                'student_no' => (string)($r['student_no'] ?? ''),
                'program' => (string)($r['program'] ?? ''),
                'year_level' => (string)($r['year_level'] ?? ''),
                'formation_status' => (string)($r['formation_status'] ?? ''),
                'enrollment_status' => (string)($r['enrollment_status'] ?? 'Pending'),
                'billed' => $billed,
                'paid' => $paid,
                'balance' => max(0.0, $bal),
                'credit' => $bal < 0 ? abs($bal) : 0.0,
                'previous_balance' => (float)($bal_info['previous_balance'] ?? 0),
                'current_term_due' => (float)($bal_info['current_term_due'] ?? 0),
            ];
        }

        ajax_ok([
            'academic_year' => $academic_year,
            'semester' => $semester,
            'year_level' => $year_level,
            'accounts' => $rows,
        ]);
    }

    if ($action === 'enrollment_enrolled_list') {
        require_roles(['Registrar']);

        $current_ay = get_current_academic_year();
        $ay_options = get_active_academic_years();
        if (!in_array($current_ay, $ay_options, true)) {
            array_unshift($ay_options, $current_ay);
        }

        $allowed_year_levels = [
            'Propaedeutic Stage',
            'First Year',
            'Second Year',
            'Third Year',
            'Fourth Year',
            'Completed',
        ];

        $ay = trim((string)($_GET['academic_year'] ?? ''));
        $yl = trim((string)($_GET['year_level'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 10;
        if ($ay !== '' && !in_array($ay, $ay_options, true)) $ay = '';
        if ($yl !== '' && !in_array($yl, $allowed_year_levels, true)) $yl = '';

        $base_where = " WHERE a.archived_at IS NULL AND a.status = 'Enrolled'";
        $joins = " LEFT JOIN users u ON (a.user_id = u.id OR (a.user_id IS NULL AND a.status = 'Enrolled' AND u.username = a.full_name))"
            . " LEFT JOIN user_profiles p ON p.user_id = u.id"
            . " LEFT JOIN seminarians sem ON sem.user_id = u.id";

        $types = '';
        $params = [];
        if ($ay !== '') {
            $base_where .= " AND a.academic_year = ?";
            $types .= 's';
            $params[] = $ay;
        }
        if ($yl !== '') {
            $base_where .= " AND sem.year_level = ?";
            $types .= 's';
            $params[] = $yl;
        }

        $count_sql = "SELECT COUNT(*) as c FROM applicants a" . $joins . $base_where;
        $stmt_c = $conn->prepare($count_sql);
        if ($types !== '') {
            $bind_c = [];
            $bind_c[] = $types;
            foreach ($params as $k => $v) $bind_c[] = &$params[$k];
            call_user_func_array([$stmt_c, 'bind_param'], $bind_c);
        }
        $stmt_c->execute();
        $total_rows = (int)($stmt_c->get_result()->fetch_assoc()['c'] ?? 0);
        $total_pages = (int)max(1, (int)ceil($total_rows / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT a.id, a.full_name, a.gender, a.age, a.contact, a.address, a.academic_history, a.parent_info, a.status,\n"
            . "  u.username AS current_username, p.full_name AS profile_full_name, p.contact AS profile_contact, p.address AS profile_address,\n"
            . "  sem.id AS seminarian_id, sem.year_level AS sem_year_level, sem.is_graduate AS sem_is_graduate\n"
            . "FROM applicants a" . $joins . $base_where . " ORDER BY a.id DESC LIMIT ? OFFSET ?";
        $types_q = $types . 'ii';
        $params_q = $params;
        $params_q[] = $per_page;
        $params_q[] = $offset;

        $stmt = $conn->prepare($sql);
        $bind = [];
        $bind[] = $types_q;
        foreach ($params_q as $k => $v) $bind[] = &$params_q[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $display_name = trim((string)($r['profile_full_name'] ?? ''));
            if ($display_name === '') $display_name = trim((string)($r['current_username'] ?? ''));
            if ($display_name === '') $display_name = (string)($r['full_name'] ?? '');

            $display_contact = trim((string)($r['profile_contact'] ?? ''));
            if ($display_contact === '') $display_contact = (string)($r['contact'] ?? '');

            $display_address = trim((string)($r['profile_address'] ?? ''));
            if ($display_address === '') $display_address = (string)($r['address'] ?? '');

            $is_graduate = ((int)($r['sem_is_graduate'] ?? 0) === 1) || (trim((string)($r['sem_year_level'] ?? '')) === 'Completed');
            $status_text = (string)($r['status'] ?? 'Enrolled');
            $badge_class = 'badge-success';
            if ($is_graduate) {
                $status_text = 'Graduated';
                $badge_class = 'badge-primary';
            }

            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'display_name' => $display_name,
                'gender' => (string)($r['gender'] ?? ''),
                'age' => (string)($r['age'] ?? ''),
                'contact' => $display_contact,
                'address' => $display_address,
                'academic_history' => (string)($r['academic_history'] ?? ''),
                'parent_info' => (string)($r['parent_info'] ?? ''),
                'status_text' => $status_text,
                'badge_class' => $badge_class,
                'seminarian_id' => (int)($r['seminarian_id'] ?? 0),
            ];
        }

        ajax_ok([
            'total_rows' => $total_rows,
            'page' => $page,
            'total_pages' => $total_pages,
            'rows' => $rows,
        ]);
    }

    // ── Module 1: Calendar events for the mini-calendar widget ──────────────
    if ($action === 'get_events_for_month') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Not authenticated.', 401);
        }
        $year  = (int)($_GET['year']  ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');
        if ($month < 1   || $month > 12)  $month = (int)date('n');

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $role = (string)($_SESSION['role'] ?? '');
        $show_all = in_array($role, ['Dean', 'Shrine', 'Registrar', 'Rector'], true);

        // Check visibility column exists before using it
        $vis_col_res = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'visibility'");
        $has_visibility = $vis_col_res && (int)($vis_col_res->fetch_assoc()['c'] ?? 0) > 0;

        $vis_clause = ($has_visibility && !$show_all) ? " AND visibility = 'all'" : '';

        $stmt = $conn->prepare(
            "SELECT id, event, date, time, type, note"
            . ($has_visibility ? ", end_time, visibility" : "")
            . " FROM timetable"
            . " WHERE date BETWEEN ? AND ?"
            . "   AND archived_at IS NULL"
            . $vis_clause
            . " ORDER BY date ASC, time ASC"
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $events = [];
        foreach ($rows as $r) {
            $events[] = [
                'id'    => (int)($r['id'] ?? 0),
                'event' => (string)($r['event'] ?? ''),
                'date'  => (string)($r['date'] ?? ''),
                'time'  => (string)($r['time'] ?? ''),
                'type'  => (string)($r['type'] ?? ''),
                'note'  => (string)($r['note'] ?? ''),
                'end_time' => (string)($r['end_time'] ?? ''),
            ];
        }
        ajax_ok(['events' => $events]);
    }

    // ── Module: Student Editable Class Schedule Actions ─────────────────────
    if ($action === 'save_student_schedule') {
        if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'Student') {
            ajax_error('Unauthorized. Student session required.', 403);
        }
        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        if (!is_array($json_data)) $json_data = [];
        $csrf_token = $_POST['csrf_token'] ?? ($json_data['csrf_token'] ?? '');
        if (!verify_csrf_token($csrf_token)) {
            ajax_error('CSRF token validation failed.', 403);
        }
        $user_id = (int)$_SESSION['user'];
        $stmt_s = $conn->prepare("SELECT id FROM seminarians WHERE user_id = ? LIMIT 1");
        $stmt_s->bind_param('i', $user_id);
        $stmt_s->execute();
        $sem = $stmt_s->get_result()->fetch_assoc();
        if (!$sem) {
            ajax_error('Seminarian record not found.', 404);
        }
        $seminarian_id = (int)$sem['id'];

        $schedules = $_POST['schedules'] ?? ($json_data['schedules'] ?? []);
        if (!is_array($schedules)) {
            ajax_error('Invalid schedule data payload.', 400);
        }

        $saved = [];
        $stmt_upsert = $conn->prepare("INSERT INTO student_schedules (seminarian_id, subject_code, subject_name, days, start_time, end_time, room, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                subject_name = VALUES(subject_name),
                days = VALUES(days),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                room = VALUES(room),
                updated_at = NOW()");

        foreach ($schedules as $item) {
            $code = trim((string)($item['subject_code'] ?? ''));
            $name = trim((string)($item['subject_name'] ?? ''));
            $days = trim((string)($item['days'] ?? 'MWF'));
            $start = trim((string)($item['start_time'] ?? '08:00 AM'));
            $end = trim((string)($item['end_time'] ?? '09:30 AM'));
            $room = trim((string)($item['room'] ?? 'St. Thomas Hall 201'));

            if ($code === '') continue;

            $stmt_upsert->bind_param('issssss', $seminarian_id, $code, $name, $days, $start, $end, $room);
            $stmt_upsert->execute();

            $saved[] = [
                'code' => $code,
                'subject' => $name,
                'days' => $days,
                'start_time' => $start,
                'end_time' => $end,
                'time' => $start . ' - ' . $end,
                'room' => $room,
            ];
        }

        ajax_ok([
            'message' => 'Class schedule saved successfully!',
            'schedules' => $saved
        ]);
    }

    if ($action === 'get_student_schedule') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Not authenticated.', 401);
        }
        $user_id = (int)$_SESSION['user'];
        $stmt_s = $conn->prepare("SELECT id FROM seminarians WHERE user_id = ? LIMIT 1");
        $stmt_s->bind_param('i', $user_id);
        $stmt_s->execute();
        $sem = $stmt_s->get_result()->fetch_assoc();
        $seminarian_id = (int)($sem['id'] ?? 0);

        $schedules = [];
        if ($seminarian_id > 0) {
            $stmt_get = $conn->prepare("SELECT subject_code, subject_name, days, start_time, end_time, room
                FROM student_schedules
                WHERE seminarian_id = ?
                ORDER BY start_time ASC");
            $stmt_get->bind_param('i', $seminarian_id);
            $stmt_get->execute();
            $res = $stmt_get->get_result();
            while ($r = $res->fetch_assoc()) {
                $schedules[] = [
                    'code' => $r['subject_code'],
                    'subject' => $r['subject_name'],
                    'days' => $r['days'],
                    'start_time' => $r['start_time'],
                    'end_time' => $r['end_time'],
                    'time' => $r['start_time'] . ' - ' . $r['end_time'],
                    'room' => $r['room'],
                ];
            }
        }
        ajax_ok(['schedules' => $schedules]);
    }

    if ($action === 'toggle_report_access') {
        if (!isset($_SESSION['user'])) {
            ajax_error('Authentication required.', 401);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            ajax_error('Method not allowed.', 405);
        }
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!verify_csrf_token($csrf)) {
            ajax_error('CSRF token validation failed.', 403);
        }

        require_once __DIR__ . '/../includes/eval_ui_helpers.php';
        $role = (string)($_SESSION['role'] ?? '');
        $sid = (int)($_POST['seminarian_id'] ?? 0);
        $val = (int)($_POST['new_value'] ?? 0);
        $result = eval_handle_report_access_update($conn, $role, $sid, $val);
        if (empty($result['ok'])) {
            ajax_error((string)($result['error'] ?? 'Failed to update report access.'), (int)($result['status'] ?? 400));
        }
        ajax_ok($result);
    }

    ajax_error('Unknown action.', 404);
} catch (Throwable $e) {
    error_log('EnformaSys ajax error (' . $action . '): ' . $e->getMessage());
    ajax_error('Server error.', 500);
}
