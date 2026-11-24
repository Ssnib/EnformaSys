<?php
include '../includes/session_fix.php';
require_roles(['PastoralFormator']);
include '../includes/db.php';
include '../includes/pastoral_evaluation.php';

$formator_id = (int)($_SESSION['user'] ?? 0);

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = pfe_get_default_semester();

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$group_ctx = eval_init_group_batch($conn, 'pastoral', $formator_id, $filter_academic_year, $filter_semester, $evaluation_id);
$is_group_mode = !empty($group_ctx['active']);

$questions = [];
$res_q = $conn->query("SELECT question_id, category, question_text, sort_order FROM pastoral_questions WHERE is_active = 1 ORDER BY sort_order ASC, question_id ASC");
if ($res_q) {
    while ($row = $res_q->fetch_assoc()) {
        $questions[] = $row;
    }
}

$students = [];
$stmt_students = $conn->prepare("SELECT s.id, u.username, up.full_name, s.pastoral_eval_view_enabled,
        s.year_level, s.formation_stage, s.enrollment_status, s.formation_status, s.is_graduate, s.completed_at,
        stu.is_graduate AS stu_is_graduate, stu.graduated_at, stu.formation_status AS stu_formation_status
    FROM seminarians s
    JOIN seminarian_assignments sa ON sa.seminarian_id = s.id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students stu ON stu.user_id = u.id
    WHERE u.role = 'Student'
      AND sa.assignment_type = 'Pastoral'
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
    'areas_for_improvement' => '',
    'challenges' => '',
    'recommendations' => '',
    'ministry_development_plan' => '',
    'follow_up_actions' => '',
];

if ($evaluation_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM pastoral_evaluations WHERE evaluation_id = ? AND formator_id = ? LIMIT 1");
    $stmt->bind_param('ii', $evaluation_id, $formator_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    if ($edit) {
        $student_id = (int)$edit['seminarian_id'];
        $filter_academic_year = (string)$edit['academic_year'];
        $filter_semester = (string)$edit['semester'];
    }

    $stmt_r = $conn->prepare("SELECT question_id, rating FROM pastoral_responses WHERE evaluation_id = ?");
    $stmt_r->bind_param('i', $evaluation_id);
    $stmt_r->execute();
    $res = $stmt_r->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing_responses[(int)$row['question_id']] = (int)$row['rating'];
    }

    $stmt_rem = $conn->prepare("SELECT strengths, areas_for_improvement, challenges, recommendations, ministry_development_plan, follow_up_actions FROM pastoral_remarks WHERE evaluation_id = ? LIMIT 1");
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
        'areas_for_improvement' => trim((string)($_POST['areas_for_improvement'] ?? '')),
        'challenges' => trim((string)($_POST['challenges'] ?? '')),
        'recommendations' => trim((string)($_POST['recommendations'] ?? '')),
        'ministry_development_plan' => trim((string)($_POST['ministry_development_plan'] ?? '')),
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
            $part = eval_partition_batch_ids($conn, 'pastoral', $formator_id, $target_ids, $academic_year_post, $semester_post, $evaluation_id_post);
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
        $calc = pfe_compute_results($questions, $ratings);
        if ($calc['count'] !== count($questions)) {
            $error = 'Please answer all questions.';
        } else {
            $conn->begin_transaction();
            try {
                $eval_entries = [];
                if ($evaluation_id_post > 0) {
                    $single_sid = $target_ids[0];
                    $stmt_upd = $conn->prepare("UPDATE pastoral_evaluations
                        SET seminarian_id = ?, formator_id = ?, academic_year = ?, semester = ?, evaluation_date = ?, updated_at = NOW()
                        WHERE evaluation_id = ? AND formator_id = ? AND archived_at IS NULL");
                    $stmt_upd->bind_param('iisssii', $single_sid, $formator_id, $academic_year_post, $semester_post, $evaluation_date, $evaluation_id_post, $formator_id);
                    if (!$stmt_upd->execute()) {
                        throw new RuntimeException('Failed to update pastoral evaluation.');
                    }

                    $stmt_del = $conn->prepare("DELETE FROM pastoral_responses WHERE evaluation_id = ?");
                    $stmt_del->bind_param('i', $evaluation_id_post);
                    if (!$stmt_del->execute()) {
                        throw new RuntimeException('Failed to clear old pastoral responses.');
                    }

                    $eval_entries[] = ['eval_id' => $evaluation_id_post, 'sid' => $single_sid];
                } else {
                    $stmt_ins = $conn->prepare("INSERT INTO pastoral_evaluations (seminarian_id, formator_id, academic_year, semester, evaluation_date, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                    if (!$stmt_ins) {
                        throw new RuntimeException('Failed to prepare pastoral evaluation insert.');
                    }
                    foreach ($target_ids as $target_sid) {
                        if (eval_find_active_duplicate($conn, 'pastoral', $target_sid, $academic_year_post, $semester_post, 0)) {
                            throw new RuntimeException('Duplicate active pastoral evaluation detected for seminarian #' . $target_sid);
                        }
                        $stmt_ins->bind_param('iisss', $target_sid, $formator_id, $academic_year_post, $semester_post, $evaluation_date);
                        if (!$stmt_ins->execute()) {
                            throw new RuntimeException('Failed to insert pastoral evaluation.');
                        }
                        $eval_entries[] = ['eval_id' => (int)$conn->insert_id, 'sid' => $target_sid];
                    }
                }

                $interp = (string)($calc['interpretation'] ?? '');
                $total_score = (int)$calc['total'];
                $avg_score = (float)$calc['average'];
                $cat_avgs = $calc['category_avgs'] ?? [];
                $pastoral_ministry = isset($cat_avgs['Pastoral Ministry and Service']) ? (float)$cat_avgs['Pastoral Ministry and Service'] : null;
                $leadership = isset($cat_avgs['Leadership and Responsibility']) ? (float)$cat_avgs['Leadership and Responsibility'] : null;
                $communication = isset($cat_avgs['Communication and Interpersonal Skills']) ? (float)$cat_avgs['Communication and Interpersonal Skills'] : null;
                $community = isset($cat_avgs['Community Engagement and Collaboration']) ? (float)$cat_avgs['Community Engagement and Collaboration'] : null;
                $apostolic = isset($cat_avgs['Apostolic Zeal and Mission Awareness']) ? (float)$cat_avgs['Apostolic Zeal and Mission Awareness'] : null;

                $stmt_resp = $conn->prepare("INSERT INTO pastoral_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                $stmt_res = $conn->prepare("INSERT INTO pastoral_results (evaluation_id, total_score, average_rating, interpretation, pastoral_ministry_avg, leadership_avg, communication_avg, community_engagement_avg, apostolic_zeal_avg, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE total_score = VALUES(total_score), average_rating = VALUES(average_rating), interpretation = VALUES(interpretation),
                        pastoral_ministry_avg = VALUES(pastoral_ministry_avg), leadership_avg = VALUES(leadership_avg), communication_avg = VALUES(communication_avg),
                        community_engagement_avg = VALUES(community_engagement_avg), apostolic_zeal_avg = VALUES(apostolic_zeal_avg), computed_at = NOW()");
                $stmt_rem_up = $conn->prepare("INSERT INTO pastoral_remarks (evaluation_id, strengths, areas_for_improvement, challenges, recommendations, ministry_development_plan, follow_up_actions, updated_at)
                    VALUES (?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NOW())
                    ON DUPLICATE KEY UPDATE strengths = VALUES(strengths), areas_for_improvement = VALUES(areas_for_improvement), challenges = VALUES(challenges),
                        recommendations = VALUES(recommendations), ministry_development_plan = VALUES(ministry_development_plan), follow_up_actions = VALUES(follow_up_actions), updated_at = NOW()");
                $stmt_prev = $conn->prepare("SELECT r.average_rating
                    FROM pastoral_results r
                    JOIN pastoral_evaluations e ON e.evaluation_id = r.evaluation_id
                    WHERE e.archived_at IS NULL AND e.seminarian_id = ? AND e.evaluation_id <> ?
                    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC
                    LIMIT 1");
                $stmt_ga = $conn->prepare("INSERT INTO pastoral_analytics (seminarian_id, evaluation_id, previous_score, current_score, growth_status, growth_percentage, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE previous_score = VALUES(previous_score), current_score = VALUES(current_score), growth_status = VALUES(growth_status), growth_percentage = VALUES(growth_percentage), computed_at = NOW()");

                if (!$stmt_resp || !$stmt_res || !$stmt_rem_up || !$stmt_prev || !$stmt_ga) {
                    throw new RuntimeException('Failed to prepare secondary pastoral statements.');
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
                            throw new RuntimeException('Failed to insert pastoral response.');
                        }
                    }

                    $stmt_res->bind_param('iidsddddd', $evaluation_id_final, $total_score, $avg_score, $interp, $pastoral_ministry, $leadership, $communication, $community, $apostolic);
                    if (!$stmt_res->execute()) {
                        throw new RuntimeException('Failed to insert pastoral result.');
                    }

                    $stmt_rem_up->bind_param(
                        'issssss',
                        $evaluation_id_final,
                        $remarks_post['strengths'],
                        $remarks_post['areas_for_improvement'],
                        $remarks_post['challenges'],
                        $remarks_post['recommendations'],
                        $remarks_post['ministry_development_plan'],
                        $remarks_post['follow_up_actions']
                    );
                    if (!$stmt_rem_up->execute()) {
                        throw new RuntimeException('Failed to insert pastoral remarks.');
                    }

                    $prev = null;
                    $stmt_prev->bind_param('ii', $entry_sid, $evaluation_id_final);
                    $stmt_prev->execute();
                    $prev_row = $stmt_prev->get_result()->fetch_assoc();
                    if ($prev_row && $prev_row['average_rating'] !== null) {
                        $prev = (float)$prev_row['average_rating'];
                    }

                    $growth = pfe_compute_growth($prev, $avg_score);
                    $g_status = $growth['status'];
                    $g_pct = $growth['percent'];

                    $stmt_ga->bind_param('iiddsd', $entry_sid, $evaluation_id_final, $prev, $avg_score, $g_status, $g_pct);
                    if (!$stmt_ga->execute()) {
                        throw new RuntimeException('Failed to insert pastoral analytics.');
                    }

                    $saved_eval_ids[] = $evaluation_id_final;
                }

                $conn->commit();
                eval_redirect_after_save('pastoral', $academic_year_post, $semester_post, $saved_eval_ids, $is_bulk_post);
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Failed to save evaluation.';
            }
        }
    }
}
?>

<?php
$pageTitle = $evaluation_id > 0 ? 'Edit Pastoral Evaluation' : 'New Pastoral Evaluation';
$activeNav = 'pfe_evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';

$student_label = '';
$student_view_enabled = 0;
foreach ($students as $s) {
    if ((int)$s['id'] === (int)$student_id) {
        $student_label = trim((string)($s['full_name'] ?? ''));
        if ($student_label === '') $student_label = (string)($s['username'] ?? '');
        $student_view_enabled = (int)($s['pastoral_eval_view_enabled'] ?? 0);
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
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-user-check"></i> <?php echo $evaluation_id > 0 ? 'Edit Pastoral Evaluation' : 'New Pastoral Evaluation'; ?></h1>
                <a href="pfe_evaluations.php" class="eval-back-btn print:hidden"><i class="fas fa-arrow-left" aria-hidden="true"></i> <span>Back</span></a>
            </div>

            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
            <?php eval_render_group_evaluation_header('pastoral', $group_ctx, $filter_academic_year, $filter_semester); ?>

            <?php
            $is_selected_graduated = (!$is_group_mode && $student_id > 0)
                ? eval_check_seminarian_graduated_db($conn, $student_id)
                : false;
            $duplicate_eval = (!$is_group_mode && !$is_selected_graduated && $evaluation_id <= 0 && $student_id > 0)
                ? eval_find_active_duplicate($conn, 'pastoral', $student_id, $filter_academic_year, $filter_semester)
                : null;
            if ($is_selected_graduated) {
                eval_render_graduated_blocked_banner('pastoral', $student_label, $filter_academic_year, $filter_semester);
            } elseif ($duplicate_eval) {
                $dup_student_name = '';
                foreach ($students as $s_opt) {
                    if ((int)$s_opt['id'] === (int)$student_id) {
                        $dup_student_name = trim((string)($s_opt['full_name'] ?? ''));
                        if ($dup_student_name === '') $dup_student_name = (string)($s_opt['username'] ?? '');
                        break;
                    }
                }
                eval_render_already_evaluated_banner('pastoral', (int)$duplicate_eval['evaluation_id'], $dup_student_name, $filter_academic_year, $filter_semester);
            } elseif ($is_group_mode && empty($group_ctx['has_recipients'])) {
                // Header card already informs the Formator that no eligible recipients remain
            } else {
            ?>
            <div class="card eval-filter-card" style="margin-top: 0;">
                <form method="post" id="pfeEvalForm" style="margin: 0; padding: 0; background: transparent; border: none; box-shadow: none; gap: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>">
                    <?php if ($is_group_mode) { eval_render_group_hidden_inputs($group_ctx); } ?>

                    <div class="eval-filter-grid">
                        <?php if ($is_group_mode) { ?>
                        <div class="eval-filter-field">
                            <label>Group Recipients</label>
                            <div style="min-height: 42px; display: flex; align-items: center; padding: 0 14px; border-radius: 10px; background: #F8EEEE; border: 1px solid #E2C9C8; font-weight: 800; color: #59443F; font-size: 0.85rem;">
                                Applying to <?php echo count($group_ctx['pending_items']); ?> selected seminarians
                            </div>
                        </div>
                        <?php } else { ?>
                        <div class="eval-filter-field">
                            <label for="pfeStudentSelect">Seminarian</label>
                            <select id="pfeStudentSelect" name="seminarian_id" required>
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
                        <?php } ?>
                        <div class="eval-filter-field">
                            <label for="pfeAcademicYearInput">Academic Year</label>
                            <input id="pfeAcademicYearInput" type="text" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>" required>
                        </div>
                        <div class="eval-filter-field">
                            <label for="pfeSemesterSelect">Semester</label>
                            <select id="pfeSemesterSelect" name="semester" required>
                                <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                    <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="eval-filter-field">
                            <label for="pfeEvalDateInput">Evaluation Date</label>
                            <input id="pfeEvalDateInput" type="date" name="evaluation_date" value="<?php echo htmlspecialchars($edit ? (string)$edit['evaluation_date'] : date('Y-m-d')); ?>" required>
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

                    <?php foreach ($grouped as $cat => $qs) { ?>
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
                                        <?php foreach ($qs as $q) {
                                            $qid = (int)$q['question_id'];
                                            $selected = isset($existing_responses[$qid]) ? (int)$existing_responses[$qid] : 0;
                                            $labels = [5 => 'Outstanding', 4 => 'Very Good', 3 => 'Satisfactory', 2 => 'Needs Improvement', 1 => 'Poor'];
                                        ?>
                                            <tr class="eval-question-row" data-qid="<?php echo $qid; ?>">
                                                <td>
                                                    <strong><?php echo (int)$q['sort_order']; ?>.</strong>
                                                    <?php echo htmlspecialchars((string)$q['question_text']); ?>
                                                    <div class="eval-q-error"><i class="fas fa-circle-exclamation"></i> Please select a rating (1–5) for this question.</div>
                                                    <div style="margin-top: 0.55rem;">
                                                        <input type="hidden" name="rating[<?php echo $qid; ?>]" id="rating_<?php echo $qid; ?>" value="<?php echo (int)$selected; ?>">
                                                        <div class="likert-btns" data-qid="<?php echo $qid; ?>">
                                                            <?php foreach ([5,4,3,2,1] as $i) { ?>
                                                                <button type="button" class="likert-btn <?php echo $selected === $i ? 'active' : ''; ?>" data-value="<?php echo $i; ?>">
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
                            <h3><i class="fas fa-pen-to-square"></i> Formator Remarks</h3>
                        </div>
                        <div class="eval-remarks-grid">
                            <div class="field" style="margin: 0;">
                                <label>Strengths in Ministry</label>
                                <textarea name="strengths" rows="5"><?php echo htmlspecialchars($remarks['strengths']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Areas for Improvement</label>
                                <textarea name="areas_for_improvement" rows="5"><?php echo htmlspecialchars($remarks['areas_for_improvement']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Pastoral Challenges Observed</label>
                                <textarea name="challenges" rows="5"><?php echo htmlspecialchars($remarks['challenges']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Recommendations</label>
                                <textarea name="recommendations" rows="5"><?php echo htmlspecialchars($remarks['recommendations']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Ministry Development Plan</label>
                                <textarea name="ministry_development_plan" rows="5"><?php echo htmlspecialchars($remarks['ministry_development_plan']); ?></textarea>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Follow-Up Actions</label>
                                <textarea name="follow_up_actions" rows="5"><?php echo htmlspecialchars($remarks['follow_up_actions']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="eval-submit-bar print:hidden">
                        <a href="pfe_evaluations.php" class="eval-back-btn print:hidden">Cancel</a>
                        <button type="submit" name="save_evaluation" class="eval-submit-btn print:hidden" data-confirm="Submit this pastoral evaluation? Once saved, results will be recorded for the selected term." data-confirm-title="Submit Evaluation" data-confirm-text="Submit Evaluation" data-confirm-variant="primary" data-confirm-loading="Saving...">
                            <i class="fas fa-save" aria-hidden="true"></i> <span><?php echo $is_group_mode ? ('Save Evaluation for All (' . count($group_ctx['pending_items']) . ')') : 'Save Evaluation'; ?></span>
                        </button>
                    </div>
                </form>
            </div>
            <?php
            eval_render_smart_validation_assets('pfeEvalForm');
            }
            ?>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
