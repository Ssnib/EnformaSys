<?php
include '../includes/session_fix.php';
if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}
include '../includes/db.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';
$role = $_SESSION['role'];
$allowed_timetable_types = ['Academic', 'Formation', 'Church', 'Spiritual', 'Human', 'Pastoral'];

$is_admin  = $role === 'Dean';
$is_shrine = $role === 'Shrine';
$show_archived       = isset($_GET['archived']) && $_GET['archived'] === '1' && $is_admin;
$can_add_events      = $is_admin || $is_shrine;
$can_update_events   = $is_admin || $is_shrine;
$can_archive_events  = $is_admin;

$_tt_schema = timetable_ensure_schema($conn);
$_has_created_by = !empty($_tt_schema['has_created_by']);
$_has_visibility = !empty($_tt_schema['has_visibility']);
$_has_end_time   = !empty($_tt_schema['has_end_time']);
$_has_location   = !empty($_tt_schema['has_location']);

// Date prefill from dashboard mini-calendar click
$prefill_date = '';
if (isset($_GET['prefill_date'])) {
    $pd = trim((string)$_GET['prefill_date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd)) {
        $prefill_date = $pd;
    }
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_start_ts = strtotime($month_start);
$month_end = date('Y-m-t', $month_start_ts);

$conflict = false;
$conflict_message = '';
$conflict_modal = '';
$conflict_form_data = [];
if ($can_archive_events) {
    if (isset($_POST['archive_event'])) {
        if (!verify_csrf_token($_POST['csrf_token'])) {
            die("CSRF token validation failed.");
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        $redirect_year = (int)($_POST['year'] ?? $year);
        $redirect_month = (int)($_POST['month'] ?? $month);
        if ($redirect_month < 1 || $redirect_month > 12) {
            $redirect_month = $month;
        }
        if ($event_id > 0) {
            $stmt_arc = $conn->prepare("UPDATE timetable SET archived_at = NOW() WHERE id = ?");
            $stmt_arc->bind_param('i', $event_id);
            $stmt_arc->execute();
        }
        header('Location: timetable.php?year=' . $redirect_year . '&month=' . $redirect_month . '&archived=0');
        exit;
    }

    if (isset($_POST['restore_event'])) {
        if (!verify_csrf_token($_POST['csrf_token'])) {
            die("CSRF token validation failed.");
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        $redirect_year = (int)($_POST['year'] ?? $year);
        $redirect_month = (int)($_POST['month'] ?? $month);
        if ($redirect_month < 1 || $redirect_month > 12) {
            $redirect_month = $month;
        }
        if ($event_id > 0) {
            $stmt_res = $conn->prepare("UPDATE timetable SET archived_at = NULL WHERE id = ?");
            $stmt_res->bind_param('i', $event_id);
            $stmt_res->execute();
        }
        header('Location: timetable.php?year=' . $redirect_year . '&month=' . $redirect_month . '&archived=1');
        exit;
    }
}

if ($can_update_events) {
    if (isset($_POST['update_event'])) {
        if (!verify_csrf_token($_POST['csrf_token'])) {
            die("CSRF token validation failed.");
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        $event = trim((string)($_POST['event'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));
        $time = trim((string)($_POST['time'] ?? ''));
        $end_time = trim((string)($_POST['end_time'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $type = $_POST['type'] ?? '';
        if (!in_array($type, $allowed_timetable_types, true)) {
            die("Invalid event type.");
        }
        $note = $_POST['note'] ?? '';
        $redirect_year = (int)($_POST['year'] ?? $year);
        $redirect_month = (int)($_POST['month'] ?? $month);
        if ($redirect_month < 1 || $redirect_month > 12) {
            $redirect_month = $month;
        }

        if ($end_time === '' && $time !== '') {
            $st_n = timetable_normalize_time($time);
            if ($st_n !== null) {
                $end_time = substr(timetable_effective_end_time($st_n, null), 0, 5);
            }
        }

        if ($event_id > 0) {
            $conf = timetable_find_conflict($conn, $date, $time, $end_time, $event_id, $location);
            if ($conf !== null) {
                http_response_code(409);
                $conflict = true;
                $conflict_message = (string)$conf['message'];
                $conflict_modal = 'edit';
                $conflict_form_data = [
                    'event_id' => $event_id,
                    'event' => $event,
                    'date' => $date,
                    'time' => $time,
                    'end_time' => $end_time,
                    'location' => $location,
                    'type' => $type,
                    'note' => $note,
                ];
            } else {
                $end_norm = timetable_normalize_time($end_time);
                $loc_val = $location !== '' ? $location : null;
                $stmt_upd = $conn->prepare("UPDATE timetable SET event = ?, date = ?, time = ?, end_time = ?, type = ?, location = ?, note = ? WHERE id = ?");
                $stmt_upd->bind_param('sssssssi', $event, $date, $time, $end_norm, $type, $loc_val, $note, $event_id);
                $stmt_upd->execute();
                header('Location: timetable.php?year=' . $redirect_year . '&month=' . $redirect_month);
                exit;
            }
        }
    }
}

if (isset($_POST['add_event']) && $can_add_events) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $event = trim((string)($_POST['event'] ?? ''));
    $date  = trim((string)($_POST['date'] ?? ''));
    $time  = trim((string)($_POST['time'] ?? ''));
    $end_time = trim((string)($_POST['end_time'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $type  = $_POST['type'] ?? '';
    if (!in_array($type, $allowed_timetable_types, true)) {
        die("Invalid event type.");
    }
    $note = $_POST['note'] ?? '';
    $visibility_val = ($_has_visibility && in_array($_POST['visibility'] ?? '', ['all', 'admin_only'], true))
        ? $_POST['visibility']
        : 'all';

    if ($end_time === '' && $time !== '') {
        $st_n = timetable_normalize_time($time);
        if ($st_n !== null) {
            $end_time = substr(timetable_effective_end_time($st_n, null), 0, 5);
        }
    }

    // Check for schedule overlap conflict
    $conf = timetable_find_conflict($conn, $date, $time, $end_time, 0, $location);
    if ($conf !== null) {
        http_response_code(409);
        $conflict = true;
        $conflict_message = (string)$conf['message'];
        $conflict_modal = 'add';
        $conflict_form_data = [
            'event' => $event,
            'date' => $date,
            'time' => $time,
            'end_time' => $end_time,
            'location' => $location,
            'type' => $type,
            'note' => $note,
            'visibility' => $visibility_val,
        ];
    } else {
        $created_by_val = (int)($_SESSION['user'] ?? 0);
        $end_norm = timetable_normalize_time($end_time);
        $loc_val = $location !== '' ? $location : null;
        if ($_has_created_by && $_has_visibility) {
            $stmt = $conn->prepare("INSERT INTO timetable (event, date, time, end_time, type, location, note, created_by, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssis", $event, $date, $time, $end_norm, $type, $loc_val, $note, $created_by_val, $visibility_val);
        } elseif ($_has_created_by) {
            $stmt = $conn->prepare("INSERT INTO timetable (event, date, time, end_time, type, location, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssi", $event, $date, $time, $end_norm, $type, $loc_val, $note, $created_by_val);
        } else {
            $stmt = $conn->prepare("INSERT INTO timetable (event, date, time, end_time, type, location, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $event, $date, $time, $end_norm, $type, $loc_val, $note);
        }
        $stmt->execute();
        $redirect_year  = (int)($_POST['year']  ?? $year);
        $redirect_month = (int)($_POST['month'] ?? $month);
        if ($redirect_month < 1 || $redirect_month > 12) {
            $redirect_month = $month;
        }
        header('Location: timetable.php?year=' . $redirect_year . '&month=' . $redirect_month);
        exit;
    }
}

$events_by_date = [];
$month_sql = $show_archived
    ? "SELECT * FROM timetable WHERE date BETWEEN ? AND ? AND archived_at IS NOT NULL ORDER BY date, time"
    : "SELECT * FROM timetable WHERE date BETWEEN ? AND ? AND archived_at IS NULL ORDER BY date, time";
$stmt_month = $conn->prepare($month_sql);
$stmt_month->bind_param('ss', $month_start, $month_end);
$stmt_month->execute();
$month_res = $stmt_month->get_result();
while ($row = $month_res->fetch_assoc()) {
    $events_by_date[$row['date']][] = $row;
}

$today = date('Y-m-d');
$upcoming_sql = $show_archived
    ? "SELECT * FROM timetable WHERE archived_at IS NOT NULL ORDER BY date DESC, time DESC LIMIT 50"
    : "SELECT * FROM timetable WHERE archived_at IS NULL AND date >= ? ORDER BY date, time LIMIT 50";
$stmt_upcoming = $conn->prepare($upcoming_sql);
if (!$show_archived) {
    $stmt_upcoming->bind_param('s', $today);
}
$stmt_upcoming->execute();
$result = $stmt_upcoming->get_result();
?>
<?php
$pageTitle = 'Timetable';
$activeNav = 'timetable';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin: 0; font-size: 1.5rem; font-weight: 800;"><i class="fas fa-calendar-alt" style="color: #0f766e; margin-right: 0.35rem;"></i> Timetable & Events</h1>
                    <div style="color: #64748b; font-size: 0.85rem; font-weight: 600;">Seminary schedule, liturgy, formation, and academic activities.</div>
                </div>
				<?php if ($can_archive_events) { ?>
					<div style="display: flex; align-items: center; gap: 0.5rem;">
						<a href="timetable.php?year=<?php echo (int)$year; ?>&month=<?php echo (int)$month; ?>&archived=<?php echo $show_archived ? 0 : 1; ?>" class="action-btn-secondary" style="font-size: 0.8rem; padding: 0.45rem 0.85rem;">
							<i class="fas fa-archive"></i> <?php echo $show_archived ? 'Show Active' : 'Show Archived'; ?>
						</a>
						<?php if (!$show_archived) { ?>
							<button type="button" id="openAddEventModal" class="action-btn-primary" style="padding: 0.45rem 0.95rem; font-size: 0.85rem;">
								<i class="fas fa-plus"></i> Add Event
							</button>
						<?php } ?>
					</div>
				<?php } elseif ($can_add_events && !$show_archived) { ?>
					<button type="button" id="openAddEventModal" class="action-btn-primary" style="padding: 0.45rem 0.95rem; font-size: 0.85rem;">
						<i class="fas fa-plus"></i> Add Event
					</button>
				<?php } ?>
            </div>

            <?php if (isset($success)) { ?>
                <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>
            <?php if ($conflict) { ?>
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-red-600"></i>
                    <span><?php echo htmlspecialchars($conflict_message !== '' ? $conflict_message : 'Schedule Conflict! Another event overlaps this time range.'); ?></span>
                </div>
            <?php } ?>

            <?php
                $prev_ts = strtotime('-1 month', $month_start_ts);
                $next_ts = strtotime('+1 month', $month_start_ts);
                $prev_year = (int)date('Y', $prev_ts);
                $prev_month = (int)date('n', $prev_ts);
                $next_year = (int)date('Y', $next_ts);
                $next_month = (int)date('n', $next_ts);
                $title = date('F Y', $month_start_ts);
                $first_day_weekday = (int)date('w', $month_start_ts);
                $days_in_month = (int)date('t', $month_start_ts);
                $today_str = date('Y-m-d');
            ?>

			<?php if (!$show_archived) { ?>
			<div class="minimal-card calendar" style="margin-bottom: 2rem;">
                <div class="minimal-toolbar" style="background: #ffffff; padding: 0.85rem 1.25rem;">
                    <div style="font-size: 1.15rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-calendar-days" style="color: #0f766e;"></i>
                        <span><?php echo htmlspecialchars($title); ?></span>
                    </div>
                    <div class="pill-group">
                        <a href="timetable.php?year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?>" class="pill-btn" aria-label="Previous month"><i class="fas fa-chevron-left text-xs"></i> Prev</a>
                        <a href="timetable.php" class="pill-btn <?php echo ($year === (int)date('Y') && $month === (int)date('n')) ? 'active' : ''; ?>" aria-label="Go to current month">Today</a>
                        <a href="timetable.php?year=<?php echo $next_year; ?>&month=<?php echo $next_month; ?>" class="pill-btn" aria-label="Next month">Next <i class="fas fa-chevron-right text-xs"></i></a>
                    </div>
                </div>
			<?php } ?>
                </div>
                <div class="calendar-weekdays">
                    <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                </div>
                <div class="calendar-grid">
                    <?php
                        $total_cells = $first_day_weekday + $days_in_month;
                        $total_cells = (int)ceil($total_cells / 7) * 7;
                        $day_num = 1;
                        for ($cell = 0; $cell < $total_cells; $cell++) {
                            $is_current_month = $cell >= $first_day_weekday && $day_num <= $days_in_month;

                            if ($is_current_month) {
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_num);
                                $events = $events_by_date[$date_str] ?? [];
                                $is_today = $date_str === $today_str;
                                $classes = 'calendar-day' . ($is_today ? ' today' : '');
                                echo "<div class='{$classes}' data-date='{$date_str}'>";
                                echo "<div class='calendar-day-number'>{$day_num}</div>";
                                if (!empty($events)) {
                                    echo "<div class='calendar-badges'>";
                                    $shown = 0;
                                    foreach ($events as $ev) {
                                        if ($shown >= 2) break;
                                        $t = date('h:i A', strtotime($ev['time']));
                                        $name_raw = (string)$ev['event'];
                                        $name = htmlspecialchars($name_raw);
                                        if (strlen($name_raw) > 18) {
                                            $name = htmlspecialchars(substr($name_raw, 0, 18)) . '…';
                                        }
                                        $safe_title = htmlspecialchars($ev['event']);
                                        $safe_type = htmlspecialchars($ev['type']);
                                        $safe_date = htmlspecialchars($ev['date']);
                                        $end_disp = !empty($ev['end_time']) ? (' – ' . date('h:i A', strtotime($ev['end_time']))) : '';
                                        $safe_time = htmlspecialchars(date('h:i A', strtotime($ev['time'])) . $end_disp);
                                        $safe_start_raw = htmlspecialchars(substr((string)$ev['time'], 0, 5));
                                        $safe_end_raw = htmlspecialchars(!empty($ev['end_time']) ? substr((string)$ev['end_time'], 0, 5) : date('H:i', min(86399, strtotime('1970-01-01 ' . substr((string)$ev['time'], 0, 8)) + 3600)));
                                        $safe_loc = htmlspecialchars((string)($ev['location'] ?? ''));
                                        $safe_id = (int)$ev['id'];
                                        $safe_note = htmlspecialchars($ev['note'] ?? '');
                                        echo "<div class='calendar-event' data-event-id='{$safe_id}' data-event-title='{$safe_title}' data-event-type='{$safe_type}' data-event-date='{$safe_date}' data-event-time='{$safe_time}' data-event-start-time='{$safe_start_raw}' data-event-end-time='{$safe_end_raw}' data-event-location='{$safe_loc}' data-event-note='{$safe_note}' title='{$safe_title}'><span>{$name}</span><span class='meta'>{$t}</span></div>";
                                        $shown++;
                                    }
                                    $remaining = count($events) - $shown;
                                    if ($remaining > 0) {
                                        echo "<div class='calendar-more'>+{$remaining} more</div>";
                                    }
                                    echo "</div>";
                                }
                                echo "</div>";
                                $day_num++;
                            } else {
                                echo "<div class='calendar-day muted'></div>";
                            }
                        }
                    ?>
                </div>
            </div>

            <div class="minimal-card">
                <div class="minimal-toolbar">
                    <strong style="font-size: 0.95rem; color: #0f172a;"><i class="fas fa-calendar-check" style="color: #0f766e; margin-right: 0.35rem;"></i> <?php echo $show_archived ? 'Archived Events' : 'Upcoming Events'; ?></strong>
                </div>
                <div style="overflow-x: auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th>Time & Date</th>
                                <th>Event Details</th>
                                <th>Category</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()) {
                                $row_end_disp = !empty($row['end_time']) ? (' – ' . date('h:i A', strtotime($row['end_time']))) : '';
                                $row_time_range = date('h:i A', strtotime($row['time'])) . $row_end_disp;
                                $row_end_input = !empty($row['end_time']) ? substr((string)$row['end_time'], 0, 5) : date('H:i', min(86399, strtotime('1970-01-01 ' . substr((string)$row['time'], 0, 8)) + 3600));
                                $row_loc = trim((string)($row['location'] ?? ''));
                            ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <strong style="color: #0f766e;"><?php echo htmlspecialchars($row_time_range); ?></strong><br>
                                    <small style="color: #64748b;"><?php echo date('M d, Y', strtotime($row['date'])); ?></small>
                                </td>
                                <td>
                                    <strong style="font-size: 1rem; color: #0f172a;"><?php echo htmlspecialchars($row['event']); ?></strong>
                                    <?php if ($row_loc !== '') { ?>
                                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                                            <i class="fas fa-location-dot" style="color: #0f766e;"></i> <?php echo htmlspecialchars($row_loc); ?>
                                        </div>
                                    <?php } ?>
                                </td>
                                <td>
                                    <span class="status-badge status-info">
                                        <?php echo htmlspecialchars($row['type']); ?>
                                    </span>
                                </td>
                                <td style="white-space: nowrap; text-align: right;">
                                    <button type="button" class="viewEventBtn action-btn-secondary" 
                                        data-event-id="<?php echo (int)$row['id']; ?>"
                                        data-event-title="<?php echo htmlspecialchars($row['event']); ?>"
                                        data-event-type="<?php echo htmlspecialchars($row['type']); ?>"
                                        data-event-date="<?php echo htmlspecialchars($row['date']); ?>"
                                        data-event-time="<?php echo htmlspecialchars($row_time_range); ?>"
                                        data-event-start-time="<?php echo htmlspecialchars(substr((string)$row['time'], 0, 5)); ?>"
                                        data-event-end-time="<?php echo htmlspecialchars($row_end_input); ?>"
                                        data-event-location="<?php echo htmlspecialchars($row_loc); ?>"
                                        data-event-note="<?php echo htmlspecialchars($row['note'] ?? ''); ?>"
                                        style="padding: 0.3rem 0.6rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($can_update_events || $can_archive_events) { ?>
                                        <?php if (!$show_archived) { ?>
                                        <button type="button" class="editEventBtn action-btn-secondary" 
                                            data-event-id="<?php echo (int)$row['id']; ?>"
                                            data-event-title="<?php echo htmlspecialchars($row['event']); ?>"
                                            data-event-type="<?php echo htmlspecialchars($row['type']); ?>"
                                            data-event-date="<?php echo htmlspecialchars($row['date']); ?>"
                                            data-event-time="<?php echo htmlspecialchars(substr($row['time'], 0, 5)); ?>"
                                            data-event-end-time="<?php echo htmlspecialchars($row_end_input); ?>"
                                            data-event-location="<?php echo htmlspecialchars($row_loc); ?>"
                                            data-event-note="<?php echo htmlspecialchars($row['note'] ?? ''); ?>"
                                            style="padding: 0.3rem 0.6rem; font-size: 0.75rem;">
                                            <i class="fas fa-pen"></i> Edit
                                        </button>
                                        <?php if ($can_archive_events) { ?>
                                            <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="event_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                                                <input type="hidden" name="month" value="<?php echo (int)$month; ?>">
                                                <button type="submit" name="archive_event" class="action-btn-danger" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick="return confirm('Archive this event?');">
                                                    <i class="fas fa-archive"></i>
                                                </button>
                                            </form>
                                        <?php } ?>
                                        <?php } else { ?>
                                        <?php if ($can_archive_events) { ?>
                                            <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="event_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                                                <input type="hidden" name="month" value="<?php echo (int)$month; ?>">
                                                <button type="submit" name="restore_event" class="action-btn-secondary" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick="return confirm('Restore this event?');">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                            </form>
                                        <?php } ?>
                                        <?php } ?>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

    <div class="modal" id="viewEventModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="viewEventTitle">
            <div class="modal-header">
                <div class="modal-title" id="viewEventTitle"><i class="fas fa-eye"></i> Event Details</div>
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Title</div>
                        <div id="viewEventValueTitle" style="font-size: 1.15rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Type</div>
                        <div id="viewEventValueType" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Date</div>
                        <div id="viewEventValueDate" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Time</div>
                        <div id="viewEventValueTime" style="font-size: 1rem; font-weight: 900; color: #0f172a;"></div>
                    </div>
                </div>
                <div id="viewEventLocationSection" style="margin-top: 1rem; display: none;">
                    <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Location / Venue</div>
                    <div id="viewEventValueLocation" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-top: 0.25rem;"></div>
                </div>
                <div id="viewEventNoteSection" style="margin-top: 1rem;">
                    <div style="font-size: 0.8rem; font-weight: 900; color: #64748b; letter-spacing: 0.08em; text-transform: uppercase;">Note</div>
                    <div id="viewEventValueNote" style="font-size: 0.95rem; color: #334155; margin-top: 0.25rem;"></div>
                </div>

                <div class="modal-actions" style="margin-top: 1.25rem;">
                    <button type="button" class="modal-close" data-modal-close>Close</button>
                    <?php if ($can_update_events) { ?>
                        <?php if (!$show_archived) { ?>
                            <button type="button" id="openEditFromView" style="background: var(--accent);">Edit</button>
                        <?php } ?>
                    <?php } ?>
                    <?php if ($can_archive_events) { ?>
                        <form method="post" style="display: inline; box-shadow: none; padding: 0; background: none; border: none; margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="event_id" id="deleteEventId" value="">
                            <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                            <input type="hidden" name="month" value="<?php echo (int)$month; ?>">
                            <?php if (!$show_archived) { ?>
                                <button type="submit" name="archive_event" style="background: var(--danger);" onclick="return confirm('Archive this event?');">Archive</button>
                            <?php } else { ?>
                                <button type="submit" name="restore_event" style="background: var(--accent);" onclick="return confirm('Restore this event?');">Restore</button>
                            <?php } ?>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($can_update_events) { ?>
    <div class="modal" id="editEventModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editEventTitleLabel">
            <div class="modal-header">
                <div class="modal-title" id="editEventTitleLabel"><i class="fas fa-pen"></i> Update Event</div>
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="editEventForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="event_id" id="editEventId" value="<?php echo $conflict_modal === 'edit' ? (int)($conflict_form_data['event_id'] ?? 0) : ''; ?>">
                    <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                    <input type="hidden" name="month" value="<?php echo (int)$month; ?>">

                    <div id="editEventConflictBanner" class="<?php echo ($conflict_modal === 'edit' && $conflict_message !== '') ? '' : 'hidden'; ?> mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm font-semibold flex items-start gap-2" role="alert">
                        <i class="fa-solid fa-triangle-exclamation text-red-600 mt-0.5 shrink-0"></i>
                        <span id="editEventConflictText"><?php echo $conflict_modal === 'edit' ? htmlspecialchars($conflict_message) : ''; ?></span>
                    </div>

                    <div class="field">
                        <label>Event Title</label>
                        <input type="text" name="event" id="editEventTitle" value="<?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['event'] ?? '')) : ''; ?>" required>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                        <div class="field">
                            <label>Date</label>
                            <input type="date" name="date" id="editEventDate" value="<?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['date'] ?? '')) : ''; ?>" required>
                        </div>
                        <div class="field">
                            <label>Start Time</label>
                            <input type="time" name="time" id="editEventTime" value="<?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['time'] ?? '')) : ''; ?>" required>
                        </div>
                        <div class="field">
                            <label>End Time</label>
                            <input type="time" name="end_time" id="editEventEndTime" value="<?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['end_time'] ?? '')) : ''; ?>" required>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div class="field">
                            <label>Event Type</label>
                            <select name="type" id="editEventType">
                                <?php foreach ($allowed_timetable_types as $t) { ?>
                                    <option <?php echo ($conflict_modal === 'edit' && ($conflict_form_data['type'] ?? '') === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Location / Venue (Optional)</label>
                            <input type="text" name="location" id="editEventLocation" placeholder="e.g. Main Chapel, AVR" value="<?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['location'] ?? '')) : ''; ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label>Note</label>
                        <textarea name="note" id="editEventNote" rows="3" style="width: 100%; padding: 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.08); color: #e2e8f0; font-family: inherit; resize: vertical;"><?php echo $conflict_modal === 'edit' ? htmlspecialchars((string)($conflict_form_data['note'] ?? '')) : ''; ?></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="modal-close" data-modal-close>Cancel</button>
                        <button type="submit" name="update_event" id="editEventSubmitBtn">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if ($can_add_events) { ?>
    <div class="modal" id="addEventModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="addEventTitle">
            <div class="modal-header">
                <div class="modal-title" id="addEventTitle"><i class="fas fa-plus-circle"></i> Add New Event</div>
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="addEventForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                    <input type="hidden" name="month" value="<?php echo (int)$month; ?>">

                    <div id="addEventConflictBanner" class="<?php echo ($conflict_modal === 'add' && $conflict_message !== '') ? '' : 'hidden'; ?> mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm font-semibold flex items-start gap-2" role="alert">
                        <i class="fa-solid fa-triangle-exclamation text-red-600 mt-0.5 shrink-0"></i>
                        <span id="addEventConflictText"><?php echo $conflict_modal === 'add' ? htmlspecialchars($conflict_message) : ''; ?></span>
                    </div>

                    <div class="field">
                        <label>Event Title</label>
                        <input type="text" name="event" id="eventTitle" placeholder="e.g. Morning Prayer" value="<?php echo $conflict_modal === 'add' ? htmlspecialchars((string)($conflict_form_data['event'] ?? '')) : ''; ?>" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                        <div class="field">
                            <label>Date</label>
                            <input type="date" name="date" id="eventDate" required
                                   value="<?php echo htmlspecialchars($conflict_modal === 'add' ? (string)($conflict_form_data['date'] ?? $today) : ($prefill_date !== '' ? $prefill_date : $today)); ?>">
                        </div>
                        <div class="field">
                            <label>Start Time</label>
                            <input type="time" name="time" id="eventTime" value="<?php echo $conflict_modal === 'add' ? htmlspecialchars((string)($conflict_form_data['time'] ?? '')) : ''; ?>" required>
                        </div>
                        <div class="field">
                            <label>End Time</label>
                            <input type="time" name="end_time" id="eventEndTime" value="<?php echo $conflict_modal === 'add' ? htmlspecialchars((string)($conflict_form_data['end_time'] ?? '')) : ''; ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div class="field">
                            <label>Event Type</label>
                            <select name="type" id="eventType">
                                <?php foreach ($allowed_timetable_types as $t) { ?>
                                    <option <?php echo ($conflict_modal === 'add' && ($conflict_form_data['type'] ?? '') === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Location / Venue (Optional)</label>
                            <input type="text" name="location" id="eventLocation" placeholder="e.g. Main Chapel, AVR" value="<?php echo $conflict_modal === 'add' ? htmlspecialchars((string)($conflict_form_data['location'] ?? '')) : ''; ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>Note</label>
                        <textarea name="note" id="eventNote" rows="3" style="width: 100%; padding: 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.08); color: #e2e8f0; font-family: inherit; resize: vertical;" placeholder="Optional notes or description..."><?php echo $conflict_modal === 'add' ? htmlspecialchars((string)($conflict_form_data['note'] ?? '')) : ''; ?></textarea>
                    </div>

                    <?php if ($_has_visibility && $can_add_events) { ?>
                    <div class="field">
                        <label>Visibility</label>
                        <select name="visibility" id="eventVisibility">
                            <option value="all">All Users</option>
                            <option value="admin_only" <?php echo ($conflict_modal === 'add' && ($conflict_form_data['visibility'] ?? '') === 'admin_only') ? 'selected' : ''; ?>>Admin Only (Dean &amp; Shrine)</option>
                        </select>
                    </div>
                    <?php } ?>

                    <div class="modal-actions">
                        <button type="button" class="modal-close" data-modal-close>Cancel</button>
                        <button type="submit" name="add_event" id="addEventSubmitBtn">Schedule Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php } ?>

    <script>
    (function () {
        function openModal(modal, focusEl) {
            if (!modal) return;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            if (focusEl) focusEl.focus();
        }

        function closeModal(modal, returnFocusEl) {
            if (!modal) return;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            if (returnFocusEl) returnFocusEl.focus();
        }

        function wireClose(modal, returnFocusEl) {
            if (!modal) return;
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal(modal, returnFocusEl);
            });
            modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    closeModal(modal, returnFocusEl);
                });
            });
        }

        const viewModal = document.getElementById('viewEventModal');
        const viewTitle = document.getElementById('viewEventValueTitle');
        const viewType = document.getElementById('viewEventValueType');
        const viewDate = document.getElementById('viewEventValueDate');
        const viewTime = document.getElementById('viewEventValueTime');
        const deleteId = document.getElementById('deleteEventId');
        const openEditFromView = document.getElementById('openEditFromView');

        let currentEvent = null;

        function setCurrentEvent(ev) {
            currentEvent = ev;
            if (viewTitle) viewTitle.textContent = ev.title || '';
            if (viewType) viewType.textContent = ev.type || '';
            if (viewDate) viewDate.textContent = ev.date || '';
            if (viewTime) viewTime.textContent = ev.time || '';
            if (deleteId) deleteId.value = ev.id || '';
            const locSection = document.getElementById('viewEventLocationSection');
            const locEl = document.getElementById('viewEventValueLocation');
            if (locSection && locEl) {
                if (ev.location) {
                    locSection.style.display = '';
                    locEl.textContent = ev.location;
                } else {
                    locSection.style.display = 'none';
                    locEl.textContent = '';
                }
            }
            const noteSection = document.getElementById('viewEventNoteSection');
            const noteEl = document.getElementById('viewEventValueNote');
            if (noteSection && noteEl) {
                const noteVal = ev.note || '';
                if (noteVal) {
                    noteSection.style.display = '';
                    noteEl.textContent = noteVal;
                } else {
                    noteSection.style.display = 'none';
                    noteEl.textContent = '';
                }
            }
        }

        function openView(ev, returnFocusEl) {
            if (!viewModal) return;
            setCurrentEvent(ev);
            wireClose(viewModal, returnFocusEl);
            const closeBtn = viewModal.querySelector('[data-modal-close]');
            openModal(viewModal, closeBtn);
        }

        document.querySelectorAll('.calendar-event').forEach(function (el) {
            el.addEventListener('click', function () {
                openView({
                    id: el.getAttribute('data-event-id'),
                    title: el.getAttribute('data-event-title'),
                    type: el.getAttribute('data-event-type'),
                    date: el.getAttribute('data-event-date'),
                    time: el.getAttribute('data-event-time'),
                    start_time: el.getAttribute('data-event-start-time') || '',
                    end_time: el.getAttribute('data-event-end-time') || '',
                    location: el.getAttribute('data-event-location') || '',
                    note: el.getAttribute('data-event-note') || '',
                }, el);
            });
        });

        document.querySelectorAll('.viewEventBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openView({
                    id: btn.getAttribute('data-event-id'),
                    title: btn.getAttribute('data-event-title'),
                    type: btn.getAttribute('data-event-type'),
                    date: btn.getAttribute('data-event-date'),
                    time: btn.getAttribute('data-event-time'),
                    start_time: btn.getAttribute('data-event-start-time') || '',
                    end_time: btn.getAttribute('data-event-end-time') || '',
                    location: btn.getAttribute('data-event-location') || '',
                    note: btn.getAttribute('data-event-note') || '',
                }, btn);
            });
        });

        function addOneHour(hhmm) {
            if (!hhmm || !/^\d{2}:\d{2}/.test(hhmm)) return '';
            const parts = hhmm.split(':');
            const totalMin = Math.min(23 * 60 + 59, (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10) + 60);
            return String(Math.floor(totalMin / 60)).padStart(2, '0') + ':' + String(totalMin % 60).padStart(2, '0');
        }

        function attachConflictChecker(cfg) {
            if (!cfg.formEl || !cfg.dateEl || !cfg.startEl || !cfg.endEl) return { check: async function() { return false; } };
            let hasConflict = false;
            let seq = 0;

            function setConflictUI(conflictMsg) {
                hasConflict = !!conflictMsg;
                if (cfg.bannerEl && cfg.textEl) {
                    if (conflictMsg) {
                        cfg.textEl.textContent = conflictMsg;
                        cfg.bannerEl.classList.remove('hidden');
                        cfg.bannerEl.style.display = 'flex';
                    } else {
                        cfg.textEl.textContent = '';
                        cfg.bannerEl.classList.add('hidden');
                        cfg.bannerEl.style.display = 'none';
                    }
                }
                if (cfg.submitBtn) {
                    cfg.submitBtn.disabled = hasConflict;
                    cfg.submitBtn.style.opacity = hasConflict ? '0.5' : '1';
                    cfg.submitBtn.style.cursor = hasConflict ? 'not-allowed' : 'pointer';
                }
            }

            async function runCheck() {
                const dateVal = (cfg.dateEl.value || '').trim();
                const startVal = (cfg.startEl.value || '').trim();
                const endVal = (cfg.endEl.value || '').trim();
                const locVal = cfg.locEl ? (cfg.locEl.value || '').trim() : '';
                const exclVal = cfg.idEl ? (cfg.idEl.value || '0') : '0';

                if (!dateVal || !startVal || !endVal) {
                    setConflictUI('');
                    return false;
                }
                if (endVal <= startVal) {
                    setConflictUI('End time must be later than Start time.');
                    return true;
                }

                const mySeq = ++seq;
                try {
                    const params = new URLSearchParams({
                        action: 'timetable_check_conflict',
                        date: dateVal,
                        time: startVal,
                        end_time: endVal,
                        exclude_id: exclVal,
                        location: locVal
                    });
                    const res = await fetch('ajax.php?' + params.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (mySeq !== seq) return hasConflict;
                    const data = await res.json();
                    if (res.status === 409 || (data && data.conflict)) {
                        setConflictUI(data.error || 'Schedule Conflict: Overlaps with an existing event.');
                        return true;
                    }
                    setConflictUI('');
                    return false;
                } catch (err) {
                    return false;
                }
            }

            cfg.startEl.addEventListener('change', function() {
                if (!cfg.endEl.value && cfg.startEl.value) {
                    cfg.endEl.value = addOneHour(cfg.startEl.value);
                }
                runCheck();
            });
            cfg.dateEl.addEventListener('change', runCheck);
            cfg.endEl.addEventListener('change', runCheck);
            cfg.endEl.addEventListener('input', runCheck);
            if (cfg.locEl) cfg.locEl.addEventListener('input', runCheck);

            cfg.formEl.addEventListener('submit', async function(e) {
                if (hasConflict) {
                    e.preventDefault();
                    return;
                }
                const found = await runCheck();
                if (found) {
                    e.preventDefault();
                }
            });

            return { check: runCheck, clear: function() { setConflictUI(''); } };
        }

        const editModal = document.getElementById('editEventModal');
        const editForm = document.getElementById('editEventForm');
        const editId = document.getElementById('editEventId');
        const editTitle = document.getElementById('editEventTitle');
        const editDate = document.getElementById('editEventDate');
        const editTime = document.getElementById('editEventTime');
        const editEndTime = document.getElementById('editEventEndTime');
        const editLocation = document.getElementById('editEventLocation');
        const editType = document.getElementById('editEventType');
        const editNote = document.getElementById('editEventNote');

        const editChecker = attachConflictChecker({
            formEl: editForm,
            dateEl: editDate,
            startEl: editTime,
            endEl: editEndTime,
            locEl: editLocation,
            idEl: editId,
            bannerEl: document.getElementById('editEventConflictBanner'),
            textEl: document.getElementById('editEventConflictText'),
            submitBtn: document.getElementById('editEventSubmitBtn'),
        });

        function openEdit(ev, returnFocusEl) {
            if (!editModal) return;
            if (editId) editId.value = ev.id || '';
            if (editTitle) editTitle.value = ev.title || '';
            if (editDate) editDate.value = ev.date || '';
            if (editTime) editTime.value = ev.start_time || ev.time || '';
            if (editEndTime) editEndTime.value = ev.end_time || addOneHour(ev.start_time || ev.time || '');
            if (editLocation) editLocation.value = ev.location || '';
            if (editType) editType.value = ev.type || 'Academic';
            if (editNote) editNote.value = ev.note || '';
            editChecker.clear();
            wireClose(editModal, returnFocusEl);
            openModal(editModal, editTitle || editModal.querySelector('input,select,button'));
            editChecker.check();
        }

        document.querySelectorAll('.editEventBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openEdit({
                    id: btn.getAttribute('data-event-id'),
                    title: btn.getAttribute('data-event-title'),
                    type: btn.getAttribute('data-event-type'),
                    date: btn.getAttribute('data-event-date'),
                    time: btn.getAttribute('data-event-time'),
                    start_time: btn.getAttribute('data-event-time'),
                    end_time: btn.getAttribute('data-event-end-time') || '',
                    location: btn.getAttribute('data-event-location') || '',
                    note: btn.getAttribute('data-event-note') || '',
                }, btn);
            });
        });

        if (openEditFromView) {
            openEditFromView.addEventListener('click', function () {
                if (!currentEvent) return;
                closeModal(viewModal);
                openEdit(currentEvent, openEditFromView);
            });
        }

        const addModal = document.getElementById('addEventModal');
        const addForm = document.getElementById('addEventForm');
        const addBtn = document.getElementById('openAddEventModal');
        const addDate = document.getElementById('eventDate');
        const addTitle = document.getElementById('eventTitle');
        const addTime = document.getElementById('eventTime');
        const addEndTime = document.getElementById('eventEndTime');
        const addLocation = document.getElementById('eventLocation');

        const addChecker = attachConflictChecker({
            formEl: addForm,
            dateEl: addDate,
            startEl: addTime,
            endEl: addEndTime,
            locEl: addLocation,
            idEl: null,
            bannerEl: document.getElementById('addEventConflictBanner'),
            textEl: document.getElementById('addEventConflictText'),
            submitBtn: document.getElementById('addEventSubmitBtn'),
        });

        if (addModal && addBtn) {
            function openAdd(dateValue) {
                if (addDate && dateValue) addDate.value = dateValue;
                addChecker.clear();
                wireClose(addModal, addBtn);
                openModal(addModal, addTitle || addModal.querySelector('input,select,button'));
            }

            addBtn.addEventListener('click', function () {
                openAdd();
            });
            document.querySelectorAll('.calendar-day[data-date]').forEach(function (cell) {
                cell.addEventListener('dblclick', function () {
                    openAdd(cell.getAttribute('data-date'));
                    if (addTime) addTime.focus();
                });
            });
        }

        // If server returned HTTP 409 conflict on POST, auto-open the relevant modal with the error banner visible
        const serverConflictModal = <?php echo json_encode($conflict_modal); ?>;
        if (serverConflictModal === 'add' && addModal) {
            wireClose(addModal, addBtn);
            openModal(addModal, addDate || addTitle);
            if (document.getElementById('addEventSubmitBtn')) {
                document.getElementById('addEventSubmitBtn').disabled = true;
                document.getElementById('addEventSubmitBtn').style.opacity = '0.5';
            }
        } else if (serverConflictModal === 'edit' && editModal) {
            wireClose(editModal, null);
            openModal(editModal, editDate || editTitle);
            if (document.getElementById('editEventSubmitBtn')) {
                document.getElementById('editEventSubmitBtn').disabled = true;
                document.getElementById('editEventSubmitBtn').style.opacity = '0.5';
            }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            [viewModal, editModal, addModal].forEach(function (m) {
                if (m && m.classList.contains('open')) {
                    closeModal(m);
                }
            });
        });
    })();
    </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
