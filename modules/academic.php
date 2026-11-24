<?php
include '../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'Rector']);
include '../includes/db.php';

$show_archived = isset($_GET['archived']) && $_GET['archived'] === '1' && in_array($_SESSION['role'], ['Registrar', 'Dean', 'Rector']);

$selected_seminarian_id = isset($_GET['seminarian_id']) ? (int)$_GET['seminarian_id'] : 0;
$filter_term = isset($_GET['term']) ? trim((string)$_GET['term']) : '';
$filter_school_year = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : '';

$current_ay = get_current_academic_year();
$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT school_year as academic_year FROM grades WHERE school_year IS NOT NULL AND school_year <> '') UNION (SELECT DISTINCT academic_year FROM applicants WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

if (isset($_POST['archive_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $grade_id = (int)($_POST['grade_id'] ?? 0);
    if ($grade_id > 0) {
        $stmt = $conn->prepare("UPDATE grades SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL");
        $stmt->bind_param('i', $grade_id);
        $stmt->execute();
        $success = 'Grade archived successfully.';
    }
}

if (isset($_POST['restore_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $grade_id = (int)($_POST['grade_id'] ?? 0);
    if ($grade_id > 0) {
        $stmt = $conn->prepare("UPDATE grades SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL");
        $stmt->bind_param('i', $grade_id);
        $stmt->execute();
        $success = 'Grade restored successfully.';
    }
}

if (isset($_POST['update_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $grade_id = (int)($_POST['grade_id'] ?? 0);
    $subject = $_POST['subject'] ?? '';
    $units = (float)($_POST['units'] ?? 0);
    $grade_percent = $_POST['grade_percent'] ?? null;
    $grade = academic_percent_to_rating($grade_percent);
    if ($grade === null) {
        $grade = 0;
    }
    $term = $_POST['term'] ?? null;
    $school_year = $_POST['school_year'] ?? null;
    $date = $_POST['date'] ?? '';

    if ($grade_id > 0) {
        $stmt = $conn->prepare("UPDATE grades SET subject = ?, units = ?, grade = ?, grade_percent = ?, term = ?, school_year = ?, date = ? WHERE id = ?");
        $stmt->bind_param('sddsssi', $subject, $units, $grade, $grade_percent, $term, $school_year, $date, $grade_id);
        $stmt->execute();
        $success = 'Grade updated successfully.';
    }
}

if (isset($_POST['add_grade'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $seminarian_id = $_POST['seminarian_id'];
    $subject = $_POST['subject'];
    $units = (float)($_POST['units'] ?? 0);
    $grade_percent = $_POST['grade_percent'] ?? null;
    $grade = academic_percent_to_rating($grade_percent);
    if ($grade === null) {
        $grade = 0;
    }
    $term = $_POST['term'] ?? null;
    $school_year = $_POST['school_year'] ?? null;
    $date = $_POST['date'];

    $assessed_by = (int)$_SESSION['user'];

    $stmt = $conn->prepare("INSERT INTO grades (seminarian_id, subject, units, grade, grade_percent, term, school_year, assessed_by, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isddssis", $seminarian_id, $subject, $units, $grade, $grade_percent, $term, $school_year, $assessed_by, $date);
    $stmt->execute();
    
    // Log Activity
    $student_res = $conn->query("SELECT u.username FROM seminarians s JOIN users u ON s.user_id = u.id WHERE s.id = $seminarian_id");
    $student_name = $student_res->fetch_assoc()['username'];
    log_activity($_SESSION['user'], "Added academic grade for $student_name in $subject");
    
    $success = "Grade added successfully.";
}

$where = $show_archived ? 'g.archived_at IS NOT NULL' : 'g.archived_at IS NULL';
$params = [];
$types = '';
if ($selected_seminarian_id > 0) {
    $where .= ' AND g.seminarian_id = ?';
    $params[] = $selected_seminarian_id;
    $types .= 'i';
}
if ($filter_term !== '') {
    $where .= ' AND g.term = ?';
    $params[] = $filter_term;
    $types .= 's';
}
if ($filter_school_year !== '') {
    $where .= ' AND g.school_year = ?';
    $params[] = $filter_school_year;
    $types .= 's';
}

$grades_sql = "SELECT g.*, u.username, au.username AS assessor_username FROM grades g JOIN seminarians s ON g.seminarian_id = s.id JOIN users u ON s.user_id = u.id LEFT JOIN users au ON g.assessed_by = au.id WHERE {$where} ORDER BY g.date DESC, g.id DESC";
$stmt_grades = $conn->prepare($grades_sql);
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt_grades, 'bind_param'], $bind);
}
$stmt_grades->execute();
$result = $stmt_grades->get_result();

$students = [];
$students_res = $conn->query("SELECT s.id, u.username FROM seminarians s JOIN users u ON s.user_id = u.id ORDER BY u.username");
if ($students_res) {
    while ($row = $students_res->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<?php
$pageTitle = 'Academic Formation';
$activeNav = 'formation';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <a href="formation.php" style="color: var(--primary); text-decoration: none;">Formation</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Academic</span>
            </nav>
            <div style="margin-bottom: 2rem;">
                <a href="formation.php" class="back-link">Back to Progress Categories</a>
                <h1 class="page-title"><i class="fas fa-book"></i> Academic Formation</h1>
            </div>

            <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>
			<?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

            <div class="data-card" style="margin-bottom: 2rem;">
                <div class="data-card-header">
					<h3><i class="fas fa-list-check"></i> <?php echo $show_archived ? 'Archived Grades' : 'Recorded Grades'; ?></h3>
					<div style="display: flex; align-items: center; gap: 0.75rem;">
						<input type="text" id="gradeSearch" class="search-box" placeholder="Search by student or subject...">
						<a href="academic.php?archived=<?php echo $show_archived ? 0 : 1; ?>" class="back-link" style="margin: 0; white-space: nowrap;">
							<?php echo $show_archived ? 'Show Active' : 'Show Archived'; ?>
						</a>
						<?php if (!$show_archived) { ?>
							<button type="button" id="openAddGradeModal" style="width: auto; padding: 0.45rem 0.75rem; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); border-radius: 0.5rem; font-size: 0.85rem; font-weight: 700;">
								<i class="fas fa-plus"></i> Add Grade
							</button>
						<?php } ?>
					</div>
                </div>
                <div style="overflow-x: auto;">
                    <table id="gradeTable">
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Grade</th>
								<th>Rating</th>
                                <th>Percent</th>
                                <th>Term</th>
                                <th>School Year</th>
                                <th>Assessed By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()) { 
                                $class = $row['grade'] < 2.0 ? 'low' : '';
                            ?>
                            <tr class="grade-row <?php echo $class; ?>">
                                <td class="student-name"><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td class="subject-name"><?php echo htmlspecialchars($row['subject']); ?></td>
                                <td><span class="badge badge-primary"><?php echo (float)($row['units'] ?? 0) == (int)($row['units'] ?? 0) ? (int)($row['units'] ?? 0) : number_format((float)($row['units'] ?? 0), 1); ?></span></td>
                                <td><span class="badge badge-primary"><?php echo htmlspecialchars($row['grade']); ?></span></td>
								<td><?php echo render_rating_badge($row['grade']); ?></td>
                                <td><span class="badge badge-primary"><?php echo htmlspecialchars($row['grade_percent'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($row['term'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['school_year'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['assessor_username'] ?? ''); ?></td>
                                <td style="color: var(--text-secondary);"><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if ($show_archived) { ?>
                                        <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="grade_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="restore_grade" style="width: auto; padding: 0.4rem 0.6rem; background: var(--accent);" data-confirm="Restore this grade record?" data-confirm-title="Restore Grade" data-confirm-text="Restore" data-confirm-variant="primary" data-confirm-loading="Restoring...">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <button type="button" class="editGradeBtn"
                                            data-grade-id="<?php echo (int)$row['id']; ?>"
                                            data-grade-subject="<?php echo htmlspecialchars($row['subject']); ?>"
                                            data-grade-units="<?php echo (float)($row['units'] ?? 0); ?>"
                                            data-grade-percent="<?php echo htmlspecialchars((string)($row['grade_percent'] ?? '')); ?>"
                                            data-grade-term="<?php echo htmlspecialchars($row['term'] ?? ''); ?>"
                                            data-grade-school-year="<?php echo htmlspecialchars($row['school_year'] ?? ''); ?>"
                                            data-grade-date="<?php echo htmlspecialchars($row['date']); ?>"
                                            style="width: auto; padding: 0.4rem 0.6rem; background: var(--accent);">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="grade_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="archive_grade" style="width: auto; padding: 0.4rem 0.6rem; background: var(--danger);" data-confirm="Archive this grade record?" data-confirm-title="Archive Grade" data-confirm-text="Archive" data-confirm-variant="danger" data-confirm-loading="Archiving...">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        </form>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

	<div class="modal" id="addGradeModal" aria-hidden="true">
		<div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="addGradeTitle">
			<div class="modal-header">
				<div class="modal-title" id="addGradeTitle"><i class="fas fa-plus-circle"></i> Add New Grade</div>
				<button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
			</div>
			<div class="modal-body">
				<form method="post">
					<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
					<div class="field">
						<label>Seminarian</label>
						<div class="typeahead">
							<input type="text" id="studentSearchAcademic" placeholder="Search student..." autocomplete="off" required>
							<input type="hidden" name="seminarian_id" id="studentIdAcademic">
							<div class="typeahead-list" id="studentListAcademic" hidden></div>
						</div>
					</div>

					<div class="field">
						<label>Subject</label>
						<input type="text" name="subject" placeholder="e.g. Philosophy, Theology" required>
					</div>

					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
						<div class="field">
							<label>Units</label>
							<input type="number" name="units" min="0.5" max="15" step="0.5" placeholder="1.5" required>
						</div>
						<div class="field">
							<label>Grade (0 - 100)</label>
							<input type="number" name="grade_percent" id="addGradePercent" min="0" max="100" step="0.01" placeholder="85" required>
						</div>
						<div class="field">
							<label>Performance Rating</label>
							<input type="text" id="addGradeRatingText" disabled value="No performance">
						</div>
						<div class="field">
							<label>Term</label>
							<select name="term">
								<option value="">(Optional)</option>
								<option value="1st">1st</option>
								<option value="2nd">2nd</option>
								<option value="Summer">Summer</option>
							</select>
						</div>
						<div class="field">
							<label>Date</label>
							<input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
						</div>
					</div>

					<div style="display: grid; grid-template-columns: 1fr; gap: 1rem; margin-top: 1rem;">
						<div class="field" style="margin: 0;">
							<label>School Year</label>
							<select name="school_year">
								<?php foreach ($ay_options as $ay) { ?>
									<option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($ay); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>

					<div style="margin-top: 0.25rem; color: #64748b; font-size: 0.85rem; font-weight: 700;">Performance mapping: &lt;60 Unsuitable (1), 60-69 (2), 70-79 (3), 80-89 (4), 90-100 (5)</div>

					<div class="modal-actions">
						<button type="button" class="modal-close" data-modal-close>Cancel</button>
						<button type="submit" name="add_grade">Record Grade</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<div class="modal" id="editGradeModal" aria-hidden="true">
		<div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editGradeTitle">
			<div class="modal-header">
				<div class="modal-title" id="editGradeTitle"><i class="fas fa-pen"></i> Update Grade</div>
				<button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
			</div>
			<div class="modal-body">
				<form method="post" id="editGradeForm">
					<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
					<input type="hidden" name="grade_id" id="editGradeId" value="">
					<div class="field">
						<label>Subject</label>
						<input type="text" name="subject" id="editGradeSubject" required>
					</div>
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
						<div class="field">
							<label>Units</label>
							<input type="number" name="units" id="editGradeUnits" min="0.5" max="15" step="0.5" required>
						</div>
						<div class="field">
							<label>Grade (0 - 100)</label>
							<input type="number" name="grade_percent" id="editGradePercent" min="0" max="100" step="0.01" required>
						</div>
						<div class="field">
							<label>Performance Rating</label>
							<input type="text" id="editGradeRatingText" disabled>
						</div>
						<div class="field">
							<label>Term</label>
							<select name="term" id="editGradeTerm">
								<option value="">(Optional)</option>
								<option value="1st">1st</option>
								<option value="2nd">2nd</option>
								<option value="Summer">Summer</option>
							</select>
						</div>
						<div class="field">
							<label>Date</label>
							<input type="date" name="date" id="editGradeDate" required>
						</div>
					</div>

					<div style="margin-top: 0.25rem; color: #64748b; font-size: 0.85rem; font-weight: 700;">Performance mapping: &lt;60 Unsuitable (1), 60-69 (2), 70-79 (3), 80-89 (4), 90-100 (5)</div>

					<div style="display: grid; grid-template-columns: 1fr; gap: 1rem; margin-top: 1rem;">
						<div class="field" style="margin: 0;">
							<label>School Year</label>
							<select name="school_year" id="editGradeSchoolYear">
								<?php foreach ($ay_options as $ay) { ?>
									<option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $current_ay ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($ay); ?>
									</option>
								<?php } ?>
							</select>
						</div>
					</div>
					<div class="modal-actions">
						<button type="button" class="modal-close" data-modal-close>Cancel</button>
						<button type="submit" name="update_grade">Update</button>
					</div>
				</form>
			</div>
		</div>
	</div>

    <script>
	(function () {
		const modal = document.getElementById('addGradeModal');
		const openBtn = document.getElementById('openAddGradeModal');
		if (!modal || !openBtn) return;

		const students = <?php echo json_encode($students); ?>;
		const input = document.getElementById('studentSearchAcademic');
		const hidden = document.getElementById('studentIdAcademic');
		const list = document.getElementById('studentListAcademic');
		const form = modal.querySelector('form');

		function renderList(items) {
			list.innerHTML = '';
			items.slice(0, 8).forEach(function (s) {
				const div = document.createElement('div');
				div.className = 'typeahead-item';
				div.innerHTML = `<span>${s.username}</span><span class="typeahead-meta">#${s.id}</span>`;
				div.addEventListener('click', function () {
					input.value = s.username;
					hidden.value = s.id;
					list.hidden = true;
				});
				list.appendChild(div);
			});
			list.hidden = items.length === 0;
		}

		if (input && hidden && list) {
			function showAll() {
				const q = input.value.trim().toLowerCase();
				if (!q) {
					renderList(students);
				} else {
					const matches = students.filter(s => (s.username || '').toLowerCase().includes(q));
					renderList(matches);
				}
			}

			input.addEventListener('focus', showAll);
			input.addEventListener('click', showAll);

			input.addEventListener('input', function () {
				hidden.value = '';
				const q = input.value.trim().toLowerCase();
				if (!q) {
					renderList(students);
					return;
				}
				const matches = students.filter(s => (s.username || '').toLowerCase().includes(q));
				renderList(matches);
			});
			document.addEventListener('click', function (e) {
				if (!list || list.hidden) return;
				if (e.target === input || list.contains(e.target)) return;
				list.hidden = true;
			});
		}

		if (form) {
			form.addEventListener('submit', function (e) {
				if (hidden && !hidden.value) {
					e.preventDefault();
					if (input) {
						input.focus();
						input.setCustomValidity('Please select a student from the list.');
						input.reportValidity();
						setTimeout(() => input.setCustomValidity(''), 0);
					}
				}
			});
		}

		function openModal() {
			modal.classList.add('open');
			modal.setAttribute('aria-hidden', 'false');
			const first = modal.querySelector('input, select, textarea, button');
			if (first) first.focus();
		}

		function closeModal() {
			modal.classList.remove('open');
			modal.setAttribute('aria-hidden', 'true');
			openBtn.focus();
		}

		openBtn.addEventListener('click', openModal);
		modal.addEventListener('click', function (e) {
			if (e.target === modal) closeModal();
		});
		modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', closeModal);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
		});
	})();

	(function () {
		const modal = document.getElementById('editGradeModal');
		if (!modal) return;
		const idEl = document.getElementById('editGradeId');
        const subjectEl = document.getElementById('editGradeSubject');
        const unitsEl = document.getElementById('editGradeUnits');
        const percentEl = document.getElementById('editGradePercent');
        const ratingTextEl = document.getElementById('editGradeRatingText');
        const termEl = document.getElementById('editGradeTerm');
        const schoolYearEl = document.getElementById('editGradeSchoolYear');
        const dateEl = document.getElementById('editGradeDate');

		function percentToLabel(p) {
			const n = Number(p);
			if (!Number.isFinite(n) || n <= 0) return 'No performance';
			if (n < 60) return 'Unsuitable (1)';
			if (n <= 69) return 'Needs Improvement (2)';
			if (n <= 79) return 'Developing (3)';
			if (n <= 89) return 'Very Good (4)';
			return 'Outstanding (5)';
		}

		function syncRatingText() {
			if (!ratingTextEl || !percentEl) return;
			ratingTextEl.value = percentToLabel(percentEl.value);
		}

		function openModal(btn) {
            idEl.value = btn.getAttribute('data-grade-id') || '';
            subjectEl.value = btn.getAttribute('data-grade-subject') || '';
            unitsEl.value = btn.getAttribute('data-grade-units') || '0';
            if (percentEl) percentEl.value = btn.getAttribute('data-grade-percent') || '';
            if (termEl) termEl.value = btn.getAttribute('data-grade-term') || '';
            if (schoolYearEl) schoolYearEl.value = btn.getAttribute('data-grade-school-year') || '';
            dateEl.value = btn.getAttribute('data-grade-date') || '';
            syncRatingText();
			modal.classList.add('open');
			modal.setAttribute('aria-hidden', 'false');
			subjectEl.focus();
		}

		function closeModal() {
			modal.classList.remove('open');
			modal.setAttribute('aria-hidden', 'true');
		}

		document.querySelectorAll('.editGradeBtn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				openModal(btn);
			});
		});

		modal.addEventListener('click', function (e) {
			if (e.target === modal) closeModal();
		});
		modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', closeModal);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
		});
		if (percentEl) {
			percentEl.addEventListener('input', syncRatingText);
		}
	})();

	(function () {
		const percentEl = document.getElementById('addGradePercent');
		const ratingTextEl = document.getElementById('addGradeRatingText');
		if (!percentEl || !ratingTextEl) return;

		function percentToLabel(p) {
			const n = Number(p);
			if (!Number.isFinite(n) || n <= 0) return 'No performance';
			if (n < 60) return 'Unsuitable (1)';
			if (n <= 69) return 'Needs Improvement (2)';
			if (n <= 79) return 'Developing (3)';
			if (n <= 89) return 'Very Good (4)';
			return 'Outstanding (5)';
		}

		function sync() {
			ratingTextEl.value = percentToLabel(percentEl.value);
		}

		percentEl.addEventListener('input', sync);
		sync();
	})();

	(function () {
		const students = <?php echo json_encode($students); ?>;
		const input = document.getElementById('filterStudentSearch');
		const hidden = document.getElementById('filterStudentId');
		const list = document.getElementById('filterStudentList');
		const btn = document.getElementById('applyAcademicFilters');
		const term = document.getElementById('filterTerm');
		const sy = document.getElementById('filterSchoolYear');
		if (!input || !hidden || !list || !btn) return;

		function renderList(items) {
			list.innerHTML = '';
			items.slice(0, 8).forEach(function (s) {
				const div = document.createElement('div');
				div.className = 'typeahead-item';
				div.innerHTML = `<span>${s.username}</span><span class="typeahead-meta">#${s.id}</span>`;
				div.addEventListener('click', function () {
					input.value = s.username;
					hidden.value = s.id;
					list.hidden = true;
				});
				list.appendChild(div);
			});
			list.hidden = items.length === 0;
		}

		input.addEventListener('input', function () {
			const q = input.value.trim().toLowerCase();
			if (!q) {
				hidden.value = '';
				list.hidden = true;
				list.innerHTML = '';
				return;
			}
			const matches = students.filter(s => (s.username || '').toLowerCase().includes(q));
			renderList(matches);
		});

		document.addEventListener('click', function (e) {
			if (!list || list.hidden) return;
			if (e.target === input || list.contains(e.target)) return;
			list.hidden = true;
		});

		btn.addEventListener('click', function () {
			const params = new URLSearchParams();
			const sid = hidden.value ? String(hidden.value) : '';
			if (sid) params.set('seminarian_id', sid);
			if (term && term.value) params.set('term', term.value);
			if (sy && sy.value.trim()) params.set('school_year', sy.value.trim());
			<?php if ($show_archived) { ?>
			params.set('archived', '1');
			<?php } ?>
			window.location.href = 'academic.php' + (params.toString() ? ('?' + params.toString()) : '');
		});
	})();

    document.getElementById('gradeSearch').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('.grade-row');
        
        rows.forEach(row => {
            const student = row.querySelector('.student-name').textContent.toLowerCase();
            const subject = row.querySelector('.subject-name').textContent.toLowerCase();
            if (student.includes(searchTerm) || subject.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
