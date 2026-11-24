<?php
include '../includes/session_fix.php';
require_roles(['HumanFormator']);
include '../includes/db.php';
include '../includes/human_evaluation.php';

$formator_id = (int)($_SESSION['user'] ?? 0);

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = hfe_get_default_semester();

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$group_ctx = eval_init_group_batch($conn, 'human', $formator_id, $filter_academic_year, $filter_semester, $evaluation_id);
$is_group_mode = !empty($group_ctx['active']);

$questions = [];
$res_q = $conn->query("SELECT question_id, category, question_text, sort_order FROM human_questions WHERE is_active = 1 ORDER BY sort_order ASC, question_id ASC");
if ($res_q) {
    while ($row = $res_q->fetch_assoc()) {
        $questions[] = $row;
    }
}

$students = [];
$stmt_students = $conn->prepare("SELECT s.id, u.username, up.full_name, s.human_eval_view_enabled,
        s.year_level, s.formation_stage, s.enrollment_status, s.formation_status, s.is_graduate, s.completed_at,
        stu.is_graduate AS stu_is_graduate, stu.graduated_at, stu.formation_status AS stu_formation_status
    FROM seminarians s
    JOIN seminarian_assignments sa ON sa.seminarian_id = s.id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students stu ON stu.user_id = u.id
    WHERE u.role = 'Student'
      AND sa.assignment_type = 'Human'
      AND sa.assigned_user_id = ?
      AND sa.active = 1
    ORDER BY COALESCE(up.full_name, u.username) ASC");
$stmt_students->bind_param('i', $formator_id);
$stmt_students->execute();
$res_s = $stmt_students->get_result();
while ($res_s && ($row = $res_s->fetch_assoc())) {
    $row['is_graduated'] = eval_is_seminarian_graduated($row);
    $row['status'] = $row['is_graduated'] ? 'graduated' : 'active';
    $students[] = $row;
}

$existing_responses = [];
$edit = null;
$remarks = [
    'strengths' => '',
    'weaknesses' => '',
    'behavioral_concerns' => '',
    'recommendations' => '',
    'follow_up_actions' => '',
];

if ($evaluation_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM human_evaluations WHERE evaluation_id = ? AND formator_id = ? LIMIT 1");
    $stmt->bind_param('ii', $evaluation_id, $formator_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    if ($edit) {
        $student_id = (int)$edit['seminarian_id'];
        $filter_academic_year = (string)$edit['academic_year'];
        $filter_semester = (string)$edit['semester'];
    }

    $stmt_r = $conn->prepare("SELECT question_id, rating FROM human_responses WHERE evaluation_id = ?");
    $stmt_r->bind_param('i', $evaluation_id);
    $stmt_r->execute();
    $res = $stmt_r->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing_responses[(int)$row['question_id']] = (int)$row['rating'];
    }

    $stmt_rem = $conn->prepare("SELECT strengths, weaknesses, behavioral_concerns, recommendations, follow_up_actions FROM human_remarks WHERE evaluation_id = ? LIMIT 1");
    $stmt_rem->bind_param('i', $evaluation_id);
    $stmt_rem->execute();
    $rem = $stmt_rem->get_result()->fetch_assoc();
    if ($rem) {
        foreach ($remarks as $k => $v) {
            $remarks[$k] = (string)($rem[$k] ?? '');
        }
    }
}

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
        'weaknesses' => trim((string)($_POST['weaknesses'] ?? '')),
        'behavioral_concerns' => trim((string)($_POST['behavioral_concerns'] ?? '')),
        'recommendations' => trim((string)($_POST['recommendations'] ?? '')),
        'follow_up_actions' => trim((string)($_POST['follow_up_actions'] ?? '')),
    ];

    if (empty($target_ids) || $academic_year_post === '' || !in_array($semester_post, ['1st','2nd','Summer'], true)) {
        $error = 'Please select seminarian(s), academic year, and semester.';
    } else {
        foreach ($target_ids as $chk_sid) {
            if (eval_check_seminarian_graduated_db($conn, (int)$chk_sid)) {
                $error = eval_block_graduated_submission();
                break;
            }
        }
        if (!isset($error)) {
            $part = eval_partition_batch_ids($conn, 'human', $formator_id, $target_ids, $academic_year_post, $semester_post, $evaluation_id_post);
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

    if (!isset($error)) {
        $calc = hfe_compute_results($questions, $ratings);
        if ($calc['count'] !== count($questions)) {
            $error = 'Please answer all questions.';
        } else {
            $conn->begin_transaction();
            try {
                $eval_entries = [];
                if ($evaluation_id_post > 0) {
                    $single_sid = $target_ids[0];
                    $stmt_upd = $conn->prepare("UPDATE human_evaluations
                        SET seminarian_id = ?, formator_id = ?, academic_year = ?, semester = ?, evaluation_date = ?, updated_at = NOW()
                        WHERE evaluation_id = ? AND formator_id = ? AND archived_at IS NULL");
                    $stmt_upd->bind_param('iisssii', $single_sid, $formator_id, $academic_year_post, $semester_post, $evaluation_date, $evaluation_id_post, $formator_id);
                    if (!$stmt_upd->execute()) {
                        throw new RuntimeException('Failed to update human evaluation.');
                    }

                    $stmt_del = $conn->prepare("DELETE FROM human_responses WHERE evaluation_id = ?");
                    $stmt_del->bind_param('i', $evaluation_id_post);
                    if (!$stmt_del->execute()) {
                        throw new RuntimeException('Failed to clear old human responses.');
                    }

                    $eval_entries[] = ['eval_id' => $evaluation_id_post, 'sid' => $single_sid];
                } else {
                    $stmt_ins = $conn->prepare("INSERT INTO human_evaluations (seminarian_id, formator_id, academic_year, semester, evaluation_date, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$stmt_ins) {
                        throw new RuntimeException('Failed to prepare human evaluation insert.');
                    }
                    foreach ($target_ids as $target_sid) {
                        if (eval_find_active_duplicate($conn, 'human', $target_sid, $academic_year_post, $semester_post, 0)) {
                            throw new RuntimeException('Duplicate active human evaluation detected for seminarian #' . $target_sid);
                        }
                        $stmt_ins->bind_param('iisss', $target_sid, $formator_id, $academic_year_post, $semester_post, $evaluation_date);
                        if (!$stmt_ins->execute()) {
                            throw new RuntimeException('Failed to insert human evaluation.');
                        }
                        $eval_entries[] = ['eval_id' => (int)$conn->insert_id, 'sid' => $target_sid];
                    }
                }

                $interp = (string)($calc['interpretation'] ?? '');
                $total_score = (int)$calc['total'];
                $avg_score = (float)$calc['average'];
                $cat_avgs = $calc['category_avgs'] ?? [];
                $responsibility = isset($cat_avgs['Personal Responsibility']) ? (float)$cat_avgs['Personal Responsibility'] : null;
                $integrity = isset($cat_avgs['Moral Character and Integrity']) ? (float)$cat_avgs['Moral Character and Integrity'] : null;
                $emotional = isset($cat_avgs['Emotional Maturity']) ? (float)$cat_avgs['Emotional Maturity'] : null;
                $interpersonal = isset($cat_avgs['Interpersonal Relationships']) ? (float)$cat_avgs['Interpersonal Relationships'] : null;
                $leadership = isset($cat_avgs['Leadership and Community Participation']) ? (float)$cat_avgs['Leadership and Community Participation'] : null;

                $stmt_resp = $conn->prepare("INSERT INTO human_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                $stmt_res = $conn->prepare("INSERT INTO human_results (evaluation_id, overall_score, average_rating, interpretation, responsibility_avg, integrity_avg, emotional_maturity_avg, interpersonal_avg, leadership_avg, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE overall_score = VALUES(overall_score), average_rating = VALUES(average_rating), interpretation = VALUES(interpretation),
                        responsibility_avg = VALUES(responsibility_avg), integrity_avg = VALUES(integrity_avg), emotional_maturity_avg = VALUES(emotional_maturity_avg),
                        interpersonal_avg = VALUES(interpersonal_avg), leadership_avg = VALUES(leadership_avg), computed_at = NOW()");
                $stmt_rem_up = $conn->prepare("INSERT INTO human_remarks (evaluation_id, strengths, weaknesses, behavioral_concerns, recommendations, follow_up_actions, updated_at)
                    VALUES (?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NOW())
                    ON DUPLICATE KEY UPDATE strengths = VALUES(strengths), weaknesses = VALUES(weaknesses), behavioral_concerns = VALUES(behavioral_concerns),
                        recommendations = VALUES(recommendations), follow_up_actions = VALUES(follow_up_actions), updated_at = NOW()");
                $stmt_prev = $conn->prepare("SELECT r.average_rating
                    FROM human_results r
                    JOIN human_evaluations e ON e.evaluation_id = r.evaluation_id
                    WHERE e.archived_at IS NULL AND e.seminarian_id = ? AND e.evaluation_id <> ?
                    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC
                    LIMIT 1");
                $stmt_ga = $conn->prepare("INSERT INTO human_analytics (seminarian_id, evaluation_id, previous_score, current_score, growth_status, growth_percentage, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE previous_score = VALUES(previous_score), current_score = VALUES(current_score), growth_status = VALUES(growth_status), growth_percentage = VALUES(growth_percentage), computed_at = NOW()");

                if (!$stmt_resp || !$stmt_res || !$stmt_rem_up || !$stmt_prev || !$stmt_ga) {
                    throw new RuntimeException('Failed to prepare secondary human evaluation statements.');
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
                            throw new RuntimeException('Failed to insert human response.');
                        }
                    }

                    $stmt_res->bind_param('iidsddddd', $evaluation_id_final, $total_score, $avg_score, $interp, $responsibility, $integrity, $emotional, $interpersonal, $leadership);
                    if (!$stmt_res->execute()) {
                        throw new RuntimeException('Failed to insert human result.');
                    }

                    $stmt_rem_up->bind_param(
                        'isssss',
                        $evaluation_id_final,
                        $remarks_post['strengths'],
                        $remarks_post['weaknesses'],
                        $remarks_post['behavioral_concerns'],
                        $remarks_post['recommendations'],
                        $remarks_post['follow_up_actions']
                    );
                    if (!$stmt_rem_up->execute()) {
                        throw new RuntimeException('Failed to insert human remarks.');
                    }

                    $prev = null;
                    $stmt_prev->bind_param('ii', $entry_sid, $evaluation_id_final);
                    $stmt_prev->execute();
                    $prev_row = $stmt_prev->get_result()->fetch_assoc();
                    if ($prev_row && $prev_row['average_rating'] !== null) {
                        $prev = (float)$prev_row['average_rating'];
                    }

                    $growth = hfe_compute_growth($prev, $avg_score);
                    $g_status = $growth['status'];
                    $g_pct = $growth['percent'];

                    $stmt_ga->bind_param('iiddsd', $entry_sid, $evaluation_id_final, $prev, $avg_score, $g_status, $g_pct);
                    if (!$stmt_ga->execute()) {
                        throw new RuntimeException('Failed to insert human analytics.');
                    }

                    $saved_eval_ids[] = $evaluation_id_final;
                }

                $conn->commit();
                eval_redirect_after_save('human', $academic_year_post, $semester_post, $saved_eval_ids, $is_bulk_post);
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Failed to save evaluation.';
            }
        }
    }
}
?>

<?php
$pageTitle = $evaluation_id > 0 ? 'Edit Human Evaluation' : 'New Human Evaluation';
$activeNav = 'hfe_evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';

$student_label = '';
$student_view_enabled = 0;
foreach ($students as $s) {
    if ((int)$s['id'] === (int)$student_id) {
        $student_label = trim((string)($s['full_name'] ?? ''));
        if ($student_label === '') $student_label = (string)($s['username'] ?? '');
        $student_view_enabled = (int)($s['human_eval_view_enabled'] ?? 0);
        break;
    }
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
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-user-check"></i> <?php echo $evaluation_id > 0 ? 'Edit Human Evaluation' : 'New Human Evaluation'; ?></h1>
                <a href="hfe_evaluations.php" class="eval-back-btn print:hidden"><i class="fas fa-arrow-left" aria-hidden="true"></i> <span>Back</span></a>
            </div>

            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
            <?php eval_render_group_evaluation_header('human', $group_ctx, $filter_academic_year, $filter_semester); ?>

            <?php if (!$is_group_mode) { ?>
            <div class="card eval-filter-card" style="margin-top: 0;">
                <form method="get" class="eval-filter-grid">
                    <?php if ($evaluation_id > 0) { ?><input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>"><?php } ?>
                    <div class="eval-filter-field">
                        <label for="hfeStudentSelect">Seminarian</label>
                        <select id="hfeStudentSelect" name="student_id" required>
                            <option value="">Select seminarian</option>
                            <?php foreach ($students as $s) {
                                $name = trim((string)($s['full_name'] ?? ''));
                                if ($name === '') $name = (string)($s['username'] ?? '');
                                $s_grad = !empty($s['is_graduated']);
                            ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$student_id ? 'selected' : ''; ?> <?php echo $s_grad ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?> (#<?php echo (int)$s['id']; ?>)<?php echo $s_grad ? ' — [Graduated]' : ''; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="eval-filter-field">
                        <label for="hfeAcademicYearInput">Academic Year</label>
                        <input id="hfeAcademicYearInput" type="text" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>" required>
                    </div>
                    <div class="eval-filter-field">
                        <label for="hfeSemesterSelect">Semester</label>
                        <select id="hfeSemesterSelect" name="semester">
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="eval-load-btn"><i class="fas fa-rotate" aria-hidden="true"></i> <span>Load</span></button>
                    </div>
                </form>
            </div>
            <?php } ?>

            <?php if (($is_group_mode && !empty($group_ctx['has_recipients'])) || (!$is_group_mode && $student_id > 0)) { ?>
                <?php
                $is_selected_graduated = (!$is_group_mode && $student_id > 0)
                    ? eval_check_seminarian_graduated_db($conn, $student_id)
                    : false;
                $duplicate_eval = (!$is_group_mode && !$is_selected_graduated && $evaluation_id <= 0 && $student_id > 0)
                    ? eval_find_active_duplicate($conn, 'human', $student_id, $filter_academic_year, $filter_semester)
                    : null;
                if ($is_selected_graduated) {
                    eval_render_graduated_blocked_banner('human', $student_label, $filter_academic_year, $filter_semester);
                } elseif ($duplicate_eval) {
                    $dup_student_name = '';
                    foreach ($students as $s_opt) {
                        if ((int)$s_opt['id'] === (int)$student_id) {
                            $dup_student_name = trim((string)($s_opt['full_name'] ?? ''));
                            if ($dup_student_name === '') $dup_student_name = (string)($s_opt['username'] ?? '');
                            break;
                        }
                    }
                    eval_render_already_evaluated_banner('human', (int)$duplicate_eval['evaluation_id'], $dup_student_name, $filter_academic_year, $filter_semester);
                } else {
                ?>
                <form method="post" id="hfeEvalForm" class="card eval-filter-card" style="margin-top: 1.25rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>">
                    <?php if ($is_group_mode) {
                        eval_render_group_hidden_inputs($group_ctx);
                    } else { ?>
                    <input type="hidden" name="seminarian_id" value="<?php echo (int)$student_id; ?>">
                    <?php } ?>
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                    <div class="eval-meta-grid">
                        <div class="eval-filter-field">
                            <label for="hfeEvalDateInput">Evaluation Date</label>
                            <input id="hfeEvalDateInput" type="date" name="evaluation_date" value="<?php echo htmlspecialchars((string)($edit['evaluation_date'] ?? date('Y-m-d'))); ?>" required>
                        </div>
                    </div>

                    <?php
                    $hfe_scale = [];
                    foreach ([5, 4, 3, 2, 1] as $score_v) {
                        $hfe_scale[$score_v] = hfe_likert_label($score_v);
                    }
                    eval_render_rating_scale_strip(
                        $hfe_scale,
                        (!$is_group_mode && $student_id > 0) ? (bool)$student_view_enabled : null,
                        (!$is_group_mode && $student_id > 0) ? $student_label : '',
                        'Rating Scale'
                    );
                    ?>

                    <?php foreach ($grouped as $cat => $items) { ?>
                        <div class="data-card eval-criteria-card" style="margin-top: 1.25rem;">
                            <div class="data-card-header">
                                <h3><i class="fas fa-list"></i> <?php echo htmlspecialchars($cat); ?></h3>
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
                                            $cur = isset($_POST['rating'][$qid]) ? (int)$_POST['rating'][$qid] : (isset($existing_responses[$qid]) ? (int)$existing_responses[$qid] : 0);
                                        ?>
                                            <tr class="eval-question-row" data-qid="<?php echo $qid; ?>">
                                                <td>
                                                    <div style="font-weight: 900; color: #0f172a;">
                                                        <?php echo htmlspecialchars((string)$q['question_text']); ?>
                                                    </div>
                                                    <div class="eval-q-error"><i class="fas fa-circle-exclamation"></i> Please select a rating (1–5) for this question.</div>
                                                    <div style="margin-top: 0.55rem;">
                                                        <input type="hidden" name="rating[<?php echo $qid; ?>]" id="rating_<?php echo $qid; ?>" value="<?php echo (int)$cur; ?>">
                                                        <div class="likert-btns" data-qid="<?php echo $qid; ?>">
                                                            <?php foreach ([5,4,3,2,1] as $v) { ?>
                                                                <button type="button" class="likert-btn <?php echo $cur === $v ? 'active' : ''; ?>" data-value="<?php echo $v; ?>">
                                                                    <span><?php echo $v; ?></span>
                                                                    <span class="likert-label"><?php echo htmlspecialchars(hfe_likert_label($v)); ?></span>
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
                            <h3><i class="fas fa-comment-dots"></i> Formator Remarks</h3>
                        </div>
                        <div class="eval-remarks-grid">
                            <div class="field" style="margin: 0;">
                                <label>Strengths Observed</label>
                                <textarea name="strengths" rows="4"><?php echo htmlspecialchars((string)($_POST['strengths'] ?? $remarks['strengths'])); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Areas for Improvement</label>
                                <textarea name="weaknesses" rows="4"><?php echo htmlspecialchars((string)($_POST['weaknesses'] ?? $remarks['weaknesses'])); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Behavioral Concerns</label>
                                <textarea name="behavioral_concerns" rows="4"><?php echo htmlspecialchars((string)($_POST['behavioral_concerns'] ?? $remarks['behavioral_concerns'])); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Formation Recommendations</label>
                                <textarea name="recommendations" rows="4"><?php echo htmlspecialchars((string)($_POST['recommendations'] ?? $remarks['recommendations'])); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Follow-up Actions</label>
                                <textarea name="follow_up_actions" rows="4"><?php echo htmlspecialchars((string)($_POST['follow_up_actions'] ?? $remarks['follow_up_actions'])); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="eval-submit-bar print:hidden">
                        <a href="hfe_evaluations.php" class="eval-back-btn print:hidden">Cancel</a>
                        <button type="submit" name="save_evaluation" class="eval-submit-btn print:hidden" data-confirm="Submit this human formation evaluation? Once saved, results will be recorded for the selected term." data-confirm-title="Submit Evaluation" data-confirm-text="Submit Evaluation" data-confirm-variant="primary" data-confirm-loading="Saving...">
                            <i class="fas fa-save" aria-hidden="true"></i> <span><?php echo $is_group_mode ? ('Save Evaluation for All (' . count($group_ctx['pending_items']) . ')') : 'Save Evaluation'; ?></span>
                        </button>
                    </div>
                </form>
                <?php
                eval_render_smart_validation_assets('hfeEvalForm');
                }
                ?>
            <?php } elseif (!$is_group_mode) { ?>
                <div class="card" style="margin-top: 1.25rem; color: var(--text-secondary); font-weight: 800;">
                    Select a seminarian above and click Load to start the evaluation.
                </div>
            <?php } ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
