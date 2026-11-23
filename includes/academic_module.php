<?php
function afms_compute_final_percent($prelim, $midterm, $final) {
    $terms = [];
    if ($prelim !== null && $prelim !== '' && is_numeric($prelim) && (float)$prelim > 0) {
        $terms[] = (float)$prelim;
    }
    if ($midterm !== null && $midterm !== '' && is_numeric($midterm) && (float)$midterm > 0) {
        $terms[] = (float)$midterm;
    }
    if ($final !== null && $final !== '' && is_numeric($final) && (float)$final > 0) {
        $terms[] = (float)$final;
    }
    if (empty($terms)) {
        return null;
    }
    // Calculate percentage average of available terms, formatted to 2 decimal places (e.g., (Prelim + Midterm + Final) / 3)
    return round(array_sum($terms) / count($terms), 2);
}

function afms_percent_to_gpa($percent) {
    if ($percent === null || $percent === '' || !is_numeric($percent)) {
        return null;
    }
    $p = (float)$percent;
    if ($p <= 0) {
        return null;
    }
    if ($p >= 97) return 1.00;
    if ($p >= 94) return 1.25;
    if ($p >= 91) return 1.50;
    if ($p >= 88) return 1.75;
    if ($p >= 85) return 2.00;
    if ($p >= 82) return 2.25;
    if ($p >= 79) return 2.50;
    if ($p >= 76) return 2.75;
    if ($p >= 75) return 3.00;
    return 5.00;
}

function afms_performance_category($gpa) {
    if ($gpa === null || $gpa === '' || !is_numeric($gpa)) {
        return null;
    }
    $g = (float)$gpa;
    if ($g > 5.0) {
        if ($g >= 95.0) return 'Excellent';
        if ($g >= 90.0) return 'Very Good';
        if ($g >= 85.0) return 'Good';
        if ($g >= 75.0) return 'Satisfactory';
        return 'Academic Risk';
    }
    if ($g <= 1.50) return 'Excellent';
    if ($g <= 2.00) return 'Very Good';
    if ($g <= 2.50) return 'Good';
    if ($g <= 3.00) return 'Needs Improvement';
    return 'Academic Risk';
}

function afms_compute_semester_gpa(mysqli $conn, int $student_id, string $academic_year, string $semester) {
    $sql = "SELECT sg.final_computed_grade, s.units
        FROM student_grades sg
        JOIN subjects s ON s.subject_id = sg.subject_id
        WHERE sg.student_id = ?
          AND sg.academic_year = ?
          AND sg.semester = ?
          AND sg.status = 'Completed'
          AND sg.final_computed_grade IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $student_id, $academic_year, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $total_units = 0.0;
    $total_points = 0.0;
    while ($r = $res->fetch_assoc()) {
        $gpa = (float)$r['final_computed_grade'];
        $units = (float)($r['units'] ?? 0);
        if ($units <= 0) {
            continue;
        }
        $total_units += $units;
        $total_points += ($gpa * $units);
    }
    if ($total_units <= 0) {
        return null;
    }
    return $total_points / $total_units;
}

function afms_compute_cumulative_gpa(mysqli $conn, int $student_id) {
    $sql = "SELECT sg.final_computed_grade, s.units
        FROM student_grades sg
        JOIN subjects s ON s.subject_id = sg.subject_id
        WHERE sg.student_id = ?
          AND sg.status = 'Completed'
          AND sg.final_computed_grade IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $total_units = 0.0;
    $total_points = 0.0;
    while ($r = $res->fetch_assoc()) {
        $gpa = (float)$r['final_computed_grade'];
        $units = (float)($r['units'] ?? 0);
        if ($units <= 0) {
            continue;
        }
        $total_units += $units;
        $total_points += ($gpa * $units);
    }
    if ($total_units <= 0) {
        return null;
    }
    return $total_points / $total_units;
}

function afms_get_previous_term(mysqli $conn, int $student_id, string $academic_year, string $semester) {
    $sql = "SELECT semester, academic_year, semester_gpa
        FROM gpa_records
        WHERE student_id = ?
          AND (academic_year < ? OR (academic_year = ? AND FIELD(semester,'1st','2nd','Summer') < FIELD(?, '1st','2nd','Summer')))
          AND semester_gpa IS NOT NULL
        ORDER BY academic_year DESC, FIELD(semester,'Summer','2nd','1st') DESC
        LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isss', $student_id, $academic_year, $academic_year, $semester);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function afms_compute_growth($previous_gpa, $current_gpa) {
    if ($previous_gpa === null || $current_gpa === null) {
        return ['status' => null, 'percent' => null];
    }
    $p = (float)$previous_gpa;
    $c = (float)$current_gpa;
    if ($p <= 0) {
        return ['status' => null, 'percent' => null];
    }
    if (abs($c - $p) < 0.0001) {
        return ['status' => 'Maintaining', 'percent' => 0.0];
    }
    if ($c > 5.0 || $p > 5.0) {
        $pct = (($c - $p) / $p) * 100.0;
        return ['status' => ($c > $p ? 'Growing' : 'Declining'), 'percent' => abs($pct)];
    }
    if ($c < $p) {
        return ['status' => 'Growing', 'percent' => (($p - $c) / $p) * 100.0];
    }
    return ['status' => 'Declining', 'percent' => (($p - $c) / $p) * 100.0];
}

function afms_collect_irregular_reasons(mysqli $conn, int $student_id, string $academic_year, string $semester) {
    $reasons = [];

    $stmt_profile = $conn->prepare("SELECT year_level FROM seminarians WHERE id = ?");
    $stmt_profile->bind_param('i', $student_id);
    $stmt_profile->execute();
    $profile = $stmt_profile->get_result()->fetch_assoc();
    $year_level = (string)($profile['year_level'] ?? '');

    $stmt = $conn->prepare("SELECT sg.status, sg.final_computed_grade, sg.final_grade FROM student_grades sg WHERE sg.student_id = ? AND sg.academic_year = ? AND sg.semester = ?");
    $stmt->bind_param('iss', $student_id, $academic_year, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $has_incomplete = false;
    $has_dropped = false;
    $has_failed = false;
    while ($r = $res->fetch_assoc()) {
        $st = (string)($r['status'] ?? '');
        $grade_val = is_numeric($r['final_computed_grade'] ?? null)
            ? (float)$r['final_computed_grade']
            : (is_numeric($r['final_grade'] ?? null) ? (float)$r['final_grade'] : null);
        if ($st === 'Incomplete') $has_incomplete = true;
        if ($st === 'Dropped') $has_dropped = true;
        if ($st !== 'Dropped' && $grade_val !== null) {
            if (($grade_val > 5.0 && $grade_val < 75.00) || ($grade_val > 0 && $grade_val <= 5.0 && $grade_val > 3.00)) {
                $has_failed = true;
            }
        }
    }
    if ($has_incomplete) $reasons[] = 'Has incomplete grades';
    if ($has_dropped) $reasons[] = 'Has dropped subjects';
    if ($has_failed) $reasons[] = 'Has failed subjects';

    if ($year_level !== '') {
        $stmt_out = $conn->prepare("SELECT COUNT(*) as c
            FROM student_grades sg
            LEFT JOIN semester_subjects ss
              ON ss.subject_id = sg.subject_id
             AND ss.academic_year = sg.academic_year
             AND ss.semester = sg.semester
             AND (ss.year_level = ? OR ss.year_level IS NULL)
            WHERE sg.student_id = ?
              AND sg.academic_year = ?
              AND sg.semester = ?
              AND ss.id IS NULL");
        $stmt_out->bind_param('siss', $year_level, $student_id, $academic_year, $semester);
        $stmt_out->execute();
        $out = (int)($stmt_out->get_result()->fetch_assoc()['c'] ?? 0);
        if ($out > 0) {
            $reasons[] = 'Enrolled in subjects outside the regular curriculum sequence';
        }
    }

    return $reasons;
}

function afms_notify_formators(mysqli $conn, string $type, string $message, ?int $student_id = null) {
    $stmt_users = $conn->prepare("SELECT id FROM users WHERE role = 'AcademicFormator'");
    $stmt_users->execute();
    $res = $stmt_users->get_result();
    while ($r = $res->fetch_assoc()) {
        $uid = (int)$r['id'];

        $stmt_check = $conn->prepare("SELECT COUNT(*) as c FROM academic_notifications WHERE recipient_user_id = ? AND type = ? AND student_id <=> ? AND read_at IS NULL AND created_at >= (NOW() - INTERVAL 1 DAY)");
        $stmt_check->bind_param('isi', $uid, $type, $student_id);
        $stmt_check->execute();
        $exists = (int)($stmt_check->get_result()->fetch_assoc()['c'] ?? 0);
        if ($exists > 0) {
            continue;
        }

        $stmt_ins = $conn->prepare("INSERT INTO academic_notifications (recipient_user_id, student_id, type, message) VALUES (?, ?, ?, ?)");
        $stmt_ins->bind_param('iiss', $uid, $student_id, $type, $message);
        $stmt_ins->execute();
    }
}

function afms_recompute_student(mysqli $conn, int $student_id, string $academic_year, string $semester) {
    $semester_gpa = afms_compute_semester_gpa($conn, $student_id, $academic_year, $semester);
    $cumulative_gpa = afms_compute_cumulative_gpa($conn, $student_id);
    $category = $semester_gpa !== null ? afms_performance_category($semester_gpa) : null;

    $stmt_gpa = $conn->prepare("INSERT INTO gpa_records (student_id, semester, academic_year, semester_gpa, cumulative_gpa, performance_category, computed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE semester_gpa = VALUES(semester_gpa), cumulative_gpa = VALUES(cumulative_gpa), performance_category = VALUES(performance_category), computed_at = NOW()");
    $stmt_gpa->bind_param('issdds', $student_id, $semester, $academic_year, $semester_gpa, $cumulative_gpa, $category);
    $stmt_gpa->execute();

    $previous = afms_get_previous_term($conn, $student_id, $academic_year, $semester);
    $prev_gpa = $previous && is_numeric($previous['semester_gpa']) ? (float)$previous['semester_gpa'] : null;
    $growth = afms_compute_growth($prev_gpa, $semester_gpa);

    $stmt_prog = $conn->prepare("INSERT INTO academic_progress (student_id, semester, academic_year, previous_gpa, current_gpa, growth_status, growth_percentage, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE previous_gpa = VALUES(previous_gpa), current_gpa = VALUES(current_gpa), growth_status = VALUES(growth_status), growth_percentage = VALUES(growth_percentage), created_at = NOW()");
    $growth_status = $growth['status'];
    $growth_percent = $growth['percent'];
    $stmt_prog->bind_param('issddsd', $student_id, $semester, $academic_year, $prev_gpa, $semester_gpa, $growth_status, $growth_percent);
    $stmt_prog->execute();

    $reasons = afms_collect_irregular_reasons($conn, $student_id, $academic_year, $semester);
    $is_irregular = count($reasons) > 0;
    $status = $is_irregular ? 'Irregular' : 'Regular';
    $stmt_status = $conn->prepare("UPDATE seminarians SET academic_status = ? WHERE id = ?");
    $stmt_status->bind_param('si', $status, $student_id);
    $stmt_status->execute();

    if ($is_irregular) {
        $reason_text = implode("; ", $reasons);
        $stmt_ir = $conn->prepare("INSERT INTO irregular_students (student_id, semester, academic_year, reason, status, date_flagged)
            VALUES (?, ?, ?, ?, 'Active', NOW())
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'Active', date_flagged = NOW()");
        $stmt_ir->bind_param('isss', $student_id, $semester, $academic_year, $reason_text);
        $stmt_ir->execute();

        afms_notify_formators($conn, 'irregular', "Student #{$student_id} became irregular ({$academic_year} {$semester}). {$reason_text}", $student_id);
    }

    $is_failing_avg = $semester_gpa !== null && (
        ($semester_gpa > 5.0 && $semester_gpa < 75.00)
        || ($semester_gpa > 0 && $semester_gpa <= 5.0 && $semester_gpa > 3.00)
    );
    if ($is_failing_avg) {
        afms_notify_formators($conn, 'gpa_risk', "Student #{$student_id} average is {$semester_gpa} ({$academic_year} {$semester}).", $student_id);
    }
    if ($growth_status === 'Declining') {
        afms_notify_formators($conn, 'declining', "Student #{$student_id} academic performance declined ({$academic_year} {$semester}).", $student_id);
    }
    if (in_array('Has incomplete grades', $reasons, true)) {
        afms_notify_formators($conn, 'incomplete', "Student #{$student_id} has incomplete grades ({$academic_year} {$semester}).", $student_id);
    }
}

/**
 * Resolve a subject row's Final Grade using 'stored', 'weighted' (30-30-40), or 'simple' (mean).
 */
function afms_resolve_subject_final_grade(array $row, string $formula = 'stored'): ?float {
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'dropped') {
        return null;
    }

    $has_p = isset($row['prelim_grade']) && $row['prelim_grade'] !== '' && is_numeric($row['prelim_grade']) && (float)$row['prelim_grade'] > 0;
    $has_m = isset($row['midterm_grade']) && $row['midterm_grade'] !== '' && is_numeric($row['midterm_grade']) && (float)$row['midterm_grade'] > 0;
    $has_f = isset($row['final_grade']) && $row['final_grade'] !== '' && is_numeric($row['final_grade']) && (float)$row['final_grade'] > 0;
    $has_stored = isset($row['final_computed_grade']) && $row['final_computed_grade'] !== '' && is_numeric($row['final_computed_grade']);

    if ($formula === 'weighted' && $has_p && $has_m && $has_f) {
        return round(((float)$row['prelim_grade'] * 0.30) + ((float)$row['midterm_grade'] * 0.30) + ((float)$row['final_grade'] * 0.40), 2, PHP_ROUND_HALF_UP);
    }
    if ($formula === 'simple' && $has_p && $has_m && $has_f) {
        return round(((float)$row['prelim_grade'] + (float)$row['midterm_grade'] + (float)$row['final_grade']) / 3.0, 2, PHP_ROUND_HALF_UP);
    }

    if ($has_stored) {
        return round((float)$row['final_computed_grade'], 2, PHP_ROUND_HALF_UP);
    }

    if ($has_p && $has_m && $has_f) {
        if ($formula === 'simple') {
            return round(((float)$row['prelim_grade'] + (float)$row['midterm_grade'] + (float)$row['final_grade']) / 3.0, 2, PHP_ROUND_HALF_UP);
        }
        return round(((float)$row['prelim_grade'] * 0.30) + ((float)$row['midterm_grade'] * 0.30) + ((float)$row['final_grade'] * 0.40), 2, PHP_ROUND_HALF_UP);
    }

    $avail = [];
    if ($has_p) $avail[] = (float)$row['prelim_grade'];
    if ($has_m) $avail[] = (float)$row['midterm_grade'];
    if ($has_f) $avail[] = (float)$row['final_grade'];
    if (!empty($avail)) {
        return round(array_sum($avail) / count($avail), 2, PHP_ROUND_HALF_UP);
    }
    return null;
}

/**
 * Compute unit-weighted General Average (falls back to simple mean if units are unavailable).
 */
function afms_compute_general_average_from_rows(array $rows, string $formula = 'stored'): ?float {
    $weighted_sum = 0.0;
    $unit_sum = 0.0;
    $plain_vals = [];

    foreach ($rows as $r) {
        $grade = afms_resolve_subject_final_grade($r, $formula);
        if ($grade === null) {
            continue;
        }
        $plain_vals[] = $grade;
        $u = (float)($r['units'] ?? 0);
        if ($u > 0) {
            $weighted_sum += ($grade * $u);
            $unit_sum += $u;
        }
    }

    if (empty($plain_vals)) {
        return null;
    }
    if ($unit_sum > 0) {
        return round($weighted_sum / $unit_sum, 2, PHP_ROUND_HALF_UP);
    }
    return round(array_sum($plain_vals) / count($plain_vals), 2, PHP_ROUND_HALF_UP);
}

/**
 * Evaluate a single Final Grade (0-100) into automated remark, Tailwind badge classes, and DB status.
 * Rules:
 * - Dropped -> 'Dropped' (bg-gray-100 text-gray-600)
 * - >= 88 -> 'Passed' (bg-green-100 text-green-700)
 * - >= 75 && < 88 -> 'Passed - Below Standard' (bg-orange-100 text-orange-700)
 * - < 75 -> 'Failed' (bg-red-100 text-red-700)
 * - null -> 'Pending' (bg-slate-100 text-slate-500)
 *
 * @return array{grade: ?float, remark: string, label: string, badge_class: string, db_status: string}
 */
function afms_evaluate_single_final_grade(?float $final_grade, bool $is_dropped = false): array {
    if ($is_dropped) {
        return [
            'grade' => null,
            'remark' => 'Dropped',
            'label' => 'Dropped',
            'badge_class' => 'bg-gray-100 text-gray-600',
            'db_status' => 'Dropped',
        ];
    }

    if ($final_grade === null) {
        return [
            'grade' => null,
            'remark' => '',
            'label' => 'Pending',
            'badge_class' => 'bg-slate-100 text-slate-500',
            'db_status' => 'Incomplete',
        ];
    }

    $g = round($final_grade, 2, PHP_ROUND_HALF_UP);
    if ($g >= 88.0) {
        return [
            'grade' => $g,
            'remark' => 'Passed',
            'label' => 'Passed',
            'badge_class' => 'bg-green-100 text-green-700',
            'db_status' => 'Completed',
        ];
    }
    if ($g >= 75.0) {
        return [
            'grade' => $g,
            'remark' => 'Passed - Below Standard',
            'label' => 'Passed - Below Standard',
            'badge_class' => 'bg-orange-100 text-orange-700',
            'db_status' => 'Completed',
        ];
    }

    return [
        'grade' => $g,
        'remark' => 'Failed',
        'label' => 'Failed',
        'badge_class' => 'bg-red-100 text-red-700',
        'db_status' => 'Completed',
    ];
}

/**
 * Resolve a database grade row into its single Final Grade (0-100) or null if Dropped/ungraded.
 */
function afms_resolve_row_single_final_grade(array $row): ?float {
    $status = strtolower(trim((string)($row['status'] ?? $row['grade_status'] ?? '')));
    $remarks = strtolower(trim((string)($row['remarks'] ?? '')));
    if ($status === 'dropped' || $remarks === 'dropped') {
        return null;
    }

    $fcg = $row['final_computed_grade'] ?? null;
    if ($fcg !== null && $fcg !== '' && is_numeric($fcg)) {
        return round((float)$fcg, 2, PHP_ROUND_HALF_UP);
    }

    $fg = $row['final_grade'] ?? null;
    if ($fg !== null && $fg !== '' && is_numeric($fg)) {
        return round((float)$fg, 2, PHP_ROUND_HALF_UP);
    }

    return afms_resolve_subject_final_grade($row, 'stored');
}

/**
 * Compute simple average of single Final Grade values across non-dropped graded subjects.
 */
function afms_compute_simple_final_average(array $rows): ?float {
    $sum = 0.0;
    $count = 0;
    foreach ($rows as $r) {
        $g = afms_resolve_row_single_final_grade($r);
        if ($g === null) {
            continue;
        }
        $sum += $g;
        $count++;
    }
    return $count > 0 ? round($sum / $count, 2, PHP_ROUND_HALF_UP) : null;
}

/**
 * Normalize various semester representations ('1st Semester', 'First Semester', '1st') to canonical ENUM ('1st', '2nd', 'Summer').
 */
function afms_normalize_semester_value(?string $sem): string {
    $s = strtolower(trim((string)($sem ?? '')));
    if ($s === '' || strpos($s, '1st') !== false || strpos($s, 'first') !== false || $s === '1') {
        return '1st';
    }
    if (strpos($s, '2nd') !== false || strpos($s, 'second') !== false || $s === '2') {
        return '2nd';
    }
    if (strpos($s, 'summer') !== false) {
        return 'Summer';
    }
    return '1st';
}

/**
 * Single source of truth query for Academic Formator student rosters aligned with Registrar & Cashier.
 * Starts from core seminarians/users/students and LEFT JOINs active/latest enrollments so students
 * without grades or evaluations yet are never omitted.
 */
function afms_get_canonical_students(mysqli $conn, string $academic_year = '', string $semester = ''): array {
    $ay = trim($academic_year);
    $sem = $semester !== '' ? afms_normalize_semester_value($semester) : '';

    $sql = "SELECT s.id AS student_id,
                s.user_id,
                u.username,
                COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) AS full_name,
                COALESCE(NULLIF(TRIM(stu.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no,
                COALESCE(NULLIF(TRIM(e_term.academic_year), ''), NULLIF(TRIM(e_latest.academic_year), ''), s.current_academic_year) AS current_academic_year,
                COALESCE(NULLIF(TRIM(e_term.program), ''), NULLIF(TRIM(e_latest.program), ''), s.program) AS program,
                COALESCE(NULLIF(TRIM(e_term.year_level), ''), NULLIF(TRIM(e_latest.year_level), ''), s.year_level) AS year_level,
                COALESCE(NULLIF(TRIM(e_term.enrollment_status), ''), NULLIF(TRIM(e_latest.enrollment_status), ''), s.enrollment_status) AS enrollment_status,
                s.formation_stage,
                COALESCE(NULLIF(TRIM(e_term.academic_standing), ''), NULLIF(TRIM(e_latest.academic_standing), ''), s.academic_status, 'Regular') AS academic_status,
                s.formation_status,
                s.academic_remarks,
                s.academic_view_enabled,
                s.is_graduate,
                stu.is_graduate AS stu_is_graduate,
                stu.graduated_at,
                stu.formation_status AS stu_formation_status,
                (e_term.id IS NOT NULL) AS has_term_enrollment
            FROM seminarians s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN user_profiles up ON up.user_id = u.id
            LEFT JOIN students stu ON stu.user_id = u.id
            LEFT JOIN (
                SELECT e1.*
                FROM enrollments e1
                JOIN (
                    SELECT student_id, MAX(id) AS max_id
                    FROM enrollments
                    GROUP BY student_id
                ) em ON em.student_id = e1.student_id AND em.max_id = e1.id
            ) e_latest ON e_latest.student_id = s.id";

    if ($ay !== '' && $sem !== '') {
        $sql .= " LEFT JOIN enrollments e_term ON e_term.student_id = s.id AND e_term.academic_year = ? AND e_term.semester = ?";
    } else {
        $sql .= " LEFT JOIN enrollments e_term ON 1 = 0";
    }

    $sql .= " WHERE u.role = 'Student'
              ORDER BY COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($ay !== '' && $sem !== '') {
        $stmt->bind_param('ss', $ay, $sem);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

if (!function_exists('afms_get_system_alerts')) {
    /**
     * Returns unread academic system alerts for the logged-in Academic Formator.
     */
    function afms_get_system_alerts(mysqli $conn, int $user_id, int $limit = 25): array {
        if ($user_id <= 0) {
            return ['alerts' => [], 'unread_count' => 0];
        }

        $unread_count = 0;
        $stmt_cnt = $conn->prepare("SELECT COUNT(*) AS c FROM academic_notifications WHERE recipient_user_id = ? AND read_at IS NULL");
        if ($stmt_cnt) {
            $stmt_cnt->bind_param('i', $user_id);
            $stmt_cnt->execute();
            $unread_count = (int)($stmt_cnt->get_result()->fetch_assoc()['c'] ?? 0);
        }

        $alerts = [];
        $stmt = $conn->prepare(
            "SELECT id, student_id, type, message, created_at, read_at
             FROM academic_notifications
             WHERE recipient_user_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $raw_type = trim((string)($row['type'] ?? 'notice'));
                $title_map = [
                    'irregular' => 'Irregular Status Alert',
                    'gpa_risk' => 'GPA Risk Notice',
                    'declining' => 'Academic Decline Alert',
                    'incomplete' => 'Incomplete Grades Alert',
                ];
                $title = $title_map[strtolower($raw_type)] ?? ucwords(str_replace('_', ' ', $raw_type));
                $created_raw = (string)($row['created_at'] ?? '');
                $ts = $created_raw !== '' ? strtotime($created_raw) : false;
                $alerts[] = [
                    'id' => (int)$row['id'],
                    'student_id' => $row['student_id'] !== null ? (int)$row['student_id'] : null,
                    'type' => $raw_type,
                    'title' => $title,
                    'message' => (string)($row['message'] ?? ''),
                    'created_at' => $created_raw,
                    'date_label' => $ts !== false ? date('M d, Y', $ts) : $created_raw,
                    'is_read' => !empty($row['read_at']),
                ];
            }
        }

        return [
            'alerts' => $alerts,
            'unread_count' => $unread_count,
        ];
    }
}

if (!function_exists('afms_mark_system_alerts_read')) {
    /**
     * Marks a single academic notification (or all unread notifications for the recipient when $notification_id <= 0) as read.
     */
    function afms_mark_system_alerts_read(mysqli $conn, int $user_id, int $notification_id = 0): int {
        if ($user_id <= 0) {
            return 0;
        }
        if ($notification_id > 0) {
            $stmt = $conn->prepare("UPDATE academic_notifications SET read_at = NOW() WHERE id = ? AND recipient_user_id = ? AND read_at IS NULL");
            if (!$stmt) return 0;
            $stmt->bind_param('ii', $notification_id, $user_id);
            $stmt->execute();
            return (int)$stmt->affected_rows;
        }
        $stmt = $conn->prepare("UPDATE academic_notifications SET read_at = NOW() WHERE recipient_user_id = ? AND read_at IS NULL");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        return (int)$stmt->affected_rows;
    }
}
?>
