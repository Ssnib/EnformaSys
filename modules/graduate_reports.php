<?php
include '../includes/session_fix.php';
require_roles(['Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/registrar_decoupling.php';

// Ensure decoupled tables and any legacy graduation metadata are synced and backfilled
registrar_sync_and_migrate($conn, false);
registrar_backfill_graduation_metadata($conn);

$current_ay = get_current_academic_year();
$filter_ay = trim((string)($_GET['academic_year'] ?? ''));
if ($filter_ay === '') {
    $filter_ay = 'all';
}

$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

$grad_condition = "(
    COALESCE(s.is_graduate, 0) = 1
    OR COALESCE(stu.is_graduate, 0) = 1
    OR (s.completed_at IS NOT NULL AND CAST(s.completed_at AS CHAR) NOT IN ('', '0000-00-00'))
    OR (stu.graduated_at IS NOT NULL AND CAST(stu.graduated_at AS CHAR) NOT IN ('', '0000-00-00'))
    OR LOWER(TRIM(COALESCE(s.enrollment_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
    OR LOWER(TRIM(COALESCE(s.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
    OR LOWER(TRIM(COALESCE(stu.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
    OR LOWER(TRIM(COALESCE(s.year_level, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
    OR LOWER(TRIM(COALESCE(s.formation_stage, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
    OR LOWER(TRIM(COALESCE(le.enrollment_status, ''))) IN ('graduated', 'graduate', 'alumni')
    OR LOWER(TRIM(COALESCE(le.year_level, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
)";

$resolved_ay_expr = "COALESCE(
    NULLIF(TRIM(s.academic_year_completed), ''),
    NULLIF(TRIM(le.academic_year), ''),
    NULLIF(TRIM(s.current_academic_year), '')
)";

// Include any historical completion years in the Academic Year filter dropdown
$res_grad_ays = @$conn->query(
    "SELECT DISTINCT {$resolved_ay_expr} AS grad_ay
     FROM students stu
     LEFT JOIN seminarians s ON s.id = stu.id
     LEFT JOIN enrollments le ON le.id = (
         SELECT e2.id FROM enrollments e2
         WHERE e2.student_id = stu.id
         ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC
         LIMIT 1
     )
     WHERE {$grad_condition}
       AND {$resolved_ay_expr} IS NOT NULL
       AND TRIM({$resolved_ay_expr}) <> ''
     ORDER BY grad_ay DESC"
);
if ($res_grad_ays) {
    while ($ay_row = $res_grad_ays->fetch_assoc()) {
        $ay_val = trim((string)($ay_row['grad_ay'] ?? ''));
        if ($ay_val !== '' && !in_array($ay_val, $ay_options, true)) {
            $ay_options[] = $ay_val;
        }
    }
}
usort($ay_options, static function (string $a, string $b): int {
    return strcmp($b, $a);
});

$where = $grad_condition;
$params = [];
$types = '';
if ($filter_ay !== '' && $filter_ay !== 'all') {
    $where .= " AND {$resolved_ay_expr} = ?";
    $params[] = $filter_ay;
    $types .= 's';
}

$rows = [];
$sql = "SELECT
        stu.id AS student_record_id,
        s.id AS seminarian_id,
        COALESCE(
            NULLIF(TRIM(stu.full_name), ''),
            NULLIF(TRIM(up.full_name), ''),
            NULLIF(TRIM(CONCAT_WS(' ', stu.first_name, stu.middle_name, stu.last_name, stu.suffix)), ''),
            u.username,
            CONCAT('Seminarian #', stu.id)
        ) AS full_name,
        COALESCE(
            NULLIF(TRIM(stu.student_no), ''),
            NULLIF(TRIM(up.student_id), ''),
            CONCAT('SEM-', YEAR(COALESCE(stu.created_at, NOW())), '-', LPAD(stu.id, 4, '0'))
        ) AS student_id,
        COALESCE(
            NULLIF(TRIM(s.program_completed), ''),
            NULLIF(TRIM(le.program), ''),
            NULLIF(TRIM(s.program), ''),
            NULLIF(TRIM(stu.default_program), ''),
            'Bachelor of Arts in Philosophy'
        ) AS program_completed,
        {$resolved_ay_expr} AS academic_year_completed,
        COALESCE(
            NULLIF(NULLIF(TRIM(CAST(s.completed_at AS CHAR)), '0000-00-00'), ''),
            NULLIF(NULLIF(TRIM(CAST(stu.graduated_at AS CHAR)), '0000-00-00'), ''),
            DATE_FORMAT(le.updated_at, '%Y-%m-%d'),
            DATE_FORMAT(stu.updated_at, '%Y-%m-%d')
        ) AS completed_at
    FROM students stu
    LEFT JOIN seminarians s ON s.id = stu.id
    LEFT JOIN users u ON u.id = COALESCE(stu.user_id, s.user_id)
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN enrollments le ON le.id = (
        SELECT e2.id FROM enrollments e2
        WHERE e2.student_id = stu.id
        ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC
        LIMIT 1
    )
    WHERE {$where}
    ORDER BY completed_at DESC, full_name ASC";
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
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

$pageTitle = 'Graduate Reports';
$activeNav = 'graduate_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-graduation-cap"></i> Graduate Reports</h1>
                <div class="field" style="margin: 0; min-width: 240px;">
                    <label style="color: var(--text-secondary);">Academic Year Completed</label>
                    <select id="aySelect">
                        <option value="all" <?php echo $filter_ay === 'all' ? 'selected' : ''; ?>>All Academic Years</option>
                        <?php foreach ($ay_options as $ay) { ?>
                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $filter_ay === $ay ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ay); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="data-card">
                <div class="data-card-header" style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
                    <h3><i class="fas fa-list"></i> Graduates</h3>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200/80">
                        <?php echo count($rows); ?> <?php echo count($rows) === 1 ? 'Graduate' : 'Graduates'; ?>
                    </span>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Seminarian</th>
                                <th>Program Completed</th>
                                <th>Academic Year Completed</th>
                                <th>Date Completed</th>
                                <th>Profile</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rows) === 0) { ?>
                                <tr><td colspan="6" style="color: var(--text-secondary);">No graduates found.</td></tr>
                            <?php } else { foreach ($rows as $r) {
                                $raw_date = trim((string)($r['completed_at'] ?? ''));
                                $formatted_date = '—';
                                if ($raw_date !== '' && $raw_date !== '0000-00-00') {
                                    $ts = strtotime($raw_date);
                                    $formatted_date = $ts !== false ? date('M j, Y', $ts) : $raw_date;
                                }
                                $profile_sem_id = (int)($r['seminarian_id'] ?? 0);
                                $profile_url = $profile_sem_id > 0
                                    ? ('seminarian_profile.php?seminarian_id=' . $profile_sem_id . (!empty($r['academic_year_completed']) ? '&academic_year=' . urlencode((string)$r['academic_year_completed']) : ''))
                                    : ('student_profiles.php?q=' . urlencode((string)($r['student_id'] ?? '')));
                            ?>
                                <tr>
                                    <td><span class="font-mono text-xs font-semibold text-slate-700"><?php echo htmlspecialchars((string)($r['student_id'] ?? '—')); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string)($r['program_completed'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['academic_year_completed'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars($formatted_date); ?></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($profile_url); ?>" class="back-link" style="margin: 0;">
                                            View Profile
                                        </a>
                                    </td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const sel = document.getElementById('aySelect');
            if (!sel) return;
            sel.addEventListener('change', function () {
                const params = new URLSearchParams(window.location.search);
                params.set('academic_year', sel.value || 'all');
                window.location.search = params.toString();
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
