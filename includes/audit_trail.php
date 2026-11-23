<?php
/**
 * Audit Trail & MySQL Trigger Manager for EnformaSys
 * Ensures `history_logs` table and AFTER UPDATE / BEFORE DELETE triggers
 * exist across sensitive grade and evaluation tables.
 */

if (!function_exists('audit_trail_monitored_tables')) {
    /**
     * Declarative map of sensitive tables and columns audited by MySQL triggers.
     *
     * @return array<string, array{pk:string, label:string, cols:string[], summary_old:string}>
     */
    function audit_trail_monitored_tables(): array
    {
        return [
            'student_grades' => [
                'pk' => 'grade_id',
                'label' => 'Student Grades',
                'cols' => [
                    'grade_id', 'student_id', 'subject_id', 'semester', 'academic_year',
                    'prelim_grade', 'midterm_grade', 'final_grade', 'final_computed_grade',
                    'status', 'remarks', 'is_locked', 'assessed_by', 'created_at', 'updated_at',
                ],
                'summary_old' => "CONCAT('Student #', OLD.student_id, ' · Subject #', OLD.subject_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Final: ', COALESCE(CAST(OLD.final_computed_grade AS CHAR), 'N/A'))",
            ],
            'grades' => [
                'pk' => 'id',
                'label' => 'Legacy Grades',
                'cols' => [
                    'id', 'seminarian_id', 'subject', 'units', 'grade', 'grade_percent',
                    'term', 'school_year', 'remarks', 'assessed_by', 'date', 'archived_at',
                ],
                'summary_old' => "CONCAT('Seminarian #', COALESCE(CAST(OLD.seminarian_id AS CHAR), '?'), ' · ', COALESCE(OLD.subject, 'Subject'), ' Grade: ', COALESCE(CAST(OLD.grade AS CHAR), 'N/A'))",
            ],
            'gpa_records' => [
                'pk' => 'gpa_id',
                'label' => 'GPA Records',
                'cols' => [
                    'gpa_id', 'student_id', 'semester', 'academic_year',
                    'semester_gpa', 'cumulative_gpa', 'performance_category', 'computed_at',
                ],
                'summary_old' => "CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Sem GPA: ', COALESCE(CAST(OLD.semester_gpa AS CHAR), 'N/A'))",
            ],
            'academic_evaluations' => [
                'pk' => 'id',
                'label' => 'Academic Evaluations',
                'cols' => [
                    'id', 'student_id', 'formator_id', 'gpa', 'academic_year',
                    'semester', 'evaluation_date', 'remarks', 'created_at', 'updated_at', 'archived_at',
                ],
                'summary_old' => "CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') GPA: ', COALESCE(CAST(OLD.gpa AS CHAR), 'N/A'))",
            ],
            'human_evaluations' => [
                'pk' => 'evaluation_id',
                'label' => 'Human Evaluations',
                'cols' => [
                    'evaluation_id', 'seminarian_id', 'formator_id', 'academic_year',
                    'semester', 'evaluation_date', 'created_at', 'updated_at', 'archived_at',
                ],
                'summary_old' => "CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date)",
            ],
            'pastoral_evaluations' => [
                'pk' => 'evaluation_id',
                'label' => 'Pastoral Evaluations',
                'cols' => [
                    'evaluation_id', 'seminarian_id', 'formator_id', 'academic_year',
                    'semester', 'evaluation_date', 'created_at', 'updated_at', 'archived_at',
                ],
                'summary_old' => "CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date)",
            ],
            'spiritual_evaluations' => [
                'pk' => 'evaluation_id',
                'label' => 'Spiritual Evaluations',
                'cols' => [
                    'evaluation_id', 'seminarian_id', 'spiritual_director_id', 'academic_year',
                    'semester', 'evaluation_date', 'created_at', 'updated_at', 'archived_at',
                ],
                'summary_old' => "CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date)",
            ],
        ];
    }
}

if (!function_exists('audit_build_json_expr')) {
    /**
     * Build a MySQL/MariaDB JSON_OBJECT(...) expression referencing OLD. or NEW. columns.
     *
     * @param string[] $cols
     */
    function audit_build_json_expr(string $rowPrefix, array $cols): string
    {
        $pairs = [];
        foreach ($cols as $col) {
            $pairs[] = "'" . $col . "', " . $rowPrefix . ".`" . $col . "`";
        }
        return "JSON_OBJECT(" . implode(", ", $pairs) . ")";
    }
}

if (!function_exists('audit_set_mysql_actor')) {
    /**
     * Bind current EnformaSys username/user ID into MySQL session variable @enformasys_actor
     * so AFTER UPDATE and BEFORE DELETE triggers attribute changes to the application user.
     */
    function audit_set_mysql_actor(mysqli $conn): void
    {
        static $lastActor = null;
        $actor = '';
        if (isset($_SESSION)) {
            $uname = trim((string)($_SESSION['username'] ?? ''));
            $uid = (string)($_SESSION['user_id'] ?? ($_SESSION['user'] ?? ''));
            if ($uname !== '') {
                $actor = $uname . ($uid !== '' && is_numeric($uid) ? " (#{$uid})" : '');
            } elseif ($uid !== '') {
                $actor = is_numeric($uid) ? "User #{$uid}" : $uid;
            }
        }
        if ($actor === '') {
            $actor = 'system';
        }
        if ($lastActor === $actor) {
            return;
        }
        $lastActor = $actor;
        $esc = $conn->real_escape_string(substr($actor, 0, 100));
        @$conn->query("SET @enformasys_actor = '{$esc}'");
    }
}

if (!function_exists('ensure_audit_trail_schema')) {
    /**
     * Idempotently creates `history_logs` and all AFTER UPDATE / BEFORE DELETE audit triggers.
     */
    function ensure_audit_trail_schema(mysqli $conn, bool $recreateTriggers = false): array
    {
        static $ensured = false;
        if ($ensured && !$recreateTriggers) {
            audit_set_mysql_actor($conn);
            return ['created_triggers' => 0, 'errors' => []];
        }

        $errors = [];
        $created = 0;

        $createTableSql = "CREATE TABLE IF NOT EXISTS `history_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `table_name` VARCHAR(64) NOT NULL,
            `record_id` BIGINT NULL,
            `action_type` ENUM('UPDATE', 'DELETE') NOT NULL,
            `old_data` LONGTEXT NOT NULL,
            `new_data` LONGTEXT NULL,
            `summary` VARCHAR(255) NULL,
            `changed_by` VARCHAR(100) NULL,
            `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_history_table_record` (`table_name`, `record_id`),
            KEY `idx_history_action` (`action_type`),
            KEY `idx_history_changed_at` (`changed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$conn->query($createTableSql)) {
            $errors[] = 'history_logs create failed: ' . $conn->error;
            return ['created_triggers' => 0, 'errors' => $errors];
        }

        $monitored = audit_trail_monitored_tables();

        // Check existing tables & triggers in bulk (2 fast metadata queries)
        $existingTables = [];
        $resT = @$conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        if ($resT) {
            while ($r = $resT->fetch_assoc()) {
                $existingTables[$r['TABLE_NAME']] = true;
            }
        }

        $existingTriggers = [];
        $resTr = @$conn->query("SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
        if ($resTr) {
            while ($r = $resTr->fetch_assoc()) {
                $existingTriggers[$r['TRIGGER_NAME']] = true;
            }
        }

        foreach ($monitored as $table => $spec) {
            if (empty($existingTables[$table])) {
                continue;
            }
            $pk = $spec['pk'];
            $oldJson = audit_build_json_expr('OLD', $spec['cols']);
            $newJson = audit_build_json_expr('NEW', $spec['cols']);
            $summaryExpr = $spec['summary_old'];
            $actorExpr = "COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER())";

            $updTrigger = "trg_{$table}_after_update";
            $delTrigger = "trg_{$table}_before_delete";

            if ($recreateTriggers || empty($existingTriggers[$updTrigger])) {
                @$conn->query("DROP TRIGGER IF EXISTS `{$updTrigger}`");
                $sqlUpd = "CREATE TRIGGER `{$updTrigger}` AFTER UPDATE ON `{$table}`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('{$table}', OLD.`{$pk}`, 'UPDATE', {$oldJson}, {$newJson}, LEFT({$summaryExpr}, 255), LEFT({$actorExpr}, 100), NOW());
END";
                if ($conn->query($sqlUpd)) {
                    $created++;
                } else {
                    $errors[] = "{$updTrigger}: " . $conn->error;
                }
            }

            if ($recreateTriggers || empty($existingTriggers[$delTrigger])) {
                @$conn->query("DROP TRIGGER IF EXISTS `{$delTrigger}`");
                $sqlDel = "CREATE TRIGGER `{$delTrigger}` BEFORE DELETE ON `{$table}`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('{$table}', OLD.`{$pk}`, 'DELETE', {$oldJson}, NULL, LEFT({$summaryExpr}, 255), LEFT({$actorExpr}, 100), NOW());
END";
                if ($conn->query($sqlDel)) {
                    $created++;
                } else {
                    $errors[] = "{$delTrigger}: " . $conn->error;
                }
            }
        }

        audit_set_mysql_actor($conn);
        $ensured = true;
        return ['created_triggers' => $created, 'errors' => $errors];
    }
}

if (!function_exists('audit_get_trigger_status')) {
    /**
     * Inspect installed triggers across monitored tables for the Backup & Audit UI.
     *
     * @return array<string, array{label:string, update_trigger:bool, delete_trigger:bool}>
     */
    function audit_get_trigger_status(mysqli $conn): array
    {
        $installed = [];
        $res = @$conn->query("SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $installed[$r['TRIGGER_NAME']] = true;
            }
        }

        $status = [];
        foreach (audit_trail_monitored_tables() as $table => $spec) {
            $status[$table] = [
                'label' => $spec['label'],
                'update_trigger' => !empty($installed["trg_{$table}_after_update"]),
                'delete_trigger' => !empty($installed["trg_{$table}_before_delete"]),
            ];
        }
        return $status;
    }
}

if (!function_exists('audit_fetch_recent_logs')) {
    /**
     * Query recent records from `history_logs` with optional table/action filtering.
     *
     * @return array<int, array<string, mixed>>
     */
    function audit_fetch_recent_logs(mysqli $conn, int $limit = 15, string $tableFilter = '', string $actionFilter = ''): array
    {
        $limit = max(1, min(200, $limit));
        $where = [];
        $types = '';
        $params = [];

        $validTables = array_keys(audit_trail_monitored_tables());
        if ($tableFilter !== '' && in_array($tableFilter, $validTables, true)) {
            $where[] = "table_name = ?";
            $types .= 's';
            $params[] = $tableFilter;
        }
        if (in_array($actionFilter, ['UPDATE', 'DELETE'], true)) {
            $where[] = "action_type = ?";
            $types .= 's';
            $params[] = $actionFilter;
        }

        $sql = "SELECT id, table_name, record_id, action_type, old_data, new_data, summary, changed_by, changed_at FROM history_logs";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY changed_at DESC, id DESC LIMIT {$limit}";

        $rows = [];
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
                $stmt->close();
            }
        } else {
            $res = @$conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('audit_fetch_activity_logs')) {
    /**
     * Fetch recent application-level security and activity audit logs from `activities`.
     *
     * @return array<int, array<string, mixed>>
     */
    function audit_fetch_activity_logs(mysqli $conn, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $chk = @$conn->query("SHOW TABLES LIKE 'activities'");
        if (!$chk || $chk->num_rows === 0) {
            return [];
        }

        $sql = "SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.field_name, a.previous_value, a.new_value, a.timestamp,
                       u.username, u.role AS user_role
                FROM activities a
                LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.timestamp DESC, a.id DESC
                LIMIT ?";
        $rows = [];
        $stmt = @$conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $limit);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $rows[] = $r;
                }
            }
            $stmt->close();
        }
        return $rows;
    }
}

