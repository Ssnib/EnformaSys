<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator']);
include '../includes/db.php';
include '../includes/academic_evaluation.php';

$formator_id = (int)($_SESSION['user'] ?? 0);

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = afe_get_default_semester();

$evaluation_id = isset($_GET['evaluation_id']) ? (int)$_GET['evaluation_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

require_once __DIR__ . '/../includes/eval_ui_helpers.php';
$group_ctx = eval_init_group_batch($conn, 'academic', $formator_id, $filter_academic_year, $filter_semester, $evaluation_id);
$is_group_mode = !empty($group_ctx['active']);

$questions = [];
$res_q = $conn->query("SELECT id, category, question_text, sort_order FROM academic_evaluation_questions WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
if ($res_q) {
    while ($row = $res_q->fetch_assoc()) {
        $questions[] = $row;
    }
}

$students = [];
$stmt_students = $conn->prepare("SELECT s.id, u.username, up.full_name,
        COALESCE(NULLIF(TRIM(e_latest.year_level), ''), s.year_level) AS year_level,
        s.formation_stage,
        COALESCE(NULLIF(TRIM(e_latest.enrollment_status), ''), s.enrollment_status) AS enrollment_status,
        s.formation_status, s.is_graduate, s.completed_at,
        stu.is_graduate AS stu_is_graduate, stu.graduated_at, stu.formation_status AS stu_formation_status
    FROM seminarians s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN students stu ON stu.user_id = u.id
    LEFT JOIN (
        SELECT e1.*
        FROM enrollments e1
        JOIN (SELECT student_id, MAX(id) AS max_id FROM enrollments GROUP BY student_id) em
          ON em.student_id = e1.student_id AND em.max_id = e1.id
    ) e_latest ON e_latest.student_id = s.id
    WHERE u.role = 'Student'
    ORDER BY COALESCE(NULLIF(TRIM(up.full_name), ''), u.username) ASC");
$stmt_students->execute();
$res_s = $stmt_students->get_result();
while ($res_s && ($row = $res_s->fetch_assoc())) {
    $row['is_graduated'] = eval_is_seminarian_graduated($row);
    $row['status'] = $row['is_graduated'] ? 'graduated' : 'active';
    $students[] = $row;
}

$edit = null;
$existing_responses = [];
if ($evaluation_id > 0) {
    $stmt = $conn->prepare("SELECT e.*, u.username as formator_username
        FROM academic_evaluations e
        JOIN users u ON u.id = e.formator_id
        WHERE e.id = ? AND e.formator_id = ? LIMIT 1");
    $stmt->bind_param('ii', $evaluation_id, $formator_id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    if ($edit) {
        $student_id = (int)$edit['student_id'];
        $filter_academic_year = (string)$edit['academic_year'];
        $filter_semester = (string)$edit['semester'];
    }

    $stmt_r = $conn->prepare("SELECT question_id, rating FROM academic_evaluation_responses WHERE evaluation_id = ?");
    $stmt_r->bind_param('i', $evaluation_id);
    $stmt_r->execute();
    $res = $stmt_r->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing_responses[(int)$row['question_id']] = (int)$row['rating'];
    }
}

$gpa = null;
if ($student_id > 0) {
    $gpa = afe_get_latest_gpa($conn, $student_id);
}

if (isset($_POST['save_evaluation'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $evaluation_id_post = (int)($_POST['evaluation_id'] ?? 0);
    $student_id_post = (int)($_POST['student_id'] ?? 0);
    $group_ids_post = (isset($_POST['seminarian_ids']) && is_array($_POST['seminarian_ids']))
        ? eval_parse_batch_id_list(null, $_POST['seminarian_ids'])
        : [];
    $is_bulk_post = ($evaluation_id_post <= 0 && !empty($group_ids_post));
    $target_ids = $is_bulk_post ? $group_ids_post : ($student_id_post > 0 ? [$student_id_post] : []);

    $academic_year_post = trim((string)($_POST['academic_year'] ?? ''));
    $semester_post = trim((string)($_POST['semester'] ?? ''));
    $evaluation_date = trim((string)($_POST['evaluation_date'] ?? date('Y-m-d')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $ratings = $_POST['rating'] ?? [];

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
            $part = eval_partition_batch_ids($conn, 'academic', $formator_id, $target_ids, $academic_year_post, $semester_post, $evaluation_id_post);
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
        $calc = afe_compute_results($ratings);
        if ($calc['count'] !== count($questions)) {
            $error = 'Please answer all questions.';
        } else {
            $conn->begin_transaction();
            try {
                $saved_eval_ids = [];
                if ($evaluation_id_post > 0) {
                    $single_sid = $target_ids[0];
                    $gpa_snapshot = afe_get_latest_gpa($conn, $single_sid);
                    $gpa_str = $gpa_snapshot === null ? '' : (string)$gpa_snapshot;

                    $stmt_upd = $conn->prepare("UPDATE academic_evaluations
                        SET student_id = ?, formator_id = ?, gpa = NULLIF(?,''), academic_year = ?, semester = ?, evaluation_date = ?, remarks = NULLIF(?,''), updated_at = NOW()
                        WHERE id = ? AND formator_id = ? AND archived_at IS NULL");
                    $stmt_upd->bind_param('iisssssii', $single_sid, $formator_id, $gpa_str, $academic_year_post, $semester_post, $evaluation_date, $remarks, $evaluation_id_post, $formator_id);
                    if (!$stmt_upd->execute()) {
                        throw new RuntimeException('Failed to update academic evaluation.');
                    }

                    $stmt_del = $conn->prepare("DELETE FROM academic_evaluation_responses WHERE evaluation_id = ?");
                    $stmt_del->bind_param('i', $evaluation_id_post);
                    if (!$stmt_del->execute()) {
                        throw new RuntimeException('Failed to clear old evaluation responses.');
                    }

                    $eval_ids_to_finalize = [$evaluation_id_post];
                } else {
                    $eval_ids_to_finalize = [];
                    $stmt_ins = $conn->prepare("INSERT INTO academic_evaluations (student_id, formator_id, gpa, academic_year, semester, evaluation_date, remarks, created_at, updated_at)
                        VALUES (?, ?, NULLIF(?,''), ?, ?, ?, NULLIF(?,''), NOW(), NOW())");
                    if (!$stmt_ins) {
                        throw new RuntimeException('Failed to prepare academic evaluation insert.');
                    }

                    foreach ($target_ids as $target_sid) {
                        if (eval_find_active_duplicate($conn, 'academic', $target_sid, $academic_year_post, $semester_post, 0)) {
                            throw new RuntimeException('Duplicate evaluation detected for seminarian #' . $target_sid);
                        }
                        $gpa_snapshot = afe_get_latest_gpa($conn, $target_sid);
                        $gpa_str = $gpa_snapshot === null ? '' : (string)$gpa_snapshot;
                        $stmt_ins->bind_param('iisssss', $target_sid, $formator_id, $gpa_str, $academic_year_post, $semester_post, $evaluation_date, $remarks);
                        if (!$stmt_ins->execute()) {
                            throw new RuntimeException('Failed to save academic evaluation.');
                        }
                        $eval_ids_to_finalize[] = (int)$conn->insert_id;
                    }
                }

                $label = (string)($calc['label'] ?? '');
                $rec = (string)($calc['recommendation'] ?? '');
                $total_score = (int)$calc['total'];
                $avg_score = (float)$calc['average'];
                $stmt_resp = $conn->prepare("INSERT INTO academic_evaluation_responses (evaluation_id, question_id, rating) VALUES (?, ?, ?)");
                $stmt_res = $conn->prepare("INSERT INTO academic_evaluation_results (evaluation_id, total_score, average_score, rating_label, recommendation, computed_at)
                    VALUES (?, ?, ?, ?, NULLIF(?,''), NOW())
                    ON DUPLICATE KEY UPDATE total_score = VALUES(total_score), average_score = VALUES(average_score), rating_label = VALUES(rating_label), recommendation = VALUES(recommendation), computed_at = NOW()");
                if (!$stmt_resp || !$stmt_res) {
                    throw new RuntimeException('Failed to prepare response/result queries.');
                }

                foreach ($eval_ids_to_finalize as $evaluation_id_final) {
                    foreach ($questions as $q) {
                        $qid = (int)$q['id'];
                        $val = isset($ratings[$qid]) ? (int)$ratings[$qid] : 0;
                        $stmt_resp->bind_param('iii', $evaluation_id_final, $qid, $val);
                        if (!$stmt_resp->execute()) {
                            throw new RuntimeException('Failed to save evaluation response.');
                        }
                    }

                    $stmt_res->bind_param('iidss', $evaluation_id_final, $total_score, $avg_score, $label, $rec);
                    if (!$stmt_res->execute()) {
                        throw new RuntimeException('Failed to save evaluation result.');
                    }
                    $saved_eval_ids[] = $evaluation_id_final;
                }

                $conn->commit();
                eval_redirect_after_save('academic', $academic_year_post, $semester_post, $saved_eval_ids, $is_bulk_post);
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Failed to save evaluation.';
            }
        }
    }
}
?>

<?php
$pageTitle = $evaluation_id > 0 ? 'Edit Academic Evaluation' : 'New Academic Evaluation';
$activeNav = 'afe_evaluations';
require_once __DIR__ . '/../includes/layout_app_start.php';

$student_label = '';
foreach ($students as $s) {
    if ((int)$s['id'] === (int)$student_id) {
        $student_label = trim((string)($s['full_name'] ?? ''));
        if ($student_label === '') $student_label = (string)($s['username'] ?? '');
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
            <style>
                .likert-btns { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.5rem; }
                .likert-btn { width: 100%; border: 1px solid #e2e8f0; background: #fff; color: #0f172a; font-weight: 900; padding: 0.55rem 0.7rem; border-radius: 0.75rem; cursor: pointer; text-align: center; display: inline-flex; flex-direction: row; align-items: center; justify-content: center; gap: 0.4rem; }
                .likert-btn.active { background: #0f766e; color: #fff; border-color: #0f766e; }
                .likert-btn .likert-label { font-size: 0.78rem; font-weight: 900; color: #475569; white-space: nowrap; }
                .likert-btn.active .likert-label { color: #fff; }
                @media (max-width: 900px) {
                    .likert-btns { grid-template-columns: repeat(3, minmax(0, 1fr)); }
                }
                @media (max-width: 560px) {
                    .likert-btns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                }
            </style>
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <a href="afe_dashboard.php" style="color: var(--primary); text-decoration: none;">Academic Evaluation</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span><?php echo $evaluation_id > 0 ? 'Edit' : 'New'; ?></span>
            </nav>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.15rem;">
                <h1 class="page-title" style="margin: 0;"><i class="fas fa-clipboard-check"></i> <?php echo $evaluation_id > 0 ? 'Edit Academic Evaluation' : 'New Academic Evaluation'; ?></h1>
                <a href="afe_evaluations.php" class="eval-back-btn print:hidden"><i class="fas fa-arrow-left" aria-hidden="true"></i> <span>Back</span></a>
            </div>

            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
            <?php eval_render_group_evaluation_header('academic', $group_ctx, $filter_academic_year, $filter_semester); ?>

            <?php if (!$is_group_mode) { ?>
            <div class="card eval-filter-card" style="margin-top: 0;">
                <form method="get" class="eval-filter-grid">
                    <?php if ($evaluation_id > 0) { ?><input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>"><?php } ?>
                    <div class="eval-filter-field">
                        <label for="afeStudentSelect">Seminarian</label>
                        <select id="afeStudentSelect" name="student_id" required>
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
                        <label for="afeAcademicYearInput">Academic Year</label>
                        <input id="afeAcademicYearInput" type="text" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>" required>
                    </div>
                    <div class="eval-filter-field">
                        <label for="afeSemesterSelect">Semester</label>
                        <select id="afeSemesterSelect" name="semester" required>
                            <?php foreach (['1st','2nd','Summer'] as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $s === $filter_semester ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="eval-load-btn"><i class="fas fa-sync" aria-hidden="true"></i> <span>Load</span></button>
                    </div>
                </form>
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
                        <div class="eval-summary-icon bg-emerald-50 text-emerald-600 border border-emerald-200/70"><i class="fas fa-graduation-cap" aria-hidden="true"></i></div>
                        <div class="eval-summary-meta">
                            <div class="eval-summary-label">GPA (Auto-fetched)</div>
                            <div class="eval-summary-value text-emerald-700"><?php echo $is_group_mode ? 'Per Seminarian' : ($gpa === null ? '-' : htmlspecialchars(number_format((float)$gpa, 2))); ?></div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-top: 1.25rem;">
                    <h3><i class="fas fa-circle-info"></i> Likert Scale</h3>
                    <ul style="margin: 0.6rem 0 0; padding-left: 1.25rem; color: #334155; font-weight: 800; line-height: 1.7;">
                        <li>5 – Excellent</li>
                        <li>4 – Very Good</li>
                        <li>3 – Good</li>
                        <li>2 – Fair</li>
                        <li>1 – Poor</li>
                    </ul>
                </div>

                <?php
                $is_selected_graduated = (!$is_group_mode && $student_id > 0)
                    ? eval_check_seminarian_graduated_db($conn, $student_id)
                    : false;
                $duplicate_eval = (!$is_group_mode && !$is_selected_graduated && $evaluation_id <= 0 && $student_id > 0)
                    ? eval_find_active_duplicate($conn, 'academic', $student_id, $filter_academic_year, $filter_semester)
                    : null;
                if ($is_selected_graduated) {
                    eval_render_graduated_blocked_banner('academic', $student_label, $filter_academic_year, $filter_semester);
                } elseif ($duplicate_eval) {
                    eval_render_already_evaluated_banner('academic', (int)$duplicate_eval['evaluation_id'], $student_label, $filter_academic_year, $filter_semester);
                } else {
                ?>
                <form method="post" id="afeEvalForm" style="margin-top: 1.25rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="evaluation_id" value="<?php echo (int)$evaluation_id; ?>">
                    <?php if ($is_group_mode) {
                        eval_render_group_hidden_inputs($group_ctx);
                    } else { ?>
                    <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
                    <?php } ?>
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($filter_academic_year); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($filter_semester); ?>">

                    <div class="card" style="margin-top: 0;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div class="field" style="margin: 0;">
                                <label>Evaluation Date</label>
                                <input type="date" name="evaluation_date" required value="<?php echo htmlspecialchars($edit ? (string)$edit['evaluation_date'] : date('Y-m-d')); ?>">
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Assigned Evaluator Remarks</label>
                                <input type="text" name="remarks" placeholder="Optional remarks..." value="<?php echo htmlspecialchars($edit ? (string)($edit['remarks'] ?? '') : ''); ?>">
                            </div>
                        </div>
                    </div>

                    <?php foreach ($grouped as $cat => $items) { ?>
                        <div class="data-card" style="margin-top: 1.25rem;">
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
                                            $qid = (int)$q['id'];
                                            $val = (int)($existing_responses[$qid] ?? 0);
                                            $labels = [5 => 'Excellent', 4 => 'Very Good', 3 => 'Good', 2 => 'Fair', 1 => 'Poor'];
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

                    <div class="card eval-submit-bar print:hidden">
                        <a href="afe_evaluations.php" class="eval-back-btn print:hidden">Cancel</a>
                        <button type="submit" name="save_evaluation" class="eval-submit-btn print:hidden" data-confirm="Submit this academic evaluation? Once saved, results will be recorded for the selected term." data-confirm-title="Submit Evaluation" data-confirm-text="Submit Evaluation" data-confirm-variant="primary" data-confirm-loading="Saving...">
                            <i class="fas fa-save" aria-hidden="true"></i> <span><?php echo $is_group_mode ? ('Save Evaluation for All (' . count($group_ctx['pending_items']) . ')') : 'Save Evaluation'; ?></span>
                        </button>
                    </div>
                </form>
                <?php
                eval_render_smart_validation_assets('afeEvalForm');
                }
                ?>
            <?php } elseif ($is_group_mode && empty($group_ctx['has_recipients'])) { ?>
            <?php } elseif ($student_id > 0 && count($questions) === 0) { ?>
                <div class="card" style="border-left: 6px solid #ef4444;">
                    <h3><i class="fas fa-triangle-exclamation"></i> No Questions Found</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">No active academic evaluation questions are available.</div>
                </div>
            <?php } else { ?>
                <div class="card" style="border-left: 6px solid #f59e0b; margin-top: 1.25rem;">
                    <h3><i class="fas fa-circle-info"></i> Select a Seminarian</h3>
                    <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">Choose a seminarian and term, then click Load to start the evaluation.</div>
                </div>
            <?php } ?>
        </div>
        <script>
        document.querySelectorAll('.likert-btns').forEach(function (wrap) {
            wrap.querySelectorAll('.likert-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const qid = wrap.getAttribute('data-qid');
                    const val = this.getAttribute('data-value');
                    const input = document.getElementById('rating_' + qid);
                    if (input) input.value = val;
                    wrap.querySelectorAll('.likert-btn').forEach(function (b) { b.classList.remove('active'); });
                    this.classList.add('active');
                });
            });
        });
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
