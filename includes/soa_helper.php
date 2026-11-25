<?php
/**
 * Shared Official Statement of Account (SOA) Data Loader & Document Renderer.
 * Used by both Cashier Portal (cashier_soa.php) and Student Portal (student_soa.php).
 */

if (!function_exists('soa_sanitize_particular_label')) {
    /**
     * Strip parenthetical amounts or trailing ": 0.00" suffixes from a fee label.
     */
    function soa_sanitize_particular_label(string $label): string {
        $clean = trim((string)preg_replace('/\s*\([^)]*\)/', '', $label));
        $clean = trim((string)preg_replace('/:\s*[0-9,]+(?:\.[0-9]+)?$/', '', $clean));
        return $clean;
    }
}

if (!function_exists('soa_format_payment_particulars')) {
    /**
     * Formats paid fee particulars for an official receipt / payment credit row.
     * Returns only clean comma-separated fee names (without peso amounts),
     * falling back to "General Assessment" when no itemized fee is mapped.
     */
    function soa_format_payment_particulars(array $payment_row, array $mapped_items): string {
        $labels = [];
        foreach ($mapped_items as $item) {
            $amt = (float)($item['amount'] ?? 0);
            if ($amt <= 0.0001) {
                continue;
            }
            $clean = soa_sanitize_particular_label((string)($item['particular'] ?? ''));
            if ($clean !== '' && !in_array($clean, $labels, true)) {
                $labels[] = $clean;
            }
        }

        if (count($labels) > 0) {
            return implode(', ', $labels);
        }

        // Fallback: extract non-zero fee names from lodging_payments.notes if present
        $notes = trim((string)($payment_row['notes'] ?? ''));
        if ($notes !== '') {
            if (strpos($notes, ';') !== false) {
                $segments = explode(';', $notes);
            } else {
                $notes_stripped = (string)preg_replace('/\s*\([^)]*\)/', '', $notes);
                $segments = explode(',', $notes_stripped);
            }
            foreach ($segments as $seg) {
                $seg = trim($seg);
                if ($seg === '') {
                    continue;
                }
                if (preg_match('/^(.+?):\s*([0-9,]+(?:\.[0-9]+)?)$/', $seg, $m)) {
                    $val = (float)str_replace(',', '', $m[2]);
                    if ($val <= 0.0001) {
                        continue;
                    }
                    $clean = soa_sanitize_particular_label($m[1]);
                } else {
                    $clean = soa_sanitize_particular_label($seg);
                }
                if ($clean !== '' && !in_array($clean, $labels, true)) {
                    $labels[] = $clean;
                }
            }
            if (count($labels) > 0) {
                return implode(', ', $labels);
            }
        }

        $remarks = soa_sanitize_particular_label((string)($payment_row['remarks'] ?? ''));
        return $remarks !== '' ? $remarks : 'General Assessment';
    }
}

if (!function_exists('soa_load_statement_data')) {
    /**
     * Loads complete Official SOA assessment, particulars, payment credits, and balance data.
     *
     * @param mysqli $conn
     * @param array  $student       Must include seminarian_id, full_name, student_no, program, year_level, formation_status
     * @param string $academic_year 'YYYY-YYYY' or 'all'
     * @param string $semester      '1st', '2nd', or 'all'
     * @param array  $opts          ['schedule_id' => int, 'sync_invoice' => bool, 'created_by' => int]
     * @return array
     */
    function soa_load_statement_data(mysqli $conn, array $student, string $academic_year, string $semester, array $opts = []): array {
        $student_id = (int)($student['seminarian_id'] ?? 0);
        $prog_snap = (string)($student['program'] ?? '');
        $yl_snap = (string)($student['year_level'] ?? '');
        $requested_schedule_id = (int)($opts['schedule_id'] ?? 0);
        $sync_invoice = !empty($opts['sync_invoice']);
        $created_by = (int)($opts['created_by'] ?? 1);

        $semester_label = 'First Semester';
        if ($semester === '2nd') {
            $semester_label = 'Second Semester';
        } elseif ($semester === 'all') {
            $semester_label = 'All Semesters / Full Year';
        }

        $rice_sacks = ($semester === '2nd') ? '2 sacks' : '1 sack';
        if ($semester === 'all') {
            $rice_sacks = '3 sacks (1st & 2nd Semesters)';
        }

        // 1. RESOLVE SPECIFIC FEE SCHEDULE ID FOR DYNAMIC PRICING
        $selected_schedule_id = $requested_schedule_id;

        if ($selected_schedule_id <= 0) {
            $stmt_inv_sched = $conn->prepare(
                "SELECT fee_schedule_id FROM lodging_invoices
                 WHERE seminarian_id = ?
                   AND (academic_year = ? OR academic_year IS NULL OR academic_year = '')
                   AND (semester = ? OR semester IS NULL OR semester = '')
                   AND archived_at IS NULL AND status <> 'void'
                   AND fee_schedule_id IS NOT NULL AND fee_schedule_id > 0
                 ORDER BY id DESC LIMIT 1"
            );
            if ($stmt_inv_sched) {
                $stmt_inv_sched->bind_param('iss', $student_id, $academic_year, $semester);
                $stmt_inv_sched->execute();
                $r_inv_sched = $stmt_inv_sched->get_result()->fetch_assoc();
                if ($r_inv_sched && (int)$r_inv_sched['fee_schedule_id'] > 0) {
                    $selected_schedule_id = (int)$r_inv_sched['fee_schedule_id'];
                }
            }
        }

        if ($selected_schedule_id <= 0) {
            $stmt_find_sched = $conn->prepare(
                "SELECT id FROM cashier_fee_schedules
                 WHERE (academic_year = ? OR academic_year = '')
                   AND (semester = ? OR semester = '')
                   AND is_active = 1 AND archived_at IS NULL
                 ORDER BY
                   (CASE WHEN program = ? THEN 1 WHEN program IS NULL OR program = '' THEN 2 ELSE 3 END),
                   id DESC
                 LIMIT 1"
            );
            if ($stmt_find_sched) {
                $stmt_find_sched->bind_param('sss', $academic_year, $semester, $prog_snap);
                $stmt_find_sched->execute();
                $r_found = $stmt_find_sched->get_result()->fetch_assoc();
                if ($r_found && (int)$r_found['id'] > 0) {
                    $selected_schedule_id = (int)$r_found['id'];
                }
            }
        }

        // 2. FETCH DYNAMIC PARTICULARS LINKING INVOICE & FEE SCHEDULE VIA SQL JOIN
        $charges = [];
        $active_schedule_name = '';

        if ($selected_schedule_id > 0) {
            $sql_joined = "SELECT
                fi.id as fee_item_id,
                fi.description as particular_name,
                fi.amount as dynamic_amount,
                fi.sort_order,
                fs.id as schedule_id,
                fs.academic_year as sched_academic_year,
                fs.semester as sched_semester,
                fs.program as sched_program,
                i.id as invoice_id,
                i.invoice_no,
                i.issue_date,
                it.id as invoice_item_id,
                it.quantity,
                it.unit_price,
                it.amount as invoice_item_amount
            FROM cashier_fee_items fi
            JOIN cashier_fee_schedules fs ON fs.id = fi.schedule_id
            LEFT JOIN lodging_invoices i ON i.seminarian_id = ?
                AND (i.fee_schedule_id = fs.id OR (i.academic_year = fs.academic_year AND i.semester = fs.semester))
                AND i.archived_at IS NULL
                AND i.status IN ('issued', 'partially_paid', 'paid')
            LEFT JOIN lodging_invoice_items it ON it.invoice_id = i.id
                AND (LOWER(TRIM(it.description)) = LOWER(TRIM(fi.description)))
            WHERE fs.id = ?
            ORDER BY fi.sort_order ASC, fi.id ASC";

            $stmt_join = $conn->prepare($sql_joined);
            $stmt_join->bind_param('ii', $student_id, $selected_schedule_id);
            $stmt_join->execute();
            $res_join = $stmt_join->get_result();

            $inv_id_found = 0;
            while ($res_join && ($r = $res_join->fetch_assoc())) {
                $p_desc = (string)$r['particular_name'];
                $dynamic_amt = (float)$r['dynamic_amount'];
                if (!empty($r['invoice_id'])) {
                    $inv_id_found = (int)$r['invoice_id'];
                }
                $active_schedule_name = 'Schedule #' . $r['schedule_id'] . ' (' . $r['sched_academic_year'] . ' ' . $r['sched_semester'] . ' Sem)';

                $comp = '';
                $is_commodity = false;
                if (stripos($p_desc, 'Board') !== false) {
                    $monthly = $dynamic_amt > 0 ? ($dynamic_amt / 5.0) : 4000.00;
                    $comp = 'Php ' . number_format($monthly, 2) . ' x 5 mons.';
                } elseif (stripos($p_desc, 'Bus') !== false) {
                    $monthly = $dynamic_amt > 0 ? ($dynamic_amt / 5.0) : 300.00;
                    $comp = 'Php ' . number_format($monthly, 2) . ' x 5 mons.';
                } elseif (stripos($p_desc, 'Comput') !== false) {
                    $monthly = $dynamic_amt > 0 ? ($dynamic_amt / 5.0) : 200.00;
                    $comp = 'Php ' . number_format($monthly, 2) . ' x 5 mons.';
                } elseif (stripos($p_desc, 'Misc') !== false) {
                    $monthly = $dynamic_amt > 0 ? ($dynamic_amt / 5.0) : 100.00;
                    $comp = 'Php ' . number_format($monthly, 2) . ' x 5 mons.';
                } elseif (stripos($p_desc, 'Rice') !== false) {
                    $is_commodity = true;
                    $comp = $rice_sacks;
                } else {
                    $comp = 'Standard Term Fee';
                }

                $amt_display = 'P ' . number_format($dynamic_amt, 2);
                if ($is_commodity && $dynamic_amt == 0.00) {
                    $amt_display = $rice_sacks;
                }

                $charges[] = [
                    'item_id' => (int)$r['fee_item_id'],
                    'description' => $p_desc,
                    'computation' => $comp,
                    'amount' => $dynamic_amt,
                    'amount_display' => $amt_display,
                    'is_commodity' => $is_commodity,
                    'invoice_no' => $r['invoice_no'] ?? '',
                    'issue_date' => $r['issue_date'] ?? date('Y-m-d'),
                ];
            }

            // Only synchronize/create invoices when explicitly allowed (Cashier view)
            if ($sync_invoice && $academic_year !== 'all' && $semester !== 'all' && count($charges) > 0) {
                if ($inv_id_found > 0) {
                    $stmt_sync_inv = $conn->prepare("UPDATE lodging_invoices SET fee_schedule_id = ?, year_level = ?, program = ? WHERE id = ?");
                    if ($stmt_sync_inv) {
                        $stmt_sync_inv->bind_param('issi', $selected_schedule_id, $yl_snap, $prog_snap, $inv_id_found);
                        $stmt_sync_inv->execute();
                    }
                    $stmt_del_it = $conn->prepare("DELETE FROM lodging_invoice_items WHERE invoice_id = ?");
                    if ($stmt_del_it) {
                        $stmt_del_it->bind_param('i', $inv_id_found);
                        $stmt_del_it->execute();
                    }
                    $stmt_ins_it = $conn->prepare("INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt_ins_it) {
                        foreach ($charges as $c_item) {
                            $c_amt = (float)$c_item['amount'];
                            $c_qty = 1;
                            $stmt_ins_it->bind_param('isidd', $inv_id_found, $c_item['description'], $c_qty, $c_amt, $c_amt);
                            $stmt_ins_it->execute();
                        }
                    }
                    if (function_exists('lodging_recompute_invoice_status')) {
                        lodging_recompute_invoice_status($conn, $inv_id_found);
                    }
                } else {
                    $inv_no = function_exists('lodging_generate_invoice_no') ? lodging_generate_invoice_no($conn) : ('INV-' . date('Ymd-His'));
                    $issue_date = date('Y-m-d');
                    $stmt_new_inv = $conn->prepare("INSERT INTO lodging_invoices (seminarian_id, invoice_no, issue_date, due_date, period_start, period_end, academic_year, semester, year_level, program, invoice_type, fee_schedule_id, status, notes, created_by) VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, 'semester_fees', ?, 'issued', 'Semester Fees from Schedule', ?)");
                    if ($stmt_new_inv) {
                        $stmt_new_inv->bind_param('issssssiii', $student_id, $inv_no, $issue_date, $academic_year, $semester, $yl_snap, $prog_snap, $selected_schedule_id, $created_by);
                        $stmt_new_inv->execute();
                        $new_id = (int)$conn->insert_id;
                        if ($new_id > 0) {
                            $stmt_ins_it = $conn->prepare("INSERT INTO lodging_invoice_items (invoice_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?)");
                            if ($stmt_ins_it) {
                                foreach ($charges as $c_item) {
                                    $c_amt = (float)$c_item['amount'];
                                    $c_qty = 1;
                                    $stmt_ins_it->bind_param('isidd', $new_id, $c_item['description'], $c_qty, $c_amt, $c_amt);
                                    $stmt_ins_it->execute();
                                }
                            }
                            if (function_exists('lodging_recompute_invoice_status')) {
                                lodging_recompute_invoice_status($conn, $new_id);
                            }
                        }
                    }
                }
            }
        }

        // Fallback: Query invoice items directly from lodging_invoices if no fee schedule linked
        if (count($charges) === 0) {
            $ch_sql = "SELECT it.id as item_id,
                              COALESCE(NULLIF(it.description, ''), 'Fee Item') as description,
                              it.quantity,
                              it.unit_price,
                              it.amount,
                              i.id as invoice_id,
                              i.invoice_no,
                              i.issue_date,
                              i.academic_year,
                              i.semester,
                              i.fee_schedule_id
                       FROM lodging_invoices i
                       JOIN lodging_invoice_items it ON it.invoice_id = i.id
                       WHERE i.seminarian_id = ?
                         AND i.archived_at IS NULL
                         AND i.status IN ('issued', 'partially_paid', 'paid')";
            $ch_types = 'i';
            $ch_params = [$student_id];

            if ($academic_year !== 'all') {
                $ch_sql .= " AND (i.academic_year = ? OR i.academic_year IS NULL OR i.academic_year = '')";
                $ch_types .= 's';
                $ch_params[] = $academic_year;
            }

            if ($semester !== 'all') {
                $ch_sql .= " AND (i.semester = ? OR i.semester IS NULL OR i.semester = '')";
                $ch_types .= 's';
                $ch_params[] = $semester;
            }

            $ch_sql .= " ORDER BY i.issue_date ASC, it.id ASC";
            $stmt_ch = $conn->prepare($ch_sql);
            $ch_bind = [&$ch_types];
            foreach ($ch_params as $k => $v) {
                $ch_bind[] = &$ch_params[$k];
            }
            call_user_func_array([$stmt_ch, 'bind_param'], $ch_bind);
            $stmt_ch->execute();
            $res_ch = $stmt_ch->get_result();

            while ($res_ch && ($r = $res_ch->fetch_assoc())) {
                $desc = (string)$r['description'];
                $amt = (float)($r['amount'] ?? 0);
                $is_commodity = false;
                $comp = 'Standard Term Fee';
                if (stripos($desc, 'Board') !== false) {
                    $comp = 'Php ' . number_format($amt > 0 ? $amt / 5.0 : 4000.00, 2) . ' x 5 mons.';
                } elseif (stripos($desc, 'Bus') !== false) {
                    $comp = 'Php ' . number_format($amt > 0 ? $amt / 5.0 : 300.00, 2) . ' x 5 mons.';
                } elseif (stripos($desc, 'Comput') !== false) {
                    $comp = 'Php ' . number_format($amt > 0 ? $amt / 5.0 : 200.00, 2) . ' x 5 mons.';
                } elseif (stripos($desc, 'Misc') !== false) {
                    $comp = 'Php ' . number_format($amt > 0 ? $amt / 5.0 : 100.00, 2) . ' x 5 mons.';
                } elseif (stripos($desc, 'Rice') !== false) {
                    $is_commodity = true;
                    $comp = $rice_sacks;
                }

                $amt_display = 'P ' . number_format($amt, 2);
                if ($is_commodity && $amt == 0.00) {
                    $amt_display = $rice_sacks;
                }

                $charges[] = [
                    'item_id' => (int)$r['item_id'],
                    'description' => $desc,
                    'computation' => $comp,
                    'amount' => $amt,
                    'amount_display' => $amt_display,
                    'is_commodity' => $is_commodity,
                    'invoice_no' => $r['invoice_no'] ?? '',
                    'issue_date' => $r['issue_date'] ?? date('Y-m-d'),
                ];
            }
        }

        // 3. TOTAL COMPUTED ACCOUNTS & PREVIOUS BALANCE CARRYOVER
        $total_charges = 0.0;
        foreach ($charges as $c) {
            $total_charges += (float)($c['amount'] ?? 0);
        }
        $current_semester_fees = $total_charges;

        $previous_balance = 0.0;
        $prior_invoices_breakdown = [];
        $prev_term_text = '';

        if ($academic_year !== 'all' && $semester !== 'all') {
            $prev_info = lodging_get_previous_terms_unpaid_invoices($conn, $student_id, $academic_year, $semester);
            $unpaid_prior_invoices = (float)($prev_info['previous_balance'] ?? 0);
            $prior_invoices_breakdown = $prev_info['invoices'] ?? [];

            $paid_towards_prior = 0.0;
            $curr_sort = lodging_term_sort_key($academic_year, $semester);
            $stmt_alloc_details = $conn->prepare(
                "SELECT pa.invoice_id, pa.amount, i.academic_year as inv_ay, i.semester as inv_sem
                 FROM lodging_payment_allocations pa
                 JOIN lodging_payments p ON p.id = pa.payment_id
                 JOIN lodging_invoices i ON i.id = pa.invoice_id
                 WHERE p.seminarian_id = ?
                   AND (p.academic_year = ? OR p.academic_year IS NULL OR p.academic_year = '')
                   AND (p.semester = ? OR p.semester IS NULL OR p.semester = '')"
            );
            if ($stmt_alloc_details) {
                $stmt_alloc_details->bind_param('iss', $student_id, $academic_year, $semester);
                $stmt_alloc_details->execute();
                $res_ad = $stmt_alloc_details->get_result();
                while ($rad = $res_ad->fetch_assoc()) {
                    $i_key = lodging_term_sort_key((string)$rad['inv_ay'], (string)$rad['inv_sem']);
                    if ($i_key > 0 && $curr_sort > 0 && $i_key < $curr_sort) {
                        $paid_towards_prior += (float)$rad['amount'];
                    }
                }
            }
            $previous_balance = round($unpaid_prior_invoices + $paid_towards_prior, 2);

            if (count($prior_invoices_breakdown) > 0) {
                $terms_list = [];
                foreach ($prior_invoices_breakdown as $pib) {
                    $terms_list[] = 'AY ' . $pib['academic_year'] . ' ' . $pib['semester'] . ' Sem';
                }
                $prev_term_text = 'Unpaid balance from ' . implode(', ', array_unique($terms_list));
            } else {
                $prev_term_text = 'Unpaid balance forwarded from previous term';
            }
        }

        $grand_total_assessment = round($current_semester_fees + $previous_balance, 2);

        // 4. FETCH PAYMENTS CREDITED & ITEMIZED PARTICULARS
        $payments = [];
        $total_payments = 0.0;
        $total_change = 0.0;

        $pay_sql = "SELECT p.id,
                           p.payment_date,
                           p.or_number,
                           p.payer_type,
                           p.payer_name,
                           p.amount,
                           p.amount_tendered,
                           p.remaining_balance,
                           p.overpayment,
                           p.method,
                           p.reference_no,
                           p.notes
                    FROM lodging_payments p
                    WHERE p.seminarian_id = ?";
        $pay_types = 'i';
        $pay_params = [$student_id];

        if ($academic_year !== 'all') {
            $pay_sql .= " AND (p.academic_year = ? OR p.academic_year IS NULL OR p.academic_year = '')";
            $pay_types .= 's';
            $pay_params[] = $academic_year;
        }

        if ($semester !== 'all') {
            $pay_sql .= " AND (p.semester = ? OR p.semester IS NULL OR p.semester = '')";
            $pay_types .= 's';
            $pay_params[] = $semester;
        }

        $pay_sql .= " ORDER BY p.payment_date ASC, p.id ASC";

        $stmt_pay = $conn->prepare($pay_sql);
        $pay_bind = [&$pay_types];
        foreach ($pay_params as $k => $v) {
            $pay_bind[] = &$pay_params[$k];
        }
        call_user_func_array([$stmt_pay, 'bind_param'], $pay_bind);
        $stmt_pay->execute();
        $res_pay = $stmt_pay->get_result();
        $payment_ids = [];
        while ($res_pay && ($r = $res_pay->fetch_assoc())) {
            $payments[] = $r;
            $payment_ids[] = (int)$r['id'];
            $total_payments += (float)($r['amount'] ?? 0);
            $total_change += (float)($r['overpayment'] ?? 0);
        }

        $payment_items_map = [];
        if (count($payment_ids) > 0) {
            $pi_placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
            $pi_types = str_repeat('i', count($payment_ids));
            $stmt_pi = $conn->prepare(
                "SELECT payment_id, particular, amount
                 FROM cashier_payment_items
                 WHERE payment_id IN ({$pi_placeholders})
                 ORDER BY sort_order ASC, id ASC"
            );
            if ($stmt_pi) {
                $pi_bind = [&$pi_types];
                foreach ($payment_ids as $k => $pid_val) {
                    $pi_bind[] = &$payment_ids[$k];
                }
                call_user_func_array([$stmt_pi, 'bind_param'], $pi_bind);
                $stmt_pi->execute();
                $res_pi = $stmt_pi->get_result();
                while ($res_pi && ($pi_row = $res_pi->fetch_assoc())) {
                    $pid_key = (int)$pi_row['payment_id'];
                    $payment_items_map[$pid_key][] = [
                        'particular' => trim((string)($pi_row['particular'] ?? '')),
                        'amount' => (float)($pi_row['amount'] ?? 0),
                    ];
                }
            }
        }

        foreach ($payments as &$p_ref) {
            $pid_val = (int)($p_ref['id'] ?? 0);
            $p_ref['particulars_display'] = soa_format_payment_particulars($p_ref, $payment_items_map[$pid_val] ?? []);
        }
        unset($p_ref);

        $net_balance = round($grand_total_assessment - $total_payments, 2);
        $remaining_balance = max(0.0, $net_balance);
        $advance_credit = $net_balance < 0 ? abs($net_balance) : 0.0;

        return [
            'student' => $student,
            'student_id' => $student_id,
            'academic_year' => $academic_year,
            'semester' => $semester,
            'semester_label' => $semester_label,
            'selected_schedule_id' => $selected_schedule_id,
            'active_schedule_name' => $active_schedule_name,
            'charges' => $charges,
            'current_semester_fees' => $current_semester_fees,
            'previous_balance' => $previous_balance,
            'prev_term_text' => $prev_term_text,
            'grand_total_assessment' => $grand_total_assessment,
            'payments' => $payments,
            'total_payments' => $total_payments,
            'total_change' => $total_change,
            'remaining_balance' => $remaining_balance,
            'advance_credit' => $advance_credit,
            'seminary_name' => 'Littleway College Seminary',
            'seminary_address' => 'Sitio Muhon Brgy. Binicuil, Kabankalan City, Negros Occidental',
            'seminary_contact' => 'Tel: (088) 813-2456 • Email: littlewaycollegeseminary@gmail.com',
        ];
    }
}

if (!function_exists('soa_render_styles')) {
    /**
     * Outputs shared CSS & @media print rules for the Official Statement of Account.
     */
    function soa_render_styles(): void {
        ?>
<style>
/* Receipt & Statement Design System */
.soa-document {
    background: #ffffff;
    border: 2px dashed #1e3a5f;
    border-radius: 12px;
    overflow: hidden;
    margin-top: 1.25rem;
    box-shadow: 0 4px 20px rgba(30, 58, 95, 0.08);
    position: relative;
}

.soa-header {
    background: #1e3a5f;
    color: #ffffff;
    padding: 1.75rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
    border-bottom: 3px solid #0f243e;
}

.soa-header .brand {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.soa-header .title-area {
    text-align: right;
}

.soa-header h1 {
    font-size: 1.65rem;
    font-weight: 900;
    letter-spacing: 0.12em;
    margin: 0;
    text-transform: uppercase;
    color: #ffffff;
}

.soa-badge {
    display: inline-block;
    background: #e0f2fe;
    border: 1px solid #7dd3fc;
    color: #0369a1;
    font-size: 0.9rem;
    font-weight: 800;
    padding: 0.25rem 0.85rem;
    border-radius: 999px;
    margin-top: 0.4rem;
}

.soa-body {
    padding: 2rem;
}

.soa-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

@media (max-width: 768px) {
    .soa-grid {
        grid-template-columns: 1fr;
    }
    .soa-header .title-area {
        text-align: left;
    }
}

.soa-info-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.25rem;
}

.soa-info-card h4 {
    margin: 0 0 0.75rem 0;
    color: #1e3a5f;
    font-size: 0.88rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #cbd5e1;
    padding-bottom: 0.35rem;
}

.soa-row {
    display: flex;
    justify-content: space-between;
    padding: 0.3rem 0;
    font-size: 0.92rem;
    gap: 0.75rem;
}

.soa-label {
    color: #64748b;
    font-weight: 600;
}

.soa-val {
    color: #0f172a;
    font-weight: 700;
    text-align: right;
}

.soa-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto;
    margin: 1rem 0;
}

.soa-table {
    display: table !important;
    width: 100% !important;
    min-width: 100% !important;
    max-width: 100% !important;
    table-layout: auto !important;
    border-collapse: collapse !important;
    margin: 0 !important;
    border-radius: 0 !important;
    border: none !important;
    overflow: visible !important;
    font-variant-numeric: tabular-nums;
}

.soa-table th {
    background: #f1f5f9;
    color: #1e3a5f;
    font-weight: 800;
    padding: 0.75rem 0.85rem;
    text-align: left;
    border-top: 2px solid #1e3a5f;
    border-bottom: 2px solid #1e3a5f;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: normal !important;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.soa-table td {
    padding: 0.7rem 0.85rem;
    border-bottom: 1px dashed #cbd5e1;
    font-size: 0.9rem;
    color: #1e293b;
    vertical-align: top;
    white-space: normal !important;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.soa-table tr:hover td {
    background-color: #f8fafc;
}

.soa-table tr.total-row td {
    border-top: 2px solid #1e3a5f;
    border-bottom: 2px solid #1e3a5f;
    background: #f8fafc;
    font-weight: 900;
}

.soa-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem;
    background: #f8fafc;
    border: 2px solid #1e3a5f;
    border-radius: 10px;
    padding: 1.25rem;
    margin: 1.75rem 0;
}

.soa-summary-item {
    text-align: center;
}

.soa-summary-item .lbl {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #1e3a5f;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.soa-summary-item .val {
    font-size: 1.3rem;
    font-weight: 900;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

.soa-summary-item.highlight-due {
    background: #fff;
    border-radius: 8px;
    padding: 0.5rem;
    box-shadow: 0 2px 6px rgba(185, 28, 28, 0.12);
}

.soa-summary-item.highlight-due .val {
    color: #b91c1c;
}

.soa-summary-item.highlight-clear {
    background: #fff;
    border-radius: 8px;
    padding: 0.5rem;
    box-shadow: 0 2px 6px rgba(5, 150, 105, 0.12);
}

.soa-summary-item.highlight-clear .val {
    color: #059669;
}

.soa-footer {
    margin-top: 2.5rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3rem;
    align-items: flex-end;
}

.signature-block {
    text-align: center;
}

.signature-line {
    border-top: 2px solid #0f172a;
    padding-top: 0.5rem;
    font-weight: 800;
    color: #0f172a;
    font-size: 0.95rem;
}

.signature-subtitle {
    font-size: 0.8rem;
    color: #64748b;
}

@page {
    size: portrait;
    margin: 10mm;
}

@media print {
    *, *::before, *::after {
        box-sizing: border-box !important;
    }
    aside, #sidebar, #sidebarBackdrop, header, .no-print {
        display: none !important;
    }
    html, body, #appShell, main, .content-body {
        display: block !important;
        position: static !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        overflow: visible !important;
    }
    .soa-document {
        position: static !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        border: 1.5px solid #1e3a5f !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        overflow: visible !important;
        box-decoration-break: clone;
        -webkit-box-decoration-break: clone;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .soa-header {
        padding: 0.75rem 1rem !important;
        gap: 0.75rem !important;
    }
    .soa-header img {
        width: 46px !important;
        height: 46px !important;
    }
    .soa-header h1 {
        font-size: 1.2rem !important;
    }
    .soa-badge {
        font-size: 0.72rem !important;
        padding: 0.15rem 0.65rem !important;
        margin-top: 0.2rem !important;
    }
    .soa-body {
        padding: 0.8rem 1rem !important;
    }
    .soa-page-1 {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    .soa-page-2 {
        break-before: page !important;
        page-break-before: always !important;
        padding-top: 0.15rem !important;
    }
    .soa-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 0.75rem !important;
        margin-bottom: 0.65rem !important;
    }
    .soa-info-card {
        padding: 0.6rem 0.8rem !important;
    }
    .soa-info-card h4 {
        font-size: 0.76rem !important;
        margin-bottom: 0.35rem !important;
        padding-bottom: 0.2rem !important;
    }
    .soa-row {
        padding: 0.14rem 0 !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
    }
    .soa-section-heading {
        margin: 0.6rem 0 0.3rem 0 !important;
    }
    .soa-section-heading h3 {
        font-size: 0.85rem !important;
    }
    .soa-table-wrap {
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
        margin: 0.25rem 0 0.5rem 0 !important;
    }
    .soa-table {
        display: table !important;
        width: 100% !important;
        min-width: 100% !important;
        max-width: 100% !important;
        table-layout: auto !important;
        margin: 0 !important;
    }
    .soa-table th {
        padding: 0.35rem 0.45rem !important;
        font-size: 10px !important;
        letter-spacing: 0.02em !important;
    }
    .soa-table td {
        padding: 0.3rem 0.45rem !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
    }
    .soa-table tr.total-row td {
        font-size: 11.5px !important;
        padding: 0.4rem 0.45rem !important;
    }
    .soa-summary-grid {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 0.5rem !important;
        padding: 0.75rem !important;
        margin: 1rem 0 !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    .soa-summary-grid.has-advance {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
    .soa-summary-item .lbl {
        font-size: 9px !important;
        letter-spacing: 0.02em !important;
    }
    .soa-summary-item .val {
        font-size: 13px !important;
    }
    .soa-footer {
        margin-top: 1.5rem !important;
        gap: 2rem !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    .signature-line {
        font-size: 12px !important;
    }
    .signature-subtitle {
        font-size: 10px !important;
    }
    .soa-legal-note {
        margin-top: 1rem !important;
        font-size: 10px !important;
    }
}
</style>
        <?php
    }
}

if (!function_exists('soa_render_document')) {
    /**
     * Renders the Official Statement of Account HTML document.
     */
    function soa_render_document(array $soa): void {
        $student = $soa['student'];
        $academic_year = $soa['academic_year'];
        $semester_label = $soa['semester_label'];
        $active_schedule_name = $soa['active_schedule_name'];
        $charges = $soa['charges'];
        $current_semester_fees = $soa['current_semester_fees'];
        $previous_balance = $soa['previous_balance'];
        $prev_term_text = $soa['prev_term_text'];
        $grand_total_assessment = $soa['grand_total_assessment'];
        $payments = $soa['payments'];
        $total_payments = $soa['total_payments'];
        $remaining_balance = $soa['remaining_balance'];
        $advance_credit = $soa['advance_credit'];
        $seminary_name = $soa['seminary_name'];
        $seminary_address = $soa['seminary_address'];
        $seminary_contact = $soa['seminary_contact'];
        ?>
            <!-- Statement Document (Traditional Receipt Container) -->
            <div class="soa-document">
                <!-- Document Header -->
                <div class="soa-header">
                    <div class="brand">
                        <img src="../assets/LOGO%20(2).jpg" alt="Logo" style="width:64px; height:64px; object-fit:cover; border-radius:8px; border:2px solid #ffffff;">
                        <div>
                            <div style="font-size:1.25rem; font-weight:900; letter-spacing:0.04em;"><?php echo htmlspecialchars($seminary_name); ?></div>
                            <div style="font-size:0.82rem; opacity:0.9;"><?php echo htmlspecialchars($seminary_address); ?></div>
                            <div style="font-size:0.82rem; opacity:0.9;"><?php echo htmlspecialchars($seminary_contact); ?></div>
                        </div>
                    </div>
                    <div class="title-area">
                        <h1>Statement of Account</h1>
                        <div class="soa-badge">
                            <?php echo $academic_year === 'all' ? 'All Academic Years' : ('AY ' . htmlspecialchars($academic_year)); ?> • <?php echo htmlspecialchars($semester_label); ?>
                        </div>
                    </div>
                </div>

                <div class="soa-body">
                    <div class="soa-page-1">
                    <!-- Student & Statement Metadata Cards -->
                    <div class="soa-grid">
                        <div class="soa-info-card">
                            <h4><i class="fas fa-user-graduate"></i> Seminarian Profile</h4>
                            <div class="soa-row">
                                <span class="soa-label">Full Name:</span>
                                <span class="soa-val"><?php echo htmlspecialchars((string)($student['full_name'] ?? '')); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Student ID:</span>
                                <span class="soa-val"><?php echo htmlspecialchars((string)($student['student_no'] ?? '—')); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Program:</span>
                                <span class="soa-val"><?php echo htmlspecialchars((string)($student['program'] ?? '—')); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Year Level / Stage:</span>
                                <span class="soa-val"><?php echo htmlspecialchars((string)($student['year_level'] ?? '—')); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Formation Status:</span>
                                <span class="soa-val"><?php echo htmlspecialchars((string)($student['formation_status'] ?? 'Active')); ?></span>
                            </div>
                        </div>

                        <div class="soa-info-card">
                            <h4><i class="fas fa-file-invoice"></i> Assessment Details</h4>
                            <div class="soa-row">
                                <span class="soa-label">Academic Year:</span>
                                <span class="soa-val"><?php echo htmlspecialchars($academic_year === 'all' ? 'All Academic Years' : $academic_year); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Semester:</span>
                                <span class="soa-val"><?php echo htmlspecialchars($semester_label); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Date:</span>
                                <span class="soa-val"><?php echo htmlspecialchars(date('F d, Y')); ?></span>
                            </div>
                            <div class="soa-row" style="border-top:1px dashed #cbd5e1; padding-top:0.4rem; margin-top:0.3rem;">
                                <span class="soa-label">Current Semester Fees:</span>
                                <span class="soa-val">Php <?php echo htmlspecialchars(number_format($current_semester_fees, 2)); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Previous Balance:</span>
                                <span class="soa-val" style="color:<?php echo $previous_balance > 0 ? '#b91c1c' : '#059669'; ?>;">
                                    Php <?php echo htmlspecialchars(number_format($previous_balance, 2)); ?>
                                    <?php if ($previous_balance > 0) { ?>
                                        <span style="font-size:0.75rem; background:#fee2e2; color:#991b1b; padding:1px 5px; border-radius:3px; font-weight:700; margin-left:3px;">Forwarded</span>
                                    <?php } ?>
                                </span>
                            </div>
                            <div class="soa-row" style="border-top:1.5px solid #1e3a5f; padding-top:0.4rem; margin-top:0.2rem;">
                                <span class="soa-label" style="font-weight:800; color:#1e3a5f;">Grand Total Assessment:</span>
                                <span class="soa-val" style="font-size:1.05rem; font-weight:900; color:#1e3a5f;">Php <?php echo htmlspecialchars(number_format($grand_total_assessment, 2)); ?></span>
                            </div>
                            <div class="soa-row">
                                <span class="soa-label">Account Status:</span>
                                <span class="soa-val" style="color:<?php echo $remaining_balance <= 0 ? ($advance_credit > 0 ? '#0284c7' : '#059669') : '#b91c1c'; ?>;">
                                    <?php
                                        if ($remaining_balance > 0) {
                                            if ($total_payments > 0) {
                                                echo 'Partially Paid (Bal: Php ' . htmlspecialchars(number_format($remaining_balance, 2)) . ')';
                                            } else {
                                                echo 'With Balance (Php ' . htmlspecialchars(number_format($remaining_balance, 2)) . ')';
                                            }
                                        } elseif ($advance_credit > 0) {
                                            echo 'Advance Credit (Php ' . htmlspecialchars(number_format($advance_credit, 2)) . ')';
                                        } else {
                                            echo 'Fully Paid (Php 0.00)';
                                        }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- PARTICULARS SECTION (Traditional Receipt Style with Dynamic Prices) -->
                    <div class="soa-section-heading" style="display:flex; justify-content:space-between; align-items:flex-end; margin:1.5rem 0 0.5rem 0;">
                        <h3 style="color:#1e3a5f; margin:0; font-size:1.05rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em;">
                            <i class="fas fa-list-check"></i> Particulars (Schedule of Fees)
                        </h3>
                        <span style="font-size:0.8rem; color:#64748b; font-weight:600;">Dynamic Schedule Ledger</span>
                    </div>

                    <div class="soa-table-wrap">
                    <table class="soa-table print:text-[11px]" style="width: 100%; display: table;">
                        <thead>
                            <tr>
                                <th class="print:py-1 print:text-xs" style="width: 6%; text-align: center;">#</th>
                                <th class="print:py-1 print:text-xs">Particulars / Fee Description</th>
                                <th class="print:py-1 print:text-xs" style="width: 28%;">Computation / Rate</th>
                                <th class="print:py-1 print:text-xs" style="text-align: right; width: 18%; white-space: nowrap;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($charges) === 0 && $previous_balance <= 0.0001) { ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #64748b; padding: 1.5rem; font-style: italic;">
                                        No fee particulars found for this schedule.
                                    </td>
                                </tr>
                            <?php } else {
                                $item_no = 1;

                                if ($previous_balance > 0.0001) {
                            ?>
                                <tr style="background:#fff1f2;">
                                    <td style="text-align: center; color: #b91c1c; font-weight: 800;"><i class="fas fa-clock-rotate-left"></i></td>
                                    <td>
                                        <div style="font-weight: 800; color: #b91c1c; font-size: 0.95rem;">
                                            Previous Balance
                                            <span style="font-size: 0.72rem; background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 4px; font-weight: 800; margin-left: 6px; border: 1px solid #fca5a5;">Carried Over</span>
                                        </div>
                                        <div style="font-size: 0.8rem; color: #7f1d1d; margin-top: 2px;">
                                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($prev_term_text); ?>
                                        </div>
                                    </td>
                                    <td style="color: #991b1b; font-weight: 700;">
                                        Outstanding Prior Term Debt
                                    </td>
                                    <td style="text-align: right; font-weight: 900; color: #b91c1c; font-size: 0.95rem;">
                                        P <?php echo htmlspecialchars(number_format($previous_balance, 2)); ?>
                                    </td>
                                </tr>
                            <?php
                                }

                                foreach ($charges as $c) {
                                    $is_comm = !empty($c['is_commodity']);
                                    $amt_val = (float)($c['amount'] ?? 0);
                                    $amt_str = $c['amount_display'] ?? ('P ' . number_format($amt_val, 2));
                            ?>
                                <tr>
                                    <td style="text-align: center; color: #64748b; font-weight: 700;"><?php echo $item_no++; ?></td>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;">
                                            <?php echo htmlspecialchars((string)($c['description'] ?? 'Fee Item')); ?>
                                        </div>
                                    </td>
                                    <td style="color: #475569; font-weight: 600;">
                                        <?php if (!empty($c['computation'])) { ?>
                                            <span><?php echo htmlspecialchars((string)$c['computation']); ?></span>
                                        <?php } else { ?>
                                            <span style="color: #94a3b8;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 800; color: <?php echo $is_comm && $amt_val == 0.00 ? '#0284c7' : '#0f172a'; ?>; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($amt_str); ?>
                                    </td>
                                </tr>
                            <?php } ?>

                            <!-- Current Semester Fees Subtotal Row -->
                            <tr style="background:#f8fafc; border-top:1.5px solid #cbd5e1;">
                                <td colspan="3" style="text-align: right; font-weight: 700; color: #475569; font-size: 0.88rem;">
                                    Current Semester Fees Subtotal:
                                </td>
                                <td style="text-align: right; font-size: 1rem; color: #0f172a; font-weight: 800;">
                                    P <?php echo htmlspecialchars(number_format($current_semester_fees, 2)); ?>
                                </td>
                            </tr>

                            <?php if ($previous_balance > 0.0001) { ?>
                            <!-- Previous Balance Forwarded Row -->
                            <tr style="background:#fff1f2;">
                                <td colspan="3" style="text-align: right; font-weight: 700; color: #991b1b; font-size: 0.88rem;">
                                    <i class="fas fa-clock-rotate-left"></i> Previous Balance Forwarded:
                                </td>
                                <td style="text-align: right; font-size: 1rem; color: #b91c1c; font-weight: 800;">
                                    P <?php echo htmlspecialchars(number_format($previous_balance, 2)); ?>
                                </td>
                            </tr>
                            <?php } ?>

                            <!-- Grand Total Assessment Row -->
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.92rem; color: #1e3a5f; font-weight: 900;">
                                    Grand Total Assessment (Total Amount Due):
                                </td>
                                <td style="text-align: right; font-size: 1.25rem; color: #1e3a5f; font-weight: 900;">
                                    P <?php echo htmlspecialchars(number_format($grand_total_assessment, 2)); ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    </div>
                    </div><!-- /.soa-page-1 -->

                    <!-- PAYMENTS CREDITED SECTION (Page 2 / Back of Bond Paper) -->
                    <div class="soa-page-2 break-before-page">
                    <div class="soa-section-heading" style="display:flex; justify-content:space-between; align-items:flex-end; margin:2rem 0 0.5rem 0;">
                        <h3 style="color:#1e3a5f; margin:0; font-size:1.05rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em;">
                            <i class="fas fa-receipt"></i> Payment Credits Applied
                        </h3>
                        <span style="font-size:0.8rem; color:#64748b; font-weight:600;">Official Receipt &amp; Remittance Log</span>
                    </div>

                    <div class="soa-table-wrap">
                    <table class="soa-table print:text-[11px]" style="width: 100%; display: table;">
                        <thead>
                            <tr>
                                <th class="print:py-1 print:text-xs" style="width: 12%;">Date</th>
                                <th class="print:py-1 print:text-xs" style="width: 13%;">OR Number</th>
                                <th class="print:py-1 print:text-xs" style="width: 16%;">Payer / Remitter</th>
                                <th class="print:py-1 print:text-xs">Particulars</th>
                                <th class="print:py-1 print:text-xs" style="width: 16%;">Payment Method</th>
                                <th class="print:py-1 print:text-xs" style="text-align: right; width: 16%; white-space: nowrap;">Amount Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($payments) === 0) { ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #64748b; padding: 1.25rem; font-style: italic;">
                                        No payments recorded for this term.
                                    </td>
                                </tr>
                            <?php } else {
                                foreach ($payments as $p) {
                                    $p_amt = (float)($p['amount'] ?? 0);
                                    $payer_txt = trim((string)($p['payer_type'] ?? '') . ' ' . (string)($p['payer_name'] ?? ''));
                                    if ($payer_txt === '') $payer_txt = 'Seminarian / Direct';
                                    $particulars_txt = (string)($p['particulars_display'] ?? 'General Assessment');
                                    $method_txt = ucfirst((string)($p['method'] ?? 'cash'));
                                    if (!empty($p['reference_no'])) {
                                        $method_txt .= ' (Ref: ' . $p['reference_no'] . ')';
                                    }
                            ?>
                                <tr>
                                    <td class="print:py-1" style="color:#475569;"><?php echo htmlspecialchars((string)($p['payment_date'] ?? '')); ?></td>
                                    <td class="print:py-1"><strong style="color:#1e3a5f;"><?php echo htmlspecialchars((string)($p['or_number'] ?? '')); ?></strong></td>
                                    <td class="print:py-1"><?php echo htmlspecialchars($payer_txt); ?></td>
                                    <td class="print:py-1" style="color:#0f172a; font-weight:600;"><?php echo htmlspecialchars($particulars_txt); ?></td>
                                    <td class="print:py-1"><?php echo htmlspecialchars($method_txt); ?></td>
                                    <td class="print:py-1" style="text-align: right; font-weight: 800; color: #047857; white-space: nowrap;">P <?php echo htmlspecialchars(number_format($p_amt, 2)); ?></td>
                                </tr>
                            <?php } ?>
                                <tr class="total-row">
                                    <td colspan="5" style="text-align: right; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.9rem; color: #047857;">
                                        Total Payments Credited:
                                    </td>
                                    <td style="text-align: right; font-size: 1.15rem; color: #047857; font-weight: 900; white-space: nowrap;">
                                        P <?php echo htmlspecialchars(number_format($total_payments, 2)); ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    </div>

                    <!-- ACCOUNT SUMMARY LEDGER (Dynamic Totals & Balance) -->
                    <div class="soa-summary-grid<?php echo $advance_credit > 0 ? ' has-advance' : ''; ?>">
                        <div class="soa-summary-item">
                            <div class="lbl">Total Payments Credited</div>
                            <div class="val" style="color:#059669;">P <?php echo htmlspecialchars(number_format($total_payments, 2)); ?></div>
                        </div>
                        <?php if ($advance_credit > 0) { ?>
                        <div class="soa-summary-item" style="background:#ecfdf5; border-radius:8px; padding:0.5rem;">
                            <div class="lbl" style="color:#047857;">Advance Credit</div>
                            <div class="val" style="color:#059669;">P <?php echo htmlspecialchars(number_format($advance_credit, 2)); ?></div>
                        </div>
                        <?php } ?>
                        <div class="soa-summary-item <?php echo $remaining_balance > 0 ? 'highlight-due' : 'highlight-clear'; ?>">
                            <div class="lbl">Outstanding Balance</div>
                            <div class="val" style="color:<?php echo $remaining_balance > 0 ? '#b91c1c' : '#059669'; ?>;">
                                P <?php echo htmlspecialchars(number_format($remaining_balance, 2)); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Signatures & Verification Footer -->
                    <div class="soa-footer">
                        <div class="signature-block">
                            <div class="signature-line">Authorized Accounting Officer / Cashier</div>
                            <div class="signature-subtitle">Finance &amp; Accounting Office</div>
                        </div>
                        <div class="signature-block">
                            <div class="signature-line">Rev. Fr. Paolo D. Eseller</div>
                            <div class="signature-subtitle">Procurator's Office / Administration</div>
                        </div>
                    </div>

                    <div class="soa-legal-note" style="text-align:center; margin-top:2.25rem; font-size:0.75rem; color:#64748b; letter-spacing:0.04em;">
                        This Statement of Account serves as the official assessment document issued by Littleway College Seminary. For billing inquiries or payment validation, please present this copy to the Seminary Cashier Office.
                    </div>
                    </div><!-- /.soa-page-2 -->
                </div>
            </div>
        <?php
    }
}
