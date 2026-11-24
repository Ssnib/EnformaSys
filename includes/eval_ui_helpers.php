<?php
/**
 * Shared UI, pagination, Group Evaluation Mode ("One Form for Many"),
 * duplicate-prevention, and smart-validation helpers for EnformaSys
 * Formator Evaluation modules (Academic, Human, Pastoral, Spiritual).
 */

/**
 * Returns whitelisted database and route configuration for an evaluation module.
 */
function eval_module_config(string $module): array
{
    static $configs = [
        'academic' => [
            'assignment_type' => 'Academic',
            'table' => 'academic_evaluations',
            'pk_col' => 'id',
            'student_col' => 'student_id',
            'evaluator_col' => 'formator_id',
            'res_table' => 'academic_evaluation_results',
            'res_avg_col' => 'average_score',
            'res_label_col' => 'rating_label',
            'form_file' => 'afe_evaluation_form.php',
            'report_file' => 'afe_report.php',
            'list_file' => 'afe_evaluations.php',
            'title' => 'Academic Evaluation',
        ],
        'human' => [
            'assignment_type' => 'Human',
            'table' => 'human_evaluations',
            'pk_col' => 'evaluation_id',
            'student_col' => 'seminarian_id',
            'evaluator_col' => 'formator_id',
            'res_table' => 'human_results',
            'res_avg_col' => 'average_rating',
            'res_label_col' => 'interpretation',
            'form_file' => 'hfe_evaluation_form.php',
            'report_file' => 'hfe_report.php',
            'list_file' => 'hfe_evaluations.php',
            'title' => 'Human Formation Evaluation',
        ],
        'pastoral' => [
            'assignment_type' => 'Pastoral',
            'table' => 'pastoral_evaluations',
            'pk_col' => 'evaluation_id',
            'student_col' => 'seminarian_id',
            'evaluator_col' => 'formator_id',
            'res_table' => 'pastoral_results',
            'res_avg_col' => 'average_rating',
            'res_label_col' => 'interpretation',
            'form_file' => 'pfe_evaluation_form.php',
            'report_file' => 'pfe_report.php',
            'list_file' => 'pfe_evaluations.php',
            'title' => 'Pastoral Formation Evaluation',
        ],
        'spiritual' => [
            'assignment_type' => 'Spiritual',
            'table' => 'spiritual_evaluations',
            'pk_col' => 'evaluation_id',
            'student_col' => 'seminarian_id',
            'evaluator_col' => 'spiritual_director_id',
            'res_table' => 'spiritual_results',
            'res_avg_col' => 'average_rating',
            'res_label_col' => 'interpretation',
            'form_file' => 'sfe_evaluation_form.php',
            'report_file' => 'sfe_report.php',
            'list_file' => 'sfe_evaluations.php',
            'title' => 'Spiritual Formation Evaluation',
        ],
    ];

    if (!isset($configs[$module])) {
        throw new InvalidArgumentException('Unknown evaluation module: ' . $module);
    }

    return $configs[$module];
}

/**
 * Checks if an active (non-archived) evaluation already exists for a seminarian and term.
 *
 * @return array{evaluation_id:int,evaluator_id:int,evaluation_date:string}|null
 */
function eval_find_active_duplicate(
    mysqli $conn,
    string $module,
    int $student_id,
    string $academic_year,
    string $semester,
    int $exclude_eval_id = 0
): ?array {
    if ($student_id <= 0 || $academic_year === '' || $semester === '') {
        return null;
    }

    $cfg = eval_module_config($module);
    $table = $cfg['table'];
    $pk_col = $cfg['pk_col'];
    $student_col = $cfg['student_col'];
    $evaluator_col = $cfg['evaluator_col'];

    $sql = "SELECT {$pk_col} AS evaluation_id, {$evaluator_col} AS evaluator_id, evaluation_date
            FROM {$table}
            WHERE {$student_col} = ?
              AND academic_year = ?
              AND semester = ?
              AND archived_at IS NULL";
    if ($exclude_eval_id > 0) {
        $sql .= " AND {$pk_col} <> ?";
    }
    $sql .= " ORDER BY {$pk_col} DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($exclude_eval_id > 0) {
        $stmt->bind_param('issi', $student_id, $academic_year, $semester, $exclude_eval_id);
    } else {
        $stmt->bind_param('iss', $student_id, $academic_year, $semester);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    return [
        'evaluation_id' => (int)$row['evaluation_id'],
        'evaluator_id' => (int)$row['evaluator_id'],
        'evaluation_date' => (string)($row['evaluation_date'] ?? ''),
    ];
}

/**
 * Returns the canonical Year Level filter options for Formator Evaluation views.
 *
 * @return string[]
 */
function eval_year_level_filter_options(): array
{
    return [
        'Propaedeutic Stage',
        '1st Year',
        '2nd Year',
        '3rd Year',
        '4th Year',
    ];
}

/**
 * Normalizes any raw year level label (e.g. 'First Year' or '1st Year') to one of
 * the 5 canonical filter option strings, or '' if not matched.
 */
function eval_normalize_year_level_option(?string $raw): string
{
    $clean = strtolower(trim((string)($raw ?? '')));
    if ($clean === '') {
        return '';
    }
    if (strpos($clean, 'propaedeutic') !== false || strpos($clean, 'prep') !== false) {
        return 'Propaedeutic Stage';
    }
    if (strpos($clean, '1st') !== false || strpos($clean, 'first') !== false || $clean === '1') {
        return '1st Year';
    }
    if (strpos($clean, '2nd') !== false || strpos($clean, 'second') !== false || $clean === '2') {
        return '2nd Year';
    }
    if (strpos($clean, '3rd') !== false || strpos($clean, 'third') !== false || $clean === '3') {
        return '3rd Year';
    }
    if (strpos($clean, '4th') !== false || strpos($clean, 'fourth') !== false || $clean === '4') {
        return '4th Year';
    }
    return '';
}

/**
 * Returns all database string variants matching a canonical Year Level option.
 *
 * @return string[]
 */
function eval_year_level_sql_aliases(?string $filter_yl): array
{
    $norm = eval_normalize_year_level_option($filter_yl);
    switch ($norm) {
        case 'Propaedeutic Stage':
            return ['Propaedeutic Stage', 'Propaedeutic'];
        case '1st Year':
            return ['1st Year', 'First Year', '1st', '1'];
        case '2nd Year':
            return ['2nd Year', 'Second Year', '2nd', '2'];
        case '3rd Year':
            return ['3rd Year', 'Third Year', '3rd', '3'];
        case '4th Year':
            return ['4th Year', 'Fourth Year', '4th', '4'];
        default:
            return [];
    }
}

/**
 * Determines whether a seminarian row represents a student who has already
 * completed the program or graduated.
 *
 * @param array<string,mixed> $row
 */
function eval_is_seminarian_graduated(array $row): bool
{
    if (!empty($row['is_graduate']) || !empty($row['is_graduated']) || !empty($row['stu_is_graduate'])) {
        return true;
    }

    foreach (['completed_at', 'graduated_at'] as $date_key) {
        $dt = trim((string)($row[$date_key] ?? ''));
        if ($dt !== '' && $dt !== '0000-00-00' && $dt !== '0000-00-00 00:00:00') {
            return true;
        }
    }

    $status_fields = [
        $row['status'] ?? '',
        $row['enrollment_status'] ?? '',
        $row['formation_status'] ?? '',
        $row['stu_formation_status'] ?? '',
        $row['academic_status'] ?? '',
        $row['year_level'] ?? '',
        $row['formation_stage'] ?? '',
    ];

    foreach ($status_fields as $val) {
        $clean = strtolower(trim((string)$val));
        if ($clean === '') {
            continue;
        }
        if (
            in_array($clean, ['graduated', 'graduate', 'completed', 'complete', 'alumni'], true)
            || strpos($clean, 'graduat') !== false
            || strpos($clean, 'completed') !== false
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Performs a server-side database check to verify if a seminarian has graduated or completed the program.
 */
function eval_check_seminarian_graduated_db(mysqli $conn, int $seminarian_id): bool
{
    if ($seminarian_id <= 0) {
        return false;
    }

    $sql = "SELECT s.id,
                   s.is_graduate,
                   s.completed_at,
                   s.enrollment_status,
                   s.formation_status,
                   s.academic_status,
                   s.year_level,
                   s.formation_stage,
                   stu.is_graduate AS stu_is_graduate,
                   stu.graduated_at,
                   stu.formation_status AS stu_formation_status
            FROM seminarians s
            LEFT JOIN students stu ON stu.user_id = s.user_id
            WHERE s.id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $seminarian_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }

    return eval_is_seminarian_graduated($row);
}

/**
 * Returns the canonical SQL expression that evaluates to 1 when a seminarian is graduated/completed.
 */
function eval_sql_is_graduated_expr(string $sem_alias = 's', string $stu_alias = 'stu'): string
{
    return "(
        COALESCE({$sem_alias}.is_graduate, 0) = 1
        OR COALESCE({$stu_alias}.is_graduate, 0) = 1
        OR ({$sem_alias}.completed_at IS NOT NULL AND CAST({$sem_alias}.completed_at AS CHAR) NOT IN ('', '0000-00-00'))
        OR ({$stu_alias}.graduated_at IS NOT NULL AND CAST({$stu_alias}.graduated_at AS CHAR) NOT IN ('', '0000-00-00'))
        OR LOWER(TRIM(COALESCE({$sem_alias}.enrollment_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete')
        OR LOWER(TRIM(COALESCE({$sem_alias}.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete')
        OR LOWER(TRIM(COALESCE({$stu_alias}.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete')
        OR LOWER(TRIM(COALESCE({$sem_alias}.year_level, ''))) IN ('graduated', 'graduate', 'completed')
        OR LOWER(TRIM(COALESCE({$sem_alias}.formation_stage, ''))) IN ('graduated', 'graduate', 'completed')
    )";
}

/**
 * Fetches a paginated slice of assigned seminarians along with total COUNT() metadata
 * and each student's active evaluation status for the selected term.
 *
 * @return array<string,mixed>
 */
function eval_fetch_paginated_batch_seminarians(
    mysqli $conn,
    string $module,
    int $evaluator_id,
    string $academic_year,
    string $semester,
    int $page = 1,
    int $per_page = 10,
    string $status_filter = 'all',
    string $search_q = '',
    string $year_level_filter = ''
): array {
    $cfg = eval_module_config($module);
    $assign_type = $cfg['assignment_type'];
    $table = $cfg['table'];
    $pk_col = $cfg['pk_col'];
    $student_col = $cfg['student_col'];
    $res_table = $cfg['res_table'];
    $res_avg_col = $cfg['res_avg_col'];
    $res_label_col = $cfg['res_label_col'];

    $per_page = max(1, min(50, $per_page));
    $page = max(1, $page);
    $status_filter = in_array($status_filter, ['all', 'pending', 'evaluated'], true) ? $status_filter : 'all';
    $search_q = trim($search_q);
    $yl_aliases = eval_year_level_sql_aliases($year_level_filter);

    $empty_result = [
        'rows' => [],
        'total_all' => 0,
        'total_evaluated' => 0,
        'total_pending' => 0,
        'filtered_total' => 0,
        'page' => 1,
        'per_page' => $per_page,
        'total_pages' => 1,
    ];

    // Derived map ensures strictly 1 row per seminarian even if historical duplicate rows exist.
    $ev_subquery = "LEFT JOIN (
            SELECT {$student_col} AS sid, MAX({$pk_col}) AS latest_eval_id
            FROM {$table}
            WHERE academic_year = ?
              AND semester = ?
              AND archived_at IS NULL
            GROUP BY {$student_col}
        ) ev_map ON ev_map.sid = s.id";

    $yl_sql = '';
    $yl_types = '';
    $yl_params = [];
    if (!empty($yl_aliases)) {
        $yl_placeholders = implode(',', array_fill(0, count($yl_aliases), '?'));
        $yl_sql = " AND s.year_level IN ({$yl_placeholders})";
        $yl_types = str_repeat('s', count($yl_aliases));
        $yl_params = $yl_aliases;
    }

    $base_from = "FROM seminarian_assignments sa
        JOIN seminarians s ON s.id = sa.seminarian_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN students stu ON stu.user_id = u.id
        {$ev_subquery}
        WHERE sa.assignment_type = ?
          AND sa.assigned_user_id = ?
          AND sa.active = 1
          AND u.role = 'Student'{$yl_sql}";

    $grad_expr = eval_sql_is_graduated_expr('s', 'stu');

    // Secondary COUNT() summary query across all assigned seminarians for this term (and year level if filtered).
    $sql_summary = "SELECT COUNT(*) AS total_all,
            COALESCE(SUM(CASE WHEN ev_map.latest_eval_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS total_evaluated,
            COALESCE(SUM(CASE WHEN ev_map.latest_eval_id IS NULL AND NOT {$grad_expr} THEN 1 ELSE 0 END), 0) AS total_pending
        {$base_from}";
    $stmt_sum = $conn->prepare($sql_summary);
    if (!$stmt_sum) {
        return $empty_result;
    }
    $sum_types = 'sssi' . $yl_types;
    $sum_params = array_merge([$academic_year, $semester, $assign_type, $evaluator_id], $yl_params);
    $stmt_sum->bind_param($sum_types, ...$sum_params);
    $stmt_sum->execute();
    $sum_row = $stmt_sum->get_result()->fetch_assoc();
    $total_all = (int)($sum_row['total_all'] ?? 0);
    $total_evaluated = (int)($sum_row['total_evaluated'] ?? 0);
    $total_pending = (int)($sum_row['total_pending'] ?? 0);

    $extra_where = '';
    $extra_types = '';
    $extra_params = [];
    if ($status_filter === 'pending') {
        $extra_where .= " AND ev_map.latest_eval_id IS NULL AND NOT {$grad_expr}";
    } elseif ($status_filter === 'evaluated') {
        $extra_where .= " AND ev_map.latest_eval_id IS NOT NULL";
    }

    if ($search_q !== '') {
        $like = '%' . $search_q . '%';
        $extra_where .= " AND (
            COALESCE(NULLIF(TRIM(up.full_name),''), NULLIF(TRIM(stu.full_name),''), u.username) LIKE ?
            OR COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) LIKE ?
            OR COALESCE(s.program, '') LIKE ?
            OR COALESCE(s.year_level, '') LIKE ?
        )";
        $extra_types .= 'ssss';
        array_push($extra_params, $like, $like, $like, $like);
    }

    if ($extra_where === '') {
        $filtered_total = $total_all;
    } else {
        $sql_count = "SELECT COUNT(*) AS cnt {$base_from} {$extra_where}";
        $stmt_cnt = $conn->prepare($sql_count);
        if (!$stmt_cnt) {
            return $empty_result;
        }
        $cnt_types = 'sssi' . $yl_types . $extra_types;
        $cnt_params = array_merge([$academic_year, $semester, $assign_type, $evaluator_id], $yl_params, $extra_params);
        $stmt_cnt->bind_param($cnt_types, ...$cnt_params);
        $stmt_cnt->execute();
        $cnt_row = $stmt_cnt->get_result()->fetch_assoc();
        $filtered_total = (int)($cnt_row['cnt'] ?? 0);
    }

    $total_pages = max(1, (int)ceil($filtered_total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql_data = "SELECT s.id AS seminarian_id,
            u.username,
            COALESCE(NULLIF(TRIM(up.full_name),''), NULLIF(TRIM(stu.full_name),''), u.username) AS full_name,
            COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no,
            s.year_level,
            s.program,
            s.formation_stage,
            s.enrollment_status,
            s.formation_status,
            s.is_graduate,
            s.completed_at,
            stu.is_graduate AS stu_is_graduate,
            stu.graduated_at,
            stu.formation_status AS stu_formation_status,
            ev_map.latest_eval_id AS evaluation_id,
            e.evaluation_date,
            r.{$res_avg_col} AS average_score,
            r.{$res_label_col} AS rating_label
        FROM seminarian_assignments sa
        JOIN seminarians s ON s.id = sa.seminarian_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN students stu ON stu.user_id = u.id
        {$ev_subquery}
        LEFT JOIN {$table} e ON e.{$pk_col} = ev_map.latest_eval_id
        LEFT JOIN {$res_table} r ON r.evaluation_id = e.{$pk_col}
        WHERE sa.assignment_type = ?
          AND sa.assigned_user_id = ?
          AND sa.active = 1
          AND u.role = 'Student'
          {$yl_sql}
          {$extra_where}
        ORDER BY full_name ASC
        LIMIT ? OFFSET ?";

    $rows = [];
    $stmt_data = $conn->prepare($sql_data);
    if ($stmt_data) {
        $data_types = 'sssi' . $yl_types . $extra_types . 'ii';
        $data_params = array_merge([$academic_year, $semester, $assign_type, $evaluator_id], $yl_params, $extra_params, [$per_page, $offset]);
        $stmt_data->bind_param($data_types, ...$data_params);
        $stmt_data->execute();
        $res_data = $stmt_data->get_result();
        while ($res_data && ($row = $res_data->fetch_assoc())) {
            $eval_id = (int)($row['evaluation_id'] ?? 0);
            $is_grad = eval_is_seminarian_graduated($row);
            $raw_status = strtolower(trim((string)($row['formation_status'] ?: ($row['enrollment_status'] ?: ''))));
            $status_val = $is_grad
                ? ($raw_status === 'completed' ? 'completed' : 'graduated')
                : ($raw_status !== '' ? $raw_status : 'active');
            $rows[] = [
                'seminarian_id' => (int)$row['seminarian_id'],
                'full_name' => (string)$row['full_name'],
                'username' => (string)$row['username'],
                'student_no' => (string)$row['student_no'],
                'year_level' => (string)($row['year_level'] ?? ''),
                'program' => (string)($row['program'] ?? ''),
                'status' => $status_val,
                'is_graduated' => $is_grad,
                'is_graduate' => $is_grad ? 1 : 0,
                'completed_at' => (string)($row['completed_at'] ?? ''),
                'graduated_at' => (string)($row['graduated_at'] ?? ''),
                'evaluated' => $eval_id > 0,
                'evaluation_id' => $eval_id,
                'evaluation_date' => (string)($row['evaluation_date'] ?? ''),
                'average_score' => $row['average_score'] !== null ? (float)$row['average_score'] : null,
                'rating_label' => (string)($row['rating_label'] ?? ''),
            ];
        }
    }

    return [
        'rows' => $rows,
        'total_all' => $total_all,
        'total_evaluated' => $total_evaluated,
        'total_pending' => $total_pending,
        'filtered_total' => $filtered_total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
    ];
}

/**
 * Computes two-letter uppercase initials for the student avatar badge.
 */
function eval_initials(string $name): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $name));
    if ($clean === '') {
        return 'S';
    }
    $parts = explode(' ', $clean);
    $first = strtoupper(substr($parts[0], 0, 1));
    $second = count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $second;
}

/**
 * Builds a clean query URL while preserving current filter query parameters.
 */
function eval_build_list_url(string $base_file, array $overrides = [], array $strip_keys = ['group_saved', 'batch_completed', 'last_eval_id']): string
{
    $params = $_GET;
    foreach ($strip_keys as $k) {
        unset($params[$k]);
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return $qs !== '' ? ($base_file . '?' . $qs) : $base_file;
}

/**
 * Parses comma-separated or array batch IDs into a deduplicated int array.
 *
 * @param mixed $raw_batch
 * @param mixed $raw_batch_ids
 * @return int[]
 */
function eval_parse_batch_id_list($raw_batch, $raw_batch_ids = null): array
{
    $ids = [];
    $push = function ($val) use (&$ids): void {
        $n = (int)$val;
        if ($n > 0 && !in_array($n, $ids, true)) {
            $ids[] = $n;
        }
    };

    if (is_array($raw_batch_ids)) {
        foreach ($raw_batch_ids as $v) {
            $push($v);
        }
    }
    if (is_string($raw_batch) && trim($raw_batch) !== '') {
        foreach (explode(',', $raw_batch) as $part) {
            $push(trim($part));
        }
    } elseif (is_array($raw_batch)) {
        foreach ($raw_batch as $v) {
            $push($v);
        }
    }

    return $ids;
}

/**
 * Partitions selected seminarian IDs into pending, already-evaluated, and unassigned sets
 * while preserving original selection order.
 *
 * @param int[] $candidate_ids
 * @return array{
 *   pending: array<int,array{id:int,name:string,student_no:string}>,
 *   already_evaluated: array<int,array{id:int,name:string,student_no:string,evaluation_id:int}>,
 *   unassigned_ids: int[]
 * }
 */
function eval_partition_batch_ids(
    mysqli $conn,
    string $module,
    int $evaluator_id,
    array $candidate_ids,
    string $academic_year,
    string $semester,
    int $exclude_eval_id = 0
): array {
    $clean_ids = eval_parse_batch_id_list(null, $candidate_ids);
    if (empty($clean_ids) || $evaluator_id <= 0) {
        return ['pending' => [], 'already_evaluated' => [], 'graduated' => [], 'unassigned_ids' => []];
    }

    $cfg = eval_module_config($module);
    $assign_type = $cfg['assignment_type'];
    $table = $cfg['table'];
    $pk_col = $cfg['pk_col'];
    $student_col = $cfg['student_col'];

    $placeholders = implode(',', array_fill(0, count($clean_ids), '?'));
    $excl_sql = $exclude_eval_id > 0 ? " AND {$pk_col} <> ?" : "";

    $sql = "SELECT s.id,
            COALESCE(NULLIF(TRIM(up.full_name),''), NULLIF(TRIM(stu.full_name),''), u.username) AS full_name,
            COALESCE(NULLIF(TRIM(stu.student_no),''), NULLIF(TRIM(up.student_id),''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no,
            s.year_level,
            s.formation_stage,
            s.enrollment_status,
            s.formation_status,
            s.is_graduate,
            s.completed_at,
            stu.is_graduate AS stu_is_graduate,
            stu.graduated_at,
            stu.formation_status AS stu_formation_status,
            e_chk.eval_id
        FROM seminarian_assignments sa
        JOIN seminarians s ON s.id = sa.seminarian_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN students stu ON stu.user_id = u.id
        LEFT JOIN (
            SELECT {$student_col} AS sid, MAX({$pk_col}) AS eval_id
            FROM {$table}
            WHERE academic_year = ? AND semester = ? AND archived_at IS NULL {$excl_sql}
            GROUP BY {$student_col}
        ) e_chk ON e_chk.sid = s.id
        WHERE sa.assignment_type = ?
          AND sa.assigned_user_id = ?
          AND sa.active = 1
          AND u.role = 'Student'
          AND s.id IN ({$placeholders})";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['pending' => [], 'already_evaluated' => [], 'graduated' => [], 'unassigned_ids' => $clean_ids];
    }

    $params = [$academic_year, $semester];
    $types = 'ss';
    if ($exclude_eval_id > 0) {
        $params[] = $exclude_eval_id;
        $types .= 'i';
    }
    $params[] = $assign_type;
    $params[] = $evaluator_id;
    $types .= 'si' . str_repeat('i', count($clean_ids));
    foreach ($clean_ids as $cid) {
        $params[] = $cid;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $by_id = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $by_id[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['full_name'],
            'student_no' => (string)$row['student_no'],
            'evaluation_id' => (int)($row['eval_id'] ?? 0),
            'is_graduated' => eval_is_seminarian_graduated($row),
        ];
    }

    $pending = [];
    $already_evaluated = [];
    $graduated = [];
    $unassigned_ids = [];

    foreach ($clean_ids as $cid) {
        if (!isset($by_id[$cid])) {
            $unassigned_ids[] = $cid;
            continue;
        }
        $entry = $by_id[$cid];
        if (!empty($entry['is_graduated'])) {
            $graduated[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'student_no' => $entry['student_no'],
            ];
        } elseif ($entry['evaluation_id'] > 0) {
            $already_evaluated[] = $entry;
        } else {
            $pending[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'student_no' => $entry['student_no'],
            ];
        }
    }

    return [
        'pending' => $pending,
        'already_evaluated' => $already_evaluated,
        'graduated' => $graduated,
        'unassigned_ids' => $unassigned_ids,
    ];
}

/**
 * Resolves bulk Group Evaluation context from GET/POST batch IDs, verifying none
 * are already evaluated or graduated for the active term and stripping them beforehand.
 *
 * @return array<string,mixed>
 */
function eval_init_group_batch(
    mysqli $conn,
    string $module,
    int $evaluator_id,
    string $academic_year,
    string $semester,
    int $evaluation_id = 0
): array {
    $inactive = [
        'active' => false,
        'pending_items' => [],
        'pending_ids' => [],
        'removed_already_evaluated' => [],
        'removed_graduated' => [],
        'removed_unassigned' => [],
        'has_recipients' => false,
    ];

    if ($evaluation_id > 0) {
        return $inactive;
    }

    $raw_ids = [];
    if (isset($_POST['seminarian_ids']) && is_array($_POST['seminarian_ids'])) {
        $raw_ids = eval_parse_batch_id_list(null, $_POST['seminarian_ids']);
    }
    if (empty($raw_ids)) {
        $raw_ids = eval_parse_batch_id_list($_GET['batch'] ?? '', $_GET['batch_ids'] ?? null);
    }

    if (empty($raw_ids)) {
        return $inactive;
    }

    $part = eval_partition_batch_ids($conn, $module, $evaluator_id, $raw_ids, $academic_year, $semester, 0);
    $pending_ids = array_map(fn($item) => (int)$item['id'], $part['pending']);

    return [
        'active' => true,
        'pending_items' => $part['pending'],
        'pending_ids' => $pending_ids,
        'removed_already_evaluated' => $part['already_evaluated'],
        'removed_graduated' => $part['graduated'] ?? [],
        'removed_unassigned' => $part['unassigned_ids'],
        'has_recipients' => !empty($part['pending']),
    ];
}

/**
 * Renders the "Group Evaluation Mode" header card listing all selected recipients
 * plus any warnings for seminarians stripped due to existing evaluations.
 */
function eval_render_group_evaluation_header(
    string $module,
    array $group_ctx,
    string $academic_year,
    string $semester
): void {
    if (empty($group_ctx['active'])) {
        return;
    }

    $cfg = eval_module_config($module);
    $back_url = $cfg['list_file'] . '?academic_year=' . urlencode($academic_year) . '&semester=' . urlencode($semester);
    $removed = $group_ctx['removed_already_evaluated'] ?? [];
    $removed_grad = $group_ctx['removed_graduated'] ?? [];
    $pending = $group_ctx['pending_items'] ?? [];
    $names_csv = implode(', ', array_map(fn($x) => (string)$x['name'], $pending));
    ?>

    <?php if (!empty($removed_grad)) {
        $grad_names = implode(', ', array_map(fn($x) => (string)$x['name'], $removed_grad));
    ?>
        <div class="bg-white rounded-3xl shadow-lg border border-gray-200 p-5 mb-5" role="alert">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 shrink-0">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <div class="text-sm font-extrabold text-[#59443F]">Graduated Seminarians Excluded from Evaluation</div>
                        <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                            Cannot submit evaluation: <strong class="text-[#59443F]"><?php echo htmlspecialchars($grad_names, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php echo count($removed_grad) === 1 ? 'has already graduated/completed the program.' : 'have already graduated/completed the program.'; ?>
                        </p>
                    </div>
                </div>
                <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-medium shrink-0 self-start sm:self-center">Graduated</span>
            </div>
        </div>
    <?php } ?>

    <?php if (!empty($removed)) {
        $removed_names = implode(', ', array_map(fn($x) => (string)$x['name'], $removed));
    ?>
        <div class="bg-white rounded-3xl shadow-lg border border-amber-200 p-5 mb-5" role="alert">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-700 shrink-0">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div class="text-sm font-extrabold text-[#59443F]">Already Evaluated Seminarians Removed from Batch</div>
                        <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                            Excluded <strong class="text-[#59443F]"><?php echo htmlspecialchars($removed_names, ENT_QUOTES, 'UTF-8'); ?></strong> because
                            <?php echo count($removed) === 1 ? 'an active evaluation already exists' : 'active evaluations already exist'; ?>
                            for AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester).
                        </p>
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold shrink-0">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    <?php } ?>

    <?php if (empty($pending)) { ?>
        <div class="bg-white rounded-3xl shadow-lg border border-rose-200 p-6 mb-5" role="alert">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 shrink-0">
                        <i class="fas fa-ban text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-extrabold text-[#59443F] m-0">No Eligible Seminarians Remaining</h3>
                        <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                            All selected seminarians have already been evaluated for AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester), or are not assigned to you.
                        </p>
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-sm shrink-0">
                    <i class="fas fa-arrow-left"></i> Back to Evaluate Seminarians
                </a>
            </div>
        </div>
    <?php } else { ?>
        <section class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-5">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-[#F3E4E4]">
                <div class="flex items-start gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] border border-[#E2C9C8] flex items-center justify-center text-[#59443F] shrink-0">
                        <i class="fas fa-users text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#59443F] text-white text-xs font-extrabold shadow-sm">
                                <i class="fas fa-layer-group"></i> Group Evaluation Mode
                            </span>
                            <span class="inline-flex items-center px-3 py-1 rounded-full bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-extrabold">
                                <?php echo count($pending); ?> Seminarian<?php echo count($pending) === 1 ? '' : 's'; ?> Selected
                            </span>
                            <span class="text-xs font-semibold text-[#8B7168]">
                                AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?> &middot; <?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester
                            </span>
                        </div>
                        <p class="text-xs font-semibold text-[#8B7168] mt-1.5 mb-0">
                            One form applies to all listed recipients simultaneously:
                            <strong class="text-[#59443F]"><?php echo htmlspecialchars($names_csv, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </p>
                    </div>
                </div>

                <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-[#FDF8F8] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold shrink-0 transition-colors">
                    <i class="fas fa-arrow-left"></i> Change Selection
                </a>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($pending as $item) { ?>
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-2xl bg-[#F8EEEE] border border-[#E2C9C8] text-xs font-bold text-[#59443F]">
                        <span class="w-5 h-5 rounded-full bg-[#59443F] text-white text-[10px] font-extrabold inline-flex items-center justify-center">
                            <?php echo htmlspecialchars(eval_initials((string)$item['name']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-[11px] font-semibold text-[#8B7168]">(<?php echo htmlspecialchars((string)$item['student_no'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                    </span>
                <?php } ?>
            </div>
        </section>
    <?php
    }
}

/**
 * Outputs `<input type="hidden" name="seminarian_ids[]" value="...">` inside the
 * evaluation POST form when in Group Evaluation Mode.
 */
function eval_render_group_hidden_inputs(array $group_ctx): void
{
    if (empty($group_ctx['pending_items'])) {
        return;
    }
    foreach ($group_ctx['pending_items'] as $item) {
        echo '<input type="hidden" name="seminarian_ids[]" value="' . (int)$item['id'] . '">' . "\n";
    }
}

/**
 * Redirects cleanly after saving either a Group bulk evaluation or a single evaluation.
 *
 * @param int[] $saved_eval_ids
 */
function eval_redirect_after_save(
    string $module,
    string $academic_year,
    string $semester,
    array $saved_eval_ids,
    bool $is_group
): void {
    $cfg = eval_module_config($module);
    $count = count($saved_eval_ids);
    $last_id = $count > 0 ? (int)end($saved_eval_ids) : 0;

    if ($is_group) {
        header('Location: ' . $cfg['list_file']
            . '?academic_year=' . urlencode($academic_year)
            . '&semester=' . urlencode($semester)
            . '&group_saved=' . $count
            . '&last_eval_id=' . $last_id);
        exit;
    }

    header('Location: ' . $cfg['report_file'] . '?evaluation_id=' . $last_id);
    exit;
}

/**
 * Renders the paginated "Evaluate Seminarians" batch list container with Select All,
 * row checkboxes, "Evaluate Selected (N)", "Already Evaluated" badges, and pagination bar.
 */
function eval_render_batch_panel(
    mysqli $conn,
    string $module,
    int $evaluator_id,
    string $academic_year,
    string $semester,
    bool $can_evaluate = true,
    string $year_level_filter = ''
): void {
    $cfg = eval_module_config($module);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 10;
    $status_filter = isset($_GET['batch_status']) ? trim((string)$_GET['batch_status']) : 'all';
    if (!in_array($status_filter, ['all', 'pending', 'evaluated'], true)) {
        $status_filter = 'all';
    }
    $search_q = isset($_GET['batch_q']) ? trim((string)$_GET['batch_q']) : '';
    if ($year_level_filter === '' && isset($_GET['year_level'])) {
        $year_level_filter = eval_normalize_year_level_option((string)$_GET['year_level']);
    }

    $data = eval_fetch_paginated_batch_seminarians(
        $conn,
        $module,
        $evaluator_id,
        $academic_year,
        $semester,
        $page,
        $per_page,
        $status_filter,
        $search_q,
        $year_level_filter
    );

    $rows = $data['rows'];
    $total_all = (int)$data['total_all'];
    $total_evaluated = (int)$data['total_evaluated'];
    $total_pending = (int)$data['total_pending'];
    $filtered_total = (int)$data['filtered_total'];
    $current_page = (int)$data['page'];
    $total_pages = (int)$data['total_pages'];

    $list_file = $cfg['list_file'];
    $form_url_base = $cfg['form_file'];
    $panel_uid = 'evalBatch_' . $module;
    $form_uid = 'evalBatchForm_' . $module;
    $select_all_id = 'evalSelectAll_' . $module;
    $submit_btn_id = 'evalSelectedBtn_' . $module;

    $page_pending_count = 0;
    foreach ($rows as $r) {
        $r_grad = !empty($r['is_graduated']) || in_array(strtolower((string)($r['status'] ?? '')), ['graduated', 'completed'], true);
        if (empty($r['evaluated']) && !$r_grad) {
            $page_pending_count++;
        }
    }

    $group_saved = isset($_GET['group_saved']) ? (int)$_GET['group_saved'] : 0;
    if ($group_saved === 0 && isset($_GET['batch_completed'])) {
        $group_saved = (int)$_GET['batch_completed'];
    }
    $last_eval_id = isset($_GET['last_eval_id']) ? (int)$_GET['last_eval_id'] : 0;

    $start_idx = $filtered_total > 0 ? (($current_page - 1) * $per_page + 1) : 0;
    $end_idx = $filtered_total > 0 ? min($filtered_total, $start_idx + count($rows) - 1) : 0;
    ?>
    <section id="<?php echo htmlspecialchars($panel_uid, ENT_QUOTES, 'UTF-8'); ?>"
             class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6">

        <?php if ($group_saved > 0) { ?>
            <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" role="status">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-sm font-extrabold text-emerald-900">Group Evaluation Saved</div>
                        <div class="text-xs font-semibold text-emerald-700">
                            Applied evaluation scores and remarks to <?php echo $group_saved; ?> seminarian<?php echo $group_saved === 1 ? '' : 's'; ?> for AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester).
                        </div>
                    </div>
                </div>
                <?php if ($last_eval_id > 0) { ?>
                    <a href="<?php echo htmlspecialchars($cfg['report_file'] . '?evaluation_id=' . $last_eval_id, ENT_QUOTES, 'UTF-8'); ?>"
                       class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-emerald-100 text-emerald-800 border border-emerald-200 text-xs font-bold shrink-0">
                        <i class="fas fa-file-lines"></i> View Sample Report
                    </a>
                <?php } ?>
            </div>
        <?php } ?>

        <!-- Header: Title, Term Progress Pill, Status Filters, Year Level Filter -->
        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4 pb-5 border-b border-[#F3E4E4]">
            <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] border border-[#E6CECD] flex items-center justify-center text-[#59443F] shrink-0">
                    <i class="fas fa-user-check text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h2 class="text-lg font-extrabold text-[#59443F] m-0">Evaluate Seminarians</h2>
                        <?php if ($year_level_filter !== '') { ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8]">
                                <i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($year_level_filter, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold <?php echo ($total_all > 0 && $total_evaluated === $total_all) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8]'; ?>">
                            <i class="fas <?php echo ($total_all > 0 && $total_evaluated === $total_all) ? 'fa-circle-check' : 'fa-chart-pie'; ?>"></i>
                            <?php echo $total_evaluated; ?> / <?php echo $total_all; ?> Evaluated
                        </span>
                    </div>
                    <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                        Assigned seminarians<?php echo $year_level_filter !== '' ? (' in ' . htmlspecialchars($year_level_filter, ENT_QUOTES, 'UTF-8')) : ''; ?>
                        <?php if ($total_pending > 0) { ?>
                            &middot; <span class="text-[#59443F] font-bold"><?php echo $total_pending; ?> pending</span>
                        <?php } ?>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <?php if ($total_all > 0) { ?>
                <div class="inline-flex items-center bg-[#F8EEEE] p-1 rounded-2xl border border-[#E8D5D5]" role="tablist" aria-label="Filter evaluation status">
                    <?php
                    $tabs = [
                        'all' => 'All (' . $total_all . ')',
                        'pending' => 'Pending (' . $total_pending . ')',
                        'evaluated' => 'Evaluated (' . $total_evaluated . ')',
                    ];
                    foreach ($tabs as $tab_key => $tab_label) {
                        $is_active_tab = $status_filter === $tab_key;
                        $tab_url = eval_build_list_url($list_file, ['batch_status' => $tab_key, 'page' => 1]);
                    ?>
                        <a href="<?php echo htmlspecialchars($tab_url, ENT_QUOTES, 'UTF-8'); ?>"
                           data-eval-ajax-link="1"
                           class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo $is_active_tab ? 'bg-[#59443F] text-white shadow-sm' : 'text-[#8B7168] hover:text-[#59443F]'; ?>">
                            <?php echo htmlspecialchars($tab_label, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php } ?>
                </div>
                <?php } ?>

                <form method="get" action="<?php echo htmlspecialchars($list_file, ENT_QUOTES, 'UTF-8'); ?>"
                      id="evalYearLevelFilterForm"
                      class="capstone-form-row capstone-filter-bar inline-flex flex-wrap items-center gap-2 m-0 p-0 bg-transparent border-0 shadow-none"
                      style="margin:0;padding:0;background:transparent;border:none;box-shadow:none;display:inline-flex;align-items:center;gap:0.5rem;">
                    <?php if (isset($_GET['archived']) && (string)$_GET['archived'] === '1') { ?>
                        <input type="hidden" name="archived" value="1">
                    <?php } ?>
                    <label for="yearLevelFilter" class="text-xs font-extrabold uppercase tracking-wider text-[#8B7168] m-0">Year Level</label>
                    <select name="year_level" id="yearLevelFilter"
                            aria-label="Year Level"
                            class="px-3.5 py-2 text-xs font-bold text-[#59443F] bg-[#FDF9F9] border border-[#E2C9C8] rounded-xl focus:outline-none focus:border-[#59443F] cursor-pointer"
                            style="width:auto;min-width:175px;margin:0;">
                        <?php foreach (eval_year_level_filter_options() as $yl_opt) { ?>
                            <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yl_opt === $year_level_filter ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="px-3.5 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-xs transition-all cursor-pointer" style="width:auto;margin:0;">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <?php if ($year_level_filter !== '') { ?>
                        <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['year_level' => null, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                           id="evalYearLevelResetBtn"
                           data-eval-ajax-link="1"
                           class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold"
                           style="text-decoration:none;width:auto;margin:0;"
                           title="Reset Year Level Filter">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    <?php } ?>
                </form>
            </div>
        </div>

        <?php if ($total_all === 0) { ?>
            <div class="mt-5 rounded-2xl bg-[#F8EEEE] border border-[#E8D5D5] px-5 py-6 text-center">
                <i class="fas fa-users-slash text-2xl text-[#8B7168] mb-2"></i>
                <?php if ($year_level_filter !== '') { ?>
                    <p class="text-sm font-bold text-[#59443F] m-0">No seminarians found in <?php echo htmlspecialchars($year_level_filter, ENT_QUOTES, 'UTF-8'); ?>.</p>
                    <p class="text-xs font-medium text-[#8B7168] mt-1 mb-0">Select a different Year Level or reset the filter to view all assigned seminarians.</p>
                <?php } else { ?>
                    <p class="text-sm font-bold text-[#59443F] m-0">No seminarians assigned to your account yet.</p>
                    <p class="text-xs font-medium text-[#8B7168] mt-1 mb-0">Once the Rector or Registrar assigns seminarians to you, they will appear here for evaluation.</p>
                <?php } ?>
            </div>
        <?php } else { ?>
            <!-- Group Selection Form -->
            <form method="get" action="<?php echo htmlspecialchars($form_url_base, ENT_QUOTES, 'UTF-8'); ?>"
                  id="<?php echo htmlspecialchars($form_uid, ENT_QUOTES, 'UTF-8'); ?>"
                  style="margin:0;padding:0;background:transparent;border:none;box-shadow:none;">
                <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?>">

                <?php if ($can_evaluate && count($rows) > 0) { ?>
                <div class="mt-4 mb-2 px-4 py-3 rounded-2xl bg-[#FDF8F8] border border-[#EFE2E1] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <label for="<?php echo htmlspecialchars($select_all_id, ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex items-center gap-3 cursor-pointer select-none m-0">
                        <input type="checkbox"
                               id="<?php echo htmlspecialchars($select_all_id, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $page_pending_count === 0 ? 'disabled' : ''; ?>
                               class="w-4.5 h-4.5 rounded-md border-2 border-[#D6A6A6] accent-[#59443F] cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                               style="width:1.15rem;height:1.15rem;accent-color:#59443F;">
                        <span class="text-xs font-extrabold text-[#59443F]">
                            Select All<?php echo $page_pending_count > 0 ? ' Pending on Page (' . $page_pending_count . ')' : ' (None Pending on Page)'; ?>
                        </span>
                    </label>

                    <button type="submit"
                            id="<?php echo htmlspecialchars($submit_btn_id, ENT_QUOTES, 'UTF-8'); ?>"
                            disabled
                            style="width:auto;"
                            class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-extrabold shadow-sm transition-all opacity-50 cursor-not-allowed">
                        <i class="fas fa-users"></i>
                        <span>Evaluate Selected (<span class="eval-selected-count">0</span>)</span>
                    </button>
                </div>
                <?php } ?>

                <?php if (empty($rows)) { ?>
                    <div class="mt-4 rounded-2xl bg-[#F8EEEE] border border-[#E8D5D5] px-5 py-6 text-center">
                        <p class="text-xs font-bold text-[#8B7168] m-0">No seminarians match your current filter or search.</p>
                    </div>
                <?php } else { ?>
                    <div class="mt-2 divide-y divide-[#F3E4E4]">
                        <?php foreach ($rows as $row) {
                            $sid = (int)$row['seminarian_id'];
                            $name = (string)$row['full_name'];
                            $student_no = (string)$row['student_no'];
                            $meta_parts = array_filter([(string)$row['program'], (string)$row['year_level']], fn($v) => trim($v) !== '');
                            $meta_line = !empty($meta_parts) ? implode(' · ', $meta_parts) : 'Seminarian';
                            $is_evaluated = !empty($row['evaluated']);
                            $is_graduated = !empty($row['is_graduated'])
                                || in_array(strtolower((string)($row['status'] ?? '')), ['graduated', 'completed'], true);
                            $eval_id = (int)$row['evaluation_id'];
                            $avg = $row['average_score'];
                            $eval_url = $form_url_base . '?student_id=' . $sid
                                . '&academic_year=' . urlencode($academic_year)
                                . '&semester=' . urlencode($semester);
                        ?>
                        <div class="py-3.5 px-3 rounded-2xl hover:bg-[#FDF9F9] transition-colors flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="flex items-center gap-3.5 min-w-0">
                                <?php if ($can_evaluate) { ?>
                                    <?php if ($is_graduated) { ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-gray-100 text-gray-500 border border-gray-200 shrink-0 cursor-not-allowed"
                                              title="Graduated / Completed — evaluation disabled">
                                            <i class="fas fa-user-graduate text-[11px]"></i>
                                        </span>
                                    <?php } elseif ($is_evaluated) { ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-200 shrink-0"
                                              title="Already evaluated — group selection disabled">
                                            <i class="fas fa-check text-[11px]"></i>
                                        </span>
                                    <?php } else { ?>
                                        <input type="checkbox"
                                               name="batch_ids[]"
                                               value="<?php echo $sid; ?>"
                                               aria-label="Select <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?> for group evaluation"
                                               class="eval-sem-checkbox shrink-0 cursor-pointer"
                                               style="width:1.15rem;height:1.15rem;accent-color:#59443F;">
                                    <?php } ?>
                                <?php } ?>

                                <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] border border-[#E2C9C8] flex items-center justify-center text-xs font-extrabold text-[#59443F] shrink-0">
                                    <?php echo htmlspecialchars(eval_initials($name), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-extrabold text-[#59443F] truncate"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-lg bg-[#F8EEEE] text-[#8B7168] text-[11px] font-bold border border-[#EAD7D6]">
                                            <?php echo htmlspecialchars($student_no, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <div class="text-xs font-semibold text-[#8B7168] mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span><?php echo htmlspecialchars($meta_line, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($is_graduated && !$is_evaluated) { ?>
                                            <span class="text-[#D6A6A6]">&bull;</span>
                                            <span class="text-gray-500 font-bold">Graduated / Program Completed</span>
                                        <?php } elseif ($is_evaluated) { ?>
                                            <span class="text-[#D6A6A6]">&bull;</span>
                                            <span class="text-emerald-700 font-bold">
                                                Avg: <?php echo $avg !== null ? htmlspecialchars(number_format((float)$avg, 2), ENT_QUOTES, 'UTF-8') : '-'; ?>
                                                <?php if ($row['rating_label'] !== '') { ?>
                                                    (<?php echo htmlspecialchars($row['rating_label'], ENT_QUOTES, 'UTF-8'); ?>)
                                                <?php } ?>
                                            </span>
                                            <?php if ($row['evaluation_date'] !== '') { ?>
                                                <span class="text-[#8B7168]">on <?php echo htmlspecialchars($row['evaluation_date'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span class="text-[#D6A6A6]">&bull;</span>
                                            <span class="text-amber-700 font-bold">Pending evaluation</span>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0 sm:justify-end">
                                <?php if ($is_graduated) { ?>
                                    <?php if ($is_evaluated && $eval_id > 0) { ?>
                                        <a href="<?php echo htmlspecialchars($cfg['report_file'] . '?evaluation_id=' . $eval_id, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[#FDF8F8] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors"
                                           title="View historical evaluation report">
                                            <i class="fas fa-file-lines"></i> Report
                                        </a>
                                    <?php } ?>
                                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-medium inline-flex items-center gap-1.5 border border-gray-200 cursor-not-allowed select-none"
                                          title="Cannot submit evaluation: This student has already graduated/completed the program.">
                                        <i class="fas fa-user-graduate text-[11px]"></i> Graduated
                                    </span>
                                <?php } elseif ($is_evaluated) { ?>
                                    <?php if ($eval_id > 0) { ?>
                                        <a href="<?php echo htmlspecialchars($cfg['report_file'] . '?evaluation_id=' . $eval_id, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[#FDF8F8] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors"
                                           title="View evaluation report">
                                            <i class="fas fa-file-lines"></i> Report
                                        </a>
                                    <?php } ?>
                                    <span class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-extrabold cursor-default"
                                          title="This seminarian has already been evaluated for the selected term">
                                        <i class="fas fa-circle-check"></i> Already Evaluated
                                    </span>
                                <?php } elseif ($can_evaluate) { ?>
                                    <a href="<?php echo htmlspecialchars($eval_url, ENT_QUOTES, 'UTF-8'); ?>"
                                       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-sm transition-colors">
                                        <i class="fas fa-clipboard-check"></i> Evaluate
                                    </a>
                                <?php } else { ?>
                                    <span class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-100 text-slate-500 border border-slate-200 text-xs font-bold">
                                        <i class="fas fa-clock"></i> Pending
                                    </span>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </form>

            <!-- Pagination Footer -->
            <?php if ($filtered_total > 0) { ?>
            <div class="mt-5 pt-4 border-t border-[#F3E4E4] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="text-xs font-semibold text-[#8B7168]">
                    Showing <strong class="text-[#59443F]"><?php echo $start_idx; ?>&ndash;<?php echo $end_idx; ?></strong>
                    of <strong class="text-[#59443F]"><?php echo $filtered_total; ?></strong> seminarians
                    (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                </div>

                <?php if ($total_pages > 1) {
                    $prev_page = max(1, $current_page - 1);
                    $next_page = min($total_pages, $current_page + 1);
                    $win_start = max(1, $current_page - 2);
                    $win_end = min($total_pages, $current_page + 2);
                ?>
                <nav class="flex items-center gap-1.5 flex-wrap" aria-label="Seminarian list pagination">
                    <?php if ($current_page > 1) { ?>
                        <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['page' => $prev_page]), ENT_QUOTES, 'UTF-8'); ?>"
                           data-eval-ajax-link="1"
                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-[#FDF9F9] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                            <i class="fas fa-chevron-left text-[10px]"></i> Previous
                        </a>
                    <?php } else { ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-[#FAF6F6] text-[#C5B0AC] border border-[#EFE3E3] text-xs font-bold cursor-not-allowed">
                            <i class="fas fa-chevron-left text-[10px]"></i> Previous
                        </span>
                    <?php } ?>

                    <?php if ($win_start > 1) { ?>
                        <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                           data-eval-ajax-link="1"
                           class="inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-xl bg-[#FDF9F9] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">1</a>
                        <?php if ($win_start > 2) { ?>
                            <span class="px-1 text-xs font-bold text-[#8B7168]">&hellip;</span>
                        <?php } ?>
                    <?php } ?>

                    <?php for ($p = $win_start; $p <= $win_end; $p++) {
                        if ($p === $current_page) { ?>
                            <span aria-current="page"
                                  class="inline-flex items-center justify-center min-w-[2rem] h-8 px-2.5 rounded-xl bg-[#59443F] text-white border border-[#59443F] text-xs font-extrabold shadow-sm">
                                <?php echo $p; ?>
                            </span>
                        <?php } else { ?>
                            <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['page' => $p]), ENT_QUOTES, 'UTF-8'); ?>"
                               data-eval-ajax-link="1"
                               class="inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-xl bg-[#FDF9F9] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                                <?php echo $p; ?>
                            </a>
                        <?php }
                    } ?>

                    <?php if ($win_end < $total_pages) {
                        if ($win_end < $total_pages - 1) { ?>
                            <span class="px-1 text-xs font-bold text-[#8B7168]">&hellip;</span>
                        <?php } ?>
                        <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['page' => $total_pages]), ENT_QUOTES, 'UTF-8'); ?>"
                           data-eval-ajax-link="1"
                           class="inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-xl bg-[#FDF9F9] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                            <?php echo $total_pages; ?>
                        </a>
                    <?php } ?>

                    <?php if ($current_page < $total_pages) { ?>
                        <a href="<?php echo htmlspecialchars(eval_build_list_url($list_file, ['page' => $next_page]), ENT_QUOTES, 'UTF-8'); ?>"
                           data-eval-ajax-link="1"
                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-[#FDF9F9] hover:bg-[#F8EEEE] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                            Next <i class="fas fa-chevron-right text-[10px]"></i>
                        </a>
                    <?php } else { ?>
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-[#FAF6F6] text-[#C5B0AC] border border-[#EFE3E3] text-xs font-bold cursor-not-allowed">
                            Next <i class="fas fa-chevron-right text-[10px]"></i>
                        </span>
                    <?php } ?>
                </nav>
                <?php } ?>
            </div>
            <?php } ?>
        <?php } ?>
    </section>

    <script>
    (function () {
        const panelUid = <?php echo json_encode($panel_uid); ?>;
        const formUid = <?php echo json_encode($form_uid); ?>;
        const selectAllId = <?php echo json_encode($select_all_id); ?>;
        const submitBtnId = <?php echo json_encode($submit_btn_id); ?>;
        const listFile = <?php echo json_encode($list_file); ?>;

        function syncSelectionUI() {
            const form = document.getElementById(formUid);
            if (!form) return;
            const selectAll = document.getElementById(selectAllId);
            const submitBtn = document.getElementById(submitBtnId);
            const countEl = submitBtn ? submitBtn.querySelector('.eval-selected-count') : null;
            const checkboxes = Array.from(form.querySelectorAll('.eval-sem-checkbox'));
            const checkedCount = checkboxes.filter(function (cb) { return cb.checked; }).length;
            if (countEl) {
                countEl.textContent = String(checkedCount);
            }
            if (submitBtn) {
                const enabled = checkedCount > 0;
                submitBtn.disabled = !enabled;
                submitBtn.classList.toggle('opacity-50', !enabled);
                submitBtn.classList.toggle('cursor-not-allowed', !enabled);
            }
            if (selectAll && checkboxes.length > 0) {
                selectAll.checked = checkedCount === checkboxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            }
        }

        syncSelectionUI();

        const guardKey = '__evalBatchAjaxBound_' + panelUid;
        if (window[guardKey]) return;
        window[guardKey] = true;

        let activeController = null;

        function fetchFilteredEvaluations(targetUrl) {
            const panel = document.getElementById(panelUid);
            if (!panel) return;

            const urlObj = new URL(targetUrl, window.location.href);
            const cleanUrl = new URL(urlObj.toString());
            cleanUrl.searchParams.delete('ajax');

            urlObj.searchParams.set('ajax', '1');

            if (activeController) {
                activeController.abort();
            }
            activeController = new AbortController();

            panel.style.opacity = '0.6';
            const evalBody = document.getElementById('evalBody');
            if (evalBody) evalBody.style.opacity = '0.6';

            fetch(urlObj.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                signal: activeController.signal
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || (!data.ok && !data.success)) return;
                if (typeof data.batch_html === 'string') {
                    const currentPanel = document.getElementById(panelUid);
                    if (currentPanel) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = data.batch_html.trim();
                        const nextPanel = tmp.querySelector('#' + panelUid) || tmp.firstElementChild;
                        if (nextPanel) {
                            currentPanel.replaceWith(nextPanel);
                        }
                    }
                }
                if (typeof data.recorded_html === 'string' && evalBody) {
                    evalBody.innerHTML = data.recorded_html;
                }
                const subEl = document.getElementById('evalYearLevelSubtitle');
                if (subEl && typeof data.year_level_label === 'string') {
                    subEl.textContent = data.year_level_label;
                }
                window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search);
                syncSelectionUI();
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                window.location.href = cleanUrl.toString();
            })
            .finally(function () {
                const p = document.getElementById(panelUid);
                if (p) p.style.opacity = '1';
                if (evalBody) evalBody.style.opacity = '1';
            });
        }

        document.addEventListener('change', function (e) {
            const target = e.target;
            if (!target) return;
            if (target.id === selectAllId) {
                const form = document.getElementById(formUid);
                if (!form) return;
                const checkboxes = Array.from(form.querySelectorAll('.eval-sem-checkbox'));
                const targetState = !!target.checked;
                checkboxes.forEach(function (cb) { cb.checked = targetState; });
                syncSelectionUI();
                return;
            }
            if (target.classList && target.classList.contains('eval-sem-checkbox')) {
                syncSelectionUI();
                return;
            }
            if (target.id === 'yearLevelFilter' && target.closest('#' + panelUid)) {
                const url = new URL(listFile, window.location.href);
                const curParams = new URLSearchParams(window.location.search);
                if (curParams.get('archived') === '1') url.searchParams.set('archived', '1');
                if (curParams.get('batch_status')) url.searchParams.set('batch_status', curParams.get('batch_status'));
                if (target.value) {
                    url.searchParams.set('year_level', target.value);
                } else {
                    url.searchParams.delete('year_level');
                }
                url.searchParams.set('page', '1');
                fetchFilteredEvaluations(url.toString());
            }
        });

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form) return;
            if (form.id === formUid) {
                const checkboxes = Array.from(form.querySelectorAll('.eval-sem-checkbox'));
                const checkedCount = checkboxes.filter(function (cb) { return cb.checked; }).length;
                if (checkedCount === 0) {
                    e.preventDefault();
                }
                return;
            }
            if (form.id === 'evalYearLevelFilterForm' && form.closest('#' + panelUid)) {
                e.preventDefault();
                const sel = form.querySelector('#yearLevelFilter');
                const url = new URL(listFile, window.location.href);
                const curParams = new URLSearchParams(window.location.search);
                if (curParams.get('archived') === '1') url.searchParams.set('archived', '1');
                if (curParams.get('batch_status')) url.searchParams.set('batch_status', curParams.get('batch_status'));
                if (sel && sel.value) {
                    url.searchParams.set('year_level', sel.value);
                } else {
                    url.searchParams.delete('year_level');
                }
                url.searchParams.set('page', '1');
                fetchFilteredEvaluations(url.toString());
            }
        });

        document.addEventListener('click', function (e) {
            const link = e.target && e.target.closest ? e.target.closest('#' + panelUid + ' [data-eval-ajax-link="1"]') : null;
            if (!link) return;
            const href = link.getAttribute('href');
            if (!href) return;
            e.preventDefault();
            fetchFilteredEvaluations(href);
        });
    })();
    </script>
    <?php
}

/**
 * Renders an EnformaSys soft-UI warning banner when creating a duplicate evaluation for a term.
 */
function eval_render_already_evaluated_banner(
    string $module,
    int $existing_eval_id,
    string $student_name,
    string $academic_year,
    string $semester
): void {
    $cfg = eval_module_config($module);
    $label = $student_name !== '' ? $student_name : 'Selected seminarian';
    ?>
    <div class="bg-white rounded-3xl shadow-lg border border-amber-200 p-6 mt-5" role="alert">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-700 shrink-0">
                    <i class="fas fa-shield-halved text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-[#59443F] m-0">Already Evaluated for This Term</h3>
                    <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                        <strong class="text-[#59443F]"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                        already has an active <?php echo htmlspecialchars($cfg['title'], ENT_QUOTES, 'UTF-8'); ?> for
                        AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester).
                        Duplicate evaluations for the same term are locked.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="<?php echo htmlspecialchars($cfg['list_file'] . '?academic_year=' . urlencode($academic_year) . '&semester=' . urlencode($semester), ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-sm transition-colors">
                    <i class="fas fa-arrow-left"></i> Back to Evaluate Seminarians
                </a>
                <a href="<?php echo htmlspecialchars($cfg['report_file'] . '?evaluation_id=' . $existing_eval_id, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                    <i class="fas fa-file-lines"></i> View Report
                </a>
                <a href="<?php echo htmlspecialchars($cfg['form_file'] . '?evaluation_id=' . $existing_eval_id, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white hover:bg-[#FDF8F8] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-colors">
                    <i class="fas fa-pen-to-square"></i> Edit Existing
                </a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Sends a 403 Forbidden status (and JSON payload if requested via AJAX/API) and returns
 * the canonical error message when a formator attempts to evaluate a graduated/completed student.
 */
function eval_block_graduated_submission(): string
{
    $msg = 'Cannot submit evaluation: This student has already graduated/completed the program.';
    if (!headers_sent()) {
        http_response_code(403);
    }
    $is_ajax = (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($is_ajax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'ok' => false,
            'success' => false,
            'status' => 403,
            'error' => $msg,
            'message' => $msg,
        ]);
        exit;
    }
    return $msg;
}

/**
 * Renders a non-editable warning card when a graduated/completed student is loaded on an evaluation form.
 */
function eval_render_graduated_blocked_banner(
    string $module,
    string $student_name,
    string $academic_year,
    string $semester
): void {
    $cfg = eval_module_config($module);
    $label = $student_name !== '' ? $student_name : 'Selected seminarian';
    ?>
    <div class="bg-white rounded-3xl shadow-lg border border-gray-200 p-6 mt-5" role="alert">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-2xl bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 shrink-0">
                    <i class="fas fa-user-graduate text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-base font-extrabold text-[#59443F] m-0">Evaluation Locked &mdash; Student Graduated</h3>
                        <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-medium">Graduated</span>
                    </div>
                    <p class="text-xs font-semibold text-[#8B7168] mt-1 mb-0">
                        Cannot submit evaluation: <strong class="text-[#59443F]"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                        has already graduated/completed the program.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="<?php echo htmlspecialchars($cfg['list_file'] . '?academic_year=' . urlencode($academic_year) . '&semester=' . urlencode($semester), ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-sm transition-colors">
                    <i class="fas fa-arrow-left"></i> Back to Evaluate Seminarians
                </a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Outputs inline validation alert banner placeholder, soft-red highlight CSS,
 * and smart scroll-to-missed-question JavaScript for an evaluation form.
 */
function eval_render_smart_validation_assets(string $form_id): void
{
    $banner_id = $form_id . '_validationBanner';
    $banner_text_id = $form_id . '_validationBannerText';
    ?>
    <style>
        .eval-validation-banner {
            display: none;
            margin: 0 0 1.25rem 0;
            padding: 1rem 1.25rem;
            border-radius: 1.25rem;
            background: #FFF1F2;
            border: 1.5px solid #FDA4AF;
            color: #9F1239;
            box-shadow: 0 10px 25px -10px rgba(225, 29, 72, 0.25);
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .eval-validation-banner.visible {
            display: flex;
        }
        tr.eval-question-row > td {
            transition: background-color 220ms ease, box-shadow 220ms ease;
        }
        tr.eval-question-row.eval-missed > td {
            background-color: #FFF1F2 !important;
            border-top: 2px solid #E11D48 !important;
            border-bottom: 2px solid #E11D48 !important;
            box-shadow: inset 4px 0 0 #E11D48;
        }
        tr.eval-question-row.eval-scroll-focus > td {
            animation: evalPulseHighlight 1.4s ease;
        }
        @keyframes evalPulseHighlight {
            0%, 100% { box-shadow: inset 4px 0 0 #E11D48; }
            45% { box-shadow: inset 4px 0 0 #E11D48, 0 0 0 4px rgba(225, 29, 72, 0.18); }
        }
        .field.eval-missed select,
        .field.eval-missed input,
        .field.eval-missed textarea,
        .eval-field-wrap.eval-missed select,
        .eval-field-wrap.eval-missed input,
        .eval-field-wrap.eval-missed textarea {
            border-color: #E11D48 !important;
            background-color: #FFF1F2 !important;
            box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12) !important;
        }
        .eval-q-error {
            display: none;
            margin-top: 0.55rem;
            padding: 0.38rem 0.75rem;
            border-radius: 0.75rem;
            background: #FFE4E6;
            color: #9F1239;
            font-size: 0.78rem;
            font-weight: 800;
            align-items: center;
            gap: 0.4rem;
        }
        .eval-missed .eval-q-error {
            display: inline-flex;
        }
    </style>

    <script>
    (function () {
        const form = document.getElementById(<?php echo json_encode($form_id); ?>);
        if (!form) return;

        form.noValidate = true;

        let banner = document.getElementById(<?php echo json_encode($banner_id); ?>);
        if (!banner) {
            banner = document.createElement('div');
            banner.id = <?php echo json_encode($banner_id); ?>;
            banner.className = 'eval-validation-banner';
            banner.setAttribute('role', 'alert');
            banner.innerHTML =
                '<div style="display:flex;align-items:center;gap:0.75rem;">' +
                    '<div style="width:2.25rem;height:2.25rem;border-radius:0.85rem;background:#FFE4E6;color:#E11D48;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                        '<i class="fas fa-circle-exclamation"></i>' +
                    '</div>' +
                    '<div>' +
                        '<div style="font-weight:800;font-size:0.9rem;color:#881337;">Incomplete Evaluation</div>' +
                        '<div id="' + <?php echo json_encode($banner_text_id); ?> + '" style="font-weight:600;font-size:0.8rem;color:#9F1239;">Please answer all highlighted items before saving.</div>' +
                    '</div>' +
                '</div>' +
                '<button type="button" data-jump-first-missed style="width:auto;padding:0.45rem 0.85rem;border-radius:0.75rem;background:#59443F;color:#fff;font-size:0.75rem;font-weight:800;border:none;cursor:pointer;">' +
                    '<i class="fas fa-arrow-down"></i> Go to Missed Question' +
                '</button>';
            form.insertBefore(banner, form.firstChild);
        }

        const bannerText = document.getElementById(<?php echo json_encode($banner_text_id); ?>);
        let lastFirstMissed = null;

        function scrollToMissed(el) {
            if (!el) return;
            lastFirstMissed = el;
            el.classList.remove('eval-scroll-focus');
            void el.offsetWidth;
            el.classList.add('eval-scroll-focus');
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusTarget = el.querySelector('select, input:not([type="hidden"]), textarea, .likert-btn');
            if (focusTarget && typeof focusTarget.focus === 'function') {
                setTimeout(function () {
                    try { focusTarget.focus({ preventScroll: true }); } catch (e) {}
                }, 350);
            }
        }

        const jumpBtn = banner.querySelector('[data-jump-first-missed]');
        if (jumpBtn) {
            jumpBtn.addEventListener('click', function () {
                const firstStillMissed = form.querySelector('.eval-missed') || lastFirstMissed;
                if (firstStillMissed) scrollToMissed(firstStillMissed);
            });
        }

        function refreshBannerVisibility() {
            const remaining = form.querySelectorAll('.eval-missed').length;
            if (remaining === 0) {
                banner.classList.remove('visible');
            } else if (bannerText) {
                bannerText.textContent = remaining + (remaining === 1 ? ' required item still needs an answer. ' : ' required items still need answers. ') + 'Highlighted below.';
            }
        }

        function validateFormAndCollectMissed() {
            const missed = [];

            form.querySelectorAll('.eval-missed').forEach(function (el) {
                el.classList.remove('eval-missed', 'eval-scroll-focus');
            });

            const checkNodes = form.querySelectorAll('.field, .eval-field-wrap, .eval-filter-field, .eval-question-row');
            checkNodes.forEach(function (node) {
                if (node.classList.contains('eval-question-row')) {
                    const ratingInput = node.querySelector('input[type="hidden"][name^="rating["]');
                    const ratingVal = ratingInput ? parseInt(ratingInput.value || '0', 10) : 0;
                    if (!ratingVal || ratingVal < 1 || ratingVal > 5) {
                        node.classList.add('eval-missed');
                        missed.push(node);
                    }
                } else {
                    const input = node.querySelector('select[required], input[required], textarea[required]');
                    if (input && String(input.value || '').trim() === '') {
                        node.classList.add('eval-missed');
                        missed.push(node);
                    }
                }
            });

            return missed;
        }

        function runValidationAndScroll() {
            const missed = validateFormAndCollectMissed();
            if (missed.length > 0) {
                banner.classList.add('visible');
                if (bannerText) {
                    bannerText.textContent = missed.length + (missed.length === 1 ? ' required item was missed. ' : ' required items were missed. ') + 'Scrolling to the first unanswered item.';
                }
                scrollToMissed(missed[0]);
                return false;
            }
            refreshBannerVisibility();
            return true;
        }

        form.__enformasysValidate = runValidationAndScroll;

        form.addEventListener('submit', function (e) {
            if (!runValidationAndScroll()) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        form.addEventListener('click', function (e) {
            const btn = e.target.closest('.likert-btn');
            if (!btn) return;
            const wrap = btn.closest('.likert-btns');
            if (wrap) {
                const qid = wrap.getAttribute('data-qid');
                const val = btn.getAttribute('data-value');
                const input = qid ? document.getElementById('rating_' + qid) : null;
                if (input) input.value = val;
                wrap.querySelectorAll('.likert-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
            }
            const row = btn.closest('.eval-question-row');
            if (row) {
                row.classList.remove('eval-missed', 'eval-scroll-focus');
                refreshBannerVisibility();
            }
        });

        form.addEventListener('input', clearFieldError);
        form.addEventListener('change', clearFieldError);

        function clearFieldError(e) {
            const wrap = e.target.closest('.field, .eval-field-wrap, .eval-filter-field');
            if (wrap && String(e.target.value || '').trim() !== '') {
                wrap.classList.remove('eval-missed', 'eval-scroll-focus');
                refreshBannerVisibility();
            }
        }
    })();
    </script>
    <?php
}

/**
 * Renders a compact, horizontally wrapping rating scale legend strip
 * optionally paired with the seminarian report-access badge.
 *
 * @param array<int,string>|null $labels
 */
function eval_render_rating_scale_strip(
    ?array $labels = null,
    ?bool $student_view_enabled = null,
    string $student_label = '',
    string $title = 'Rating Scale'
): void {
    $scale = $labels ?? [
        5 => 'Outstanding',
        4 => 'Very Good',
        3 => 'Satisfactory',
        2 => 'Needs Improvement',
        1 => 'Poor',
    ];
    krsort($scale);
    ?>
    <div class="eval-scale-bar">
        <div class="eval-scale-meta">
            <span class="eval-scale-title"><i class="fas fa-circle-info" aria-hidden="true"></i> <?php echo htmlspecialchars($title); ?></span>
            <?php if ($student_label !== '') { ?>
                <span>&middot; Selected: <strong style="color:#0f172a;"><?php echo htmlspecialchars($student_label); ?></strong></span>
            <?php } ?>
            <?php if ($student_view_enabled !== null) { ?>
                <span>&middot; Student view: <?php echo $student_view_enabled ? "<span class='badge badge-success'>Enabled</span>" : "<span class='badge badge-warning'>Disabled</span>"; ?></span>
            <?php } ?>
        </div>
        <div class="eval-scale-strip" role="list" aria-label="<?php echo htmlspecialchars($title); ?>">
            <?php foreach ($scale as $score => $desc) { ?>
                <span class="eval-scale-pill" role="listitem">
                    <span class="eval-scale-num"><?php echo (int)$score; ?></span>
                    <span>&ndash; <?php echo htmlspecialchars((string)$desc); ?></span>
                </span>
            <?php } ?>
        </div>
    </div>
    <?php
}

/**
 * Default academic semester matching all four formation evaluation modules.
 */
function eval_default_semester(): string {
    if (function_exists('get_active_semester')) {
        $active = function_exists('enformasys_normalize_semester')
            ? enformasys_normalize_semester((string)get_active_semester())
            : trim((string)get_active_semester());
        if (in_array($active, ['1st', '2nd', 'Summer'], true)) {
            return $active;
        }
    }
    return '1st';
}

/**
 * Map a session role name to the whitelisted evaluation module key.
 */
function eval_role_to_module(string $role): ?string {
    $map = [
        'AcademicFormator' => 'academic',
        'HumanFormator' => 'human',
        'PastoralFormator' => 'pastoral',
        'SpiritualDirector' => 'spiritual',
    ];
    return $map[$role] ?? null;
}

/**
 * Count pending active-term evaluations assigned to the logged-in Formator.
 */
function eval_count_pending_for_role(
    mysqli $conn,
    string $role,
    int $user_id,
    ?string $academic_year = null,
    ?string $semester = null
): int {
    $module = eval_role_to_module($role);
    if ($module === null || $user_id <= 0) {
        return 0;
    }

    $cfg = eval_module_config($module);
    if (!$cfg) {
        return 0;
    }

    $ay = ($academic_year !== null && trim($academic_year) !== '')
        ? trim($academic_year)
        : (function_exists('get_current_academic_year') ? (string)get_current_academic_year() : '');
    if ($ay === '') {
        $res_ay = @$conn->query("SELECT label FROM academic_years WHERE is_current = 1 AND archived_at IS NULL LIMIT 1");
        if ($res_ay && ($row_ay = $res_ay->fetch_assoc())) {
            $ay = trim((string)($row_ay['label'] ?? ''));
        }
        if ($ay === '') {
            $y = (int)date('Y');
            $start = ((int)date('n') >= 6) ? $y : ($y - 1);
            $ay = $start . '-' . ($start + 1);
        }
    }
    $sem = ($semester !== null && trim($semester) !== '')
        ? trim($semester)
        : eval_default_semester();

    if ($ay === '' || $sem === '') {
        return 0;
    }

    $assign_type = $cfg['assignment_type'];
    $table = $cfg['table'];
    $pk_col = $cfg['pk_col'];
    $student_col = $cfg['student_col'];

    $sql = "SELECT COUNT(*) AS pending_cnt
        FROM seminarian_assignments sa
        JOIN seminarians s ON s.id = sa.seminarian_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN (
            SELECT {$student_col} AS sid, MAX({$pk_col}) AS latest_eval_id
            FROM {$table}
            WHERE academic_year = ?
              AND semester = ?
              AND archived_at IS NULL
            GROUP BY {$student_col}
        ) ev_map ON ev_map.sid = s.id
        WHERE sa.assignment_type = ?
          AND sa.assigned_user_id = ?
          AND sa.active = 1
          AND u.role = 'Student'
          AND ev_map.latest_eval_id IS NULL";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('sssi', $ay, $sem, $assign_type, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['pending_cnt'] ?? 0);
}

/**
 * Fetch enriched seminarian profile metadata for Evaluation Report pages.
 */
function eval_fetch_report_profile(mysqli $conn, int $seminarian_id): array {
    $fallback = [
        'seminarian_id' => $seminarian_id,
        'student_no' => $seminarian_id > 0 ? ('SEM-' . str_pad((string)$seminarian_id, 4, '0', STR_PAD_LEFT)) : '-',
        'full_name' => '',
        'username' => '',
        'year_level' => '',
        'program' => '',
    ];
    if ($seminarian_id <= 0) {
        return $fallback;
    }

    $stmt = $conn->prepare("SELECT s.id AS seminarian_id,
            s.year_level,
            s.program,
            u.username,
            COALESCE(NULLIF(TRIM(up.full_name), ''), NULLIF(TRIM(stu.full_name), ''), u.username) AS full_name,
            COALESCE(NULLIF(TRIM(stu.student_no), ''), NULLIF(TRIM(up.student_id), ''), CONCAT('SEM-', LPAD(s.id, 4, '0'))) AS student_no
        FROM seminarians s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN students stu ON stu.user_id = u.id
        WHERE s.id = ?
        LIMIT 1");
    if (!$stmt) {
        return $fallback;
    }
    $stmt->bind_param('i', $seminarian_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return $fallback;
    }
    return [
        'seminarian_id' => (int)($row['seminarian_id'] ?? $seminarian_id),
        'student_no' => trim((string)($row['student_no'] ?? $fallback['student_no'])),
        'full_name' => trim((string)($row['full_name'] ?? '')),
        'username' => trim((string)($row['username'] ?? '')),
        'year_level' => trim((string)($row['year_level'] ?? '')),
        'program' => trim((string)($row['program'] ?? '')),
    ];
}

if (!function_exists('getFormationInterpretation')) {
    /**
     * Unified 5-point formation rating interpretation scale for both
     * individual pillar scores and consolidated overall formation averages.
     *
     * Scale:
     *   4.50 - 5.00 => Outstanding       (bg-emerald-100 text-emerald-700)
     *   3.50 - 4.49 => Very Good         (bg-green-100 text-green-700)
     *   2.50 - 3.49 => Satisfactory      (bg-blue-100 text-blue-700)
     *   1.50 - 2.49 => Needs Improvement (bg-amber-100 text-amber-700)
     *   1.00 - 1.49 => Poor              (bg-red-100 text-red-700)
     *   null / <= 0 => Pending           (bg-gray-100 text-gray-600)
     *
     * @param mixed $score
     * @return array<string, mixed>
     */
    function getFormationInterpretation($score): array {
        if ($score === null || $score === '' || !is_numeric($score) || (float)$score <= 0) {
            return [
                'key' => 'pending',
                'label' => 'Pending',
                'badge_class' => 'bg-gray-100 text-gray-600',
                'pill' => 'bg-gray-100 text-gray-600 border-gray-200',
                'dot' => 'bg-gray-400',
                'badge_bg' => '#f3f4f6',
                'badge_text' => '#4b5563',
                'badge_border' => '#e5e7eb',
                'badge_icon' => 'fa-hourglass-half',
                'range' => 'Incomplete',
            ];
        }

        $val = round((float)$score, 2, PHP_ROUND_HALF_UP);

        if ($val >= 4.50) {
            return [
                'key' => 'outstanding',
                'label' => 'Outstanding',
                'badge_class' => 'bg-emerald-100 text-emerald-700',
                'pill' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                'dot' => 'bg-emerald-500',
                'badge_bg' => '#d1fae5',
                'badge_text' => '#047857',
                'badge_border' => '#a7f3d0',
                'badge_icon' => 'fa-award',
                'range' => '4.50 - 5.00',
            ];
        }
        if ($val >= 3.50) {
            return [
                'key' => 'very_good',
                'label' => 'Very Good',
                'badge_class' => 'bg-green-100 text-green-700',
                'pill' => 'bg-green-100 text-green-700 border-green-200',
                'dot' => 'bg-green-500',
                'badge_bg' => '#dcfce7',
                'badge_text' => '#15803d',
                'badge_border' => '#bbf7d0',
                'badge_icon' => 'fa-circle-check',
                'range' => '3.50 - 4.49',
            ];
        }
        if ($val >= 2.50) {
            return [
                'key' => 'satisfactory',
                'label' => 'Satisfactory',
                'badge_class' => 'bg-blue-100 text-blue-700',
                'pill' => 'bg-blue-100 text-blue-700 border-blue-200',
                'dot' => 'bg-blue-500',
                'badge_bg' => '#dbeafe',
                'badge_text' => '#1d4ed8',
                'badge_border' => '#bfdbfe',
                'badge_icon' => 'fa-thumbs-up',
                'range' => '2.50 - 3.49',
            ];
        }
        if ($val >= 1.50) {
            return [
                'key' => 'needs_improvement',
                'label' => 'Needs Improvement',
                'badge_class' => 'bg-amber-100 text-amber-700',
                'pill' => 'bg-amber-100 text-amber-700 border-amber-200',
                'dot' => 'bg-amber-500',
                'badge_bg' => '#fef3c7',
                'badge_text' => '#b45309',
                'badge_border' => '#fde68a',
                'badge_icon' => 'fa-triangle-exclamation',
                'range' => '1.50 - 2.49',
            ];
        }

        return [
            'key' => 'poor',
            'label' => 'Poor',
            'badge_class' => 'bg-red-100 text-red-700',
            'pill' => 'bg-red-100 text-red-700 border-red-200',
            'dot' => 'bg-red-500',
            'badge_bg' => '#fee2e2',
            'badge_text' => '#b91c1c',
            'badge_border' => '#fecaca',
            'badge_icon' => 'fa-circle-exclamation',
            'range' => '1.00 - 1.49',
        ];
    }
}

/**
 * Map evaluation interpretation or growth status text to soft badge classes.
 */
function eval_badge_tone_classes(string $label): array {
    $norm = strtolower(trim($label));
    if ($norm === '' || $norm === '-') {
        return [
            'pill' => 'bg-gray-100 text-gray-600 border-gray-200',
            'dot' => 'bg-gray-400',
        ];
    }
    if ($norm === 'pending' || $norm === 'pending evaluation') {
        return [
            'pill' => 'bg-gray-100 text-gray-600 border-gray-200',
            'dot' => 'bg-gray-400',
        ];
    }
    if (
        str_contains($norm, 'poor')
        || str_contains($norm, 'unsatisfactory')
        || str_contains($norm, 'serious')
        || str_contains($norm, 'concern')
        || str_contains($norm, 'declin')
        || str_contains($norm, 'not recommended')
        || str_contains($norm, 'intervention')
        || str_contains($norm, 'regression')
        || str_contains($norm, 'at risk')
    ) {
        return [
            'pill' => 'bg-red-100 text-red-700 border-red-200',
            'dot' => 'bg-red-500',
        ];
    }
    if (
        str_contains($norm, 'needs improvement')
        || str_contains($norm, 'fair')
        || str_contains($norm, 'moderate')
        || str_contains($norm, 'conditional')
        || str_contains($norm, 'monitor')
    ) {
        return [
            'pill' => 'bg-amber-100 text-amber-700 border-amber-200',
            'dot' => 'bg-amber-500',
        ];
    }
    if (str_contains($norm, 'outstanding') || str_contains($norm, 'excellent') || str_contains($norm, 'growing') || str_contains($norm, 'improv') || str_contains($norm, 'promot') || str_contains($norm, 'recommended')) {
        return [
            'pill' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            'dot' => 'bg-emerald-500',
        ];
    }
    if (str_contains($norm, 'very good') || str_contains($norm, 'very satisfactory')) {
        return [
            'pill' => 'bg-green-100 text-green-700 border-green-200',
            'dot' => 'bg-green-500',
        ];
    }
    if (
        str_contains($norm, 'satisfactory')
        || str_contains($norm, 'good')
        || str_contains($norm, 'stable')
        || str_contains($norm, 'steady')
        || str_contains($norm, 'on track')
    ) {
        return [
            'pill' => 'bg-blue-100 text-blue-700 border-blue-200',
            'dot' => 'bg-blue-500',
        ];
    }
    return [
        'pill' => 'bg-[#F8EEEE] text-[#59443F] border-[#E2C9C8]',
        'dot' => 'bg-[#8B7168]',
    ];
}

/**
 * Determine semantic fill bar styling for a category score out of 5.0.
 */
function eval_progress_bar_fill_class(?float $score): string {
    if ($score === null) {
        return 'bg-[#D6B8B6]';
    }
    if ($score >= 4.50) {
        return 'bg-emerald-500';
    }
    if ($score >= 3.50) {
        return 'bg-teal-500';
    }
    if ($score >= 2.50) {
        return 'bg-amber-500';
    }
    return 'bg-rose-500';
}

/**
 * Render an EnformaSys soft-UI top banner for individual evaluation reports.
 */
function eval_render_report_header_banner(
    string $title,
    string $academic_year,
    string $semester,
    string $pillar_label,
    string $icon_class = 'fa-solid fa-file-lines'
): void {
    ?>
    <div class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6 print:bg-white print:shadow-none print:break-inside-avoid">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] border border-[#E6CECD] flex items-center justify-center text-[#59443F] shrink-0 print:bg-white print:shadow-none">
                    <i class="<?php echo htmlspecialchars($icon_class, ENT_QUOTES, 'UTF-8'); ?> text-xl"></i>
                </div>
                <div>
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                        <?php echo htmlspecialchars($pillar_label, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-[#59443F] m-0 mt-0.5 leading-tight">
                        <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                    </h1>
                </div>
            </div>
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-[#F8EEEE] border border-[#E6CECD] text-xs font-extrabold text-[#59443F] self-start sm:self-center shrink-0 print:bg-white print:shadow-none">
                <i class="fa-regular fa-calendar text-[#8B7168]"></i>
                <span>AY <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?> &middot; <?php echo htmlspecialchars($semester, ENT_QUOTES, 'UTF-8'); ?> Semester</span>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render the EnformaSys Seminarian Profile Card (avatar + CSS Grid metadata).
 *
 * Expected keys in $profile:
 * - name (string)
 * - student_no (string)
 * - username (string, optional)
 * - year_level (string, optional)
 * - program (string, optional)
 * - evaluation_date (string)
 * - evaluator_label (string, e.g. 'Human Formator')
 * - evaluator_name (string)
 * - extra_items (array of ['label' => string, 'value' => string], optional)
 */
function eval_render_report_profile_card(array $profile): void {
    $name = trim((string)($profile['name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($profile['username'] ?? 'Seminarian'));
    }
    $initials = eval_initials($name);
    $student_no = trim((string)($profile['student_no'] ?? '-'));
    $username = trim((string)($profile['username'] ?? ''));
    $year_level = trim((string)($profile['year_level'] ?? ''));
    $program = trim((string)($profile['program'] ?? ''));

    $program_parts = array_values(array_filter([$year_level, $program], static fn($v) => $v !== ''));
    $program_display = count($program_parts) > 0 ? implode(' · ', $program_parts) : '-';

    $eval_date_raw = trim((string)($profile['evaluation_date'] ?? ''));
    $eval_date_display = $eval_date_raw !== '' ? date('M d, Y', strtotime($eval_date_raw)) : '-';

    $evaluator_label = trim((string)($profile['evaluator_label'] ?? 'Assigned Evaluator'));
    $evaluator_name = trim((string)($profile['evaluator_name'] ?? '-'));
    if ($evaluator_name === '') {
        $evaluator_name = '-';
    }

    $grid_items = [
        ['label' => 'Student ID / No.', 'value' => $student_no !== '' ? $student_no : '-', 'icon' => 'fa-solid fa-id-card'],
        ['label' => 'Program & Year', 'value' => $program_display, 'icon' => 'fa-solid fa-graduation-cap'],
        ['label' => 'Evaluation Date', 'value' => $eval_date_display, 'icon' => 'fa-regular fa-calendar-check'],
        ['label' => $evaluator_label, 'value' => $evaluator_name, 'icon' => 'fa-solid fa-user-tie'],
    ];

    if (!empty($profile['extra_items']) && is_array($profile['extra_items'])) {
        foreach ($profile['extra_items'] as $extra) {
            if (!is_array($extra) || empty($extra['label'])) {
                continue;
            }
            $grid_items[] = [
                'label' => (string)$extra['label'],
                'value' => (string)($extra['value'] ?? '-'),
                'icon' => (string)($extra['icon'] ?? 'fa-solid fa-circle-info'),
            ];
        }
    }
    ?>
    <section class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6 print:bg-white print:shadow-none print:break-inside-avoid">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 border-b border-[#F3E4E4]">
            <div class="flex items-center gap-4 min-w-0">
                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#F8EEEE] to-[#EAD1D0] border-2 border-[#DFC2C1] text-[#59443F] flex items-center justify-center font-extrabold text-lg shrink-0 shadow-sm print:bg-white print:shadow-none"
                     aria-hidden="true">
                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="min-w-0">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                        Seminarian Profile
                    </div>
                    <h2 class="text-xl font-extrabold text-[#59443F] m-0 mt-0.5 truncate">
                        <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>
                    <div class="flex flex-wrap items-center gap-2 mt-1">
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-[#8B7168]">
                            <i class="fa-solid fa-hashtag text-[10px]"></i>
                            <?php echo htmlspecialchars($student_no !== '' ? $student_no : '-', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if ($username !== '') { ?>
                            <span class="text-[#D6B8B6]">&bull;</span>
                            <span class="text-xs font-semibold text-[#8B7168]">
                                @<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-4 gap-3.5 mt-5">
            <?php foreach ($grid_items as $cell) { ?>
                <div class="rounded-2xl bg-[#F8EEEE]/75 border border-[#EDE0E0] px-4 py-3.5 print:bg-white print:shadow-none print:break-inside-avoid">
                    <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                        <i class="<?php echo htmlspecialchars($cell['icon'], ENT_QUOTES, 'UTF-8'); ?> text-[11px] opacity-80"></i>
                        <span><?php echo htmlspecialchars($cell['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="text-sm font-extrabold text-[#59443F] mt-1.5 break-words">
                        <?php echo htmlspecialchars($cell['value'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    </section>
    <?php
}

/**
 * Render the EnformaSys Computed Results Dashboard Card:
 * - Bold Hero Metrics for Average Rating & Total Score
 * - Soft colored pill badges for Interpretation & Secondary Status (Growth Status / Recommendation)
 * - Dynamic Category Progress Bars scaled out of 5.0
 *
 * Expected keys in $metrics:
 * - average_rating (float|null)
 * - total_score (int|float|string|null)
 * - total_score_label (string, optional; defaults to 'Total Score')
 * - question_count (int, optional)
 * - interpretation_label (string, optional; defaults to 'Interpretation')
 * - interpretation_value (string)
 * - secondary_label (string, optional; defaults to 'Growth Status')
 * - secondary_value (string)
 * - secondary_subtext (string, optional; e.g., growth percentage delta)
 *
 * Expected items in $categories:
 * - ['label' => string, 'score' => float|null]
 */
function eval_render_report_results_section(array $metrics, array $categories): void {
    $avg_raw = $metrics['average_rating'] ?? null;
    $avg_num = ($avg_raw !== null && $avg_raw !== '' && is_numeric($avg_raw)) ? (float)$avg_raw : null;
    $avg_display = $avg_num !== null ? number_format($avg_num, 2) : '-';

    $total_raw = $metrics['total_score'] ?? null;
    $total_display = ($total_raw !== null && $total_raw !== '') ? (string)$total_raw : '-';
    $total_score_label = trim((string)($metrics['total_score_label'] ?? 'Total Score'));
    $question_count = (int)($metrics['question_count'] ?? 0);

    $interp_label = trim((string)($metrics['interpretation_label'] ?? 'Interpretation'));
    $interp_val = trim((string)($metrics['interpretation_value'] ?? ''));
    if ($interp_val === '') {
        $interp_val = '-';
    }
    $interp_tone = eval_badge_tone_classes($interp_val);

    $sec_label = trim((string)($metrics['secondary_label'] ?? 'Growth Status'));
    $sec_val = trim((string)($metrics['secondary_value'] ?? ''));
    if ($sec_val === '') {
        $sec_val = '-';
    }
    $sec_sub = trim((string)($metrics['secondary_subtext'] ?? ''));
    $sec_tone = eval_badge_tone_classes($sec_val);
    ?>
    <section class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6 print:bg-white print:shadow-none print:break-inside-avoid">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-4 border-b border-[#F3E4E4]">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-[#F8EEEE] border border-[#E6CECD] flex items-center justify-center text-[#59443F] shrink-0 print:bg-white print:shadow-none">
                    <i class="fa-solid fa-chart-column"></i>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold text-[#59443F] m-0">Computed Results</h2>
                    <p class="text-xs font-semibold text-[#8B7168] m-0">Summary metrics and category breakdown on a 5-point scale</p>
                </div>
            </div>
        </div>

        <!-- Hero Metrics & Status Badges -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 print:grid-cols-4 gap-4 mt-5">
            <!-- Hero Metric: Average Rating -->
            <div class="rounded-2xl bg-gradient-to-br from-[#F8EEEE] to-white border border-[#E6CECD] p-4 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                    Average Rating
                </div>
                <div class="flex items-baseline gap-1.5 mt-2">
                    <span class="text-4xl font-extrabold text-[#59443F] tabular-nums tracking-tight leading-none">
                        <?php echo htmlspecialchars($avg_display, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="text-sm font-bold text-[#8B7168]">/ 5.00</span>
                </div>
            </div>

            <!-- Hero Metric: Total Score -->
            <div class="rounded-2xl bg-gradient-to-br from-[#FDF9F9] to-white border border-[#E8D5D5] p-4 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                    <?php echo htmlspecialchars($total_score_label, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="flex items-baseline gap-2 mt-2">
                    <span class="text-4xl font-extrabold text-[#59443F] tabular-nums tracking-tight leading-none">
                        <?php echo htmlspecialchars($total_display, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($question_count > 0) { ?>
                        <span class="text-xs font-bold text-[#8B7168]">
                            / <?php echo (int)($question_count * 5); ?> pts
                        </span>
                    <?php } ?>
                </div>
            </div>

            <!-- Interpretation Pill Card -->
            <div class="rounded-2xl bg-[#FDF9F9] border border-[#E8D5D5] p-4 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                    <?php echo htmlspecialchars($interp_label, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="mt-2.5">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-extrabold <?php echo $interp_tone['pill']; ?> print:bg-white print:shadow-none">
                        <span class="w-2 h-2 rounded-full shrink-0 <?php echo $interp_tone['dot']; ?>"></span>
                        <span><?php echo htmlspecialchars($interp_val, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                </div>
            </div>

            <!-- Growth Status / Recommendation Pill Card -->
            <div class="rounded-2xl bg-[#FDF9F9] border border-[#E8D5D5] p-4 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                    <?php echo htmlspecialchars($sec_label, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-extrabold <?php echo $sec_tone['pill']; ?> print:bg-white print:shadow-none">
                        <span class="w-2 h-2 rounded-full shrink-0 <?php echo $sec_tone['dot']; ?>"></span>
                        <span><?php echo htmlspecialchars($sec_val, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <?php if ($sec_sub !== '') { ?>
                        <span class="text-xs font-bold text-[#8B7168]">
                            <?php echo htmlspecialchars($sec_sub, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- Category Scores Progress Bars -->
        <?php if (count($categories) > 0) { ?>
            <div class="mt-6 pt-5 border-t border-[#F3E4E4] print:break-inside-avoid">
                <div class="flex items-center justify-between gap-2 mb-4">
                    <h3 class="text-sm font-extrabold text-[#59443F] m-0 flex items-center gap-2">
                        <i class="fa-solid fa-bars-progress text-[#8B7168]"></i>
                        <span>Category Scores</span>
                    </h3>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                        0.00 &ndash; 5.00 Scale
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 print:grid-cols-2 gap-x-8 gap-y-4">
                    <?php foreach ($categories as $cat) {
                        $cat_name = trim((string)($cat['label'] ?? ''));
                        if ($cat_name === '') {
                            continue;
                        }
                        $raw_score = $cat['score'] ?? null;
                        $score_num = ($raw_score !== null && $raw_score !== '' && is_numeric($raw_score)) ? (float)$raw_score : null;
                        $pct = $score_num !== null ? min(100.0, max(0.0, ($score_num / 5.0) * 100.0)) : 0.0;
                        $score_text = $score_num !== null ? number_format($score_num, 2) : '-';
                        $fill_class = eval_progress_bar_fill_class($score_num);
                    ?>
                        <div class="rounded-2xl bg-[#FDF9F9] border border-[#EFE3E3] px-4 py-3 print:bg-white print:shadow-none print:break-inside-avoid">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <span class="text-xs sm:text-sm font-bold text-[#59443F]">
                                    <?php echo htmlspecialchars($cat_name, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="text-xs sm:text-sm font-extrabold text-[#59443F] tabular-nums shrink-0">
                                    <?php echo htmlspecialchars($score_text, ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="text-[11px] font-semibold text-[#8B7168]">/ 5.00</span>
                                </span>
                            </div>
                            <div class="w-full h-2.5 bg-[#EFE1E1] rounded-full overflow-hidden"
                                 role="progressbar"
                                 aria-label="<?php echo htmlspecialchars($cat_name . ' score', ENT_QUOTES, 'UTF-8'); ?>"
                                 aria-valuemin="0"
                                 aria-valuemax="5"
                                 aria-valuenow="<?php echo htmlspecialchars($score_num !== null ? number_format($score_num, 2, '.', '') : '0', ENT_QUOTES, 'UTF-8'); ?>"
                                 style="print-color-adjust: exact; -webkit-print-color-adjust: exact;">
                                <div class="h-full rounded-full transition-all duration-500 <?php echo $fill_class; ?>"
                                     style="width: <?php echo number_format($pct, 2, '.', ''); ?>%; print-color-adjust: exact; -webkit-print-color-adjust: exact;"></div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </section>
    <?php
}

/**
 * Returns whitelisted database column and UI metadata for a Formator role's
 * Seminarian Report Access portal, resolved strictly from the session role.
 */
function eval_report_access_config_for_role(?string $role): ?array
{
    static $map = [
        'AcademicFormator' => [
            'role' => 'AcademicFormator',
            'pillar' => 'Academic Formation Pillar',
            'page_title' => 'Academic Evaluation Access',
            'active_nav' => 'afe_seminarians',
            'view_col' => 'academic_view_enabled',
            'assignment_type' => 'Academic',
        ],
        'PastoralFormator' => [
            'role' => 'PastoralFormator',
            'pillar' => 'Pastoral Formation Pillar',
            'page_title' => 'Pastoral Evaluation Access',
            'active_nav' => 'pfe_seminarians',
            'view_col' => 'pastoral_eval_view_enabled',
            'assignment_type' => 'Pastoral',
        ],
        'SpiritualDirector' => [
            'role' => 'SpiritualDirector',
            'pillar' => 'Spiritual Formation Pillar',
            'page_title' => 'Spiritual Evaluation Access',
            'active_nav' => 'sfe_seminarians',
            'view_col' => 'spiritual_eval_view_enabled',
            'assignment_type' => 'Spiritual',
        ],
        'HumanFormator' => [
            'role' => 'HumanFormator',
            'pillar' => 'Human Formation Pillar',
            'page_title' => 'Human Evaluation Access',
            'active_nav' => 'hfe_seminarians',
            'view_col' => 'human_eval_view_enabled',
            'assignment_type' => 'Human',
        ],
    ];

    $clean = trim((string)($role ?? ''));
    return $map[$clean] ?? null;
}

/**
 * Fetches seminarians along with the current formator role's report access flag,
 * optionally filtered by canonical Year Level option.
 */
function eval_fetch_report_access_students(mysqli $conn, string $role, string $year_level_filter = ''): array
{
    $cfg = eval_report_access_config_for_role($role);
    if (!$cfg) {
        return [];
    }

    $col = $cfg['view_col']; // Safe allowlisted column from eval_report_access_config_for_role()
    $year_level_filter = eval_normalize_year_level_option($year_level_filter);
    $yl_aliases = eval_year_level_sql_aliases($year_level_filter);

    $students = [];
    $sql = "SELECT s.id, s.year_level, s.{$col}, u.username, up.full_name
        FROM seminarians s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE u.role = 'Student'";
    if (!empty($yl_aliases)) {
        $yl_ph = implode(',', array_fill(0, count($yl_aliases), '?'));
        $sql .= " AND s.year_level IN ({$yl_ph}) ORDER BY COALESCE(up.full_name, u.username) ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($yl_aliases)), ...$yl_aliases);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $students[] = $row;
                }
            }
            $stmt->close();
        }
    } else {
        $sql .= " ORDER BY COALESCE(up.full_name, u.username) ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $students[] = $row;
            }
        }
    }
    return $students;
}

/**
 * Render table body rows for Seminarian Report Access.
 */
function eval_render_report_access_rows(array $students, string $view_col, string $csrf, string $year_level_filter = ''): string
{
    ob_start();
    if (count($students) === 0) { ?>
        <tr>
            <td colspan="3" class="px-6 py-10 text-center">
                <div class="inline-flex flex-col items-center gap-1.5 text-[#8B7168]">
                    <i class="fas fa-users-slash text-xl opacity-75" aria-hidden="true"></i>
                    <span class="text-sm font-bold text-[#59443F]">No seminarians found<?php echo $year_level_filter !== '' ? ' in ' . htmlspecialchars($year_level_filter, ENT_QUOTES, 'UTF-8') : ''; ?></span>
                    <span class="text-xs font-medium"><?php echo $year_level_filter !== '' ? 'Select a different Year Level or reset the filter.' : 'Registered seminarians will appear here.'; ?></span>
                </div>
            </td>
        </tr>
    <?php } else {
        foreach ($students as $s) {
            $sid = (int)($s['id'] ?? 0);
            $full_name = trim((string)($s['full_name'] ?? ''));
            $username = trim((string)($s['username'] ?? ''));
            $name = $full_name !== '' ? $full_name : $username;
            $initials = eval_initials($name);
            $enabled = (int)($s[$view_col] ?? 0) === 1;
        ?>
            <tr class="s-row transition-colors duration-150 hover:bg-pink-50/50"
                data-seminarian-id="<?php echo $sid; ?>"
                data-enabled="<?php echo $enabled ? '1' : '0'; ?>"
                data-student-name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3.5">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#F8EEEE] to-[#EAD1D0] border border-[#DFC2C1] text-[#59443F] flex items-center justify-center font-extrabold text-xs shrink-0 shadow-2xs"
                             aria-hidden="true">
                            <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="min-w-0">
                            <div class="s-name font-semibold text-sm text-[#59443F] leading-snug">
                                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php if ($username !== '' && strcasecmp($username, $name) !== 0) { ?>
                                <div class="text-xs font-medium text-[#8B7168]">
                                    @<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <?php if ($enabled) { ?>
                        <span data-status-badge
                              class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 border border-green-200/80">
                            <span data-status-dot class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                            <span data-status-text>Enabled</span>
                        </span>
                    <?php } else { ?>
                        <span data-status-badge
                              class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-500 border border-gray-200/80">
                            <span data-status-dot class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                            <span data-status-text>Disabled</span>
                        </span>
                    <?php } ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right">
                    <form method="post"
                          class="js-report-access-form inline-flex items-center justify-end gap-2.5 m-0 p-0 bg-transparent border-0 shadow-none"
                          style="display: inline-flex; flex-direction: row; gap: 0.625rem; margin: 0; padding: 0; background: transparent; border: none; box-shadow: none;">
                        <input type="hidden" name="action" value="toggle_report_access">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="seminarian_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="new_value" value="<?php echo $enabled ? 0 : 1; ?>">
                        <button type="submit"
                                name="toggle_view"
                                value="1"
                                role="switch"
                                aria-checked="<?php echo $enabled ? 'true' : 'false'; ?>"
                                aria-label="<?php echo htmlspecialchars(($enabled ? 'Disable' : 'Enable') . ' report access for ' . $name, ENT_QUOTES, 'UTF-8'); ?>"
                                title="<?php echo $enabled ? 'Click to disable report access' : 'Click to enable report access'; ?>"
                                class="group relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-[#8B7168] focus-visible:ring-offset-2 <?php echo $enabled ? 'bg-[#8B7168] hover:bg-[#59443F]' : 'bg-gray-300 hover:bg-gray-400'; ?>">
                            <span class="sr-only" data-toggle-sr>
                                <?php echo $enabled ? 'Disable' : 'Enable'; ?> report access for <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span data-toggle-thumb
                                  aria-hidden="true"
                                  class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 ease-in-out <?php echo $enabled ? 'translate-x-5' : 'translate-x-0'; ?>"></span>
                        </button>
                    </form>
                </td>
            </tr>
        <?php }
    }
    return (string)ob_get_clean();
}

/**
 * Dynamically updates report view permission in `seminarians` according to the
 * authenticated Formator's session role (`$_SESSION['role']`).
 */
function eval_handle_report_access_update(
    mysqli $conn,
    string $role,
    int $seminarian_id,
    int $new_value
): array {
    $cfg = eval_report_access_config_for_role($role);
    if (!$cfg) {
        return [
            'ok' => false,
            'status' => 403,
            'error' => 'Unauthorized formator role.',
        ];
    }
    if ($seminarian_id <= 0) {
        return [
            'ok' => false,
            'status' => 400,
            'error' => 'Invalid seminarian ID.',
        ];
    }

    $enabled = ($new_value === 1) ? 1 : 0;
    $col = $cfg['view_col']; // Safe allowlisted column from eval_report_access_config_for_role()

    $stmt_chk = $conn->prepare("SELECT s.id, COALESCE(NULLIF(TRIM(up.full_name), ''), u.username) AS display_name
        FROM seminarians s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE s.id = ? AND u.role = 'Student'
        LIMIT 1");
    $stmt_chk->bind_param('i', $seminarian_id);
    $stmt_chk->execute();
    $sem_row = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();
    if (!$sem_row) {
        return [
            'ok' => false,
            'status' => 404,
            'error' => 'Seminarian record not found.',
        ];
    }

    $stmt = $conn->prepare("UPDATE seminarians SET {$col} = ? WHERE id = ?");
    $stmt->bind_param('ii', $enabled, $seminarian_id);
    $executed = $stmt->execute();
    $stmt->close();
    if (!$executed) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'Failed to update report access permission.',
        ];
    }

    $display_name = trim((string)($sem_row['display_name'] ?? ''));
    $state_word = $enabled === 1 ? 'enabled' : 'disabled';
    $message = $display_name !== ''
        ? "Report access {$state_word} for {$display_name}."
        : "Report access {$state_word}.";

    return [
        'ok' => true,
        'status' => 200,
        'seminarian_id' => $seminarian_id,
        'enabled' => $enabled === 1,
        'new_value' => $enabled,
        'next_value' => $enabled === 1 ? 0 : 1,
        'status_label' => $enabled === 1 ? 'Enabled' : 'Disabled',
        'column' => $col,
        'pillar' => $cfg['pillar'],
        'message' => $message,
    ];
}

/**
 * Handles both AJAX toggle submissions and standard POST form fallback for
 * whichever Formator role is currently logged in, then loads the student list.
 */
function eval_handle_report_access_request(mysqli $conn, string $role): array
{
    $cfg = eval_report_access_config_for_role($role);
    if (!$cfg) {
        return [
            'config' => null,
            'students' => [],
            'success' => null,
            'error' => 'Unauthorized formator role.',
        ];
    }

    $success = null;
    $error = null;

    $is_post = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
        && (isset($_POST['toggle_view']) || (($_POST['action'] ?? '') === 'toggle_report_access'));

    if ($is_post) {
        $is_ajax = (
            strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || (string)($_POST['ajax'] ?? '') === '1'
        );

        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if (!function_exists('verify_csrf_token') || !verify_csrf_token($csrf)) {
            if ($is_ajax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
                exit;
            }
            die('CSRF token validation failed.');
        }

        $sid = (int)($_POST['seminarian_id'] ?? 0);
        $val = (int)($_POST['new_value'] ?? 0);
        $result = eval_handle_report_access_update($conn, $role, $sid, $val);

        if ($is_ajax) {
            http_response_code((int)($result['status'] ?? ($result['ok'] ? 200 : 400)));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result);
            exit;
        }

        if ($result['ok']) {
            $success = (string)$result['message'];
        } else {
            $error = (string)($result['error'] ?? 'Failed to update report access.');
        }
    }

    $year_level_filter = eval_normalize_year_level_option($_GET['year_level'] ?? '');
    $students = eval_fetch_report_access_students($conn, $role, $year_level_filter);

    if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
        $view_col = $cfg['view_col'];
        $total_count = count($students);
        $enabled_count = 0;
        foreach ($students as $st) {
            if ((int)($st[$view_col] ?? 0) === 1) {
                $enabled_count++;
            }
        }
        $disabled_count = max(0, $total_count - $enabled_count);
        $csrf = (string)($_SESSION['csrf_token'] ?? '');
        $rows_html = eval_render_report_access_rows($students, $view_col, $csrf, $year_level_filter);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'success' => true,
            'year_level' => $year_level_filter,
            'year_level_label' => $year_level_filter !== '' ? $year_level_filter : 'All Year Levels',
            'total_count' => $total_count,
            'enabled_count' => $enabled_count,
            'disabled_count' => $disabled_count,
            'seminarians' => $students,
            'rows_html' => $rows_html,
        ]);
        exit;
    }

    return [
        'config' => $cfg,
        'students' => $students,
        'success' => $success,
        'error' => $error,
        'year_level_filter' => $year_level_filter,
    ];
}

/**
 * Render the EnformaSys soft-UI Seminarian Report Access table & controls.
 *
 * Supports progressive-enhancement AJAX toggles (without full page reloads) as
 * well as graceful POST fallback across Academic, Human, Pastoral, and
 * Spiritual Formator portals.
 */
function eval_render_report_access_page(
    array $students,
    string $view_col,
    ?string $success = null,
    string $pillar_label = '',
    ?string $error = null,
    ?string $ajax_url = null
): void {
    $total_count = count($students);
    $enabled_count = 0;
    foreach ($students as $st) {
        if ((int)($st[$view_col] ?? 0) === 1) {
            $enabled_count++;
        }
    }
    $disabled_count = max(0, $total_count - $enabled_count);
    $csrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($ajax_url === null) {
        $ajax_url = function_exists('capstone_url') ? capstone_url('modules/ajax.php') : 'ajax.php';
    }
    $year_level_options = eval_year_level_filter_options();
    $year_level_filter = eval_normalize_year_level_option($_GET['year_level'] ?? '');

    $initial_has_error = ($error !== null && $error !== '');
    $initial_has_success = (!$initial_has_error && $success !== null && $success !== '');
    $initial_banner_msg = $initial_has_error ? $error : ($initial_has_success ? $success : '');
    $banner_classes = $initial_has_error
        ? 'mb-6 rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3.5 flex items-center gap-3 shadow-sm'
        : 'mb-6 rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3.5 flex items-center gap-3 shadow-sm';
    if (!$initial_has_error && !$initial_has_success) {
        $banner_classes = 'hidden ' . $banner_classes;
    }
    ?>
    <div id="reportAccessRoot"
         class="content-body max-w-6xl mx-auto"
         data-ajax-url="<?php echo htmlspecialchars($ajax_url, ENT_QUOTES, 'UTF-8'); ?>"
         data-view-col="<?php echo htmlspecialchars($view_col, ENT_QUOTES, 'UTF-8'); ?>">
        <!-- Floating Header -->
        <div class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] border border-[#E6CECD] flex items-center justify-center text-[#59443F] shrink-0 shadow-2xs">
                        <i class="fas fa-eye text-lg" aria-hidden="true"></i>
                    </div>
                    <div>
                        <?php if ($pillar_label !== '') { ?>
                            <span class="inline-flex items-center text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">
                                <?php echo htmlspecialchars($pillar_label, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php } ?>
                        <h1 class="text-xl sm:text-2xl font-extrabold text-[#59443F] m-0 mt-0.5 leading-tight">
                            Seminarian Report Access
                        </h1>
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <span id="reportAccessEnabledPill" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                <span data-count-enabled><?php echo $enabled_count; ?></span> Enabled
                            </span>
                            <span id="reportAccessDisabledPill" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-500">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                <span data-count-disabled><?php echo $disabled_count; ?></span> Disabled
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Status / Confirmation Banner -->
        <div id="reportAccessStatusBanner"
             class="<?php echo $banner_classes; ?>"
             role="status"
             aria-live="polite">
            <div data-status-icon-wrap
                 class="w-8 h-8 rounded-xl <?php echo $initial_has_error ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'; ?> flex items-center justify-center shrink-0">
                <i class="fas <?php echo $initial_has_error ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> text-sm" aria-hidden="true"></i>
            </div>
            <span data-status-message class="text-sm font-bold <?php echo $initial_has_error ? 'text-rose-800' : 'text-emerald-800'; ?>">
                <?php echo htmlspecialchars($initial_banner_msg, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

        <!-- Floating Data Table Card -->
        <div class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] overflow-hidden">
            <div class="px-6 py-4 border-b border-[#F3E4E4] flex flex-wrap items-center justify-between gap-3 bg-white">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] border border-[#E6CECD] flex items-center justify-center text-[#59443F]">
                        <i class="fas fa-users text-xs" aria-hidden="true"></i>
                    </div>
                    <h2 class="text-base font-extrabold text-[#59443F] m-0">Seminarians</h2>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold bg-[#F8EEEE] text-[#59443F] border border-[#E6CECD]">
                        <span data-count-total><?php echo $total_count; ?></span>&nbsp;Total
                    </span>
                </div>

                <form method="get" id="reportAccessFilterForm" class="capstone-form-row capstone-filter-bar inline-flex flex-wrap items-center gap-2 m-0 p-0 bg-transparent border-0 shadow-none" style="margin:0;padding:0;background:transparent;border:none;box-shadow:none;display:inline-flex;align-items:center;gap:0.5rem;">
                    <label for="yearLevelFilter" class="text-xs font-extrabold uppercase tracking-wider text-[#8B7168] m-0">Year Level</label>
                    <select name="year_level" id="yearLevelFilter" aria-label="Year Level" class="px-3.5 py-2 text-xs font-bold text-[#59443F] bg-[#FDF9F9] border border-[#E2C9C8] rounded-xl focus:outline-none focus:border-[#59443F] cursor-pointer" style="width:auto;min-width:175px;margin:0;">
                        <?php foreach ($year_level_options as $yl_opt) { ?>
                            <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yl_opt === $year_level_filter ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="px-3.5 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-xs transition-all cursor-pointer" style="width:auto;margin:0;">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="?" id="reportAccessResetBtn" class="px-3 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-all <?php echo $year_level_filter !== '' ? 'inline-flex' : 'hidden'; ?> items-center gap-1" style="text-decoration:none;width:auto;margin:0;" title="Reset Year Level Filter">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" style="margin: 0;">
                    <thead>
                        <tr class="bg-[#FDF7F7] border-b border-[#EFE0DF]">
                            <th scope="col" class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">Seminarian</th>
                            <th scope="col" class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168]">View Reports</th>
                            <th scope="col" class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-[#8B7168] text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="reportAccessTbody" class="divide-y divide-[#F5EAE9] bg-white">
                        <?php echo eval_render_report_access_rows($students, $view_col, $csrf, $year_level_filter); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const root = document.getElementById('reportAccessRoot');
        if (!root) return;

        const banner = document.getElementById('reportAccessStatusBanner');
        const enabledCounterEl = root.querySelector('[data-count-enabled]');
        const disabledCounterEl = root.querySelector('[data-count-disabled]');
        const totalCounterEl = root.querySelector('[data-count-total]');
        const tbody = document.getElementById('reportAccessTbody');
        const filterForm = document.getElementById('reportAccessFilterForm');
        const yearLevelSelect = document.getElementById('yearLevelFilter');
        const resetBtn = document.getElementById('reportAccessResetBtn');
        let activeFilterCtrl = null;

        function fetchFilteredReportAccess(yearLevel) {
            const url = new URL(window.location.pathname, window.location.href);
            if (yearLevel) {
                url.searchParams.set('year_level', yearLevel);
            } else {
                url.searchParams.delete('year_level');
            }
            const cleanUrl = new URL(url.toString());
            url.searchParams.set('ajax', '1');

            if (activeFilterCtrl) activeFilterCtrl.abort();
            activeFilterCtrl = new AbortController();

            if (tbody) tbody.style.opacity = '0.6';

            fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                signal: activeFilterCtrl.signal
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || (!data.ok && !data.success)) return;
                if (tbody && typeof data.rows_html === 'string') {
                    tbody.innerHTML = data.rows_html;
                }
                if (totalCounterEl && typeof data.total_count === 'number') {
                    totalCounterEl.textContent = String(data.total_count);
                }
                if (enabledCounterEl && typeof data.enabled_count === 'number') {
                    enabledCounterEl.textContent = String(data.enabled_count);
                }
                if (disabledCounterEl && typeof data.disabled_count === 'number') {
                    disabledCounterEl.textContent = String(data.disabled_count);
                }
                if (resetBtn) {
                    resetBtn.classList.toggle('hidden', !data.year_level);
                    resetBtn.classList.toggle('inline-flex', !!data.year_level);
                }
                window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                window.location.href = cleanUrl.toString();
            })
            .finally(function () {
                if (tbody) tbody.style.opacity = '1';
            });
        }

        if (yearLevelSelect) {
            yearLevelSelect.addEventListener('change', function () {
                fetchFilteredReportAccess(yearLevelSelect.value);
            });
        }
        if (filterForm) {
            filterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                fetchFilteredReportAccess(yearLevelSelect ? yearLevelSelect.value : '');
            });
        }
        if (resetBtn) {
            resetBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fetchFilteredReportAccess('');
            });
        }

        function showStatus(message, isError) {
            if (!banner) return;
            const iconWrap = banner.querySelector('[data-status-icon-wrap]');
            const msgEl = banner.querySelector('[data-status-message]');

            banner.className = isError
                ? 'mb-6 rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3.5 flex items-center gap-3 shadow-sm'
                : 'mb-6 rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3.5 flex items-center gap-3 shadow-sm';

            if (iconWrap) {
                iconWrap.className = isError
                    ? 'w-8 h-8 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center shrink-0'
                    : 'w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0';
                iconWrap.innerHTML = isError
                    ? '<i class="fas fa-triangle-exclamation text-sm" aria-hidden="true"></i>'
                    : '<i class="fas fa-circle-check text-sm" aria-hidden="true"></i>';
            }

            if (msgEl) {
                msgEl.className = isError
                    ? 'text-sm font-bold text-rose-800'
                    : 'text-sm font-bold text-emerald-800';
                msgEl.textContent = message;
            }
        }

        function syncCounts() {
            const rows = root.querySelectorAll('.s-row');
            let enabled = 0;
            rows.forEach(function (r) {
                if (r.getAttribute('data-enabled') === '1') enabled++;
            });
            if (enabledCounterEl) enabledCounterEl.textContent = String(enabled);
            if (disabledCounterEl) disabledCounterEl.textContent = String(Math.max(0, rows.length - enabled));
        }

        function applyRowState(row, form, isEnabled) {
            const name = row.getAttribute('data-student-name') || 'seminarian';
            row.setAttribute('data-enabled', isEnabled ? '1' : '0');

            const nextInput = form.querySelector('input[name="new_value"]');
            if (nextInput) nextInput.value = isEnabled ? '0' : '1';

            const btn = form.querySelector('button[role="switch"]');
            if (btn) {
                btn.setAttribute('aria-checked', isEnabled ? 'true' : 'false');
                btn.setAttribute('aria-label', (isEnabled ? 'Disable' : 'Enable') + ' report access for ' + name);
                btn.setAttribute('title', isEnabled ? 'Click to disable report access' : 'Click to enable report access');
                btn.classList.toggle('bg-[#8B7168]', isEnabled);
                btn.classList.toggle('hover:bg-[#59443F]', isEnabled);
                btn.classList.toggle('bg-gray-300', !isEnabled);
                btn.classList.toggle('hover:bg-gray-400', !isEnabled);

                const sr = btn.querySelector('[data-toggle-sr]');
                if (sr) sr.textContent = (isEnabled ? 'Disable' : 'Enable') + ' report access for ' + name;

                const thumb = btn.querySelector('[data-toggle-thumb]');
                if (thumb) {
                    thumb.classList.toggle('translate-x-5', isEnabled);
                    thumb.classList.toggle('translate-x-0', !isEnabled);
                }
            }

            const badge = row.querySelector('[data-status-badge]');
            const dot = row.querySelector('[data-status-dot]');
            const text = row.querySelector('[data-status-text]');
            if (badge) {
                badge.className = isEnabled
                    ? 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 border border-green-200/80'
                    : 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-500 border border-gray-200/80';
            }
            if (dot) {
                dot.className = isEnabled
                    ? 'w-1.5 h-1.5 rounded-full bg-green-500'
                    : 'w-1.5 h-1.5 rounded-full bg-gray-400';
            }
            if (text) {
                text.textContent = isEnabled ? 'Enabled' : 'Disabled';
            }

            syncCounts();
        }

        root.addEventListener('submit', async function (e) {
            const form = e.target.closest('.js-report-access-form');
            if (!form || !root.contains(form)) return;
            e.preventDefault();

            if (form.dataset.pending === '1') return;
            const row = form.closest('.s-row');
            const switchBtn = form.querySelector('button[role="switch"]');
            const ajaxUrl = root.getAttribute('data-ajax-url') || window.location.href;
            const formData = new FormData(form);
            formData.set('ajax', '1');
            const csrfToken = String(formData.get('csrf_token') || '');

            form.dataset.pending = '1';
            if (switchBtn) {
                switchBtn.disabled = true;
                switchBtn.classList.add('opacity-70', 'cursor-wait');
            }

            try {
                const res = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                });
                const data = await res.json();
                if (!res.ok || !data || data.ok !== true) {
                    throw new Error((data && data.error) ? data.error : 'Unable to update report access.');
                }

                if (row) {
                    applyRowState(row, form, Boolean(data.enabled));
                }
                showStatus(String(data.message || 'Report access updated.'), false);
            } catch (err) {
                showStatus(err && err.message ? err.message : 'Unable to update report access. Please try again.', true);
            } finally {
                delete form.dataset.pending;
                if (switchBtn) {
                    switchBtn.disabled = false;
                    switchBtn.classList.remove('opacity-70', 'cursor-wait');
                }
            }
        });
    })();
    </script>
    <?php
}

/**
 * Reusable Individual Analytics Dashboard Component for all 4 Formator Pillars:
 * Accepts $formatorType ('academic', 'spiritual', 'pastoral', 'human') and $data:
 * - cat_labels (string[])
 * - cat_values ((float|null)[])
 * - trend_labels (string[])
 * - trend_values ((float|null)[])
 * - dist_labels (string[])
 * - dist_values (int[])
 * - average_rating (float|null, optional)
 * - total_score (int|float|null, optional)
 */
function eval_render_individual_analytics_section(string $formatorType, array $data): void
{
    $type = strtolower(trim($formatorType));
    $themes = [
        'academic' => [
            'prefix' => 'afe',
            'pillar_badge' => 'Academic Analytics',
            'timeline_title' => 'Academic Development Timeline',
            'icon' => 'fa-solid fa-graduation-cap',
            'primary' => '#4f46e5',       // Indigo-600
            'primary_hover' => '#4338ca', // Indigo-700
            'accent' => '#6366f1',        // Indigo-500
            'grad_top' => 'rgba(79, 70, 229, 0.28)',
            'grad_bottom' => 'rgba(79, 70, 229, 0.01)',
            'badge_cls' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'icon_cls' => 'bg-indigo-50 text-indigo-600 border-indigo-100',
            'donut_colors' => ['#fda4af', '#fcd34d', '#93c5fd', '#6366f1', '#312e81'],
        ],
        'spiritual' => [
            'prefix' => 'sfe',
            'pillar_badge' => 'Spiritual Analytics',
            'timeline_title' => 'Spiritual Development Timeline',
            'icon' => 'fa-solid fa-dove',
            'primary' => '#7c3aed',       // Violet-600
            'primary_hover' => '#6d28d9', // Violet-700
            'accent' => '#8b5cf6',        // Violet-500
            'grad_top' => 'rgba(124, 58, 237, 0.28)',
            'grad_bottom' => 'rgba(124, 58, 237, 0.01)',
            'badge_cls' => 'bg-violet-50 text-violet-700 border-violet-200',
            'icon_cls' => 'bg-violet-50 text-violet-600 border-violet-100',
            'donut_colors' => ['#fda4af', '#fcd34d', '#c4b5fd', '#8b5cf6', '#4c1d95'],
        ],
        'pastoral' => [
            'prefix' => 'pfe',
            'pillar_badge' => 'Pastoral Analytics',
            'timeline_title' => 'Pastoral Development Timeline',
            'icon' => 'fa-solid fa-hands-praying',
            'primary' => '#059669',       // Emerald-600
            'primary_hover' => '#047857', // Emerald-700
            'accent' => '#10b981',        // Emerald-500
            'grad_top' => 'rgba(5, 150, 105, 0.28)',
            'grad_bottom' => 'rgba(5, 150, 105, 0.01)',
            'badge_cls' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'icon_cls' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'donut_colors' => ['#fda4af', '#fcd34d', '#6ee7b7', '#10b981', '#064e3b'],
        ],
        'human' => [
            'prefix' => 'hfe',
            'pillar_badge' => 'Human Formation Analytics',
            'timeline_title' => 'Human Formation Trend',
            'icon' => 'fa-solid fa-heart-pulse',
            'primary' => '#d97706',       // Amber-600
            'primary_hover' => '#b45309', // Amber-700
            'accent' => '#f59e0b',        // Amber-500
            'grad_top' => 'rgba(217, 119, 6, 0.28)',
            'grad_bottom' => 'rgba(217, 119, 6, 0.01)',
            'badge_cls' => 'bg-amber-50 text-amber-700 border-amber-200',
            'icon_cls' => 'bg-amber-50 text-amber-600 border-amber-100',
            'donut_colors' => ['#fda4af', '#fdba74', '#fcd34d', '#f59e0b', '#78350f'],
        ],
    ];

    $theme = $themes[$type] ?? $themes['academic'];
    $prefix = $theme['prefix'];

    $cat_labels = array_values((array)($data['cat_labels'] ?? []));
    $cat_values = array_map(
        static fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : round((float)$v, 2),
        array_values((array)($data['cat_values'] ?? []))
    );

    // Deduplicate timeline points by term/date label so identical consecutive labels never form a misleading flat line
    $raw_trend_labels = array_values((array)($data['trend_labels'] ?? []));
    $raw_trend_values = array_values((array)($data['trend_values'] ?? []));
    $dedup_trend_map = [];
    foreach ($raw_trend_labels as $idx => $lbl) {
        $clean_lbl = trim((string)$lbl);
        $raw_val = $raw_trend_values[$idx] ?? null;
        if ($clean_lbl === '' || $raw_val === null || !is_numeric($raw_val)) {
            continue;
        }
        $dedup_trend_map[$clean_lbl] = round((float)$raw_val, 2);
    }
    $trend_labels = array_keys($dedup_trend_map);
    $trend_values = array_values($dedup_trend_map);

    $dist_labels = array_values((array)($data['dist_labels'] ?? [
        '1 Poor',
        '2 Needs Improvement',
        '3 Satisfactory',
        '4 Very Good',
        '5 Outstanding',
    ]));
    $dist_values = array_map(static fn($v) => max(0, (int)$v), array_values((array)($data['dist_values'] ?? [0, 0, 0, 0, 0])));
    $dist_total = array_sum($dist_values);

    $avg_raw = $data['average_rating'] ?? null;
    if (($avg_raw === null || !is_numeric($avg_raw)) && $dist_total > 0) {
        $weighted_sum = 0;
        foreach ($dist_values as $idx => $cnt) {
            $weighted_sum += ($idx + 1) * $cnt;
        }
        $avg_raw = $weighted_sum / $dist_total;
    }
    $avg_display = ($avg_raw !== null && is_numeric($avg_raw)) ? number_format((float)$avg_raw, 2) : '-';

    $has_cat_data = false;
    foreach ($cat_values as $cv) {
        if ($cv !== null) {
            $has_cat_data = true;
            break;
        }
    }
    $has_trend_data = count($trend_values) > 0;
    $is_single_trend_point = count($trend_values) === 1;
    $has_dist_data = $dist_total > 0;

    $cat_canvas_id = $prefix . 'CatChart';
    $trend_canvas_id = $prefix . 'TrendChart';
    $dist_canvas_id = $prefix . 'DistChart';
    $section_uid = $prefix . 'AnalyticsSection_' . substr(md5($prefix . json_encode($cat_labels)), 0, 6);
    ?>
    <section id="<?php echo htmlspecialchars($section_uid, ENT_QUOTES, 'UTF-8'); ?>"
             data-formator-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
             class="bg-white rounded-3xl shadow-lg border border-[#E8D5D5] p-6 mb-6 print:bg-white print:shadow-none print:break-inside-avoid">
        <!-- Section Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-6 border-b border-[#F3E4E4]">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl border flex items-center justify-center shrink-0 <?php echo $theme['icon_cls']; ?> print:bg-white print:shadow-none">
                    <i class="fa-solid fa-chart-simple text-base"></i>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold text-[#59443F] m-0">Individual Analytics</h2>
                    <p class="text-xs font-semibold text-[#8B7168] m-0">Dimension breakdown, longitudinal progress, and rating distribution</p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold border self-start sm:self-center <?php echo $theme['badge_cls']; ?> print:bg-white print:shadow-none">
                <i class="<?php echo htmlspecialchars($theme['icon'], ENT_QUOTES, 'UTF-8'); ?> text-[11px]"></i>
                <span><?php echo htmlspecialchars($theme['pillar_badge'], ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        </div>

        <!-- Main 2-Column Responsive Analytics Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- 1. Category Scores (Bar Chart) -->
            <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-5 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="flex items-start justify-between gap-2 mb-4">
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-900 m-0">Category Scores</h3>
                        <p class="text-xs font-medium text-slate-500 mt-0.5 m-0">Hover any bar to inspect full category title &amp; rating</p>
                    </div>
                    <span class="text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg bg-slate-50 text-slate-600 border border-slate-200/70">
                        Max 5.00
                    </span>
                </div>
                <?php if ($has_cat_data) { ?>
                    <div class="relative w-full" style="height: 290px;">
                        <canvas id="<?php echo htmlspecialchars($cat_canvas_id, ENT_QUOTES, 'UTF-8'); ?>"></canvas>
                    </div>
                <?php } else { ?>
                    <div class="flex flex-col items-center justify-center text-center py-12 px-4 rounded-xl bg-slate-50/70 border border-dashed border-slate-200" style="height: 290px;">
                        <i class="fa-solid fa-chart-column text-2xl text-slate-300 mb-2"></i>
                        <div class="text-sm font-bold text-slate-600">No Category Scores Available</div>
                        <p class="text-xs text-slate-400 mt-1 max-w-xs">Category averages will appear here once evaluation responses are recorded.</p>
                    </div>
                <?php } ?>
            </div>

            <!-- 2. Development Timeline (Smooth Area/Line Chart with Single-Point Handling) -->
            <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-5 flex flex-col justify-between print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="flex items-start justify-between gap-2 mb-4">
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-900 m-0"><?php echo htmlspecialchars($theme['timeline_title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-xs font-medium text-slate-500 mt-0.5 m-0">
                            <?php echo $is_single_trend_point
                                ? 'Baseline evaluation point established for this term'
                                : 'Term-over-term progression across active evaluations'; ?>
                        </p>
                    </div>
                    <?php if ($is_single_trend_point) { ?>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg <?php echo $theme['badge_cls']; ?>">
                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                            <span>1 Term Recorded</span>
                        </span>
                    <?php } else { ?>
                        <span class="text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg bg-slate-50 text-slate-600 border border-slate-200/70">
                            <?php echo count($trend_values); ?> Terms
                        </span>
                    <?php } ?>
                </div>
                <?php if ($has_trend_data) { ?>
                    <div class="relative w-full" style="height: 290px;">
                        <canvas id="<?php echo htmlspecialchars($trend_canvas_id, ENT_QUOTES, 'UTF-8'); ?>"></canvas>
                    </div>
                <?php } else { ?>
                    <div class="flex flex-col items-center justify-center text-center py-12 px-4 rounded-xl bg-slate-50/70 border border-dashed border-slate-200" style="height: 290px;">
                        <i class="fa-solid fa-chart-line text-2xl text-slate-300 mb-2"></i>
                        <div class="text-sm font-bold text-slate-600">No Timeline Data Yet</div>
                        <p class="text-xs text-slate-400 mt-1 max-w-xs">Historical trend lines will populate as evaluations are completed.</p>
                    </div>
                <?php } ?>
            </div>

            <!-- 3. Rating Distribution (Sleek Donut Chart + Custom Interactive Legend) -->
            <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-5 lg:col-span-2 print:bg-white print:shadow-none print:break-inside-avoid">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-5">
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-900 m-0">Rating Distribution (This Evaluation)</h3>
                        <p class="text-xs font-medium text-slate-500 mt-0.5 m-0">Breakdown of questionnaire responses across the 5-point Likert scale</p>
                    </div>
                    <div class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 bg-slate-50 border border-slate-200/70 px-3 py-1.5 rounded-lg self-start sm:self-center">
                        <span>Total Responses: <strong class="text-slate-900"><?php echo (int)$dist_total; ?></strong></span>
                        <span class="text-slate-300">&bull;</span>
                        <span>Average: <strong class="text-slate-900"><?php echo htmlspecialchars($avg_display, ENT_QUOTES, 'UTF-8'); ?> / 5.00</strong></span>
                    </div>
                </div>

                <?php if ($has_dist_data) { ?>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                        <!-- Donut Canvas with Center Overlay Label -->
                        <div class="md:col-span-5 relative flex items-center justify-center" style="height: 250px;">
                            <canvas id="<?php echo htmlspecialchars($dist_canvas_id, ENT_QUOTES, 'UTF-8'); ?>"></canvas>
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-3xl font-extrabold text-slate-900 tabular-nums leading-none">
                                    <?php echo htmlspecialchars($avg_display, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="text-[10px] font-extrabold uppercase tracking-widest text-slate-400 mt-1">
                                    Avg Rating
                                </span>
                                <span class="text-[11px] font-semibold text-slate-500 mt-0.5">
                                    <?php echo (int)$dist_total; ?> <?php echo $dist_total === 1 ? 'item' : 'items'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Custom Cohesive Legend & Breakdown Bars -->
                        <div class="md:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php
                            for ($i = count($dist_labels) - 1; $i >= 0; $i--) {
                                $tier_label = (string)($dist_labels[$i] ?? '');
                                $tier_count = (int)($dist_values[$i] ?? 0);
                                $tier_pct = $dist_total > 0 ? round(($tier_count / $dist_total) * 100, 1) : 0.0;
                                $tier_color = $theme['donut_colors'][$i] ?? '#6366f1';
                            ?>
                                <div class="flex flex-col justify-between p-3 rounded-xl bg-slate-50/70 border border-slate-100 hover:border-slate-200 transition-colors">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span class="w-3 h-3 rounded-full shrink-0 shadow-2xs"
                                                  style="background-color: <?php echo htmlspecialchars($tier_color, ENT_QUOTES, 'UTF-8'); ?>; print-color-adjust: exact; -webkit-print-color-adjust: exact;"></span>
                                            <span class="text-xs font-bold text-slate-800 truncate">
                                                <?php echo htmlspecialchars($tier_label, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <div class="text-xs font-extrabold text-slate-900 tabular-nums shrink-0">
                                            <?php echo $tier_count; ?>
                                            <span class="text-[11px] font-semibold text-slate-400">(<?php echo number_format($tier_pct, 0); ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full h-1.5 bg-slate-200/70 rounded-full overflow-hidden mt-2"
                                         style="print-color-adjust: exact; -webkit-print-color-adjust: exact;">
                                        <div class="h-full rounded-full transition-all duration-300"
                                             style="width: <?php echo number_format($tier_pct, 1, '.', ''); ?>%; background-color: <?php echo htmlspecialchars($tier_color, ENT_QUOTES, 'UTF-8'); ?>; print-color-adjust: exact; -webkit-print-color-adjust: exact;"></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } else { ?>
                    <div class="flex flex-col items-center justify-center text-center py-10 px-4 rounded-xl bg-slate-50/70 border border-dashed border-slate-200">
                        <i class="fa-solid fa-chart-pie text-2xl text-slate-300 mb-2"></i>
                        <div class="text-sm font-bold text-slate-600">No Rating Distribution Data</div>
                        <p class="text-xs text-slate-400 mt-1 max-w-xs">Response counts across the 1–5 rating scale will appear here once questions are scored.</p>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const catLabels = <?php echo json_encode($cat_labels); ?>;
        const catValues = <?php echo json_encode($cat_values); ?>;
        const trendLabels = <?php echo json_encode($trend_labels); ?>;
        const trendValues = <?php echo json_encode($trend_values); ?>;
        const distLabels = <?php echo json_encode($dist_labels); ?>;
        const distValues = <?php echo json_encode($dist_values); ?>;
        const theme = <?php echo json_encode($theme); ?>;
        const avgDisplay = <?php echo json_encode($avg_display); ?>;

        // Helper: Wrap long category labels into 2 short lines so X-axis ticks stay 0-deg horizontal
        function wrapAxisLabel(str, maxLen) {
            maxLen = maxLen || 12;
            const words = String(str || '').trim().split(/\s+/).filter(Boolean);
            if (words.length === 0) return '';
            const lines = [];
            let current = words[0];
            for (let i = 1; i < words.length; i++) {
                if ((current + ' ' + words[i]).length <= maxLen) {
                    current += ' ' + words[i];
                } else {
                    lines.push(current);
                    current = words[i];
                    if (lines.length === 1) {
                        const rest = [current].concat(words.slice(i + 1)).join(' ');
                        current = rest.length > maxLen + 2 ? rest.slice(0, maxLen + 1) + '…' : rest;
                        break;
                    }
                }
            }
            lines.push(current);
            return lines;
        }

        // Custom Tailwind HTML Tooltip handler for Chart.js
        function getOrCreateTailwindTooltip() {
            let el = document.getElementById('enformasysChartTooltip');
            if (!el) {
                el = document.createElement('div');
                el.id = 'enformasysChartTooltip';
                el.className = 'fixed z-50 bg-slate-900/95 text-white rounded-xl px-3.5 py-2.5 text-xs shadow-xl border border-slate-700/70 pointer-events-none transition-opacity duration-150 opacity-0';
                document.body.appendChild(el);
            }
            return el;
        }

        function externalTailwindTooltip(context) {
            const { chart, tooltip } = context;
            const tooltipEl = getOrCreateTailwindTooltip();
            if (tooltip.opacity === 0) {
                tooltipEl.style.opacity = '0';
                return;
            }
            const titleLines = tooltip.title || [];
            const bodyLines = (tooltip.body || []).map(b => b.lines);
            let innerHtml = '';
            titleLines.forEach(function (t) {
                innerHtml += '<div class="font-extrabold text-white mb-1 leading-snug">' + t + '</div>';
            });
            bodyLines.forEach(function (b, idx) {
                const colors = tooltip.labelColors[idx];
                const swatch = colors ? colors.backgroundColor : theme.primary;
                innerHtml += '<div class="flex items-center gap-2 font-semibold text-slate-200">'
                    + '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + swatch + ';"></span>'
                    + '<span>' + b.join(', ') + '</span>'
                    + '</div>';
            });
            tooltipEl.innerHTML = innerHtml;
            const rect = chart.canvas.getBoundingClientRect();
            tooltipEl.style.opacity = '1';
            tooltipEl.style.left = Math.min(window.innerWidth - 220, Math.max(12, rect.left + tooltip.caretX + 12)) + 'px';
            tooltipEl.style.top = Math.max(12, rect.top + tooltip.caretY - 28) + 'px';
        }

        const hasChart = typeof Chart !== 'undefined';

        // 1. Category Scores Bar Chart
        const catEl = document.getElementById(<?php echo json_encode($cat_canvas_id); ?>);
        if (catEl) {
            if (hasChart) {
                const ctx = catEl.getContext('2d');
                const barGrad = ctx.createLinearGradient(0, 0, 0, 260);
                barGrad.addColorStop(0, theme.primary);
                barGrad.addColorStop(1, theme.accent);

                new Chart(catEl, {
                    type: 'bar',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            label: 'Score',
                            data: catValues,
                            backgroundColor: barGrad,
                            hoverBackgroundColor: theme.primary_hover,
                            borderRadius: { topLeft: 8, topRight: 8, bottomLeft: 0, bottomRight: 0 },
                            borderSkipped: false,
                            maxBarThickness: 44
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: {
                                    maxRotation: 0,
                                    minRotation: 0,
                                    autoSkip: false,
                                    color: '#475569',
                                    font: { family: 'Inter, system-ui, sans-serif', size: 11, weight: '600' },
                                    callback: function (value) {
                                        const rawLabel = this.getLabelForValue(value);
                                        return wrapAxisLabel(rawLabel, 12);
                                    }
                                }
                            },
                            y: {
                                min: 0,
                                max: 5,
                                ticks: {
                                    stepSize: 1,
                                    color: '#64748b',
                                    font: { family: 'Inter, system-ui, sans-serif', size: 11, weight: '600' },
                                    callback: function (v) { return Number(v).toFixed(1); }
                                },
                                grid: { color: '#f1f5f9' },
                                border: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: false,
                                external: externalTailwindTooltip,
                                callbacks: {
                                    title: function (items) {
                                        return items.length ? catLabels[items[0].dataIndex] : '';
                                    },
                                    label: function (item) {
                                        const v = item.raw != null ? Number(item.raw).toFixed(2) : '-';
                                        return 'Category Average: ' + v + ' / 5.00';
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (window.SimpleCharts) {
                window.SimpleCharts.bar(catEl, catLabels, catValues, {
                    yMin: 0,
                    yMax: 5,
                    steps: 5,
                    color: theme.primary,
                    colorEnd: theme.accent
                });
            }
        }

        // 2. Development Timeline Smooth Area/Line Chart (with Single-Point Handling)
        const trendEl = document.getElementById(<?php echo json_encode($trend_canvas_id); ?>);
        if (trendEl) {
            if (hasChart) {
                const ctx = trendEl.getContext('2d');
                const areaGrad = ctx.createLinearGradient(0, 0, 0, 260);
                areaGrad.addColorStop(0, theme.grad_top);
                areaGrad.addColorStop(1, theme.grad_bottom);
                const isSingle = trendValues.length === 1;

                // Plugin to draw a subtle dashed horizontal baseline when only 1 point exists
                const singlePointGuidePlugin = {
                    id: 'singlePointGuide',
                    beforeDatasetsDraw: function (chart) {
                        if (!isSingle) return;
                        const meta = chart.getDatasetMeta(0);
                        if (!meta || !meta.data || !meta.data[0]) return;
                        const pt = meta.data[0];
                        const { ctx, chartArea } = chart;
                        ctx.save();
                        ctx.beginPath();
                        ctx.setLineDash([6, 6]);
                        ctx.strokeStyle = theme.accent;
                        ctx.lineWidth = 1.5;
                        ctx.moveTo(chartArea.left, pt.y);
                        ctx.lineTo(chartArea.right, pt.y);
                        ctx.stroke();
                        ctx.restore();
                    }
                };

                new Chart(trendEl, {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [{
                            label: 'Average Rating',
                            data: trendValues,
                            borderColor: theme.primary,
                            backgroundColor: areaGrad,
                            fill: !isSingle,
                            cubicInterpolationMode: 'monotone',
                            tension: 0.4,
                            borderWidth: 2.5,
                            pointRadius: isSingle ? 7 : 4.5,
                            pointHoverRadius: isSingle ? 10 : 7,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: theme.primary,
                            pointBorderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                offset: isSingle,
                                grid: { display: false },
                                ticks: {
                                    maxRotation: 0,
                                    minRotation: 0,
                                    color: '#475569',
                                    font: { family: 'Inter, system-ui, sans-serif', size: 11, weight: '600' }
                                }
                            },
                            y: {
                                min: 0,
                                max: 5,
                                ticks: {
                                    stepSize: 1,
                                    color: '#64748b',
                                    font: { family: 'Inter, system-ui, sans-serif', size: 11, weight: '600' },
                                    callback: function (v) { return Number(v).toFixed(1); }
                                },
                                grid: { color: '#f1f5f9' },
                                border: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: false,
                                external: externalTailwindTooltip,
                                callbacks: {
                                    title: function (items) {
                                        return items.length ? ('Term: ' + trendLabels[items[0].dataIndex]) : '';
                                    },
                                    label: function (item) {
                                        const v = item.raw != null ? Number(item.raw).toFixed(2) : '-';
                                        return 'Overall Average: ' + v + ' / 5.00';
                                    }
                                }
                            }
                        }
                    },
                    plugins: [singlePointGuidePlugin]
                });
            } else if (window.SimpleCharts) {
                window.SimpleCharts.line(trendEl, trendLabels, trendValues, {
                    yMin: 0,
                    yMax: 5,
                    steps: 5,
                    stroke: theme.primary,
                    fill: theme.grad_top
                });
            }
        }

        // 3. Rating Distribution Sleek Donut Chart
        const distEl = document.getElementById(<?php echo json_encode($dist_canvas_id); ?>);
        if (distEl) {
            if (hasChart) {
                new Chart(distEl, {
                    type: 'doughnut',
                    data: {
                        labels: distLabels,
                        datasets: [{
                            data: distValues,
                            backgroundColor: theme.donut_colors,
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            borderRadius: 6,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: false,
                                external: externalTailwindTooltip,
                                callbacks: {
                                    title: function (items) {
                                        return items.length ? distLabels[items[0].dataIndex] : '';
                                    },
                                    label: function (item) {
                                        const count = Number(item.raw || 0);
                                        const total = distValues.reduce((a, b) => a + b, 0);
                                        const pct = total > 0 ? Math.round((count / total) * 100) : 0;
                                        return count + (count === 1 ? ' response (' : ' responses (') + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (window.SimpleCharts) {
                window.SimpleCharts.doughnut(distEl, distLabels, distValues, {
                    colors: theme.donut_colors,
                    centerValue: avgDisplay,
                    centerLabel: 'AVG RATING'
                });
            }
        }
    });
    </script>
    <?php
}



