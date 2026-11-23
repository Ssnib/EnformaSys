<?php
if (!function_exists('lodging_generate_invoice_no')) {
    function lodging_generate_invoice_no(mysqli $conn): string
    {
        $prefix = 'INV-' . date('Ymd') . '-';
        $rand = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $no = $prefix . date('His') . $rand;
        $no = substr($no, 0, 30);
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM lodging_invoices WHERE invoice_no = ?");
        $stmt->bind_param('s', $no);
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($exists > 0) {
            return lodging_generate_invoice_no($conn);
        }
        return $no;
    }
}

if (!function_exists('lodging_invoice_total')) {
    function lodging_invoice_total(mysqli $conn, int $invoice_id): float
    {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) as total FROM lodging_invoice_items WHERE invoice_id = ?");
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        return (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('lodging_invoice_allocated')) {
    function lodging_invoice_allocated(mysqli $conn, int $invoice_id): float
    {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) as total FROM lodging_payment_allocations WHERE invoice_id = ?");
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        return (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('lodging_recompute_invoice_status')) {
    function lodging_recompute_invoice_status(mysqli $conn, int $invoice_id): void
    {
        $stmt = $conn->prepare("SELECT status FROM lodging_invoices WHERE id = ?");
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $status = (string)($stmt->get_result()->fetch_assoc()['status'] ?? '');
        if ($status === 'void' || $status === '') {
            return;
        }
        $total = lodging_invoice_total($conn, $invoice_id);
        $paid = lodging_invoice_allocated($conn, $invoice_id);
        if ($paid + 0.00001 >= $total && $total > 0) {
            $new_status = 'paid';
        } elseif ($paid > 0.00001) {
            $new_status = 'partially_paid';
        } else {
            $new_status = 'issued';
        }
        if ($new_status !== $status) {
            $stmt_upd = $conn->prepare("UPDATE lodging_invoices SET status = ? WHERE id = ?");
            $stmt_upd->bind_param('si', $new_status, $invoice_id);
            $stmt_upd->execute();
        }
    }
}

if (!function_exists('lodging_term_sort_key')) {
    function lodging_term_sort_key(string $ay, string $sem): int
    {
        $start_year = 0;
        if (preg_match('/^(\d{4})/', trim($ay), $m)) {
            $start_year = (int)$m[1];
        }
        $sem_val = (trim($sem) === '2nd') ? 2 : 1;
        return ($start_year * 10) + $sem_val;
    }
}

if (!function_exists('lodging_get_previous_terms_unpaid_invoices')) {
    function lodging_get_previous_terms_unpaid_invoices(mysqli $conn, int $seminarian_id, string $current_ay, string $current_sem): array
    {
        $current_key = lodging_term_sort_key($current_ay, $current_sem);
        $previous_balance = 0.0;
        $invoices = [];

        $stmt = $conn->prepare("SELECT i.id, i.invoice_no, i.academic_year, i.semester, i.status, i.issue_date, i.due_date,
                    (SELECT COALESCE(SUM(amount), 0) FROM lodging_invoice_items WHERE invoice_id = i.id) AS total_due,
                    (SELECT COALESCE(SUM(amount), 0) FROM lodging_payment_allocations WHERE invoice_id = i.id) AS total_paid
                FROM lodging_invoices i
                WHERE i.seminarian_id = ?
                  AND i.archived_at IS NULL
                  AND i.status IN ('issued', 'partially_paid')
                ORDER BY 
                    (CASE WHEN i.academic_year IS NOT NULL AND i.academic_year <> '' THEN SUBSTRING(i.academic_year, 1, 4) ELSE '1900' END) ASC,
                    (CASE WHEN i.semester = '1st' THEN 1 WHEN i.semester = '2nd' THEN 2 ELSE 1 END) ASC,
                    i.id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $seminarian_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $i_ay = (string)($r['academic_year'] ?? '');
                $i_sem = (string)($r['semester'] ?? '');
                $i_key = lodging_term_sort_key($i_ay, $i_sem);

                // Strictly prior terms only
                if ($i_key > 0 && $current_key > 0 && $i_key >= $current_key) {
                    continue;
                }

                $tot = (float)$r['total_due'];
                $paid = (float)$r['total_paid'];
                $unpaid = max(0.0, round($tot - $paid, 2));

                if ($unpaid > 0.005) {
                    $previous_balance = round($previous_balance + $unpaid, 2);
                    $invoices[] = [
                        'id' => (int)$r['id'],
                        'invoice_no' => (string)$r['invoice_no'],
                        'academic_year' => $i_ay,
                        'semester' => $i_sem,
                        'total_due' => $tot,
                        'total_paid' => $paid,
                        'unpaid_balance' => $unpaid,
                        'issue_date' => (string)$r['issue_date'],
                    ];
                }
            }
        }

        return [
            'previous_balance' => $previous_balance,
            'invoices' => $invoices,
        ];
    }
}

if (!function_exists('lodging_allocate_payment')) {
    function lodging_allocate_payment(mysqli $conn, int $payment_id, int $seminarian_id, float $amount): void
    {
        $remaining = max(0.0, $amount);
        if ($remaining <= 0) {
            return;
        }

        // Strict FIFO: Query all active, unpaid invoices of this seminarian in chronological order
        // Prior academic years and semesters first, then earlier due/issue date and id
        $sql = "SELECT i.id,
                    (SELECT COALESCE(SUM(amount),0) FROM lodging_invoice_items WHERE invoice_id = i.id) AS total,
                    (SELECT COALESCE(SUM(amount),0) FROM lodging_payment_allocations WHERE invoice_id = i.id) AS paid
                FROM lodging_invoices i
                WHERE i.seminarian_id = ?
                  AND i.archived_at IS NULL
                  AND i.status IN ('issued', 'partially_paid')
                ORDER BY 
                    (CASE WHEN i.academic_year IS NOT NULL AND i.academic_year <> '' THEN SUBSTRING(i.academic_year, 1, 4) ELSE '1900' END) ASC,
                    (CASE WHEN i.semester = '1st' THEN 1 WHEN i.semester = '2nd' THEN 2 ELSE 1 END) ASC,
                    COALESCE(i.due_date, i.issue_date) ASC,
                    i.id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $seminarian_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ($remaining <= 0.0001) {
                break;
            }
            $invoice_id = (int)$row['id'];
            $total = (float)$row['total'];
            $paid = (float)$row['paid'];
            $due = max(0.0, round($total - $paid, 2));
            if ($due <= 0.00001) {
                lodging_recompute_invoice_status($conn, $invoice_id);
                continue;
            }
            $alloc = min($remaining, $due);
            $alloc = round($alloc, 2);
            if ($alloc <= 0) {
                continue;
            }
            $stmt_ins = $conn->prepare("INSERT INTO lodging_payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
            $stmt_ins->bind_param('iid', $payment_id, $invoice_id, $alloc);
            $stmt_ins->execute();
            $remaining = max(0.0, round($remaining - $alloc, 2));
            lodging_recompute_invoice_status($conn, $invoice_id);
        }
    }
}

if (!function_exists('lodging_ensure_student_invoice')) {
    function lodging_ensure_student_invoice(mysqli $conn, int $seminarian_id, string $academic_year, string $semester): int
    {
        $stmt = $conn->prepare("SELECT id FROM lodging_invoices WHERE seminarian_id = ? AND (academic_year = ? OR academic_year IS NULL OR academic_year = '') AND (semester = ? OR semester IS NULL OR semester = '') AND archived_at IS NULL AND status <> 'void' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iss', $seminarian_id, $academic_year, $semester);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && (int)$row['id'] > 0) {
                return (int)$row['id'];
            }
        }

        $prog = '';
        $yl = '';
        $stmt_st = $conn->prepare("SELECT program, year_level FROM seminarians WHERE id = ? LIMIT 1");
        if ($stmt_st) {
            $stmt_st->bind_param('i', $seminarian_id);
            $stmt_st->execute();
            $r_st = $stmt_st->get_result()->fetch_assoc();
            if ($r_st) {
                $prog = (string)($r_st['program'] ?? '');
                $yl = (string)($r_st['year_level'] ?? '');
            }
        }

        $sched_id = 0;
        $stmt_sc = $conn->prepare("SELECT id FROM cashier_fee_schedules WHERE (academic_year = ? OR academic_year = '') AND (semester = ? OR semester = '') AND is_active = 1 AND archived_at IS NULL ORDER BY (CASE WHEN program = ? THEN 1 WHEN program IS NULL OR program = '' THEN 2 ELSE 3 END), id DESC LIMIT 1");
        if ($stmt_sc) {
            $stmt_sc->bind_param('sss', $academic_year, $semester, $prog);
            $stmt_sc->execute();
            $r_sc = $stmt_sc->get_result()->fetch_assoc();
            if ($r_sc && (int)$r_sc['id'] > 0) {
                $sched_id = (int)$r_sc['id'];
            }
        }

        $inv_no = lodging_generate_invoice_no($conn);
        $issue_date = date('Y-m-d');
        $created_by = (int)($_SESSION['user'] ?? 1);
        $stmt_inv = $conn->prepare("INSERT INTO lodging_invoices (seminarian_id, invoice_no, issue_date, academic_year, semester, year_level, program, invoice_type, fee_schedule_id, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'semester_fees', ?, 'issued', 'Semester Fees from Schedule', ?)");
        if ($stmt_inv) {
            $stmt_inv->bind_param('issssssii', $seminarian_id, $inv_no, $issue_date, $academic_year, $semester, $yl, $prog, $sched_id, $created_by);
            $stmt_inv->execute();
            $inv_id = (int)$conn->insert_id;
            if ($inv_id > 0) {
                if ($sched_id > 0) {
                    $stmt_items = $conn->prepare("SELECT description, amount FROM cashier_fee_items WHERE schedule_id = ? ORDER BY sort_order ASC, id ASC");
                    $stmt_items->bind_param('i', $sched_id);
                    $stmt_items->execute();
                    $res_items = $stmt_items->get_result();
                    $stmt_ins_it = $conn->prepare("INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, 1, ?, ?)");
                    while ($res_items && ($it = $res_items->fetch_assoc())) {
                        $amt = (float)$it['amount'];
                        $stmt_ins_it->bind_param('isdd', $inv_id, $it['description'], $amt, $amt);
                        $stmt_ins_it->execute();
                    }
                } else {
                    $def_fees = [
                        ['Board and Lodging Fee', 20000.00],
                        ['Bus Fee', 1500.00],
                        ['Computers Fee', 1000.00],
                        ['Miscellaneous Fee', 500.00],
                        ['Rice Fee', 0.00],
                    ];
                    $stmt_ins_it = $conn->prepare("INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, 1, ?, ?)");
                    foreach ($def_fees as $df) {
                        $amt = (float)$df[1];
                        $stmt_ins_it->bind_param('isdd', $inv_id, $df[0], $amt, $amt);
                        $stmt_ins_it->execute();
                    }
                }
                return $inv_id;
            }
        }
        return 0;
    }
}

if (!function_exists('lodging_get_seminarian_balance')) {
    function lodging_get_seminarian_balance(mysqli $conn, int $seminarian_id, string $academic_year = '', string $semester = ''): array
    {
        $current_term_due = 0.0;
        $previous_balance = 0.0;
        $total_paid = 0.0;

        $is_specific_term = ($academic_year !== '' && $academic_year !== 'all' && $semester !== '' && $semester !== 'all');

        $sql_due = "SELECT COALESCE(SUM(it.amount), 0) as total_due
                    FROM lodging_invoices i
                    JOIN lodging_invoice_items it ON it.invoice_id = i.id
                    WHERE i.seminarian_id = ?
                      AND i.archived_at IS NULL
                      AND i.status IN ('issued', 'partially_paid', 'paid')";
        $types = 'i';
        $params = [$seminarian_id];
        if ($academic_year !== '' && $academic_year !== 'all') {
            $sql_due .= " AND (i.academic_year = ? OR i.academic_year IS NULL OR i.academic_year = '')";
            $types .= 's';
            $params[] = $academic_year;
        }
        if ($semester !== '' && $semester !== 'all') {
            $sql_due .= " AND (i.semester = ? OR i.semester IS NULL OR i.semester = '')";
            $types .= 's';
            $params[] = $semester;
        }

        $stmt_due = $conn->prepare($sql_due);
        if ($stmt_due) {
            $bind = [&$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt_due, 'bind_param'], $bind);
            $stmt_due->execute();
            $current_term_due = (float)($stmt_due->get_result()->fetch_assoc()['total_due'] ?? 0);
        }

        if ($current_term_due <= 0.0001 && $is_specific_term) {
            $stmt_sched = $conn->prepare("SELECT COALESCE(SUM(fi.amount), 0) as sched_tot FROM cashier_fee_schedules fs JOIN cashier_fee_items fi ON fi.schedule_id = fs.id WHERE (fs.academic_year = ? OR fs.academic_year = '') AND (fs.semester = ? OR fs.semester = '') AND fs.is_active = 1 AND fs.archived_at IS NULL");
            if ($stmt_sched) {
                $stmt_sched->bind_param('ss', $academic_year, $semester);
                $stmt_sched->execute();
                $current_term_due = (float)($stmt_sched->get_result()->fetch_assoc()['sched_tot'] ?? 0);
            }
            if ($current_term_due <= 0.0001) {
                $current_term_due = 23000.00;
            }
        }

        // Query unpaid debt from previous terms (Balance Carryover)
        if ($is_specific_term) {
            $prev_info = lodging_get_previous_terms_unpaid_invoices($conn, $seminarian_id, $academic_year, $semester);
            $unpaid_prior = (float)($prev_info['previous_balance'] ?? 0);

            // Add any payments credited in THIS term that were already allocated to prior invoices via FIFO
            $paid_towards_prior = 0.0;
            $curr_sort = lodging_term_sort_key($academic_year, $semester);
            $stmt_alloc_details = $conn->prepare(
                "SELECT pa.amount, i.academic_year as inv_ay, i.semester as inv_sem
                 FROM lodging_payment_allocations pa
                 JOIN lodging_payments p ON p.id = pa.payment_id
                 JOIN lodging_invoices i ON i.id = pa.invoice_id
                 WHERE p.seminarian_id = ?
                   AND (p.academic_year = ? OR p.academic_year IS NULL OR p.academic_year = '')
                   AND (p.semester = ? OR p.semester IS NULL OR p.semester = '')"
            );
            if ($stmt_alloc_details) {
                $stmt_alloc_details->bind_param('iss', $seminarian_id, $academic_year, $semester);
                $stmt_alloc_details->execute();
                $res_ad = $stmt_alloc_details->get_result();
                while ($rad = $res_ad->fetch_assoc()) {
                    $i_key = lodging_term_sort_key((string)$rad['inv_ay'], (string)$rad['inv_sem']);
                    if ($i_key > 0 && $curr_sort > 0 && $i_key < $curr_sort) {
                        $paid_towards_prior += (float)$rad['amount'];
                    }
                }
            }
            $previous_balance = round($unpaid_prior + $paid_towards_prior, 2);
        }

        // Total Amount Due = (Current Semester Fees) + (Previous Outstanding Balance)
        $total_due = round($current_term_due + $previous_balance, 2);

        $sql_paid = "SELECT COALESCE(SUM(p.amount), 0) as total_paid
                     FROM lodging_payments p
                     WHERE p.seminarian_id = ?";
        $types_p = 'i';
        $params_p = [$seminarian_id];
        if ($academic_year !== '' && $academic_year !== 'all') {
            $sql_paid .= " AND (p.academic_year = ? OR p.academic_year IS NULL OR p.academic_year = '')";
            $types_p .= 's';
            $params_p[] = $academic_year;
        }
        if ($semester !== '' && $semester !== 'all') {
            $sql_paid .= " AND (p.semester = ? OR p.semester IS NULL OR p.semester = '')";
            $types_p .= 's';
            $params_p[] = $semester;
        }

        $stmt_paid = $conn->prepare($sql_paid);
        if ($stmt_paid) {
            $bind_p = [&$types_p];
            foreach ($params_p as $k => $v) {
                $bind_p[] = &$params_p[$k];
            }
            call_user_func_array([$stmt_paid, 'bind_param'], $bind_p);
            $stmt_paid->execute();
            $total_paid = (float)($stmt_paid->get_result()->fetch_assoc()['total_paid'] ?? 0);
        }

        $rem_balance = max(0.0, round($total_due - $total_paid, 2));

        return [
            'current_term_due' => round($current_term_due, 2),
            'previous_balance' => round($previous_balance, 2),
            'total_due' => $total_due,
            'total_paid' => round($total_paid, 2),
            'remaining_balance' => $rem_balance,
        ];
    }
}

if (!function_exists('lodging_get_student_fee_breakdown')) {
    function lodging_get_student_fee_breakdown(mysqli $conn, int $seminarian_id, string $academic_year = '', string $semester = ''): array
    {
        lodging_ensure_student_invoice($conn, $seminarian_id, $academic_year, $semester);
        $bal_info = lodging_get_seminarian_balance($conn, $seminarian_id, $academic_year, $semester);
        $grand_remaining_balance = (float)$bal_info['remaining_balance'];
        $previous_balance = (float)$bal_info['previous_balance'];
        $current_term_due = (float)$bal_info['current_term_due'];
        $grand_total_due = (float)$bal_info['total_due'];
        $current_term_paid = (float)$bal_info['total_paid'];

        // FIFO: Current term payments deduct from previous balance first
        $prev_paid_from_term = min($previous_balance, $current_term_paid);
        $prev_remaining = max(0.0, round($previous_balance - $prev_paid_from_term, 2));
        $excess_term_payment = max(0.0, round($current_term_paid - $previous_balance, 2));
        $current_term_balance_due = max(0.0, round($current_term_due - $excess_term_payment, 2));

        // 1. Fetch current term invoice items
        $invoice_items = [];
        $stmt = $conn->prepare("SELECT it.id, it.description, it.amount FROM lodging_invoices i JOIN lodging_invoice_items it ON it.invoice_id = i.id WHERE i.seminarian_id = ? AND i.archived_at IS NULL AND i.status IN ('issued','partially_paid','paid') AND (i.academic_year = ? OR i.academic_year IS NULL OR i.academic_year = '') AND (i.semester = ? OR i.semester IS NULL OR i.semester = '') ORDER BY it.id ASC");
        if ($stmt) {
            $stmt->bind_param('iss', $seminarian_id, $academic_year, $semester);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $invoice_items[] = [
                    'id' => (int)$r['id'],
                    'description' => (string)$r['description'],
                    'amount' => (float)$r['amount'],
                ];
            }
        }

        // Fallback to schedule or default
        if (empty($invoice_items)) {
            $prog = '';
            $yl = '';
            $st_res = $conn->query("SELECT program, year_level FROM seminarians WHERE id = {$seminarian_id} LIMIT 1");
            if ($st_res && ($sr = $st_res->fetch_assoc())) {
                $prog = (string)($sr['program'] ?? '');
                $yl = (string)($sr['year_level'] ?? '');
            }
            $stmt_sc = $conn->prepare("SELECT fi.description, fi.amount FROM cashier_fee_schedules fs JOIN cashier_fee_items fi ON fi.schedule_id = fs.id WHERE (fs.academic_year = ? OR fs.academic_year = '') AND (fs.semester = ? OR fs.semester = '') AND (fs.program = ? OR fs.program IS NULL OR fs.program = '') AND fs.is_active = 1 AND fs.archived_at IS NULL ORDER BY fi.sort_order ASC, fi.id ASC");
            if ($stmt_sc) {
                $stmt_sc->bind_param('sss', $academic_year, $semester, $prog);
                $stmt_sc->execute();
                $r_sc = $stmt_sc->get_result();
                while ($r_sc && ($ri = $r_sc->fetch_assoc())) {
                    $invoice_items[] = [
                        'id' => 0,
                        'description' => (string)$ri['description'],
                        'amount' => (float)$ri['amount'],
                    ];
                }
            }
            if (empty($invoice_items)) {
                $rice_txt = ($semester === '2nd') ? 'Rice Fee (2 sacks)' : 'Rice Fee (1 sack)';
                $invoice_items = [
                    ['id' => 1, 'description' => 'Board and Lodging Fee', 'amount' => 20000.00],
                    ['id' => 2, 'description' => 'Bus Fee', 'amount' => 1500.00],
                    ['id' => 3, 'description' => 'Computers Fee', 'amount' => 1000.00],
                    ['id' => 4, 'description' => 'Miscellaneous Fee', 'amount' => 500.00],
                    ['id' => 5, 'description' => $rice_txt, 'amount' => 0.00],
                ];
            }
        }

        // 2. Query payments made by particular for this student and term
        $stmt_pay = $conn->prepare("SELECT pi.particular, SUM(pi.amount) as paid_amt FROM lodging_payments p JOIN cashier_payment_items pi ON pi.payment_id = p.id WHERE p.seminarian_id = ? AND (p.academic_year = ? OR p.academic_year IS NULL OR p.academic_year = '') AND (p.semester = ? OR p.semester IS NULL OR p.semester = '') GROUP BY pi.particular");
        $paid_particulars = [];
        if ($stmt_pay) {
            $stmt_pay->bind_param('iss', $seminarian_id, $academic_year, $semester);
            $stmt_pay->execute();
            $res_pay = $stmt_pay->get_result();
            while ($pr = $res_pay->fetch_assoc()) {
                $paid_particulars[trim((string)$pr['particular'])] = (float)$pr['paid_amt'];
            }
        }

        $norm_key = function(string $s): string {
            $s = strtolower(trim($s));
            if (strpos($s, 'board') !== false || strpos($s, 'lodg') !== false) return 'board_lodging';
            if (strpos($s, 'bus') !== false || strpos($s, 'transport') !== false) return 'bus';
            if (strpos($s, 'comput') !== false) return 'computers';
            if (strpos($s, 'misc') !== false) return 'miscellaneous';
            if (strpos($s, 'rice') !== false) return 'rice';
            return preg_replace('/[^a-z0-9]/', '', $s);
        };

        $paid_by_norm = [];
        foreach ($paid_particulars as $part_name => $part_amt) {
            $nk = $norm_key($part_name);
            if ($nk !== '') {
                $paid_by_norm[$nk] = ($paid_by_norm[$nk] ?? 0.0) + $part_amt;
            }
        }

        // Match each item and calculate item remaining balance
        $fees = [];
        foreach ($invoice_items as $it) {
            $desc = $it['description'];
            $orig = (float)$it['amount'];
            $nk = $norm_key($desc);
            $paid_for_item = 0.0;

            if (isset($paid_particulars[$desc])) {
                $paid_for_item = $paid_particulars[$desc];
            } elseif (isset($paid_by_norm[$nk])) {
                $paid_for_item = $paid_by_norm[$nk];
            }

            $paid_for_item = min($orig, $paid_for_item);
            $rem = max(0.0, round($orig - $paid_for_item, 2));

            $fees[] = [
                'particular' => $desc,
                'original_amount' => $orig,
                'amount_paid' => $paid_for_item,
                'item_remaining_balance' => $rem,
                'amount' => $rem,
                'is_readonly' => ($rem <= 0.0001),
                'is_previous_balance' => false,
            ];
        }

        // Reconcile with current_term_balance_due to ensure exact match: sum(item_remaining_balance) === current_term_balance_due
        $sum_rem = 0.0;
        foreach ($fees as $f) {
            $sum_rem += (float)$f['item_remaining_balance'];
        }

        $diff = round($sum_rem - $current_term_balance_due, 2);
        if (abs($diff) > 0.005) {
            if ($diff > 0) {
                $to_deduct = $diff;
                for ($i = 0; $i < count($fees); $i++) {
                    if ($to_deduct <= 0.0001) break;
                    if ($fees[$i]['item_remaining_balance'] > 0) {
                        $ded = min($fees[$i]['item_remaining_balance'], $to_deduct);
                        $fees[$i]['item_remaining_balance'] = round($fees[$i]['item_remaining_balance'] - $ded, 2);
                        $fees[$i]['amount_paid'] = round($fees[$i]['amount_paid'] + $ded, 2);
                        $fees[$i]['amount'] = $fees[$i]['item_remaining_balance'];
                        $fees[$i]['is_readonly'] = ($fees[$i]['item_remaining_balance'] <= 0.0001);
                        $to_deduct -= $ded;
                    }
                }
            } else {
                $to_add = abs($diff);
                for ($i = 0; $i < count($fees); $i++) {
                    if ($fees[$i]['original_amount'] > $fees[$i]['item_remaining_balance']) {
                        $room = round($fees[$i]['original_amount'] - $fees[$i]['item_remaining_balance'], 2);
                        $add = min($room, $to_add);
                        $fees[$i]['item_remaining_balance'] = round($fees[$i]['item_remaining_balance'] + $add, 2);
                        $fees[$i]['amount_paid'] = round($fees[$i]['amount_paid'] - $add, 2);
                        $fees[$i]['amount'] = $fees[$i]['item_remaining_balance'];
                        $fees[$i]['is_readonly'] = ($fees[$i]['item_remaining_balance'] <= 0.0001);
                        $to_add -= $add;
                        if ($to_add <= 0.0001) break;
                    }
                }
            }
        }

        // If Previous Balance exists (> 0), prepend it as the FIRST line item for FIFO priority and transparency
        if ($previous_balance > 0.0001) {
            $prev_item = [
                'particular' => 'Previous Balance',
                'original_amount' => $previous_balance,
                'amount_paid' => $prev_paid_from_term,
                'item_remaining_balance' => $prev_remaining,
                'amount' => $prev_remaining,
                'is_readonly' => ($prev_remaining <= 0.0001),
                'is_previous_balance' => true,
            ];
            array_unshift($fees, $prev_item);
        }

        return [
            'fees' => $fees,
            'previous_balance' => $previous_balance,
            'previous_balance_remaining' => $prev_remaining,
            'current_term_due' => $current_term_due,
            'total_due' => $grand_total_due,
            'total_paid' => $current_term_paid,
            'remaining_balance' => $grand_remaining_balance,
        ];
    }
}

if (!function_exists('cashier_allocate_payment_particulars')) {
    /**
     * Resolve exact itemized fee particulars and amounts for a payment, prioritizing
     * explicit item amounts, then checked fee items, and finally FIFO unpaid invoice
     * particulars so receipts always reflect actual paid fees rather than generic adjustments.
     *
     * @param array<int, array{particular: string, amount: float}> $submitted_items
     * @return array<int, array{particular: string, amount: float}>
     */
    function cashier_allocate_payment_particulars(
        mysqli $conn,
        int $seminarian_id,
        string $academic_year,
        string $semester,
        float $selected_amount,
        array $submitted_items
    ): array {
        $target = round($selected_amount, 2);
        if ($target <= 0.0001) {
            return [];
        }

        $clean_label = function (string $raw): string {
            return trim((string)preg_replace('/\s*\([^)]*\)/', '', $raw));
        };

        $norm_key = function (string $s): string {
            $s = strtolower(trim($s));
            if (strpos($s, 'prev') !== false || strpos($s, 'prior') !== false) return 'previous_balance';
            if (strpos($s, 'board') !== false || strpos($s, 'lodg') !== false) return 'board_lodging';
            if (strpos($s, 'bus') !== false || strpos($s, 'transport') !== false) return 'bus';
            if (strpos($s, 'comput') !== false) return 'computers';
            if (strpos($s, 'misc') !== false) return 'miscellaneous';
            if (strpos($s, 'rice') !== false) return 'rice';
            return preg_replace('/[^a-z0-9]/', '', $s);
        };

        // Order helper: Previous Balance strictly first for FIFO
        $sort_prev_first = function (array &$arr): void {
            usort($arr, function ($a, $b) {
                $ap = (stripos((string)($a['particular'] ?? ''), 'previous balance') !== false || stripos((string)($a['particular'] ?? ''), 'prior balance') !== false);
                $bp = (stripos((string)($b['particular'] ?? ''), 'previous balance') !== false || stripos((string)($b['particular'] ?? ''), 'prior balance') !== false);
                if ($ap && !$bp) return -1;
                if (!$ap && $bp) return 1;
                return 0;
            });
        };

        $breakdown = lodging_get_student_fee_breakdown($conn, $seminarian_id, $academic_year, $semester);
        $fees = $breakdown['fees'] ?? [];
        $fee_caps = [];
        $fee_order = [];
        foreach ($fees as $f) {
            $lbl = $clean_label((string)($f['particular'] ?? ''));
            $rem_cap = round((float)($f['item_remaining_balance'] ?? $f['amount'] ?? 0), 2);
            if ($lbl !== '' && $rem_cap > 0.0001) {
                $nk = $norm_key($lbl);
                if (!isset($fee_caps[$nk])) {
                    $fee_caps[$nk] = ['particular' => $lbl, 'remaining' => 0.0];
                    $fee_order[] = $nk;
                }
                $fee_caps[$nk]['remaining'] = round($fee_caps[$nk]['remaining'] + $rem_cap, 2);
            }
        }

        $positive_submitted = [];
        $checked_zero_submitted = [];
        $explicit_sum = 0.0;
        foreach ($submitted_items as $item) {
            $lbl = $clean_label((string)($item['particular'] ?? ''));
            $amt = round((float)($item['amount'] ?? 0), 2);
            if ($lbl === '') continue;
            if ($amt > 0.0001) {
                $positive_submitted[] = ['particular' => $lbl, 'amount' => $amt];
                $explicit_sum = round($explicit_sum + $amt, 2);
            } else {
                $checked_zero_submitted[] = $lbl;
            }
        }

        // Case 1: Explicit submitted items match total exact amount
        if (count($positive_submitted) > 0 && abs($explicit_sum - $target) <= 0.01) {
            $sort_prev_first($positive_submitted);
            return $positive_submitted;
        }

        // Case 2: Explicit submitted items sum exceeds target -> allocate FIFO across selected items
        if (count($positive_submitted) > 0 && $explicit_sum > ($target + 0.01)) {
            $sort_prev_first($positive_submitted);
            $out = [];
            $rem = $target;
            foreach ($positive_submitted as $ps) {
                if ($rem <= 0.0001) break;
                $take = round(min($rem, $ps['amount']), 2);
                if ($take > 0.0001) {
                    $out[] = ['particular' => $ps['particular'], 'amount' => $take];
                    $rem = round($rem - $take, 2);
                }
            }
            return $out;
        }

        // Case 3: Partial / blank item amounts OR no checkboxes selected -> honor explicit amounts,
        // then distribute remainder to checked blank items, then FIFO unpaid breakdown items.
        $allocated = [];
        $add_alloc = function (string $label, float $amt) use (&$allocated): void {
            if ($amt <= 0.0001) return;
            foreach ($allocated as &$existing) {
                if (strcasecmp($existing['particular'], $label) === 0) {
                    $existing['amount'] = round($existing['amount'] + $amt, 2);
                    return;
                }
            }
            unset($existing);
            $allocated[] = ['particular' => $label, 'amount' => round($amt, 2)];
        };

        $rem = $target;
        $sort_prev_first($positive_submitted);
        foreach ($positive_submitted as $ps) {
            $take = round(min($rem, $ps['amount']), 2);
            if ($take > 0.0001) {
                $add_alloc($ps['particular'], $take);
                $rem = round($rem - $take, 2);
                $nk = $norm_key($ps['particular']);
                if (isset($fee_caps[$nk])) {
                    $fee_caps[$nk]['remaining'] = max(0.0, round($fee_caps[$nk]['remaining'] - $take, 2));
                }
            }
        }

        // Allocate remaining to checked checkboxes whose amount inputs were blank/0
        if ($rem > 0.0001 && count($checked_zero_submitted) > 0) {
            // Put Previous Balance first if present in checked blank boxes
            usort($checked_zero_submitted, function ($a, $b) {
                $ap = (stripos($a, 'previous balance') !== false || stripos($a, 'prior balance') !== false);
                $bp = (stripos($b, 'previous balance') !== false || stripos($b, 'prior balance') !== false);
                return $ap === $bp ? 0 : ($ap ? -1 : 1);
            });
            $non_zero_cap_count = 0;
            foreach ($checked_zero_submitted as $lbl) {
                $nk = $norm_key($lbl);
                if (!isset($fee_caps[$nk]) || $fee_caps[$nk]['remaining'] > 0.0001) {
                    $non_zero_cap_count++;
                }
            }
            foreach ($checked_zero_submitted as $lbl) {
                if ($rem <= 0.0001) break;
                $nk = $norm_key($lbl);
                if (isset($fee_caps[$nk])) {
                    $cap = $fee_caps[$nk]['remaining'];
                    if ($cap <= 0.0001) continue;
                    $take = round(min($rem, $cap), 2);
                    $fee_caps[$nk]['remaining'] = round($cap - $take, 2);
                } elseif (stripos($lbl, 'rice') !== false) {
                    continue;
                } else {
                    $take = $rem;
                }
                if ($take > 0.0001) {
                    $add_alloc($lbl, $take);
                    $rem = round($rem - $take, 2);
                }
            }
        }

        // Distribute any still-unallocated remainder across actual unpaid invoice fees (FIFO)
        if ($rem > 0.0001) {
            foreach ($fee_order as $nk) {
                if ($rem <= 0.0001) break;
                $cap = (float)($fee_caps[$nk]['remaining'] ?? 0);
                if ($cap <= 0.0001) continue;
                $take = round(min($rem, $cap), 2);
                if ($take > 0.0001) {
                    $add_alloc($fee_caps[$nk]['particular'], $take);
                    $fee_caps[$nk]['remaining'] = round($cap - $take, 2);
                    $rem = round($rem - $take, 2);
                }
            }
        }

        // Final fallback only if student has zero remaining itemized breakdown items
        if ($rem > 0.005) {
            $add_alloc('General Assessment Payment', $rem);
        }

        $sort_prev_first($allocated);
        return $allocated;
    }
}

if (!function_exists('cashier_sync_enrollment_after_payment')) {
    /**
     * Recompute cumulative term payments in `lodging_payments` and automatically promote
     * an active enrollment / seminarian from 'Pending' to 'Enrolled' once cumulative
     * payment for that Academic Year & Semester reaches >= PHP 500.00.
     *
     * @return array{term_paid: float, upgraded_to_enrolled: bool, enrollment_status: string}
     */
    function cashier_sync_enrollment_after_payment(mysqli $conn, int $seminarian_id, string $academic_year, string $semester): array
    {
        if ($seminarian_id <= 0 || trim($academic_year) === '' || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
            return ['term_paid' => 0.0, 'upgraded_to_enrolled' => false, 'enrollment_status' => ''];
        }

        $stmt_sum = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS term_paid FROM lodging_payments WHERE seminarian_id = ? AND academic_year = ? AND semester = ?"
        );
        $stmt_sum->bind_param('iss', $seminarian_id, $academic_year, $semester);
        $stmt_sum->execute();
        $term_paid = round((float)($stmt_sum->get_result()->fetch_assoc()['term_paid'] ?? 0.0), 2);

        $upgraded_to_enrolled = false;
        $resolved_status = '';

        $stmt_enr = $conn->prepare(
            "SELECT id, enrollment_status, total_assessed_fee FROM enrollments WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1"
        );
        $stmt_enr->bind_param('iss', $seminarian_id, $academic_year, $semester);
        $stmt_enr->execute();
        $enr = $stmt_enr->get_result()->fetch_assoc();

        if ($enr) {
            $cur_status = trim((string)($enr['enrollment_status'] ?? 'Pending'));
            $new_status = $cur_status;
            if (strcasecmp($cur_status, 'Pending') === 0 && $term_paid >= 500.0) {
                $new_status = 'Enrolled';
                $upgraded_to_enrolled = true;
            }
            $resolved_status = $new_status;

            $assessed = (float)($enr['total_assessed_fee'] ?? 0.0);
            if ($assessed <= 0.0 && function_exists('lodging_get_seminarian_balance')) {
                $bal_info = lodging_get_seminarian_balance($conn, $seminarian_id, $academic_year, $semester);
                $assessed = (float)($bal_info['current_term_due'] ?? 0.0);
            }
            $new_bal = max(0.0, round($assessed - $term_paid, 2));
            $enr_id = (int)$enr['id'];

            $stmt_upd_enr = $conn->prepare(
                "UPDATE enrollments SET enrollment_status = ?, total_assessed_fee = ?, total_paid = ?, fee_balance = ? WHERE id = ?"
            );
            $stmt_upd_enr->bind_param('sdddi', $new_status, $assessed, $term_paid, $new_bal, $enr_id);
            $stmt_upd_enr->execute();
        }

        // Sync `seminarians` pointer row when status is Pending and threshold is met
        $stmt_sem = $conn->prepare("SELECT enrollment_status, current_academic_year FROM seminarians WHERE id = ? LIMIT 1");
        $stmt_sem->bind_param('i', $seminarian_id);
        $stmt_sem->execute();
        $sem = $stmt_sem->get_result()->fetch_assoc();
        if ($sem) {
            $sem_status = trim((string)($sem['enrollment_status'] ?? ''));
            if (strcasecmp($sem_status, 'Pending') === 0 && $term_paid >= 500.0) {
                $stmt_upd_sem = $conn->prepare("UPDATE seminarians SET enrollment_status = 'Enrolled' WHERE id = ?");
                $stmt_upd_sem->bind_param('i', $seminarian_id);
                $stmt_upd_sem->execute();
                $upgraded_to_enrolled = true;
                if ($resolved_status === '') {
                    $resolved_status = 'Enrolled';
                }
            } elseif ($resolved_status === '') {
                $resolved_status = $sem_status;
            }
        }

        return [
            'term_paid' => $term_paid,
            'upgraded_to_enrolled' => $upgraded_to_enrolled,
            'enrollment_status' => $resolved_status,
        ];
    }
}
?>
