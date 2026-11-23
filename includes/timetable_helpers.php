<?php
/**
 * Shared Timetable Conflict Detection & Upcoming Events Notification Helpers.
 */

/**
 * Ensure timetable optional columns (`end_time`, `location`) and notification
 * read-tracking table exist safely.
 */
function timetable_ensure_schema(mysqli $conn): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $hasCol = function(string $col) use ($conn): bool {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $col);
        $stmt->execute();
        return ((int)($stmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
    };

    $hasEndTime = $hasCol('end_time');
    if (!$hasEndTime) {
        @$conn->query("ALTER TABLE timetable ADD COLUMN end_time TIME NULL AFTER time");
        $hasEndTime = $hasCol('end_time');
    }

    $hasLocation = $hasCol('location');
    if (!$hasLocation) {
        @$conn->query("ALTER TABLE timetable ADD COLUMN location VARCHAR(150) NULL AFTER type");
        $hasLocation = $hasCol('location');
    }

    @$conn->query(
        "CREATE TABLE IF NOT EXISTS user_event_notifications_read (
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $cached = [
        'has_end_time' => $hasEndTime,
        'has_location' => $hasLocation,
        'has_created_by' => $hasCol('created_by'),
        'has_visibility' => $hasCol('visibility'),
    ];
    return $cached;
}

/**
 * Normalize HH:MM or HH:MM:SS time string to HH:MM:SS, or null if invalid.
 */
function timetable_normalize_time(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m)) {
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('H:i:s', $ts);
    }
    return null;
}

/**
 * Resolve effective end time (HH:MM:SS) for an existing event row.
 * Falls back to +1 hour when end_time is null/empty.
 */
function timetable_effective_end_time(string $start_norm, ?string $end_raw): string {
    $end_norm = timetable_normalize_time($end_raw);
    $start_sec = strtotime('1970-01-01 ' . $start_norm);
    if ($end_norm !== null) {
        $end_sec = strtotime('1970-01-01 ' . $end_norm);
        if ($end_sec > $start_sec) {
            return $end_norm;
        }
    }
    return date('H:i:s', min(86399, $start_sec + 3600));
}

/**
 * Detect overlapping active events on the same date (and room if specified).
 * Standard overlap check: newStartTime < existingEndTime && newEndTime > existingStartTime
 *
 * @return array<string, mixed>|null Conflicting event details + message, or null if clear.
 */
function timetable_find_conflict(
    mysqli $conn,
    string $date,
    string $start_time,
    string $end_time,
    int $exclude_id = 0,
    string $location = ''
): ?array {
    $schema = timetable_ensure_schema($conn);
    $new_start = timetable_normalize_time($start_time);
    $new_end = timetable_normalize_time($end_time);
    if ($new_start === null || $new_end === null || $date === '') {
        return null;
    }

    $new_start_sec = strtotime('1970-01-01 ' . $new_start);
    $new_end_sec = strtotime('1970-01-01 ' . $new_end);
    if ($new_end_sec <= $new_start_sec) {
        return [
            'invalid_range' => true,
            'message' => 'End time must be later than Start time.',
        ];
    }

    $end_col = !empty($schema['has_end_time']) ? 'end_time' : 'NULL AS end_time';
    $loc_col = !empty($schema['has_location']) ? 'location' : "'' AS location";

    $sql = "SELECT id, event, date, time, {$end_col}, {$loc_col}, type
            FROM timetable
            WHERE archived_at IS NULL AND date = ?";
    if ($exclude_id > 0) {
        $sql .= " AND id <> ?";
    }
    $sql .= " ORDER BY time ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    if ($exclude_id > 0) {
        $stmt->bind_param('si', $date, $exclude_id);
    } else {
        $stmt->bind_param('s', $date);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $req_loc = strtolower(trim($location));
    while ($res && ($row = $res->fetch_assoc())) {
        $ex_start = timetable_normalize_time((string)($row['time'] ?? ''));
        if ($ex_start === null) continue;
        $ex_end = timetable_effective_end_time($ex_start, $row['end_time'] ?? null);

        $ex_start_sec = strtotime('1970-01-01 ' . $ex_start);
        $ex_end_sec = strtotime('1970-01-01 ' . $ex_end);

        if ($new_start_sec < $ex_end_sec && $new_end_sec > $ex_start_sec) {
            $title = (string)($row['event'] ?? 'Existing Event');
            $start_lbl = date('g:i A', $ex_start_sec);
            $end_lbl = date('g:i A', $ex_end_sec);
            $ex_loc = trim((string)($row['location'] ?? ''));
            $loc_suffix = ($req_loc !== '' && strtolower($ex_loc) === $req_loc)
                ? " at {$ex_loc}"
                : ($ex_loc !== '' ? " [{$ex_loc}]" : '');

            return [
                'invalid_range' => false,
                'id' => (int)$row['id'],
                'event' => $title,
                'date' => (string)$row['date'],
                'time' => $ex_start,
                'end_time' => $ex_end,
                'location' => $ex_loc,
                'start_label' => $start_lbl,
                'end_label' => $end_lbl,
                'message' => "Schedule Conflict: Overlaps with '{$title}' ({$start_lbl} – {$end_lbl}){$loc_suffix}.",
            ];
        }
    }
    return null;
}

/**
 * Fetch upcoming events for Today through the next 7 days, along with per-user unread status.
 *
 * @return array<string, mixed>
 */
function timetable_get_upcoming_notifications(mysqli $conn, int $user_id, string $role = '', int $days_ahead = 7): array {
    $schema = timetable_ensure_schema($conn);

    $today = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+{$days_ahead} days"));

    $role_norm = strtolower(trim($role));
    $can_see_admin_only = in_array($role_norm, ['admin', 'dean', 'rector', 'shrine'], true);

    $end_col = !empty($schema['has_end_time']) ? 'end_time' : 'NULL AS end_time';
    $loc_col = !empty($schema['has_location']) ? 'location' : "'' AS location";

    $sql = "SELECT id, event, date, time, {$end_col}, {$loc_col}, type, note
            FROM timetable
            WHERE archived_at IS NULL
              AND date BETWEEN ? AND ?";
    if (!empty($schema['has_visibility']) && !$can_see_admin_only) {
        $sql .= " AND (visibility = 'all' OR visibility IS NULL OR visibility = '')";
    }
    $sql .= " ORDER BY date ASC, time ASC LIMIT 25";

    $stmt = $conn->prepare($sql);
    $events = [];
    $event_ids = [];
    if ($stmt) {
        $stmt->bind_param('ss', $today, $end_date);
        $stmt->execute();
        $res = $stmt->get_result();
        $today_ts = strtotime($today);

        while ($res && ($row = $res->fetch_assoc())) {
            $eid = (int)$row['id'];
            $ev_date = (string)$row['date'];
            $days_diff = (int)round((strtotime($ev_date) - $today_ts) / 86400);

            if ($days_diff <= 0) {
                $time_tag = 'Today';
                $tag_class = 'bg-emerald-100 text-emerald-700';
            } elseif ($days_diff === 1) {
                $time_tag = 'Tomorrow';
                $tag_class = 'bg-amber-100 text-amber-700';
            } else {
                $time_tag = 'In ' . $days_diff . ' days';
                $tag_class = 'bg-blue-100 text-blue-700';
            }

            $st_norm = timetable_normalize_time((string)($row['time'] ?? ''));
            $en_norm = timetable_normalize_time($row['end_time'] ?? null);
            $time_label = $st_norm ? date('g:i A', strtotime('1970-01-01 ' . $st_norm)) : '';
            if ($time_label !== '' && $en_norm && $en_norm > $st_norm) {
                $time_label .= ' – ' . date('g:i A', strtotime('1970-01-01 ' . $en_norm));
            }

            $event_ids[] = $eid;
            $events[] = [
                'id' => $eid,
                'title' => (string)($row['event'] ?? ''),
                'date' => $ev_date,
                'date_label' => date('M d, Y', strtotime($ev_date)),
                'time' => $st_norm ?: '',
                'end_time' => $en_norm ?: '',
                'time_label' => $time_label,
                'location' => trim((string)($row['location'] ?? '')),
                'type' => (string)($row['type'] ?? ''),
                'days_away' => $days_diff,
                'time_tag' => $time_tag,
                'tag_class' => $tag_class,
                'is_read' => false,
            ];
        }
    }

    // Lookup read event IDs for this user
    $read_map = [];
    if ($user_id > 0 && count($event_ids) > 0) {
        $ph = implode(',', array_fill(0, count($event_ids), '?'));
        $types = 'i' . str_repeat('i', count($event_ids));
        $stmt_r = $conn->prepare("SELECT event_id FROM user_event_notifications_read WHERE user_id = ? AND event_id IN ({$ph})");
        if ($stmt_r) {
            $bind_args = [&$types, &$user_id];
            foreach ($event_ids as $k => $v) {
                $bind_args[] = &$event_ids[$k];
            }
            call_user_func_array([$stmt_r, 'bind_param'], $bind_args);
            $stmt_r->execute();
            $res_r = $stmt_r->get_result();
            while ($res_r && ($rr = $res_r->fetch_assoc())) {
                $read_map[(int)$rr['event_id']] = true;
            }
        }
    }

    $unread_count = 0;
    foreach ($events as &$ev) {
        if (!empty($read_map[$ev['id']])) {
            $ev['is_read'] = true;
        } else {
            $unread_count++;
        }
    }
    unset($ev);

    return [
        'events' => $events,
        'unread_count' => $unread_count,
        'read_ids' => array_keys($read_map),
    ];
}

/**
 * Mark given event IDs as read for a user.
 */
function timetable_mark_notifications_read(mysqli $conn, int $user_id, array $event_ids): int {
    if ($user_id <= 0 || count($event_ids) === 0) {
        return 0;
    }
    timetable_ensure_schema($conn);
    $stmt = $conn->prepare("INSERT IGNORE INTO user_event_notifications_read (user_id, event_id) VALUES (?, ?)");
    if (!$stmt) {
        return 0;
    }
    $marked = 0;
    foreach ($event_ids as $raw_id) {
        $eid = (int)$raw_id;
        if ($eid <= 0) continue;
        $stmt->bind_param('ii', $user_id, $eid);
        if ($stmt->execute()) {
            $marked++;
        }
    }
    return $marked;
}
