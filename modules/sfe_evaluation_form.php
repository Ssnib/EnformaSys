<?php
include '../includes/session_fix.php';
require_roles(['SpiritualDirector']);
include '../includes/db.php';
include '../includes/spiritual_evaluation.php';

$director_id = (int)($_SESSION['user'] ?? 0);

require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$auth_term = eval_get_authoritative_active_term($conn);
$active_term_ay = $auth_term['academic_year'];
$active_term_sem = $auth_term['semester'];
$requested_ay_get = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$requested_sem_get = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
$advance_term_rejected = (
    ($requested_ay_get !== '' && $requested_ay_get !== $active_term_ay)
    || ($requested_sem_get !== '' && $requested_sem_get !== $active_term_sem)
);
$current_ay = $active_term_ay;
$filter_academic_year = $active_term_ay;
$filter_semester = $active_term_sem;

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

$group_ctx = eval_init_group_batch($conn, 'spiritual', $director_id, $filter_academic_year, $filter_semester, $evaluation_id);
$is_group_mode = !empty($group_ctx['active']);

$questions = [];
$res_q = $conn->query("SELECT question_id, category, question_text, sort_order FROM spiritual_questions WHERE is_active = 1 ORDER BY sort_order ASC, question_id ASC");
if ($res_q) {
    while ($row = $res_q->fetch_assoc()) {
        $questions[] = $row;
    }
}

$students = [];
$stmt_students = $conn->prepare("SELECT s.id, u.username, up.full_name, s.spiritual_eval_view_enabled,
        s.year_level, s.formation_stage, s.enrollment_status, s.formation_status, s.is_graduate, s.completed_at,
        stu.is_graduate AS stu_is_graduate, stu.graduated_at, stu.formation_status AS stu_formation_status
    FROM seminarians s
    JOIN seminarian_assignments sa ON sa.seminarian_id = s.id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students stu ON stu.user_id = u.id
    WHERE u.role = 'Student'
      AND sa.assignment_type = 'Spiritual'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
    ORDER BY COALESCE(up.full_name, u.username) ASC");
$stmt_students->bind_param('i', $director_id);
$stmt_students->execute();
$res_s = $stmt_students->get_result();
$student_rows_raw = $res_s ? $res_s->fetch_all(MYSQLI_ASSOC) : [];
if ($res_s) $res_s->free();
$stmt_students->close();
$enrolled_active_map = eval_get_active_enrolled_seminarian_ids($conn, $active_term_ay, $active_term_sem);
foreach ($student_rows_raw as $row) {
    $row['is_graduated'] = eval_is_seminarian_graduated($row);
    $row['is_enrolled_active'] = !empty($enrolled_active_map[(int)$row['id']]);
    $row['status'] = $row['is_graduated'] ? 'graduated' : 'active';
    $students[] = $row;
}

$existing_responses = [];
$edit = null;
$remarks = [
    'strengths' => '',
    'areas_for_growth' => '',
    'challenges' => '',
    'recommendations' => '',
    'formation_plan' => '',
    'follow_up_schedule' => '',
];

if ($evaluation_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM spiritual_evaluations WHERE evaluation_id = ? AND spiritual_director_id = ? LIMIT 1");
    $stmt->bind_param('ii', $evaluation_id, $director_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    if ($edit) {
        $student_id = (int)$edit['seminarian_id'];
        $filter_academic_year = (string)$edit['academic_year'];
        $filter_semester = (string)$edit['semester'];
    }

    $stmt_r = $conn->prepare("SELECT question_id, rating FROM spiritual_responses WHERE evaluation_id = ?");
    $stmt_r->bind_param('i', $evaluation_id);
    $stmt_r->execute();
    $res = $stmt_r->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing_responses[(int)$row['question_id']] = (int)$row['rating'];
    }

    $stmt_rem = $conn->prepare("SELECT strengths, areas_for_growth, challenges, recommendations, formation_plan, follow_up_schedule FROM spiritual_remarks WHERE evaluation_id = ? LIMIT 1");
    $stmt_rem->bind_param('i', $evaluation_id);
    $stmt_rem->execute();
    $rem = $stmt_rem->get_result()->fetch_assoc();
    if ($rem) {
        foreach ($remarks as $k => $v) {
            $remarks[$k] = (string)($rem[$k] ?? '');
        }
    }
}
$is_historical_readonly = ($edit !== null && (
    trim((string)($edit['academic_year'] ?? '')) !== $active_term_ay
    || enformasys_normalize_semester((string)($edit['semester'] ?? '')) !== $active_term_sem
));

if (isset($_POST['save_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $evaluation_id_post = (int)($_POST['evaluation_id'] ?? 0);
    $student_id_post = (int)($_POST['seminarian_id'] ?? 0);
    $group_ids_post = (isset($_POST['seminarian_ids']) && is_array($_POST['seminarian_ids']))
        ? eval_parse_batch_id_list(null, $_POST['seminarian_ids'])
        : [];
    $is_bulk_post = ($evaluation_id_post <= 0 && !empty($group_ids_post));
    $target_ids = $is_bulk_post ? $group_ids_post : ($student_id_post > 0 ? [$student_id_post] : []);

    $academic_year_post = trim((string)($_POST['academic_year'] ?? ''));
    $semester_post = trim((string)($_POST['semester'] ?? ''));
    $evaluation_date = trim((string)($_POST['evaluation_date'] ?? date('Y-m-d')));
    $ratings = $_POST['rating'] ?? [];

    $remarks_post = [
        'strengths' => trim((string)($_POST['strengths'] ?? '')),
        'areas_for_growth' => trim((string)($_POST['areas_for_growth'] ?? '')),
        'challenges' => trim((string)($_POST['challenges'] ?? '')),
        'recommendations' => trim((string)($_POST['recommendations'] ?? '')),
        'formation_plan' => trim((string)($_POST['formation_plan'] ?? '')),
        'follow_up_schedule' => trim((string)($_POST['follow_up_schedule'] ?? '')),
    ];

    if (empty($target_ids) || $academic_year_post === '' || !in_array($semester_post, ['1st','2nd','Summer'], true)) {
        $error = 'Please select seminarian(s), academic year, and semester.';
    } else {
        $term_lock_error = eval_validate_active_term_submission($conn, $academic_year_post, $semester_post, $target_ids);
        if ($term_lock_error !== null) {
            $error = $term_lock_error;
        } else {
            foreach ($target_ids as $chk_sid) {
                if (eval_check_seminarian_graduated_db($conn, (int)$chk_sid)) {
                    $error = eval_block_graduated_submission();
                    break;
                }
            }
            if (!isset($error)) {
                $part = eval_partition_batch_ids($conn, 'spiritual', $director_id, $target_ids, $academic_year_post, $semester_post, $evaluation_id_post);
                if (!empty($part['graduated'])) {
                    $error = eval_block_graduated_submission();
                } elseif (!empty($part['unassigned_ids'])) {
                    $error = 'One or more selected seminarians are not assigned to you.';
                } elseif (!empty($part['already_evaluated'])) {
                    $dup_names = implode(', ', array_map(fn($x) => (string)$x['name'], $part['already_evaluated']));
                    $error = 'Duplicate evaluations are not allowed. Already evaluated for ' . $academic_year_post . ' (' . $semester_post . ' Semester): ' . $dup_names . '.';
                }
            }
        }
    }

    if (!isset($error)) {
        $calc = sfe_compute_results($questions, $ratings);
        if ($calc['count'] !== count($questions)) {
            $error = 'Please answer all questions.';
        } else {
            $conn->begin_transaction();
            try {
                $eval_entries = [];
                if ($evaluation_id_post > 0) {
                    $single_sid = $target_ids[0];
                    $stmt_upd = $conn->prepare("UPDATE spiritual_evaluations
                        SET seminarian_id = ?, spiritual_director_id = ?, academic_year = ?, semester = ?, evaluation_date = ?, updated_at = NOW()
                        WHERE evaluation_id = ? AND spiritual_director_id = ? AND archived_at IS NULL");
                    $stmt_upd->bind_param('iisssii', $single_sid, $director_id, $academic_year_post, $semester_post, $evaluation_date, $evaluation_id_post, $director_id);
                    if (!$stmt_upd->execute()) {
                        throw new RuntimeException('Failed to update spiritual evaluation.');
                    }

                    $stmt_del = $conn->prepare("DELETE FROM spiritual_responses WHERE evaluation_id = ?");
                    $stmt_del->bind_param('i', $evaluation_id_post);
                    if (!$stmt_del->execute()) {
                        throw new RuntimeException('Failed to clear old spiritual responses.');
                    }

                    $eval_entries[] = ['eval_id' => $evaluation_id_post, 'sid' => $single_sid];
                } else {
                    $stmt_ins = $conn->prepare("INSERT INTO spiritual_evaluations (seminarian_id, spiritual_director_id, academic_year, semester, evaluation_date, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$stmt_ins) {
                        throw new RuntimeException('Failed to prepare spiritual evaluation insert.');
                    }
                    foreach ($target_ids as $target_sid) {
                        if (eval_find_active_duplicate($conn, 'spiritual', $target_sid, $academic_year_post, $semester_post, 0)) {
                            throw new RuntimeException('Duplicate active spiritual evaluation detected for seminarian #' . $target_sid);
                        }
                        $stmt_ins->bind_param('iisss', $target_sid, $director_id, $academic_year_post, $semester_post, $evaluation_date);
                        if (!$stmt_ins->execute()) {
                            throw new RuntimeException('Failed to insert spiritual evaluation.');
                        }
                        $eval_entries[] = ['eval_id' => (int)$conn->insert_id, 'sid' => $target_sid];
                    }
                }

                $interp = (string)($calc['interpretation'] ?? '');
                $total_score = (int)$calc['total'];
                $avg_score = (float)$calc['average'];
                $cat_avgs = $calc['category_avgs'] ?? [];
                $prayer = isset($cat_avgs['Prayer Life']) ? (float)$cat_avgs['Prayer Life'] : null;
                $sacramental = isset($cat_avgs['Sacramental Participation']) ? (float)$cat_avgs['Sacramental Participation'] : null;
                $discipline = isset($cat_avgs['Spiritual Discipline']) ? (float)$cat_avgs['Spiritual Discipline'] : null;
                $vocation = isset($cat_avgs['Relationship with God & Vocation']) ? (float)$cat_avgs['Relationship with God & Vocation'] : null;
                $virtues = isset($cat_avgs['Christian Virtues & Community Spirituality']) ? (float)$cat_avgs['Christian Virtues & Community Spirituality'] : null;

                $stmt_resp = $conn->prepare("INSERT INTO spiritual_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                $stmt_res = $conn->prepare("INSERT INTO spiritual_results (evaluation_id, total_score, average_rating, interpretation, prayer_life_avg, sacramental_participation_avg, spiritual_discipline_avg, relationship_vocation_avg, christian_virtues_avg, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE total_score = VALUES(total_score), average_rating = VALUES(average_rating), interpretation = VALUES(interpretation),
                        prayer_life_avg = VALUES(prayer_life_avg), sacramental_participation_avg = VALUES(sacramental_participation_avg),
                        spiritual_discipline_avg = VALUES(spiritual_discipline_avg), relationship_vocation_avg = VALUES(relationship_vocation_avg),
                        christian_virtues_avg = VALUES(christian_virtues_avg), computed_at = NOW()");
                $stmt_rem_up = $conn->prepare("INSERT INTO spiritual_remarks (evaluation_id, strengths, areas_for_growth, challenges, recommendations, formation_plan, follow_up_schedule, updated_at)
                    VALUES (?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NOW())
                    ON DUPLICATE KEY UPDATE strengths = VALUES(strengths), areas_for_growth = VALUES(areas_for_growth), challenges = VALUES(challenges),
                        recommendations = VALUES(recommendations), formation_plan = VALUES(formation_plan), follow_up_schedule = VALUES(follow_up_schedule), updated_at = NOW()");
                $stmt_prev = $conn->prepare("SELECT r.average_rating
                    FROM spiritual_results r
                    JOIN spiritual_evaluations e ON e.evaluation_id = r.evaluation_id
                    WHERE e.archived_at IS NULL AND e.seminarian_id = ? AND e.evaluation_id <> ?
                    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC
                    LIMIT 1");
                $stmt_ga = $conn->prepare("INSERT INTO spiritual_analytics (seminarian_id, evaluation_id, previous_score, current_score, growth_status, growth_percentage, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE previous_score = VALUES(previous_score), current_score = VALUES(current_score), growth_status = VALUES(growth_status), growth_percentage = VALUES(growth_percentage), computed_at = NOW()");

                if (!$stmt_resp || !$stmt_res || !$stmt_rem_up || !$stmt_prev || !$stmt_ga) {
                    throw new RuntimeException('Failed to prepare secondary spiritual statements.');
                }

                $saved_eval_ids = [];
                foreach ($eval_entries as $entry) {
                    $evaluation_id_final = (int)$entry['eval_id'];
                    $entry_sid = (int)$entry['sid'];

                    foreach ($questions as $q) {
                        $qid = (int)$q['question_id'];
                        $val = isset($ratings[$qid]) ? (int)$ratings[$qid] : 0;
                        $stmt_resp->bind_param('iii', $evaluation_id_final, $qid, $val);
                        if (!$stmt_resp->execute()) {
                            throw new RuntimeException('Failed to insert spiritual response.');
                        }
                    }

                    $stmt_res->bind_param('iidsddddd', $evaluation_id_final, $total_score, $avg_score, $interp, $prayer, $sacramental, $discipline, $vocation, $virtues);
                    if (!$stmt_res->execute()) {
                        throw new RuntimeException('Failed to insert spiritual result.');
                    }

                    $stmt_rem_up->bind_param(
                        'issssss',
                        $evaluation_id_final,
                        $remarks_post['strengths'],
                        $remarks_post['areas_for_growth'],
                        $remarks_post['challenges'],
                        $remarks_post['recommendations'],
                        $remarks_post['formation_plan'],
                        $remarks_post['follow_up_schedule']
                    );
                    if (!$stmt_rem_up->execute()) {
                        throw new RuntimeException('Failed to insert spiritual remarks.');
                    }

                    $prev = null;
                    $stmt_prev->bind_param('ii', $entry_sid, $evaluation_id_final);
                    $stmt_prev->execute();
                    $prev_row = $stmt_prev->get_result()->fetch_assoc();
                    if ($prev_row && $prev_row['average_rating'] !== null) {
                        $prev = (float)$prev_row['average_rating'];
                    }

                    $growth = sfe_compute_growth($prev, $avg_score);
                    $g_status = $growth['status'];
                    $g_pct = $growth['percent'];

                    $stmt_ga->bind_param('iiddsd', $entry_sid, $evaluation_id_final, $prev, $avg_score, $g_status, $g_pct);
                    if (!$stmt_ga->execute()) {
                        throw new RuntimeException('Failed to insert spiritual analytics.');
                    }

                    $saved_eval_ids[] = $evaluation_id_final;
                }

                $conn->commit();
                eval_redirect_after_save('spiritual', $academic_year_post, $semester_post, $saved_eval_ids, $is_bulk_post);
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Failed to save evaluation.';
            }
        }
    }
}
?>

<?php
$pageTitle = $evaluation_id > 0 ? 'Edit Spiritual Evaluation' : 'New Spiritual Evaluation';
$activeNav = 'sfe_evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';

$student_label = '';
$student_view_enabled = 0;
foreach ($students as $s) {
    if ((int)$s['id'] === (int)$student_id) {
        $student_label = trim((string)($s['full_name'] ?? ''));
        if ($student_label === '') $student_label = (string)($s['username'] ?? '');
        break;
    }
}
if ($student_id > 0) {
    require_once __DIR__ . '/../includes/access_requests.php';
    $student_view_enabled = capstone_access_can_view_evaluation($conn, $student_id, 'Spiritual', $filter_academic_year, $filter_semester) ? 1 : 0;
}

$grouped = [];
foreach ($questions as $q) {
    $cat = (string)($q['category'] ?? '');
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = $q;
}
?>
        <div class="content-body">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.15rem;">
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-hands-praying"></i> <?php echo $evaluation_id > 0 ? 'Edit Spiritual Evaluation' : 'New Spiritual Evaluation'; ?></h1>
                <a href="sfe_evaluations.php" class="eval-back-btn print:hidden"><i class="fas fa-arrow-left" aria-hidden="true"></i> <span>Back</span></a>
            </div>

            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
            <?php eval_render_group_evaluation_header('spiritual', $group_ctx, $filter_academic_year, $filter_semester); ?>

            <?php if (!$is_group_mode) { ?>
            <div class="card eval-filter-card" style="margin-top: 0;">
                <form method="get" class="eval-filter-grid">
                    <?php if ($evaluation_id > 0) { ?><input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>"><?php } ?>
                    <div class="eval-filter-field">
                        <label for="sfeStudentSelect">Seminarian</label>
                        <select id="sfeStudentSelect" name="student_id" required>
                            <option value="">Select seminarian</option>
                            <?php foreach ($students as $s) {
                                $name = trim((string)($s['full_name'] ?? ''));
                                if ($name === '') $name = (string)($s['username'] ?? '');
                                $s_grad = !empty($s['is_graduated']);
                                $s_enrolled = !empty($s['is_enrolled_active']);
                                $s_disabled = ($s_grad || !$s_enrolled);
                            ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$student_id ? 'selected' : ''; ?> <?php echo $s_disabled ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?> (#<?php echo (int)$s['id']; ?>)<?php echo $s_grad ? ' — [Graduated]' : (!$s_enrolled ? ' — [Not Enrolled in Active Term]' : ''); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="eval-filter-field">
                        <label for="sfeAcademicYearInput">Academic Year</label>
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($active_term_ay); ?>">
                        <input id="sfeAcademicYearInput" type="text" value="<?php echo htmlspecialchars($filter_academic_year); ?>" disabled style="background:#f8fafc;color:#334155;cursor:not-allowed;">
                    </div>
                    <div class="eval-filter-field">
                        <label for="sfeSemesterSelect">Semester</label>
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($active_term_sem); ?>">
                        <select id="sfeSemesterSelect" disabled style="background:#f8fafc;color:#334155;cursor:not-allowed;">
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="eval-load-btn"><i class="fas fa-sync" aria-hidden="true"></i> <span>Load</span></button>
                    </div>
                </form>
                <?php eval_render_active_term_lock_badge($active_term_ay, $active_term_sem, $advance_term_rejected && $evaluation_id <= 0); ?>
            </div>
            <?php } ?>

            <?php if ((($is_group_mode && !empty($group_ctx['has_recipients'])) || (!$is_group_mode && $student_id > 0)) && count($questions) > 0) { ?>
                <div class="eval-summary-grid">
                    <div class="eval-summary-card">
                        <div class="eval-summary-icon bg-sky-50 text-sky-600 border border-sky-200/70"><i class="fas <?php echo $is_group_mode ? 'fa-users' : 'fa-user-graduate'; ?>" aria-hidden="true"></i></div>
                        <div class="eval-summary-meta">
                            <div class="eval-summary-label"><?php echo $is_group_mode ? 'Evaluation Recipients' : 'Seminarian'; ?></div>
                            <div class="eval-summary-value"><?php echo $is_group_mode ? ('Group (' . count($group_ctx['pending_items']) . ' Seminarians)') : htmlspecialchars($student_label !== '' ? $student_label : ('#' . (int)$student_id)); ?></div>
                        </div>
                    </div>
                    <div class="eval-summary-card">
                        <div class="eval-summary-icon <?php echo ($is_group_mode || $student_view_enabled) ? 'bg-emerald-50 text-emerald-600 border border-emerald-200/70' : 'bg-amber-50 text-amber-600 border border-amber-200/70'; ?>"><i class="fas <?php echo $is_group_mode ? 'fa-layer-group' : 'fa-eye'; ?>" aria-hidden="true"></i></div>
                        <div class="eval-summary-meta">
                            <div class="eval-summary-label"><?php echo $is_group_mode ? 'One Form for Many' : 'Seminarian Report Access'; ?></div>
                            <div class="eval-summary-value <?php echo ($is_group_mode || $student_view_enabled) ? 'text-emerald-700' : 'text-amber-700'; ?>"><?php echo $is_group_mode ? 'Bulk Mode' : ($student_view_enabled ? 'Enabled' : 'Disabled'); ?></div>
                        </div>
                    </div>
                </div>

                <?php
                eval_render_rating_scale_strip(
                    [5 => 'Outstanding', 4 => 'Very Good', 3 => 'Satisfactory', 2 => 'Needs Improvement', 1 => 'Poor'],
                    (!$is_group_mode && $student_id > 0) ? (bool)$student_view_enabled : null,
                    '',
                    'Rating Scale'
                );
                ?>

                <?php
                $is_selected_graduated = (!$is_group_mode && $student_id > 0)
                    ? eval_check_seminarian_graduated_db($conn, $student_id)
                    : false;
                $is_selected_unenrolled = (!$is_group_mode && !$is_selected_graduated && $evaluation_id <= 0 && $student_id > 0 && empty($enrolled_active_map[$student_id]));
                $duplicate_eval = (!$is_group_mode && !$is_selected_graduated && !$is_selected_unenrolled && $evaluation_id <= 0 && $student_id > 0)
                    ? eval_find_active_duplicate($conn, 'spiritual', $student_id, $filter_academic_year, $filter_semester)
                    : null;
                if ($is_historical_readonly) {
                    eval_render_inactive_term_blocked_banner('spiritual', $student_label, $active_term_ay, $active_term_sem, (int)$evaluation_id, (string)($edit['academic_year'] ?? ''), (string)($edit['semester'] ?? ''));
                } elseif ($is_selected_graduated) {
                    eval_render_graduated_blocked_banner('spiritual', $student_label, $filter_academic_year, $filter_semester);
                } elseif ($is_selected_unenrolled) {
                    eval_render_not_enrolled_blocked_banner('spiritual', $student_label, $active_term_ay, $active_term_sem);
                } elseif ($duplicate_eval) {
                    eval_render_already_evaluated_banner('spiritual', (int)$duplicate_eval['evaluation_id'], $student_label, $filter_academic_year, $filter_semester);
                } else {
                ?>
                <form method="post" id="sfeEvalForm" style="margin-top: 1.25rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>">
                    <?php if ($is_group_mode) {
                        eval_render_group_hidden_inputs($group_ctx);
                    } else { ?>
                    <input type="hidden" name="seminarian_id" value="<?php echo (int)$student_id; ?>">
                    <?php } ?>
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                    <div class="card eval-filter-card" style="margin-top: 0;">
                        <div class="eval-meta-grid">
                            <div class="eval-filter-field">
                                <label for="sfeEvalDateInput">Evaluation Date</label>
                                <input id="sfeEvalDateInput" type="date" name="evaluation_date" required value="<?php echo htmlspecialchars($edit ? (string)$edit['evaluation_date'] : date('Y-m-d')); ?>">
                            </div>
                        </div>
                    </div>

                    <?php foreach ($grouped as $cat => $items) { ?>
                        <div class="data-card eval-criteria-card" style="margin-top: 1.25rem;">
                            <div class="data-card-header">
                                <h3><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($cat); ?></h3>
                            </div>
                            <div class="eval-criteria-table-wrap">
                                <table class="eval-criteria-table">
                                    <thead>
                                        <tr>
                                            <th>Question</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $q) {
                                            $qid = (int)$q['question_id'];
                                            $val = (int)($existing_responses[$qid] ?? 0);
                                            $labels = [5 => 'Outstanding', 4 => 'Very Good', 3 => 'Satisfactory', 2 => 'Needs Improvement', 1 => 'Poor'];
                                        ?>
                                            <tr class="eval-question-row" data-qid="<?php echo $qid; ?>">
                                                <td>
                                                    <div style="font-weight: 900; color: #0f172a;">
                                                        <?php echo htmlspecialchars((string)$q['question_text']); ?>
                                                    </div>
                                                    <div class="eval-q-error"><i class="fas fa-circle-exclamation"></i> Please select a rating (1–5) for this question.</div>
                                                    <div style="margin-top: 0.55rem;">
                                                        <input type="hidden" name="rating[<?php echo $qid; ?>]" id="rating_<?php echo $qid; ?>" value="<?php echo (int)$val; ?>">
                                                        <div class="likert-btns" data-qid="<?php echo $qid; ?>">
                                                            <?php foreach ([5,4,3,2,1] as $i) { ?>
                                                                <button type="button" class="likert-btn <?php echo $val === $i ? 'active' : ''; ?>" data-value="<?php echo $i; ?>">
                                                                    <span><?php echo $i; ?></span>
                                                                    <span class="likert-label"><?php echo htmlspecialchars((string)($labels[$i] ?? '')); ?></span>
                                                                </button>
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php } ?>

                    <div class="data-card" style="margin-top: 1.25rem;">
                        <div class="data-card-header">
                            <h3><i class="fas fa-message"></i> Spiritual Director Remarks</h3>
                        </div>
                        <div class="eval-remarks-grid">
                            <div class="field" style="margin: 0;">
                                <label>Spiritual Strengths</label>
                                <textarea name="strengths" rows="4"><?php echo htmlspecialchars((string)$remarks['strengths']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Areas for Growth</label>
                                <textarea name="areas_for_growth" rows="4"><?php echo htmlspecialchars((string)$remarks['areas_for_growth']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Spiritual Challenges Observed</label>
                                <textarea name="challenges" rows="4"><?php echo htmlspecialchars((string)$remarks['challenges']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Recommendations</label>
                                <textarea name="recommendations" rows="4"><?php echo htmlspecialchars((string)$remarks['recommendations']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Formation Plan</label>
                                <textarea name="formation_plan" rows="4"><?php echo htmlspecialchars((string)$remarks['formation_plan']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Follow-Up Schedule</label>
                                <textarea name="follow_up_schedule" rows="4" placeholder="e.g. Monthly spiritual direction; next follow-up on ..."><?php echo htmlspecialchars((string)$remarks['follow_up_schedule']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="card eval-submit-bar print:hidden">
                        <a href="sfe_evaluations.php" class="eval-back-btn print:hidden">Cancel</a>
                        <button type="submit" name="save_evaluation" class="eval-submit-btn print:hidden" data-confirm="Submit this spiritual formation evaluation? Once saved, results will be recorded for the selected term." data-confirm-title="Submit Evaluation" data-confirm-text="Submit Evaluation" data-confirm-variant="primary" data-confirm-loading="Saving...">
                            <i class="fas fa-save" aria-hidden="true"></i> <span><?php echo $is_group_mode ? ('Save Evaluation for All (' . count($group_ctx['pending_items']) . ')') : 'Save Evaluation'; ?></span>
                        </button>
                    </div>
                </form>
                <?php
                eval_render_smart_validation_assets('sfeEvalForm');
                }
                ?>
            <?php } elseif ($is_group_mode && empty($group_ctx['has_recipients'])) { ?>
            <?php } elseif ($student_id > 0 && count($questions) === 0) { ?>
                <div class="card" style="border-left: 6px solid #ef4444;">
                    <h3><i class="fas fa-triangle-exclamation"></i> No Questions Found</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">No active spiritual evaluation questions are available.</div>
                </div>
            <?php } else { ?>
                <div class="card" style="border-left: 6px solid #f59e0b; margin-top: 1.25rem;">
                    <h3><i class="fas fa-circle-info"></i> Select a Seminarian</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">Choose a seminarian and term, then click Load to start the evaluation.</div>
                </div>
            <?php } ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
