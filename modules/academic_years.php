<?php
include '../includes/session_fix.php';
if (isset($_SESSION['role']) && (string)$_SESSION['role'] === 'Rector') {
    capstone_redirect('index.php');
}
require_roles(['Registrar', 'Dean']);
include '../includes/db.php';

if (isset($_POST['create_ay'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $start_year = (int)($_POST['start_year'] ?? 0);
    $end_year = (int)($_POST['end_year'] ?? 0);
    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));

    $start_date_db = $start_date !== '' ? $start_date : null;
    $end_date_db = $end_date !== '' ? $end_date : null;

    $sd_ok = $start_date_db === null || DateTime::createFromFormat('Y-m-d', $start_date_db) !== false;
    $ed_ok = $end_date_db === null || DateTime::createFromFormat('Y-m-d', $end_date_db) !== false;

    if (!($start_year > 0 && $end_year > 0 && $end_year === ($start_year + 1))) {
        $error = 'Invalid Academic Year. End year must be Start year + 1.';
    } elseif (!$sd_ok || !$ed_ok) {
        $error = 'Invalid date format.';
    } elseif ($start_date_db !== null && $end_date_db !== null && $end_date_db < $start_date_db) {
        $error = 'End date must be on or after start date.';
    } else {
        $label = $start_year . '-' . $end_year;
        $stmt = $conn->prepare("INSERT IGNORE INTO academic_years (label, start_year, end_year, start_date, end_date, is_active, is_current) VALUES (?, ?, ?, ?, ?, 1, 0)");
        $stmt->bind_param('siiss', $label, $start_year, $end_year, $start_date_db, $end_date_db);
        $stmt->execute();
        $success = 'Academic Year created.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Created Academic Year: ' . $label);
    }
}

if (isset($_POST['set_current'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $conn->begin_transaction();
        try {
            $stmt_cur = $conn->prepare("SELECT label FROM academic_years WHERE id = ? AND archived_at IS NULL LIMIT 1");
            $stmt_cur->bind_param('i', $id);
            $stmt_cur->execute();
            $row = $stmt_cur->get_result()->fetch_assoc();
            $label = (string)($row['label'] ?? '');
            if ($label === '') {
                throw new Exception('Academic Year not found.');
            }

            $conn->query("UPDATE academic_years SET is_current = 0");
            $stmt = $conn->prepare("UPDATE academic_years SET is_current = 1, is_active = 1 WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $conn->commit();
            $success = 'Current Academic Year set.';
            log_activity((int)($_SESSION['user'] ?? 0), 'Set Current Academic Year: ' . $label);
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to set current Academic Year.';
        }
    }
}

if (isset($_POST['toggle_active'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE academic_years SET is_active = ?, is_current = CASE WHEN ? = 0 THEN 0 ELSE is_current END WHERE id = ? AND archived_at IS NULL");
        $stmt->bind_param('iii', $is_active, $is_active, $id);
        $stmt->execute();
        $success = 'Academic Year updated.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Updated Academic Year active status (ID ' . $id . ')');
    }
}

if (isset($_POST['archive_ay'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE academic_years SET archived_at = NOW(), is_active = 0, is_current = 0 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $success = 'Academic Year archived.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Archived Academic Year (ID ' . $id . ')');
    }
}

if (isset($_POST['restore_ay'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE academic_years SET archived_at = NULL, is_active = 1 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $success = 'Academic Year restored.';
        log_activity((int)($_SESSION['user'] ?? 0), 'Restored Academic Year (ID ' . $id . ')');
    }
}

if (isset($_POST['edit_ay'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $start_year = (int)($_POST['start_year'] ?? 0);
    $end_year = (int)($_POST['end_year'] ?? 0);
    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));

    $start_date_db = $start_date !== '' ? $start_date : null;
    $end_date_db = $end_date !== '' ? $end_date : null;

    $sd_ok = $start_date_db === null || DateTime::createFromFormat('Y-m-d', $start_date_db) !== false;
    $ed_ok = $end_date_db === null || DateTime::createFromFormat('Y-m-d', $end_date_db) !== false;

    if ($id > 0 && $start_year > 0 && $end_year > 0 && $end_year === ($start_year + 1)) {
        $stmt_old = $conn->prepare("SELECT label FROM academic_years WHERE id = ? LIMIT 1");
        $stmt_old->bind_param('i', $id);
        $stmt_old->execute();
        $old = $stmt_old->get_result()->fetch_assoc();
        $old_label = trim((string)($old['label'] ?? ''));
        $new_label = $start_year . '-' . $end_year;
        if ($old_label === '') {
            $error = 'Academic Year not found.';
        } elseif (!$sd_ok || !$ed_ok) {
            $error = 'Invalid date format.';
        } elseif ($start_date_db !== null && $end_date_db !== null && $end_date_db < $start_date_db) {
            $error = 'End date must be on or after start date.';
        } else {
            $tables = [
                ['table' => 'applicants', 'col' => 'academic_year'],
                ['table' => 'semester_subjects', 'col' => 'academic_year'],
                ['table' => 'student_grades', 'col' => 'academic_year'],
                ['table' => 'gpa_records', 'col' => 'academic_year'],
                ['table' => 'academic_progress', 'col' => 'academic_year'],
                ['table' => 'irregular_students', 'col' => 'academic_year'],
                ['table' => 'academic_evaluations', 'col' => 'academic_year'],
                ['table' => 'spiritual_evaluations', 'col' => 'academic_year'],
                ['table' => 'human_evaluations', 'col' => 'academic_year'],
                ['table' => 'pastoral_evaluations', 'col' => 'academic_year'],
                ['table' => 'lodging_invoices', 'col' => 'academic_year'],
                ['table' => 'lodging_payments', 'col' => 'academic_year'],
                ['table' => 'grades', 'col' => 'school_year'],
                ['table' => 'seminarians', 'col' => 'current_academic_year'],
                ['table' => 'seminarians', 'col' => 'academic_year_completed'],
            ];

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE academic_years SET label = ?, start_year = ?, end_year = ?, start_date = ?, end_date = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('siissi', $new_label, $start_year, $end_year, $start_date_db, $end_date_db, $id);
                $stmt->execute();

                foreach ($tables as $t) {
                    $sql = "UPDATE " . $t['table'] . " SET " . $t['col'] . " = ? WHERE " . $t['col'] . " = ?";
                    $stmt_u = $conn->prepare($sql);
                    if ($stmt_u) {
                        $stmt_u->bind_param('ss', $new_label, $old_label);
                        $stmt_u->execute();
                    }
                }

                $conn->commit();
                $success = 'Academic Year updated.';
                log_activity((int)($_SESSION['user'] ?? 0), 'Edited Academic Year: ' . $old_label . ' -> ' . $new_label);
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to update Academic Year.';
            }
        }
    } else {
        $error = 'Invalid Academic Year. End year must be Start year + 1.';
    }
}

$rows = [];
$res = $conn->query("SELECT * FROM academic_years ORDER BY start_year DESC, end_year DESC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

$max_end = (int)date('Y');
foreach ($rows as $r) {
    if ((int)($r['end_year'] ?? 0) > $max_end) {
        $max_end = (int)$r['end_year'];
    }
}
$suggested_start = $max_end;
$suggested_end = $max_end + 1;
?>
<?php
$pageTitle = 'Academic Years';
$activeNav = 'academic_years';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body minimal-container">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 class="page-title" style="margin: 0 0 0.25rem 0;"><i class="fas fa-calendar-days"></i> Academic Years</h1>
                    <div style="color: var(--text-secondary); font-size: 0.85rem;">Configure school years, set the active academic calendar, and schedule term dates.</div>
                </div>
                <button type="button" id="toggleCreateAyBtn" class="action-btn-primary" style="padding: 0.5rem 1rem;">
                    <i class="fas fa-plus"></i> New Academic Year
                </button>
            </div>

            <?php if (isset($success)) echo "<p class='success' style='margin-bottom: 1rem;'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error' style='margin-bottom: 1rem;'>" . htmlspecialchars($error) . "</p>"; ?>

            <!-- Collapsible Create Card -->
            <div id="createAyCard" class="minimal-card hidden" style="border-left: 4px solid #0f766e; margin-bottom: 1.5rem;">
                <div class="minimal-toolbar">
                    <h3 style="margin: 0; font-size: 1rem;"><i class="fas fa-plus-circle" style="color: #0f766e;"></i> Create Academic Year</h3>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary);">1-Click Preset:</span>
                        <button type="button" class="quick-chip" id="presetNextAyChip"
                                data-start="<?php echo $suggested_start; ?>" data-end="<?php echo $suggested_end; ?>">
                            + <?php echo $suggested_start . '-' . $suggested_end; ?>
                        </button>
                        <button type="button" id="closeCreateAyBtn" style="background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1.1rem; margin-left: 0.5rem;">&times;</button>
                    </div>
                </div>
                <div style="padding: 1.25rem;">
                    <form method="post" id="createAyForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                            <div class="field" style="margin: 0;">
                                <label>Start Year</label>
                                <input type="number" id="createStartYear" name="start_year" value="<?php echo $suggested_start; ?>" placeholder="<?php echo $suggested_start; ?>" required>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>End Year (Autofilled)</label>
                                <input type="number" id="createEndYear" name="end_year" value="<?php echo $suggested_end; ?>" placeholder="<?php echo $suggested_end; ?>" required>
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>Start Date (Optional)</label>
                                <input type="date" name="start_date">
                            </div>
                            <div class="field" style="margin: 0;">
                                <label>End Date (Optional)</label>
                                <input type="date" name="end_date">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
                            <button type="button" id="cancelCreateAy" class="action-btn-secondary">Cancel</button>
                            <button type="submit" name="create_ay" class="action-btn-primary"><i class="fas fa-check"></i> Create Term</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="minimal-card">
                <div class="minimal-toolbar">
                    <h3 style="margin: 0; font-size: 0.95rem;"><i class="fas fa-list"></i> Academic Years (<?php echo count($rows); ?>)</h3>
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="aySearchInput" placeholder="Filter academic years..." autocomplete="off">
                        <span class="search-kbd">/</span>
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th>Academic Year</th>
                                <th>Schedule / Duration</th>
                                <th>Status</th>
                                <th>Current Active</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rows) === 0) { ?>
                                <tr><td colspan="4" style="color: var(--text-secondary);">No Academic Years found.</td></tr>
                            <?php } else { foreach ($rows as $r) { ?>
                                <?php
                                    $id = (int)($r['id'] ?? 0);
                                    $label = (string)($r['label'] ?? '');
                                    $is_active = (int)($r['is_active'] ?? 0) === 1;
                                    $is_current = (int)($r['is_current'] ?? 0) === 1;
                                    $archived = ($r['archived_at'] ?? null) !== null;
                                ?>
                                <tr class="ay-row" data-search="<?php echo htmlspecialchars(strtolower($label)); ?>" style="<?php echo $archived ? 'opacity: 0.6;' : ''; ?>">
                                    <td>
                                        <div style="font-weight: 700; font-size: 0.95rem; font-variant-numeric: tabular-nums;">
                                            <?php echo htmlspecialchars($label); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (trim((string)($r['start_date'] ?? '')) !== '' || trim((string)($r['end_date'] ?? '')) !== '') { ?>
                                            <span style="color: #475569; font-size: 0.825rem;">
                                                <i class="far fa-calendar-alt" style="color: #94a3b8; margin-right: 4px;"></i>
                                                <?php echo htmlspecialchars(trim((string)($r['start_date'] ?? ''))); ?> &rarr; <?php echo htmlspecialchars(trim((string)($r['end_date'] ?? ''))); ?>
                                            </span>
                                        <?php } else { ?>
                                            <span style="color: var(--text-secondary); font-size: 0.8rem;">Dates unconfigured</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php
                                            if ($archived) {
                                                echo "<span class='status-badge status-muted'>Archived</span>";
                                            } elseif ($is_active) {
                                                echo "<span class='status-badge status-cleared'>Active</span>";
                                            } else {
                                                echo "<span class='status-badge status-warning'>Inactive</span>";
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo $is_current
                                            ? "<span class='status-badge status-info'><i class='fas fa-check-circle'></i> Current Active</span>"
                                            : "<span style='color: var(--text-secondary); font-weight: 600;'>—</span>";
                                        ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; gap: 0.35rem; align-items: center; justify-content: flex-end;">
                                            <?php if (!$archived) { ?>
                                                <?php if (!$is_current) { ?>
                                                    <form method="post" style="display: inline; margin: 0;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                        <button type="submit" name="set_current" class="action-btn-primary" style="padding: 0.35rem 0.65rem;" title="Make this the current semester">
                                                            <i class="fas fa-flag"></i> Set Current
                                                        </button>
                                                    </form>
                                                <?php } ?>
                                                <form method="post" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $is_active ? 0 : 1; ?>">
                                                    <button type="submit" name="toggle_active" class="action-btn-secondary" style="padding: 0.35rem 0.65rem;">
                                                        <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                                <button type="button" class="action-btn-secondary toggle-edit-ay-btn" data-target="editAy_<?php echo $id; ?>" style="padding: 0.35rem 0.65rem;" title="Edit details">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Archive Academic Year <?php echo htmlspecialchars($label); ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                    <button type="submit" name="archive_ay" class="action-btn-danger" style="padding: 0.35rem 0.65rem;" title="Archive">
                                                        <i class="fas fa-box-archive"></i>
                                                    </button>
                                                </form>
                                            <?php } else { ?>
                                                <form method="post" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                    <button type="submit" name="restore_ay" class="action-btn-secondary" style="padding: 0.35rem 0.65rem;">Restore</button>
                                                </form>
                                            <?php } ?>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Inline Edit Drawer Row -->
                                <tr id="editAy_<?php echo $id; ?>" class="hidden" style="background: #f8fafc;">
                                    <td colspan="5" style="padding: 1rem 1.25rem;">
                                        <form method="post" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) auto; gap: 0.75rem; align-items: flex-end; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <div class="field" style="margin: 0;">
                                                <label style="font-size: 0.75rem;">Start Year</label>
                                                <input type="number" name="start_year" value="<?php echo (int)($r['start_year'] ?? 0); ?>" required>
                                            </div>
                                            <div class="field" style="margin: 0;">
                                                <label style="font-size: 0.75rem;">End Year</label>
                                                <input type="number" name="end_year" value="<?php echo (int)($r['end_year'] ?? 0); ?>" required>
                                            </div>
                                            <div class="field" style="margin: 0;">
                                                <label style="font-size: 0.75rem;">Start Date</label>
                                                <input type="date" name="start_date" value="<?php echo htmlspecialchars(trim((string)($r['start_date'] ?? ''))); ?>">
                                            </div>
                                            <div class="field" style="margin: 0;">
                                                <label style="font-size: 0.75rem;">End Date</label>
                                                <input type="date" name="end_date" value="<?php echo htmlspecialchars(trim((string)($r['end_date'] ?? ''))); ?>">
                                            </div>
                                            <div style="display: flex; gap: 0.35rem;">
                                                <button type="submit" name="edit_ay" class="action-btn-primary" style="padding: 0.45rem 0.85rem;"><i class="fas fa-save"></i> Save</button>
                                                <button type="button" class="action-btn-secondary close-edit-ay-btn" data-target="editAy_<?php echo $id; ?>" style="padding: 0.45rem 0.7rem;">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function() {
            // Toggle Create Drawer
            const toggleBtn = document.getElementById('toggleCreateAyBtn');
            const createCard = document.getElementById('createAyCard');
            const closeBtn = document.getElementById('closeCreateAyBtn');
            const cancelBtn = document.getElementById('cancelCreateAy');

            function toggleCreate(show) {
                if (!createCard) return;
                createCard.classList.toggle('hidden', !show);
                if (show) {
                    document.getElementById('createStartYear')?.focus();
                }
            }

            if (toggleBtn) toggleBtn.addEventListener('click', () => toggleCreate(createCard.classList.contains('hidden')));
            if (closeBtn) closeBtn.addEventListener('click', () => toggleCreate(false));
            if (cancelBtn) cancelBtn.addEventListener('click', () => toggleCreate(false));

            // Autofill end year on start year change
            const startInput = document.getElementById('createStartYear');
            const endInput = document.getElementById('createEndYear');
            if (startInput && endInput) {
                startInput.addEventListener('input', () => {
                    const y = parseInt(startInput.value, 10);
                    if (!isNaN(y) && y > 1900) {
                        endInput.value = y + 1;
                    }
                });
            }

            // Quick preset chip
            const presetChip = document.getElementById('presetNextAyChip');
            if (presetChip && startInput && endInput) {
                presetChip.addEventListener('click', () => {
                    startInput.value = presetChip.dataset.start;
                    endInput.value = presetChip.dataset.end;
                    startInput.focus();
                });
            }

            // Live Search
            const searchInput = document.getElementById('aySearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    const q = searchInput.value.toLowerCase().trim();
                    document.querySelectorAll('#ayTbody tr.ay-row').forEach(tr => {
                        const hay = tr.getAttribute('data-search') || '';
                        tr.style.display = hay.includes(q) ? '' : 'none';
                    });
                });
            }

            // Toggle Edit Rows
            document.querySelectorAll('.toggle-edit-ay-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const tid = btn.getAttribute('data-target');
                    const row = document.getElementById(tid);
                    if (row) row.classList.toggle('hidden');
                });
            });

            document.querySelectorAll('.close-edit-ay-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const tid = btn.getAttribute('data-target');
                    const row = document.getElementById(tid);
                    if (row) row.classList.add('hidden');
                });
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
