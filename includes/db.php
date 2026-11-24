<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/security.php';

$is_prod_db = enformasys_is_production();
$is_vercel_db = (enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== '');
$conn = enformasys_get_db(true);
if (!($conn instanceof mysqli)) {
    http_response_code(500);
    die('Service temporarily unavailable.');
}

require_once __DIR__ . '/seminarian_assignments.php';

if (
    isset($_SESSION)
    && function_exists('enformasys_refresh_ay_cache')
    && (empty($_SESSION['active_academic_year']) || empty($_SESSION['active_semester']))
) {
    enformasys_refresh_ay_cache($conn);
}

$skip_runtime_schema_sync = !defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC')
    && ($is_vercel_db || $is_prod_db || strtolower(trim((string)enformasys_env('DB_SKIP_SCHEMA_SYNC', 'false'))) === 'true');

if (!$skip_runtime_schema_sync) {

$role_col = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' LIMIT 1");
if ($role_col && $r = $role_col->fetch_assoc()) {
    $col_type = (string)($r['COLUMN_TYPE'] ?? '');
    if (
        $col_type !== ''
        && (
            stripos($col_type, 'cashier') === false
            || stripos($col_type, 'shrine') === false
            || stripos($col_type, 'academicformator') === false
            || stripos($col_type, 'spiritualdirector') === false
            || stripos($col_type, 'humanformator') === false
            || stripos($col_type, 'pastoralformator') === false
            || stripos($col_type, 'rector') === false
        )
    ) {
        $ok = $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('Student','Registrar','Dean','AcademicFormator','SpiritualDirector','HumanFormator','PastoralFormator','Cashier','Shrine','Rector') NOT NULL");
        if (!$ok) {
            $conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL");
        }
    }
} else {
    $conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL");
}

$tt_type_col = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'type' LIMIT 1");
if ($tt_type_col && $t = $tt_type_col->fetch_assoc()) {
    $col_type = (string)($t['COLUMN_TYPE'] ?? '');
    if ($col_type !== '' && stripos($col_type, 'spiritual') === false) {
        $conn->query("ALTER TABLE timetable MODIFY COLUMN type ENUM('Academic','Formation','Church','Spiritual','Human','Pastoral') NOT NULL");
    }
}

$tt_note_col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'note'");
if ($tt_note_col && (int)($tt_note_col->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE timetable ADD COLUMN note TEXT NULL AFTER type");
}

$ay_is_usable = false;
try {
    $test_ay = $conn->query("SELECT 1 FROM academic_years LIMIT 1");
    if ($test_ay !== false) {
        $ay_is_usable = true;
    }
} catch (Throwable $e) {
    $ay_is_usable = false;
}

if (!$ay_is_usable) {
    try {
        @$conn->query("DROP TABLE IF EXISTS academic_years");
    } catch (Throwable $e) {}

    $conn->query(
        "CREATE TABLE IF NOT EXISTS academic_years (\n"
        . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "label VARCHAR(9) NOT NULL,\n"
        . "start_year INT NOT NULL,\n"
        . "end_year INT NOT NULL,\n"
        . "start_date DATE NULL,\n"
        . "end_date DATE NULL,\n"
        . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
        . "is_current TINYINT(1) NOT NULL DEFAULT 0,\n"
        . "archived_at DATETIME NULL,\n"
        . "updated_at DATETIME NULL,\n"
        . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "UNIQUE KEY uniq_academic_years_label (label),\n"
        . "KEY idx_academic_years_active (is_active, archived_at),\n"
        . "KEY idx_academic_years_current (is_current)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

$ay_date_cols = [
    ['col' => 'start_date', 'sql' => "ALTER TABLE academic_years ADD COLUMN start_date DATE NULL AFTER end_year"],
    ['col' => 'end_date', 'sql' => "ALTER TABLE academic_years ADD COLUMN end_date DATE NULL AFTER start_date"],
];
foreach ($ay_date_cols as $c) {
    try {
        $col_check = $conn->query(
            "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_years' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
        );
        if ($col_check && (int)($col_check->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query($c['sql']);
        }
    } catch (Throwable $e) {}
}

$ay_count = 0;
try {
    $ay_count_res = $conn->query("SELECT COUNT(*) as c FROM academic_years");
    if ($ay_count_res && ($row = $ay_count_res->fetch_assoc())) {
        $ay_count = (int)($row['c'] ?? 0);
    }
} catch (Throwable $e) {
    $ay_count = 0;
}

if ($ay_count === 0) {
    $y = (int)date('Y');
    $m = (int)date('n');
    $start = $m >= 6 ? $y : ($y - 1);
    $end = $start + 1;
    $label = $start . '-' . $end;
    $start_date = sprintf('%04d-06-01', $start);
    $end_date = sprintf('%04d-05-31', $end);
    try {
        $stmt_seed_ay = $conn->prepare("INSERT INTO academic_years (label, start_year, end_year, start_date, end_date, is_active, is_current) VALUES (?, ?, ?, ?, ?, 1, 1)");
        if ($stmt_seed_ay) {
            $stmt_seed_ay->bind_param('siiss', $label, $start, $end, $start_date, $end_date);
            $stmt_seed_ay->execute();
        }
    } catch (Throwable $e) {}
}

$default_users = [
    ['username' => 'registrar', 'role' => 'Registrar'],
    ['username' => 'admin', 'role' => 'Dean'],
    ['username' => 'rector', 'role' => 'Rector'],
    ['username' => 'academicformator', 'role' => 'AcademicFormator'],
    ['username' => 'spiritualdirector', 'role' => 'SpiritualDirector'],
    ['username' => 'humanformator', 'role' => 'HumanFormator'],
    ['username' => 'pastoralformator', 'role' => 'PastoralFormator'],
    ['username' => 'cashier', 'role' => 'Cashier'],
    ['username' => 'shrine', 'role' => 'Shrine'],
];

$allow_seed_users = !enformasys_is_production() || strtolower((string)enformasys_env('ALLOW_DEFAULT_SEED', 'false')) === 'true';
$seed_pw_plain = (string)enformasys_env('DEFAULT_SEED_PASSWORD', enformasys_is_production() ? '' : 'pass');
if ($allow_seed_users && $seed_pw_plain !== '') {
    $stmt_user_exists = $conn->prepare("SELECT id, password FROM users WHERE username = ? LIMIT 1");
    $stmt_user_insert = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt_user_update_pw = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $hash = password_hash($seed_pw_plain, PASSWORD_DEFAULT);
    $seed_hashes = [
        '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2',
        '$2y$10$AOw5lwFqtx.O/f203U0nZe/y07zDCr5y9dEYB.gBiBMDt10BRn9fi',
    ];
    foreach ($default_users as $u) {
        $uname = (string)$u['username'];
        $role = (string)$u['role'];
        $stmt_user_exists->bind_param('s', $uname);
        $stmt_user_exists->execute();
        $res = $stmt_user_exists->get_result();
        if (!$res || $res->num_rows === 0) {
            $stmt_user_insert->bind_param('sss', $uname, $hash, $role);
            $stmt_user_insert->execute();
        } else {
            $row = $res->fetch_assoc();
            $uid = (int)($row['id'] ?? 0);
            $pw = (string)($row['password'] ?? '');
            if ($uid > 0 && ($pw === '' || in_array($pw, $seed_hashes, true))) {
                $stmt_user_update_pw->bind_param('si', $hash, $uid);
                $stmt_user_update_pw->execute();
            }
        }
    }
}

$activities_cols = [
    ['col' => 'entity_type', 'sql' => "ALTER TABLE activities ADD COLUMN entity_type VARCHAR(40) NULL AFTER action"],
    ['col' => 'entity_id', 'sql' => "ALTER TABLE activities ADD COLUMN entity_id INT NULL AFTER entity_type"],
    ['col' => 'field_name', 'sql' => "ALTER TABLE activities ADD COLUMN field_name VARCHAR(80) NULL AFTER entity_id"],
    ['col' => 'previous_value', 'sql' => "ALTER TABLE activities ADD COLUMN previous_value TEXT NULL AFTER field_name"],
    ['col' => 'new_value', 'sql' => "ALTER TABLE activities ADD COLUMN new_value TEXT NULL AFTER previous_value"],
];
foreach ($activities_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activities' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

// Dummy student1 account auto-seeding removed for production safety.

$col_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'units'");
if ($col_check) {
    $has_units = (int)($col_check->fetch_assoc()['c'] ?? 0);
    if ($has_units === 0) {
        $conn->query("ALTER TABLE grades ADD COLUMN units INT NOT NULL DEFAULT 0 AFTER subject");
    }
}

$archive_targets = [
    ['table' => 'applicants', 'col' => 'archived_at', 'sql' => "ALTER TABLE applicants ADD COLUMN archived_at DATETIME NULL"],
    ['table' => 'grades', 'col' => 'archived_at', 'sql' => "ALTER TABLE grades ADD COLUMN archived_at DATETIME NULL"],
    ['table' => 'evaluations', 'col' => 'archived_at', 'sql' => "ALTER TABLE evaluations ADD COLUMN archived_at DATETIME NULL"],
    ['table' => 'timetable', 'col' => 'archived_at', 'sql' => "ALTER TABLE timetable ADD COLUMN archived_at DATETIME NULL"],
];

foreach ($archive_targets as $t) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "' AND COLUMN_NAME = '" . $conn->real_escape_string($t['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($t['sql']);
        }
    }
}

$grades_extra_cols = [
    ['col' => 'grade_percent', 'sql' => "ALTER TABLE grades ADD COLUMN grade_percent DECIMAL(5,2) NULL AFTER grade"],
    ['col' => 'term', 'sql' => "ALTER TABLE grades ADD COLUMN term VARCHAR(20) NULL AFTER grade"],
    ['col' => 'school_year', 'sql' => "ALTER TABLE grades ADD COLUMN school_year VARCHAR(9) NULL AFTER term"],
    ['col' => 'remarks', 'sql' => "ALTER TABLE grades ADD COLUMN remarks TEXT NULL AFTER school_year"],
    ['col' => 'assessed_by', 'sql' => "ALTER TABLE grades ADD COLUMN assessed_by INT NULL AFTER remarks"],
];

foreach ($grades_extra_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$applicants_user_id_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'user_id'");
if ($applicants_user_id_check) {
    $has_col = (int)($applicants_user_id_check->fetch_assoc()['c'] ?? 0);
    if ($has_col === 0) {
        $conn->query('ALTER TABLE applicants ADD COLUMN user_id INT NULL AFTER id');
    }
}

$applicants_user_idx_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND INDEX_NAME = 'idx_applicants_user_id'");
if ($applicants_user_idx_check) {
    $has_idx = (int)($applicants_user_idx_check->fetch_assoc()['c'] ?? 0);
    if ($has_idx === 0) {
        $conn->query('CREATE INDEX idx_applicants_user_id ON applicants(user_id)');
    }
}

$applicants_user_fk_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND CONSTRAINT_NAME = 'fk_applicants_user'");
if ($applicants_user_fk_check) {
    $has_fk = (int)($applicants_user_fk_check->fetch_assoc()['c'] ?? 0);
    if ($has_fk === 0) {
        $conn->query('ALTER TABLE applicants ADD CONSTRAINT fk_applicants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
    }
}

$applicants_ay_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'academic_year'");
if ($applicants_ay_check) {
    $has_col = (int)($applicants_ay_check->fetch_assoc()['c'] ?? 0);
    if ($has_col === 0) {
        $conn->query("ALTER TABLE applicants ADD COLUMN academic_year VARCHAR(9) NULL AFTER parent_info");
    }
}

$applicants_extra_cols = [
    ['col' => 'program', 'sql' => "ALTER TABLE applicants ADD COLUMN program VARCHAR(80) NULL AFTER academic_year"],
    ['col' => 'created_at', 'sql' => "ALTER TABLE applicants ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"],
];
foreach ($applicants_extra_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$applicants_link_backfill = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'user_id'");
if ($applicants_link_backfill) {
    $has_col = (int)($applicants_link_backfill->fetch_assoc()['c'] ?? 0);
    if ($has_col > 0) {
        $res_unlinked = $conn->query("SELECT id, full_name FROM applicants WHERE user_id IS NULL AND status = 'Enrolled' LIMIT 50");
        if ($res_unlinked) {
            $stmt_find = $conn->prepare("SELECT id FROM users WHERE role = 'Student' AND username LIKE CONCAT(?, '%') ORDER BY CHAR_LENGTH(username) ASC LIMIT 2");
            $stmt_upd = $conn->prepare('UPDATE applicants SET user_id = ? WHERE id = ?');
            while ($a = $res_unlinked->fetch_assoc()) {
                $app_id = (int)($a['id'] ?? 0);
                $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)($a['full_name'] ?? '')));
                if ($app_id <= 0 || $base === '') {
                    continue;
                }
                $stmt_find->bind_param('s', $base);
                $stmt_find->execute();
                $match_res = $stmt_find->get_result();
                if ($match_res && $match_res->num_rows === 1) {
                    $uid = (int)($match_res->fetch_assoc()['id'] ?? 0);
                    if ($uid > 0) {
                        $stmt_upd->bind_param('ii', $uid, $app_id);
                        $stmt_upd->execute();
                    }
                }
            }
        }
    }
}

$lodging_tables = [
    [
        'table' => 'lodging_rooms',
        'sql' => "CREATE TABLE lodging_rooms (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "name VARCHAR(100) NOT NULL,\n"
            . "capacity INT NOT NULL DEFAULT 1,\n"
            . "monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'lodging_stays',
        'sql' => "CREATE TABLE lodging_stays (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "room_id INT NULL,\n"
            . "start_date DATE NOT NULL,\n"
            . "end_date DATE NULL,\n"
            . "notes TEXT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_lodging_stays_seminarian (seminarian_id),\n"
            . "KEY idx_lodging_stays_room (room_id),\n"
            . "CONSTRAINT fk_lodging_stays_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),\n"
            . "CONSTRAINT fk_lodging_stays_room FOREIGN KEY (room_id) REFERENCES lodging_rooms(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'lodging_invoices',
        'sql' => "CREATE TABLE lodging_invoices (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "invoice_no VARCHAR(30) NOT NULL,\n"
            . "issue_date DATE NOT NULL,\n"
            . "due_date DATE NULL,\n"
            . "period_start DATE NULL,\n"
            . "period_end DATE NULL,\n"
            . "status ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'issued',\n"
            . "notes TEXT NULL,\n"
            . "created_by INT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "archived_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_lodging_invoice_no (invoice_no),\n"
            . "KEY idx_lodging_invoices_seminarian (seminarian_id),\n"
            . "KEY idx_lodging_invoices_issue_date (issue_date),\n"
            . "CONSTRAINT fk_lodging_invoices_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),\n"
            . "CONSTRAINT fk_lodging_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'lodging_invoice_items',
        'sql' => "CREATE TABLE lodging_invoice_items (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "invoice_id INT NOT NULL,\n"
            . "description VARCHAR(255) NOT NULL,\n"
            . "quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,\n"
            . "unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_lodging_invoice_items_invoice (invoice_id),\n"
            . "CONSTRAINT fk_lodging_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES lodging_invoices(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'lodging_payments',
        'sql' => "CREATE TABLE lodging_payments (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "payment_date DATE NOT NULL,\n"
            . "amount DECIMAL(10,2) NOT NULL,\n"
            . "method ENUM('cash','gcash','bank','check','other') NOT NULL DEFAULT 'cash',\n"
            . "reference_no VARCHAR(80) NULL,\n"
            . "notes TEXT NULL,\n"
            . "received_by INT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_lodging_payments_seminarian (seminarian_id),\n"
            . "KEY idx_lodging_payments_payment_date (payment_date),\n"
            . "CONSTRAINT fk_lodging_payments_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),\n"
            . "CONSTRAINT fk_lodging_payments_received_by FOREIGN KEY (received_by) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'lodging_payment_allocations',
        'sql' => "CREATE TABLE lodging_payment_allocations (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "payment_id INT NOT NULL,\n"
            . "invoice_id INT NOT NULL,\n"
            . "amount DECIMAL(10,2) NOT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_lodging_payment_invoice (payment_id, invoice_id),\n"
            . "KEY idx_lodging_alloc_payment (payment_id),\n"
            . "KEY idx_lodging_alloc_invoice (invoice_id),\n"
            . "CONSTRAINT fk_lodging_alloc_payment FOREIGN KEY (payment_id) REFERENCES lodging_payments(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_lodging_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES lodging_invoices(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($lodging_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$lodging_cols = [
    ['table' => 'lodging_invoices', 'col' => 'academic_year', 'sql' => "ALTER TABLE lodging_invoices ADD COLUMN academic_year VARCHAR(9) NULL AFTER period_end"],
    ['table' => 'lodging_payments', 'col' => 'academic_year', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN academic_year VARCHAR(9) NULL AFTER payment_date"],
];
foreach ($lodging_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($c['table']) . "' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$lodging_idx = [
    ['table' => 'lodging_invoices', 'idx' => 'idx_lodging_invoices_academic_year', 'sql' => 'CREATE INDEX idx_lodging_invoices_academic_year ON lodging_invoices(academic_year)'],
    ['table' => 'lodging_payments', 'idx' => 'idx_lodging_payments_academic_year', 'sql' => 'CREATE INDEX idx_lodging_payments_academic_year ON lodging_payments(academic_year)'],
];
foreach ($lodging_idx as $i) {
    $idx_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($i['table']) . "' AND INDEX_NAME = '" . $conn->real_escape_string($i['idx']) . "'"
    );
    if ($idx_check) {
        $has_idx = (int)($idx_check->fetch_assoc()['c'] ?? 0);
        if ($has_idx === 0) {
            $conn->query($i['sql']);
        }
    }
}

$conn->query("UPDATE lodging_invoices SET academic_year = CASE WHEN MONTH(issue_date) >= 6 THEN CONCAT(YEAR(issue_date), '-', YEAR(issue_date) + 1) ELSE CONCAT(YEAR(issue_date) - 1, '-', YEAR(issue_date)) END WHERE (academic_year IS NULL OR academic_year = '') AND issue_date IS NOT NULL");
$conn->query("UPDATE lodging_payments SET academic_year = CASE WHEN MONTH(payment_date) >= 6 THEN CONCAT(YEAR(payment_date), '-', YEAR(payment_date) + 1) ELSE CONCAT(YEAR(payment_date) - 1, '-', YEAR(payment_date)) END WHERE (academic_year IS NULL OR academic_year = '') AND payment_date IS NOT NULL");

$lodging_invoice_extra_cols = [
    ['col' => 'semester', 'sql' => "ALTER TABLE lodging_invoices ADD COLUMN semester ENUM('1st','2nd') NULL AFTER academic_year"],
    ['col' => 'year_level', 'sql' => "ALTER TABLE lodging_invoices ADD COLUMN year_level VARCHAR(30) NULL AFTER semester"],
    ['col' => 'program', 'sql' => "ALTER TABLE lodging_invoices ADD COLUMN program VARCHAR(80) NULL AFTER year_level"],
    ['col' => 'invoice_type', 'sql' => "ALTER TABLE lodging_invoices ADD COLUMN invoice_type VARCHAR(40) NULL AFTER program"],
];
foreach ($lodging_invoice_extra_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_invoices' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$lodging_payments_method_col = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_payments' AND COLUMN_NAME = 'method' LIMIT 1");
if ($lodging_payments_method_col && $m = $lodging_payments_method_col->fetch_assoc()) {
    $col_type = (string)($m['COLUMN_TYPE'] ?? '');
    if (
        $col_type !== ''
        && (
            stripos($col_type, 'bank_deposit') === false
            || stripos($col_type, 'online_transfer') === false
        )
    ) {
        $ok = $conn->query("ALTER TABLE lodging_payments MODIFY COLUMN method ENUM('cash','bank_deposit','online_transfer','bank','gcash','check','other') NOT NULL DEFAULT 'cash'");
        if (!$ok) {
            $conn->query("ALTER TABLE lodging_payments MODIFY COLUMN method VARCHAR(30) NOT NULL DEFAULT 'cash'");
        }
    }
}

$lodging_payment_extra_cols = [
    ['col' => 'or_number', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN or_number VARCHAR(50) NULL AFTER id"],
    ['col' => 'payer_type', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN payer_type VARCHAR(40) NULL AFTER or_number"],
    ['col' => 'payer_name', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN payer_name VARCHAR(120) NULL AFTER payer_type"],
    ['col' => 'remarks', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN remarks TEXT NULL AFTER payer_name"],
    ['col' => 'semester', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN semester ENUM('1st','2nd') NULL AFTER academic_year"],
    ['col' => 'previous_balance', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN previous_balance DECIMAL(10,2) NULL AFTER semester"],
    ['col' => 'remaining_balance', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN remaining_balance DECIMAL(10,2) NULL AFTER previous_balance"],
    ['col' => 'overpayment', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN overpayment DECIMAL(10,2) NULL AFTER remaining_balance"],
    ['col' => 'amount_tendered', 'sql' => "ALTER TABLE lodging_payments ADD COLUMN amount_tendered DECIMAL(10,2) NULL AFTER amount"],
];
foreach ($lodging_payment_extra_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_payments' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$lodging_payment_or_idx_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_payments' AND INDEX_NAME = 'uniq_lodging_payments_or_number'");
if ($lodging_payment_or_idx_check) {
    $has_idx = (int)($lodging_payment_or_idx_check->fetch_assoc()['c'] ?? 0);
    if ($has_idx === 0) {
        $conn->query("CREATE UNIQUE INDEX uniq_lodging_payments_or_number ON lodging_payments(or_number)");
    }
}

$cpi_sort_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashier_payment_items' AND COLUMN_NAME = 'sort_order'");
if ($cpi_sort_check && (int)($cpi_sort_check->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE cashier_payment_items ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER amount");
}

$cashier_tables = [
    [
        'table' => 'cashier_fee_schedules',
        'sql' => "CREATE TABLE cashier_fee_schedules (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd') NOT NULL,\n"
            . "program VARCHAR(80) NULL,\n"
            . "year_level VARCHAR(30) NULL,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "archived_at DATETIME NULL,\n"
            . "created_by INT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_cashier_fee_sched_term (academic_year, semester),\n"
            . "KEY idx_cashier_fee_sched_scope (program, year_level),\n"
            . "CONSTRAINT fk_cashier_fee_sched_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'cashier_fee_items',
        'sql' => "CREATE TABLE cashier_fee_items (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "schedule_id INT NOT NULL,\n"
            . "description VARCHAR(255) NOT NULL,\n"
            . "amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_cashier_fee_items_schedule (schedule_id),\n"
            . "CONSTRAINT fk_cashier_fee_items_schedule FOREIGN KEY (schedule_id) REFERENCES cashier_fee_schedules(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'cashier_payment_items',
        'sql' => "CREATE TABLE cashier_payment_items (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "payment_id INT NOT NULL,\n"
            . "particular VARCHAR(255) NOT NULL,\n"
            . "amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "sort_order SMALLINT NOT NULL DEFAULT 0,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "KEY idx_cashier_payment_items_payment (payment_id),\n"
            . "KEY idx_cpi_sort (payment_id, sort_order),\n"
            . "CONSTRAINT fk_cashier_payment_items_payment FOREIGN KEY (payment_id) REFERENCES lodging_payments(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'cashier_soa_requests',
        'sql' => "CREATE TABLE cashier_soa_requests (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd') NOT NULL,\n"
            . "status ENUM('Pending','Processing','Sent','Completed') NOT NULL DEFAULT 'Pending',\n"
            . "requested_by INT NULL,\n"
            . "requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "processed_by INT NULL,\n"
            . "processed_at DATETIME NULL,\n"
            . "notes TEXT NULL,\n"
            . "KEY idx_cashier_soa_req_student (seminarian_id),\n"
            . "KEY idx_cashier_soa_req_status (status),\n"
            . "KEY idx_cashier_soa_req_term (academic_year, semester),\n"
            . "CONSTRAINT fk_cashier_soa_req_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_cashier_soa_req_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,\n"
            . "CONSTRAINT fk_cashier_soa_proc_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];
foreach ($cashier_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$soa_req_cols = [
    ['col' => 'is_read', 'sql' => "ALTER TABLE cashier_soa_requests ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status"],
];
foreach ($soa_req_cols as $c) {
    try {
        $chk = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashier_soa_requests' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'");
        if ($chk && (int)($chk->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query($c['sql']);
        }
    } catch (Throwable $e) {}
}

// Ensure is_locked column exists on student_grades
try {
    $sg_lock_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_grades' AND COLUMN_NAME = 'is_locked'");
    if ($sg_lock_check && (int)($sg_lock_check->fetch_assoc()['c'] ?? 0) === 0) {
        @$conn->query("ALTER TABLE student_grades ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks");
    }
} catch (Throwable $e) {}

// Ensure grade_edit_requests table exists
try {
    $ger_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_edit_requests'");
    if ($ger_table_check && (int)($ger_table_check->fetch_assoc()['c'] ?? 0) === 0) {
        @$conn->query("CREATE TABLE grade_edit_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester ENUM('1st','2nd','Summer') NOT NULL,
            requested_by INT NOT NULL,
            reason TEXT NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ger_status (status),
            INDEX idx_ger_term (academic_year, semester),
            INDEX idx_ger_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Throwable $e) {}

// Ensure subject_teacher_assignments table exists
try {
    $sta_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_teacher_assignments'");
    if ($sta_table_check && (int)($sta_table_check->fetch_assoc()['c'] ?? 0) === 0) {
        @$conn->query("CREATE TABLE subject_teacher_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester ENUM('1st','2nd','Summer') NOT NULL,
            teacher_user_id INT NULL,
            teacher_name_override VARCHAR(150) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sta_subject_term (subject_id, academic_year, semester),
            KEY idx_sta_teacher (teacher_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Throwable $e) {}
try {
    $conn->query("ALTER TABLE cashier_soa_requests MODIFY COLUMN status ENUM('Pending','Processing','Sent','Completed','Approved') NOT NULL DEFAULT 'Pending'");
} catch (Throwable $e) {}

$access_req_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seminarian_access_requests'");
if ($access_req_table_check && (int)($access_req_table_check->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query(
        "CREATE TABLE seminarian_access_requests (\n"
        . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "seminarian_id INT NOT NULL,\n"
        . "assignment_type VARCHAR(30) NOT NULL,\n"
        . "assigned_user_id INT NULL,\n"
        . "status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',\n"
        . "message VARCHAR(255) NULL,\n"
        . "requested_by INT NULL,\n"
        . "requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "resolved_by INT NULL,\n"
        . "resolved_at DATETIME NULL,\n"
        . "resolution_note TEXT NULL,\n"
        . "KEY idx_sar_student (seminarian_id),\n"
        . "KEY idx_sar_type_status (assignment_type, status),\n"
        . "KEY idx_sar_assignee_status (assigned_user_id, status),\n"
        . "CONSTRAINT fk_sar_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
        . "CONSTRAINT fk_sar_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,\n"
        . "CONSTRAINT fk_sar_req_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,\n"
        . "CONSTRAINT fk_sar_res_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

$sar_cols = [
    ['col' => 'request_scope', 'sql' => "ALTER TABLE seminarian_access_requests ADD COLUMN request_scope VARCHAR(20) NOT NULL DEFAULT 'whole' AFTER message"],
    ['col' => 'requested_subjects', 'sql' => "ALTER TABLE seminarian_access_requests ADD COLUMN requested_subjects TEXT NULL AFTER request_scope"],
    ['col' => 'is_read', 'sql' => "ALTER TABLE seminarian_access_requests ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status"],
];
foreach ($sar_cols as $c) {
    try {
        $chk = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seminarian_access_requests' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'");
        if ($chk && (int)($chk->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query($c['sql']);
        }
    } catch (Throwable $e) {}
}

$profiles_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles'");
if ($profiles_table_check) {
    $has_table = (int)($profiles_table_check->fetch_assoc()['c'] ?? 0);
    if ($has_table === 0) {
        $conn->query(
            "CREATE TABLE user_profiles (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "user_id INT NOT NULL,\n"
            . "full_name VARCHAR(120) NULL,\n"
            . "email VARCHAR(120) NULL,\n"
            . "contact VARCHAR(40) NULL,\n"
            . "address TEXT NULL,\n"
            . "updated_at DATETIME NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_user_profiles_user (user_id),\n"
            . "CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

$user_profile_cols = [
    ['col' => 'student_id', 'sql' => "ALTER TABLE user_profiles ADD COLUMN student_id VARCHAR(40) NULL AFTER user_id"],
    ['col' => 'first_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN first_name VARCHAR(80) NULL AFTER student_id"],
    ['col' => 'middle_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN middle_name VARCHAR(80) NULL AFTER first_name"],
    ['col' => 'last_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN last_name VARCHAR(80) NULL AFTER middle_name"],
    ['col' => 'suffix', 'sql' => "ALTER TABLE user_profiles ADD COLUMN suffix VARCHAR(20) NULL AFTER last_name"],
    ['col' => 'date_of_birth', 'sql' => "ALTER TABLE user_profiles ADD COLUMN date_of_birth DATE NULL AFTER suffix"],
    ['col' => 'place_of_birth', 'sql' => "ALTER TABLE user_profiles ADD COLUMN place_of_birth VARCHAR(150) NULL AFTER date_of_birth"],
    ['col' => 'age', 'sql' => "ALTER TABLE user_profiles ADD COLUMN age INT NULL AFTER place_of_birth"],
    ['col' => 'gender', 'sql' => "ALTER TABLE user_profiles ADD COLUMN gender VARCHAR(20) NULL AFTER age"],
    ['col' => 'civil_status', 'sql' => "ALTER TABLE user_profiles ADD COLUMN civil_status VARCHAR(30) NULL AFTER gender"],
    ['col' => 'nationality', 'sql' => "ALTER TABLE user_profiles ADD COLUMN nationality VARCHAR(60) NULL AFTER civil_status"],
    ['col' => 'religion', 'sql' => "ALTER TABLE user_profiles ADD COLUMN religion VARCHAR(80) NULL AFTER nationality"],
    ['col' => 'contact_number', 'sql' => "ALTER TABLE user_profiles ADD COLUMN contact_number VARCHAR(40) NULL AFTER religion"],
    ['col' => 'email_address', 'sql' => "ALTER TABLE user_profiles ADD COLUMN email_address VARCHAR(120) NULL AFTER contact_number"],
    ['col' => 'permanent_address', 'sql' => "ALTER TABLE user_profiles ADD COLUMN permanent_address TEXT NULL AFTER email_address"],
    ['col' => 'present_address', 'sql' => "ALTER TABLE user_profiles ADD COLUMN present_address TEXT NULL AFTER permanent_address"],
    ['col' => 'province', 'sql' => "ALTER TABLE user_profiles ADD COLUMN province VARCHAR(80) NULL AFTER present_address"],
    ['col' => 'municipality', 'sql' => "ALTER TABLE user_profiles ADD COLUMN municipality VARCHAR(80) NULL AFTER province"],
    ['col' => 'barangay', 'sql' => "ALTER TABLE user_profiles ADD COLUMN barangay VARCHAR(80) NULL AFTER municipality"],
    ['col' => 'zip_code', 'sql' => "ALTER TABLE user_profiles ADD COLUMN zip_code VARCHAR(15) NULL AFTER barangay"],
    ['col' => 'father_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN father_name VARCHAR(150) NULL AFTER zip_code"],
    ['col' => 'father_occupation', 'sql' => "ALTER TABLE user_profiles ADD COLUMN father_occupation VARCHAR(120) NULL AFTER father_name"],
    ['col' => 'mother_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN mother_name VARCHAR(150) NULL AFTER father_occupation"],
    ['col' => 'mother_occupation', 'sql' => "ALTER TABLE user_profiles ADD COLUMN mother_occupation VARCHAR(120) NULL AFTER mother_name"],
    ['col' => 'guardian_name', 'sql' => "ALTER TABLE user_profiles ADD COLUMN guardian_name VARCHAR(150) NULL AFTER mother_occupation"],
    ['col' => 'guardian_contact_number', 'sql' => "ALTER TABLE user_profiles ADD COLUMN guardian_contact_number VARCHAR(40) NULL AFTER guardian_name"],
    ['col' => 'previous_school', 'sql' => "ALTER TABLE user_profiles ADD COLUMN previous_school VARCHAR(150) NULL AFTER guardian_contact_number"],
    ['col' => 'educational_attainment', 'sql' => "ALTER TABLE user_profiles ADD COLUMN educational_attainment VARCHAR(120) NULL AFTER previous_school"],
    ['col' => 'year_graduated', 'sql' => "ALTER TABLE user_profiles ADD COLUMN year_graduated VARCHAR(10) NULL AFTER educational_attainment"],
    ['col' => 'academic_records', 'sql' => "ALTER TABLE user_profiles ADD COLUMN academic_records TEXT NULL AFTER year_graduated"],
];
foreach ($user_profile_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

$rooms_count_res = $conn->query("SELECT COUNT(*) as c FROM lodging_rooms");
if ($rooms_count_res) {
    $rooms_count = (int)($rooms_count_res->fetch_assoc()['c'] ?? 0);
    if ($rooms_count === 0) {
        $conn->query("INSERT INTO lodging_rooms (name, capacity, monthly_rate, is_active) VALUES ('Dormitory', 1, 0.00, 1)");
    }
}

$seminarians_academic_cols = [
    ['col' => 'current_academic_year', 'sql' => "ALTER TABLE seminarians ADD COLUMN current_academic_year VARCHAR(9) NULL AFTER user_id"],
    ['col' => 'year_level', 'sql' => "ALTER TABLE seminarians ADD COLUMN year_level VARCHAR(20) NULL AFTER user_id"],
    ['col' => 'formation_stage', 'sql' => "ALTER TABLE seminarians ADD COLUMN formation_stage VARCHAR(50) NULL AFTER year_level"],
    ['col' => 'academic_status', 'sql' => "ALTER TABLE seminarians ADD COLUMN academic_status ENUM('Regular','Irregular') NOT NULL DEFAULT 'Regular' AFTER formation_stage"],
    ['col' => 'enrollment_status', 'sql' => "ALTER TABLE seminarians ADD COLUMN enrollment_status VARCHAR(30) NULL AFTER academic_status"],
    ['col' => 'program', 'sql' => "ALTER TABLE seminarians ADD COLUMN program VARCHAR(80) NULL AFTER year_level"],
    ['col' => 'admission_date', 'sql' => "ALTER TABLE seminarians ADD COLUMN admission_date DATE NULL AFTER program"],
    ['col' => 'formation_status', 'sql' => "ALTER TABLE seminarians ADD COLUMN formation_status VARCHAR(30) NULL AFTER enrollment_status"],
    ['col' => 'is_graduate', 'sql' => "ALTER TABLE seminarians ADD COLUMN is_graduate TINYINT(1) NOT NULL DEFAULT 0 AFTER formation_status"],
    ['col' => 'completed_at', 'sql' => "ALTER TABLE seminarians ADD COLUMN completed_at DATE NULL AFTER is_graduate"],
    ['col' => 'academic_year_completed', 'sql' => "ALTER TABLE seminarians ADD COLUMN academic_year_completed VARCHAR(9) NULL AFTER completed_at"],
    ['col' => 'program_completed', 'sql' => "ALTER TABLE seminarians ADD COLUMN program_completed VARCHAR(80) NULL AFTER academic_year_completed"],
    ['col' => 'academic_remarks', 'sql' => "ALTER TABLE seminarians ADD COLUMN academic_remarks TEXT NULL AFTER enrollment_status"],
    ['col' => 'academic_view_enabled', 'sql' => "ALTER TABLE seminarians ADD COLUMN academic_view_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER academic_remarks"],
    ['col' => 'spiritual_eval_view_enabled', 'sql' => "ALTER TABLE seminarians ADD COLUMN spiritual_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER academic_view_enabled"],
    ['col' => 'human_eval_view_enabled', 'sql' => "ALTER TABLE seminarians ADD COLUMN human_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER spiritual_eval_view_enabled"],
    ['col' => 'pastoral_eval_view_enabled', 'sql' => "ALTER TABLE seminarians ADD COLUMN pastoral_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER human_eval_view_enabled"],
];
foreach ($seminarians_academic_cols as $c) {
    $col_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seminarians' AND COLUMN_NAME = '" . $conn->real_escape_string($c['col']) . "'"
    );
    if ($col_check) {
        $has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
        if ($has_col === 0) {
            $conn->query($c['sql']);
        }
    }
}

try {
    $has_stu = $conn->query("SHOW TABLES LIKE 'students'");
    if ($has_stu && $has_stu->num_rows > 0) {
        $has_ea = $conn->query("SHOW COLUMNS FROM students LIKE 'email_address'");
        if ($has_ea && $has_ea->num_rows === 0) {
            $conn->query("ALTER TABLE students ADD COLUMN email_address VARCHAR(150) NULL AFTER email");
            $conn->query("UPDATE students SET email_address = email WHERE (email_address IS NULL OR email_address = '') AND email IS NOT NULL AND email != ''");
        }
    }
} catch (Throwable $e) {}

$assignments_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seminarian_assignments'");
if ($assignments_table_check && (int)($assignments_table_check->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query(
        "CREATE TABLE seminarian_assignments (\n"
        . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "seminarian_id INT NOT NULL,\n"
        . "assignment_type VARCHAR(30) NOT NULL,\n"
        . "assigned_user_id INT NULL,\n"
        . "assigned_by INT NULL,\n"
        . "assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "active TINYINT(1) NOT NULL DEFAULT 1,\n"
        . "UNIQUE KEY uniq_assignment (seminarian_id, assignment_type),\n"
        . "KEY idx_assignment_user (assigned_user_id),\n"
        . "KEY idx_assignment_type (assignment_type, active),\n"
        . "CONSTRAINT fk_sa_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
        . "CONSTRAINT fk_sa_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,\n"
        . "CONSTRAINT fk_sa_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

$assignments_table_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seminarian_assignments'");
if ($assignments_table_check && (int)($assignments_table_check->fetch_assoc()['c'] ?? 0) > 0) {
    $uid_academic = get_default_assignee_user_id($conn, 'Academic');
    $uid_human = get_default_assignee_user_id($conn, 'Human');
    $uid_spiritual = get_default_assignee_user_id($conn, 'Spiritual');
    $uid_pastoral = get_default_assignee_user_id($conn, 'Pastoral');
    $uid_cashier = get_default_assignee_user_id($conn, 'Cashier');

    $conn->query("INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
        SELECT s.id, 'Academic', NULLIF(" . (int)$uid_academic . ", 0), NULL, 1
        FROM seminarians s
        LEFT JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Academic'
        WHERE sa.id IS NULL");

    $conn->query("INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
        SELECT s.id, 'Human', NULLIF(" . (int)$uid_human . ", 0), NULL, 1
        FROM seminarians s
        LEFT JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Human'
        WHERE sa.id IS NULL");

    $conn->query("INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
        SELECT s.id, 'Spiritual', NULLIF(" . (int)$uid_spiritual . ", 0), NULL, 1
        FROM seminarians s
        LEFT JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Spiritual'
        WHERE sa.id IS NULL");

    $conn->query("INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
        SELECT s.id, 'Pastoral', NULLIF(" . (int)$uid_pastoral . ", 0), NULL, 1
        FROM seminarians s
        LEFT JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Pastoral'
        WHERE sa.id IS NULL");

    $conn->query("INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
        SELECT s.id, 'Cashier', NULLIF(" . (int)$uid_cashier . ", 0), NULL, 1
        FROM seminarians s
        LEFT JOIN seminarian_assignments sa ON sa.seminarian_id = s.id AND sa.assignment_type = 'Cashier'
        WHERE sa.id IS NULL");

    // Ensure no legacy inactive assignment rows hide students now that assignments.php is decommissioned
    $conn->query("UPDATE seminarian_assignments SET active = 1 WHERE active = 0 AND assigned_user_id IS NOT NULL");
}

$academic_tables = [
    [
        'table' => 'subjects',
        'sql' => "CREATE TABLE subjects (\n"
            . "subject_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "subject_code VARCHAR(30) NOT NULL,\n"
            . "subject_name VARCHAR(150) NOT NULL,\n"
            . "units INT NOT NULL DEFAULT 0,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_subject_code (subject_code)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'semester_subjects',
        'sql' => "CREATE TABLE semester_subjects (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "subject_id INT NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "year_level VARCHAR(20) NULL,\n"
            . "formation_stage VARCHAR(50) NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_semester_subject (subject_id, semester, academic_year, year_level),\n"
            . "KEY idx_semester_subjects_year_level (year_level),\n"
            . "KEY idx_semester_subjects_ay_sem (academic_year, semester),\n"
            . "CONSTRAINT fk_semester_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'student_grades',
        'sql' => "CREATE TABLE student_grades (\n"
            . "grade_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "subject_id INT NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "prelim_grade DECIMAL(5,2) NULL,\n"
            . "midterm_grade DECIMAL(5,2) NULL,\n"
            . "final_grade DECIMAL(5,2) NULL,\n"
            . "final_computed_grade DECIMAL(4,2) NULL,\n"
            . "status ENUM('Completed','Incomplete','Dropped') NOT NULL DEFAULT 'Completed',\n"
            . "remarks TEXT NULL,\n"
            . "assessed_by INT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "KEY idx_student_grades_student (student_id),\n"
            . "KEY idx_student_grades_ay_sem (academic_year, semester),\n"
            . "UNIQUE KEY uniq_student_subject_term (student_id, subject_id, semester, academic_year),\n"
            . "CONSTRAINT fk_student_grades_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_student_grades_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),\n"
            . "CONSTRAINT fk_student_grades_assessor FOREIGN KEY (assessed_by) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'gpa_records',
        'sql' => "CREATE TABLE gpa_records (\n"
            . "gpa_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester_gpa DECIMAL(4,2) NULL,\n"
            . "cumulative_gpa DECIMAL(4,2) NULL,\n"
            . "performance_category VARCHAR(30) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_gpa_record (student_id, semester, academic_year),\n"
            . "KEY idx_gpa_records_student (student_id),\n"
            . "CONSTRAINT fk_gpa_records_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'academic_progress',
        'sql' => "CREATE TABLE academic_progress (\n"
            . "progress_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "previous_gpa DECIMAL(4,2) NULL,\n"
            . "current_gpa DECIMAL(4,2) NULL,\n"
            . "growth_status ENUM('Growing','Maintaining','Declining') NULL,\n"
            . "growth_percentage DECIMAL(6,2) NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_progress_record (student_id, semester, academic_year),\n"
            . "KEY idx_progress_student (student_id),\n"
            . "CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'irregular_students',
        'sql' => "CREATE TABLE irregular_students (\n"
            . "irregular_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NULL,\n"
            . "academic_year VARCHAR(9) NULL,\n"
            . "reason TEXT NOT NULL,\n"
            . "date_flagged DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "status ENUM('Active','Resolved') NOT NULL DEFAULT 'Active',\n"
            . "UNIQUE KEY uniq_irregular_term (student_id, semester, academic_year),\n"
            . "KEY idx_irregular_student (student_id),\n"
            . "KEY idx_irregular_status (status),\n"
            . "CONSTRAINT fk_irregular_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'academic_notifications',
        'sql' => "CREATE TABLE academic_notifications (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "recipient_user_id INT NOT NULL,\n"
            . "student_id INT NULL,\n"
            . "type VARCHAR(40) NOT NULL,\n"
            . "message TEXT NOT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "read_at DATETIME NULL,\n"
            . "KEY idx_acad_notif_recipient (recipient_user_id, read_at),\n"
            . "KEY idx_acad_notif_student (student_id),\n"
            . "CONSTRAINT fk_acad_notif_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_acad_notif_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE SET NULL\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($academic_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$prelim_col_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_grades' AND COLUMN_NAME = 'prelim_grade'");
if ($prelim_col_check && (int)($prelim_col_check->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE student_grades ADD COLUMN prelim_grade DECIMAL(5,2) NULL AFTER academic_year");
}

$subjects_archived_col_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'archived_at'");
if ($subjects_archived_col_check) {
    $has_col = (int)($subjects_archived_col_check->fetch_assoc()['c'] ?? 0);
    if ($has_col === 0) {
        $conn->query("ALTER TABLE subjects ADD COLUMN archived_at DATETIME NULL");
        $conn->query("ALTER TABLE subjects ADD KEY idx_subjects_archived_at (archived_at)");
    }
}

$curriculum_tables = [
    [
        'table' => 'curriculum_versions',
        'sql' => "CREATE TABLE curriculum_versions (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "program VARCHAR(120) NOT NULL,\n"
            . "version_name VARCHAR(120) NOT NULL,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "is_current TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "archived_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_curriculum_version (program, version_name),\n"
            . "KEY idx_curriculum_versions_program (program, is_current, archived_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'curriculum_subjects',
        'sql' => "CREATE TABLE curriculum_subjects (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "curriculum_version_id INT NOT NULL,\n"
            . "subject_id INT NOT NULL,\n"
            . "year_level VARCHAR(40) NOT NULL,\n"
            . "semester ENUM('1st','2nd') NOT NULL,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_curriculum_subject (curriculum_version_id, subject_id, year_level, semester),\n"
            . "KEY idx_curriculum_subjects_term (year_level, semester),\n"
            . "CONSTRAINT fk_curriculum_subjects_version FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_curriculum_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'student_enrolled_subjects',
        'sql' => "CREATE TABLE student_enrolled_subjects (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "subject_id INT NOT NULL,\n"
            . "curriculum_version_id INT NULL,\n"
            . "program VARCHAR(120) NULL,\n"
            . "year_level VARCHAR(40) NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "status ENUM('Enrolled','Dropped','Completed') NOT NULL DEFAULT 'Enrolled',\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_student_enrolled (student_id, subject_id, semester, academic_year),\n"
            . "KEY idx_student_enrolled_student (student_id),\n"
            . "KEY idx_student_enrolled_term (academic_year, semester),\n"
            . "CONSTRAINT fk_student_enrolled_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_student_enrolled_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_student_enrolled_curriculum FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions(id) ON DELETE SET NULL\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($curriculum_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$cv_seed_check = $conn->query("SELECT COUNT(*) as c FROM curriculum_versions");
if ($cv_seed_check) {
    $cv_count = (int)($cv_seed_check->fetch_assoc()['c'] ?? 0);
    if ($cv_count === 0) {
        $stmt_cv = $conn->prepare("INSERT INTO curriculum_versions (program, version_name, is_active, is_current) VALUES (?, ?, 1, 1)");
        $p1 = 'Bachelor of Arts in Philosophy';
        $v1 = 'AB Philosophy v1';
        $stmt_cv->bind_param('ss', $p1, $v1);
        $stmt_cv->execute();

        $p2 = 'Propaedeutic';
        $v2 = 'Propaedeutic v1';
        $stmt_cv->bind_param('ss', $p2, $v2);
        $stmt_cv->execute();
    } else {
        $pc_check = $conn->query("SELECT id FROM curriculum_versions WHERE program = 'Propaedeutic' AND version_name = 'Propaedeutic v1' LIMIT 1");
        if ($pc_check && $pc_check->num_rows === 0) {
            $stmt_pc = $conn->prepare("INSERT INTO curriculum_versions (program, version_name, is_active, is_current) VALUES ('Propaedeutic', 'Propaedeutic v1', 1, 0)");
            $stmt_pc->execute();
        }

        $ba_program = 'Bachelor of Arts in Philosophy';
        $ba_target = 'AB Philosophy v1';
        $ba_aliases = "'BA Philosophy v1','AB Philosophy 1','AB Philisophy','AB Philisophy v1'";

        $canon_id = 0;
        $canon_res = $conn->query(
            "SELECT id FROM curriculum_versions\n"
            . "WHERE program = '" . $conn->real_escape_string($ba_program) . "'\n"
            . "  AND archived_at IS NULL\n"
            . "  AND is_current = 1\n"
            . "  AND (version_name = '" . $conn->real_escape_string($ba_target) . "' OR version_name IN (" . $ba_aliases . "))\n"
            . "ORDER BY id DESC\n"
            . "LIMIT 1"
        );
        if ($canon_res && ($r = $canon_res->fetch_assoc())) {
            $canon_id = (int)($r['id'] ?? 0);
        }
        if ($canon_id <= 0) {
            $canon_res = $conn->query(
                "SELECT id FROM curriculum_versions\n"
                . "WHERE program = '" . $conn->real_escape_string($ba_program) . "'\n"
                . "  AND archived_at IS NULL\n"
                . "  AND (version_name = '" . $conn->real_escape_string($ba_target) . "' OR version_name IN (" . $ba_aliases . "))\n"
                . "ORDER BY id DESC\n"
                . "LIMIT 1"
            );
            if ($canon_res && ($r = $canon_res->fetch_assoc())) {
                $canon_id = (int)($r['id'] ?? 0);
            }
        }
        if ($canon_id <= 0) {
            $canon_res = $conn->query(
                "SELECT id FROM curriculum_versions\n"
                . "WHERE program = '" . $conn->real_escape_string($ba_program) . "'\n"
                . "  AND (version_name = '" . $conn->real_escape_string($ba_target) . "' OR version_name IN (" . $ba_aliases . "))\n"
                . "ORDER BY id DESC\n"
                . "LIMIT 1"
            );
            if ($canon_res && ($r = $canon_res->fetch_assoc())) {
                $canon_id = (int)($r['id'] ?? 0);
            }
        }

        if ($canon_id > 0) {
            $conn->query(
                "UPDATE curriculum_versions\n"
                . "SET version_name = CONCAT('" . $conn->real_escape_string($ba_target) . " (Legacy ', id, ')'),\n"
                . "    is_active = 0,\n"
                . "    is_current = 0,\n"
                . "    archived_at = COALESCE(archived_at, NOW())\n"
                . "WHERE program = '" . $conn->real_escape_string($ba_program) . "'\n"
                . "  AND id <> " . (int)$canon_id . "\n"
                . "  AND (version_name = '" . $conn->real_escape_string($ba_target) . "' OR version_name IN (" . $ba_aliases . "))"
            );
            $conn->query(
                "UPDATE curriculum_versions\n"
                . "SET version_name = '" . $conn->real_escape_string($ba_target) . "'\n"
                . "WHERE id = " . (int)$canon_id
            );
        }

        $cv_ba_cur = $conn->query("SELECT COUNT(*) as c FROM curriculum_versions WHERE program = 'Bachelor of Arts in Philosophy' AND is_current = 1 AND archived_at IS NULL");
        if ($cv_ba_cur && (int)($cv_ba_cur->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query("UPDATE curriculum_versions SET is_current = 1, is_active = 1 WHERE program = 'Bachelor of Arts in Philosophy' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
        }
        $cv_pc_cur = $conn->query("SELECT COUNT(*) as c FROM curriculum_versions WHERE program = 'Propaedeutic' AND is_current = 1 AND archived_at IS NULL");
        if ($cv_pc_cur && (int)($cv_pc_cur->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query("UPDATE curriculum_versions SET is_current = 1, is_active = 1 WHERE program = 'Propaedeutic' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
        }
    }

    // Auto-migrate legacy terminology across database tables:
    @$conn->query("ALTER TABLE seminarians MODIFY year_level VARCHAR(50) NULL");
    @$conn->query("ALTER TABLE semester_subjects MODIFY year_level VARCHAR(50) NULL");
    $conn->query("UPDATE seminarians SET year_level = 'Propaedeutic Stage' WHERE year_level LIKE 'Preparatory Seminari%' OR year_level IN ('Pre-College', 'pre-college', 'preparatory seminarian')");
    $conn->query("UPDATE seminarians SET program = 'Propaedeutic' WHERE program IN ('Pre-College', 'pre-college', 'pre college', 'Preparatory Seminarian')");
    $conn->query("UPDATE applicants SET program = 'Propaedeutic' WHERE program IN ('Pre-College', 'pre-college', 'pre college')");
    $conn->query("UPDATE curriculum_versions SET version_name = CONCAT('Propaedeutic Legacy ', id), is_active = 0, is_current = 0, archived_at = COALESCE(archived_at, NOW()) WHERE version_name LIKE 'Pre-College%'");
    $conn->query("UPDATE curriculum_versions SET program = 'Propaedeutic' WHERE program IN ('Pre-College', 'pre-college', 'pre college')");
    $conn->query("UPDATE curriculum_subjects SET year_level = 'Propaedeutic Stage' WHERE year_level LIKE 'Preparatory Seminari%'");
    $conn->query("UPDATE semester_subjects SET year_level = 'Propaedeutic Stage' WHERE year_level LIKE 'Preparatory Seminari%'");
    $conn->query("UPDATE cashier_fee_schedules SET program = 'Propaedeutic' WHERE program IN ('Pre-College', 'pre-college', 'pre college')");
    $conn->query("UPDATE cashier_fee_schedules SET year_level = 'Propaedeutic Stage' WHERE year_level LIKE 'Preparatory Seminari%'");

    // Ensure parish columns exist in user_profiles
    $chk_pp = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'parish_priest'");
    if ($chk_pp && $chk_pp->num_rows === 0) {
        $conn->query("ALTER TABLE user_profiles ADD COLUMN parish_priest VARCHAR(150) NULL AFTER religion");
    }
    $chk_pc = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'parish_chaplaincy'");
    if ($chk_pc && $chk_pc->num_rows === 0) {
        $conn->query("ALTER TABLE user_profiles ADD COLUMN parish_chaplaincy VARCHAR(150) NULL AFTER parish_priest");
    }
}

$cv_current_res = $conn->query("SELECT id FROM curriculum_versions WHERE program = 'Bachelor of Arts in Philosophy' AND is_current = 1 AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$cv_current = $cv_current_res ? $cv_current_res->fetch_assoc() : null;
$cv_id = (int)($cv_current['id'] ?? 0);
if ($cv_id > 0) {
    $curriculum_subjects_seed = [
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'Theo 1', 'name' => 'Revelation & Faith', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'Philo 1', 'name' => 'Logic', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'Philo 2', 'name' => 'Introduction to Philosophy', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'Psy 3', 'name' => 'Developmental Psychology', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'GE 1', 'name' => 'Understanding the Self', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'GE 2', 'name' => 'Readings in Philippine History', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'PATHFit 1', 'name' => 'Physical Activities Toward Health and Fitness 1', 'units' => 2],
        ['year_level' => 'First Year', 'semester' => '1st', 'code' => 'NSTP 1', 'name' => 'ROTC/CWTS 1', 'units' => 3],

        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'Theo 2', 'name' => 'Church History', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'Philo 3', 'name' => 'History of Western Philosophy I', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'Philo 5', 'name' => 'History of Chinese Philosophy', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'GE 4', 'name' => 'Mathematics in the Modern World', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'GE 6', 'name' => 'Art Appreciation', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'GE 7', 'name' => 'Science, Technology and Society', 'units' => 3],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'PATHFit 2', 'name' => 'Physical Activities Toward Health and Fitness 2', 'units' => 2],
        ['year_level' => 'First Year', 'semester' => '2nd', 'code' => 'NSTP 2', 'name' => 'ROTC/CWTS 2', 'units' => 3],

        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'Theo 3', 'name' => 'Sacraments and Liturgy', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'Philo 4', 'name' => 'History of Western Philosophy II', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'Philo 6', 'name' => 'History of Indian Philosophy', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'Philo 12', 'name' => 'Theodicy / Philosophy of Religion', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'ForLan 1', 'name' => 'Foreign Language 1', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'GE 5', 'name' => 'Purposive Communication', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'GE 9', 'name' => 'Life and Works of Rizal', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '1st', 'code' => 'PATHFit 3', 'name' => 'Physical Activities Toward Health and Fitness 3', 'units' => 2],

        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'Theo 4', 'name' => 'Christology', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'Philo 7', 'name' => 'Existentialism / Phenomenology / Hermeneutics / Postmodernism', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'Philo 8', 'name' => 'Cosmology / Philosophy of Science and Technology', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'Philo 9', 'name' => 'Advanced Philosophy of Man / Philosophical Anthropology', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'Psy 5', 'name' => 'Theories of Personality', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'GE 3', 'name' => 'The Contemporary World', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'ForLan 2', 'name' => 'Foreign Language 2', 'units' => 3],
        ['year_level' => 'Second Year', 'semester' => '2nd', 'code' => 'PATHFit 4', 'name' => 'Physical Activities Toward Health and Fitness 4', 'units' => 2],

        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'Theo 5', 'name' => 'Moral Theology', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'Philo 10', 'name' => 'Epistemology / Theory of Knowledge', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'Philo 18', 'name' => 'Special Questions in Philosophy', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'Philo 19', 'name' => 'Seminar of Contemporary Philosophy', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'Philo 23', 'name' => 'Research in Philosophy I', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'GE 8', 'name' => 'Ethics', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '1st', 'code' => 'GE 10', 'name' => 'Philippine Indigenous Communities', 'units' => 3],

        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'Theo 6', 'name' => 'Social Teachings of the Church and Community Involvement', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'Philo 11', 'name' => 'Metaphysics', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'Philo 16', 'name' => 'Modern Asian Thoughts', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'Philo 24', 'name' => 'Research in Philosophy II', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'Philo 20', 'name' => 'Special Questions in Ethics', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'GE 11', 'name' => 'Gender and Society', 'units' => 3],
        ['year_level' => 'Third Year', 'semester' => '2nd', 'code' => 'GE 12', 'name' => "People and Earth's Ecology", 'units' => 3],

        ['year_level' => 'Fourth Year', 'semester' => '1st', 'code' => 'Philo 13', 'name' => 'Political Philosophy / Social Philosophy', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '1st', 'code' => 'Philo 14', 'name' => 'Philosophy of Language', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '1st', 'code' => 'Philo 15', 'name' => 'Aesthetics / Theories of Art', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '1st', 'code' => 'Psy 16', 'name' => 'Introduction to Counseling', 'units' => 3],

        ['year_level' => 'Fourth Year', 'semester' => '2nd', 'code' => 'Philo 17', 'name' => 'Comparative Philosophy', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '2nd', 'code' => 'Philo 21', 'name' => 'Seminar on Filipino Philosophy', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '2nd', 'code' => 'Philo 22', 'name' => 'Seminar on Plato and Aristotle', 'units' => 3],
        ['year_level' => 'Fourth Year', 'semester' => '2nd', 'code' => 'Psy 13', 'name' => 'Culture and Psychology / Sikolohiyang Pilipino', 'units' => 3],
    ];

    foreach ($curriculum_subjects_seed as $i => $s) {
        $code = (string)$s['code'];
        $name = (string)$s['name'];
        $units = (float)$s['units'];
        $year_level = (string)$s['year_level'];
        $semester = (string)$s['semester'];

        $stmt_sub = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, units) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), units = VALUES(units)");
        $stmt_sub->bind_param('ssd', $code, $name, $units);
        $stmt_sub->execute();

        $stmt_sid = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_code = ? LIMIT 1");
        $stmt_sid->bind_param('s', $code);
        $stmt_sid->execute();
        $sid_row = $stmt_sid->get_result()->fetch_assoc();
        $subject_id = (int)($sid_row['subject_id'] ?? 0);
        if ($subject_id > 0) {
            $sort_order = $i + 1;
            $stmt_cs = $conn->prepare("INSERT IGNORE INTO curriculum_subjects (curriculum_version_id, subject_id, year_level, semester, is_active, sort_order) VALUES (?, ?, ?, ?, 1, ?)");
            $stmt_cs->bind_param('iissi', $cv_id, $subject_id, $year_level, $semester, $sort_order);
            $stmt_cs->execute();
        }
    }
}

$cv_propaedeutic_res = $conn->query("SELECT id FROM curriculum_versions WHERE program = 'Propaedeutic' AND is_current = 1 AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$cv_propaedeutic = $cv_propaedeutic_res ? $cv_propaedeutic_res->fetch_assoc() : null;
$cv_prop_id = (int)($cv_propaedeutic['id'] ?? 0);
if ($cv_prop_id > 0) {
    $propaedeutic_subjects_seed = [
        // 1st Semester (15 subjects × 1.5 = 22.5 units)
        ['semester' => '1st', 'code' => 'LAT 101', 'name' => 'Latin 1a', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'WRIT 101', 'name' => 'Introduction to Writing 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'SPCH 101', 'name' => 'Speech 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'CL 101', 'name' => 'Christian Living', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'CH 101', 'name' => 'Church History', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'PRAY 101', 'name' => 'Prayer Forms', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'MUS 101', 'name' => 'Music', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'SPIR 101', 'name' => 'Spirituality', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'ENG 101', 'name' => 'English Grammar 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'LIT 101', 'name' => 'Introduction to Liturgy 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'READ 101', 'name' => 'Spiritual Reading', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'SCRIP 101', 'name' => 'Introduction to Scripture 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'VOC 101', 'name' => 'Introduction to Vocation', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'LITR 101', 'name' => 'English Literature 1', 'units' => 1.5],
        ['semester' => '1st', 'code' => 'SKILL 101', 'name' => 'Skills Development 1', 'units' => 1.5],
        // 2nd Semester (15 subjects × 1.5 = 22.5 units)
        ['semester' => '2nd', 'code' => 'LAT 102', 'name' => 'Latin 1b', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'WRIT 102', 'name' => 'Introduction to Writing 2', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'SPCH 102', 'name' => 'Speech 2', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'CCC 102', 'name' => 'Catechism of the Catholic Church', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'CHRI 102', 'name' => 'Introduction to Christology', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'ECCL 102', 'name' => 'Introduction to Ecclesiology', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'SACR 102', 'name' => 'Introduction to the Sacraments', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'SCRIP 102', 'name' => 'Introduction to Scripture 2', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'LIT 102', 'name' => 'Introduction to Liturgy 2', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'HAF 102', 'name' => 'Human and Affective Formation', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'VOC 102', 'name' => 'Discernment of Vocation', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'PRAY 102', 'name' => 'Lectio Divina and Biblical Prayer', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'PAST 102', 'name' => 'Pastoral Exposure and Apostolic Service', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'LANG 102', 'name' => 'Filipino or Local Language for Pastoral Ministry', 'units' => 1.5],
        ['semester' => '2nd', 'code' => 'SKILL 102', 'name' => 'Skills Development 2', 'units' => 1.5],
    ];

    foreach ($propaedeutic_subjects_seed as $idx => $s) {
        $p_sem = (string)$s['semester'];
        $p_code = (string)$s['code'];
        $p_name = (string)$s['name'];
        $p_units = (float)$s['units'];
        $p_sort = $idx + 1;

        $stmt_psub = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, units) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), units = VALUES(units)");
        $stmt_psub->bind_param('ssd', $p_code, $p_name, $p_units);
        $stmt_psub->execute();

        $stmt_psid = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_code = ? LIMIT 1");
        $stmt_psid->bind_param('s', $p_code);
        $stmt_psid->execute();
        $psid_row = $stmt_psid->get_result()->fetch_assoc();
        $p_subject_id = (int)($psid_row['subject_id'] ?? 0);

        if ($p_subject_id > 0) {
            $stmt_pcs = $conn->prepare("INSERT IGNORE INTO curriculum_subjects (curriculum_version_id, subject_id, year_level, semester, is_active, sort_order) VALUES (?, ?, 'Propaedeutic Stage', ?, 1, ?)");
            $stmt_pcs->bind_param('iisi', $cv_prop_id, $p_subject_id, $p_sem, $p_sort);
            $stmt_pcs->execute();
        }
    }
}

// Prerequisite Support for Subjects and Curriculum Subjects
$sub_prereq_col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'prerequisite_subject_id'");
if ($sub_prereq_col && (int)($sub_prereq_col->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE subjects ADD COLUMN prerequisite_subject_id INT NULL DEFAULT NULL AFTER units");
    $conn->query("ALTER TABLE subjects ADD CONSTRAINT fk_subjects_prereq FOREIGN KEY (prerequisite_subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL");
}

$cs_prereq_col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum_subjects' AND COLUMN_NAME = 'prerequisite_subject_id'");
if ($cs_prereq_col && (int)($cs_prereq_col->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE curriculum_subjects ADD COLUMN prerequisite_subject_id INT NULL DEFAULT NULL AFTER subject_id");
    $conn->query("ALTER TABLE curriculum_subjects ADD CONSTRAINT fk_curr_sub_prereq FOREIGN KEY (prerequisite_subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL");
}

// Seed standard prerequisites for academic progression
$prereq_mappings = [
    'Theo 2' => 'Theo 1',
    'Theo 3' => 'Theo 2',
    'Theo 4' => 'Theo 3',
    'Theo 5' => 'Theo 4',
    'Theo 6' => 'Theo 5',
    'PATHFit 2' => 'PATHFit 1',
    'PATHFit 3' => 'PATHFit 2',
    'PATHFit 4' => 'PATHFit 3',
    'NSTP 2' => 'NSTP 1',
    'ForLan 2' => 'ForLan 1',
    'Philo 3' => 'Philo 2',
    'Philo 4' => 'Philo 3',
    'Philo 7' => 'Philo 4',
    'Philo 11' => 'Philo 10',
    'Philo 24' => 'Philo 23',
    'Psy 5' => 'Psy 3',
];

$stmt_set_prereq = $conn->prepare("UPDATE subjects s JOIN subjects p ON p.subject_code = ? SET s.prerequisite_subject_id = p.subject_id WHERE s.subject_code = ? AND (s.prerequisite_subject_id IS NULL OR s.prerequisite_subject_id = 0)");
if ($stmt_set_prereq) {
    foreach ($prereq_mappings as $target_code => $prereq_code) {
        $stmt_set_prereq->bind_param('ss', $prereq_code, $target_code);
        $stmt_set_prereq->execute();
    }
}

// Sync curriculum_subjects prerequisite_subject_id with subjects
$conn->query("UPDATE curriculum_subjects cs JOIN subjects s ON s.subject_id = cs.subject_id SET cs.prerequisite_subject_id = s.prerequisite_subject_id WHERE s.prerequisite_subject_id IS NOT NULL AND cs.prerequisite_subject_id IS NULL");

// Ensure 2025-2026 exists in academic_years
$conn->query("INSERT IGNORE INTO academic_years (label, start_year, end_year, is_active, is_current) VALUES ('2025-2026', 2025, 2026, 1, 0)");

// Add academic_year_id to curriculum_versions
$cv_ay_col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum_versions' AND COLUMN_NAME = 'academic_year_id'");
if ($cv_ay_col && (int)($cv_ay_col->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE curriculum_versions ADD COLUMN academic_year_id INT NULL DEFAULT NULL AFTER program");
    $conn->query("ALTER TABLE curriculum_versions ADD CONSTRAINT fk_curr_ver_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL");
    $conn->query("ALTER TABLE curriculum_versions ADD KEY idx_curriculum_versions_ay (academic_year_id)");
}

// Add academic_year_id to curriculum_subjects
$cs_ay_col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum_subjects' AND COLUMN_NAME = 'academic_year_id'");
if ($cs_ay_col && (int)($cs_ay_col->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE curriculum_subjects ADD COLUMN academic_year_id INT NULL DEFAULT NULL AFTER curriculum_version_id");
    $conn->query("ALTER TABLE curriculum_subjects ADD CONSTRAINT fk_curr_sub_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL");
    $conn->query("ALTER TABLE curriculum_subjects ADD KEY idx_curriculum_subjects_ay (academic_year_id)");
}

// Ensure unique index supports multiple academic years per program
$old_idx = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum_versions' AND INDEX_NAME = 'uniq_curriculum_version'");
if ($old_idx && (int)($old_idx->fetch_assoc()['c'] ?? 0) > 0) {
    $conn->query("ALTER TABLE curriculum_versions DROP INDEX uniq_curriculum_version");
}
$new_idx = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum_versions' AND INDEX_NAME = 'uniq_curriculum_version_ay'");
if ($new_idx && (int)($new_idx->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("ALTER TABLE curriculum_versions ADD UNIQUE KEY uniq_curriculum_version_ay (program, academic_year_id, version_name)");
}

// Backfill academic_year_id to active academic year
$def_ay_res = $conn->query("SELECT id FROM academic_years WHERE is_current = 1 AND archived_at IS NULL LIMIT 1");
if ($def_ay_res && $row_ay = $def_ay_res->fetch_assoc()) {
    $def_ay_id = (int)$row_ay['id'];
    if ($def_ay_id > 0) {
        $conn->query("DELETE FROM curriculum_versions WHERE academic_year_id IS NULL AND id NOT IN (SELECT DISTINCT curriculum_version_id FROM curriculum_subjects) AND version_name IN (SELECT version_name FROM (SELECT version_name FROM curriculum_versions WHERE academic_year_id = {$def_ay_id}) as t)");
        $conn->query("UPDATE IGNORE curriculum_versions SET academic_year_id = {$def_ay_id} WHERE academic_year_id IS NULL");
        $conn->query("UPDATE curriculum_subjects cs JOIN curriculum_versions cv ON cv.id = cs.curriculum_version_id SET cs.academic_year_id = cv.academic_year_id WHERE cs.academic_year_id IS NULL AND cv.academic_year_id IS NOT NULL");
        $conn->query("UPDATE curriculum_subjects SET academic_year_id = {$def_ay_id} WHERE academic_year_id IS NULL");
    }
}


$irregular_uniq_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'irregular_students' AND INDEX_NAME = 'uniq_irregular_term'");
if ($irregular_uniq_check) {
    $has_idx = (int)($irregular_uniq_check->fetch_assoc()['c'] ?? 0);
    if ($has_idx === 0) {
        $conn->query("CREATE UNIQUE INDEX uniq_irregular_term ON irregular_students(student_id, semester, academic_year)");
    }
}

$evaluation_tables = [
    [
        'table' => 'academic_evaluations',
        'sql' => "CREATE TABLE academic_evaluations (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "student_id INT NOT NULL,\n"
            . "formator_id INT NOT NULL,\n"
            . "gpa DECIMAL(4,2) NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "evaluation_date DATE NOT NULL,\n"
            . "remarks TEXT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "archived_at DATETIME NULL,\n"
            . "KEY idx_ae_student (student_id),\n"
            . "KEY idx_ae_term (academic_year, semester),\n"
            . "KEY idx_ae_formator (formator_id),\n"
            . "CONSTRAINT fk_ae_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_ae_formator FOREIGN KEY (formator_id) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'academic_evaluation_questions',
        'sql' => "CREATE TABLE academic_evaluation_questions (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "category VARCHAR(60) NOT NULL,\n"
            . "question_text VARCHAR(255) NOT NULL,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "KEY idx_aeq_active (is_active),\n"
            . "KEY idx_aeq_sort (sort_order)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'academic_evaluation_responses',
        'sql' => "CREATE TABLE academic_evaluation_responses (\n"
            . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "question_id INT NOT NULL,\n"
            . "rating TINYINT NOT NULL,\n"
            . "UNIQUE KEY uniq_aer (evaluation_id, question_id),\n"
            . "KEY idx_aer_eval (evaluation_id),\n"
            . "KEY idx_aer_q (question_id),\n"
            . "CONSTRAINT fk_aer_eval FOREIGN KEY (evaluation_id) REFERENCES academic_evaluations(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_aer_q FOREIGN KEY (question_id) REFERENCES academic_evaluation_questions(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'academic_evaluation_results',
        'sql' => "CREATE TABLE academic_evaluation_results (\n"
            . "evaluation_id INT PRIMARY KEY,\n"
            . "total_score INT NOT NULL,\n"
            . "average_score DECIMAL(4,2) NOT NULL,\n"
            . "rating_label VARCHAR(30) NOT NULL,\n"
            . "recommendation VARCHAR(80) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "CONSTRAINT fk_aeres_eval FOREIGN KEY (evaluation_id) REFERENCES academic_evaluations(id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($evaluation_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$q_count_res = $conn->query("SELECT COUNT(*) as c FROM academic_evaluation_questions");
if ($q_count_res && (int)($q_count_res->fetch_assoc()['c'] ?? 0) === 0) {
    $questions = [
        ['Study Habits', 'Maintains a consistent daily study schedule.', 1],
        ['Study Habits', 'Uses effective note-taking strategies during lectures.', 2],
        ['Study Habits', 'Reviews lessons regularly even without upcoming exams.', 3],
        ['Study Habits', 'Studies in a manner that promotes deep understanding, not memorization.', 4],
        ['Study Habits', 'Sets clear academic goals for each semester.', 5],
        ['Time Management', 'Plans weekly tasks and study time ahead of schedule.', 6],
        ['Time Management', 'Prioritizes academic responsibilities over distractions.', 7],
        ['Time Management', 'Balances formation activities and academic workload effectively.', 8],
        ['Time Management', 'Avoids procrastination in completing academic tasks.', 9],
        ['Time Management', 'Uses time efficiently during free periods.', 10],
        ['Class Attendance', 'Attends classes consistently and on time.', 11],
        ['Class Attendance', 'Shows attentiveness during class sessions.', 12],
        ['Class Attendance', 'Participates actively in class discussions when appropriate.', 13],
        ['Class Attendance', 'Comes to class prepared with required materials.', 14],
        ['Class Attendance', 'Demonstrates respect for teachers and classmates during classes.', 15],
        ['Academic Responsibility', 'Submits assignments and requirements on time.', 16],
        ['Academic Responsibility', 'Follows academic policies and classroom rules.', 17],
        ['Academic Responsibility', 'Demonstrates honesty and integrity in academic work.', 18],
        ['Academic Responsibility', 'Accepts accountability for academic performance outcomes.', 19],
        ['Academic Responsibility', 'Communicates responsibly with instructors regarding requirements.', 20],
        ['Critical Thinking', 'Analyzes academic concepts with depth and clarity.', 21],
        ['Critical Thinking', 'Applies learned concepts to new situations or cases.', 22],
        ['Critical Thinking', 'Demonstrates sound problem-solving skills in academic tasks.', 23],
        ['Critical Thinking', 'Forms independent and well-reasoned conclusions.', 24],
        ['Critical Thinking', 'Asks thoughtful questions that reflect understanding.', 25],
        ['Learning Attitude', 'Shows openness to feedback and correction.', 26],
        ['Learning Attitude', 'Demonstrates desire for continuous academic improvement.', 27],
        ['Learning Attitude', 'Shows intellectual curiosity beyond course requirements.', 28],
        ['Seminary Academic Performance', 'Maintains consistent academic performance across subjects.', 29],
        ['Seminary Academic Performance', 'Demonstrates academic discipline in meeting expectations.', 30],
    ];

    $stmt_ins_q = $conn->prepare("INSERT INTO academic_evaluation_questions (category, question_text, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    foreach ($questions as $q) {
        $cat = (string)$q[0];
        $text = (string)$q[1];
        $ord = (int)$q[2];
        $stmt_ins_q->bind_param('ssi', $cat, $text, $ord);
        $stmt_ins_q->execute();
    }
}

$spiritual_tables = [
    [
        'table' => 'spiritual_evaluations',
        'sql' => "CREATE TABLE spiritual_evaluations (\n"
            . "evaluation_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "spiritual_director_id INT NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "evaluation_date DATE NOT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "archived_at DATETIME NULL,\n"
            . "KEY idx_se_student (seminarian_id),\n"
            . "KEY idx_se_term (academic_year, semester),\n"
            . "KEY idx_se_director (spiritual_director_id),\n"
            . "CONSTRAINT fk_se_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_se_director FOREIGN KEY (spiritual_director_id) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'spiritual_questions',
        'sql' => "CREATE TABLE spiritual_questions (\n"
            . "question_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "category VARCHAR(80) NOT NULL,\n"
            . "question_text VARCHAR(255) NOT NULL,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "KEY idx_sq_active (is_active),\n"
            . "KEY idx_sq_sort (sort_order)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'spiritual_responses',
        'sql' => "CREATE TABLE spiritual_responses (\n"
            . "response_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "question_id INT NOT NULL,\n"
            . "rating TINYINT NOT NULL,\n"
            . "UNIQUE KEY uniq_sresp (evaluation_id, question_id),\n"
            . "KEY idx_sresp_eval (evaluation_id),\n"
            . "KEY idx_sresp_q (question_id),\n"
            . "CONSTRAINT fk_sresp_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_sresp_q FOREIGN KEY (question_id) REFERENCES spiritual_questions(question_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'spiritual_remarks',
        'sql' => "CREATE TABLE spiritual_remarks (\n"
            . "remark_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "strengths TEXT NULL,\n"
            . "areas_for_growth TEXT NULL,\n"
            . "challenges TEXT NULL,\n"
            . "recommendations TEXT NULL,\n"
            . "formation_plan TEXT NULL,\n"
            . "follow_up_schedule TEXT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_srem_eval (evaluation_id),\n"
            . "CONSTRAINT fk_srem_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'spiritual_results',
        'sql' => "CREATE TABLE spiritual_results (\n"
            . "evaluation_id INT PRIMARY KEY,\n"
            . "total_score INT NOT NULL,\n"
            . "average_rating DECIMAL(4,2) NOT NULL,\n"
            . "interpretation VARCHAR(60) NOT NULL,\n"
            . "prayer_life_avg DECIMAL(4,2) NULL,\n"
            . "sacramental_participation_avg DECIMAL(4,2) NULL,\n"
            . "spiritual_discipline_avg DECIMAL(4,2) NULL,\n"
            . "relationship_vocation_avg DECIMAL(4,2) NULL,\n"
            . "christian_virtues_avg DECIMAL(4,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "CONSTRAINT fk_sres_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'spiritual_analytics',
        'sql' => "CREATE TABLE spiritual_analytics (\n"
            . "analytics_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "previous_score DECIMAL(4,2) NULL,\n"
            . "current_score DECIMAL(4,2) NULL,\n"
            . "growth_status ENUM('Growing','Stable','At Risk') NULL,\n"
            . "growth_percentage DECIMAL(6,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_sa_eval (evaluation_id),\n"
            . "KEY idx_sa_student (seminarian_id),\n"
            . "CONSTRAINT fk_sa_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_sa_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($spiritual_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$sq_count_res = $conn->query("SELECT COUNT(*) as c FROM spiritual_questions");
if ($sq_count_res && (int)($sq_count_res->fetch_assoc()['c'] ?? 0) === 0) {
    $s_questions = [
        ['Prayer Life', 'Demonstrates consistency in personal prayer.', 1],
        ['Prayer Life', 'Participates actively in community prayer activities.', 2],
        ['Prayer Life', 'Maintains a regular schedule for spiritual reflection.', 3],
        ['Prayer Life', 'Shows attentiveness and reverence during prayer.', 4],
        ['Prayer Life', 'Integrates prayer into daily decision-making.', 5],
        ['Prayer Life', 'Demonstrates a sincere desire to deepen his prayer life.', 6],
        ['Sacramental Participation', 'Participates faithfully in the Holy Eucharist.', 7],
        ['Sacramental Participation', 'Approaches the Sacrament of Reconciliation regularly.', 8],
        ['Sacramental Participation', 'Demonstrates reverence during liturgical celebrations.', 9],
        ['Sacramental Participation', 'Understands the importance of sacramental life.', 10],
        ['Sacramental Participation', 'Actively participates in liturgical ministries when assigned.', 11],
        ['Sacramental Participation', 'Exhibits devotion and respect during worship.', 12],
        ['Spiritual Discipline', 'Practices self-discipline in daily life.', 13],
        ['Spiritual Discipline', 'Demonstrates commitment to spiritual exercises.', 14],
        ['Spiritual Discipline', 'Fulfills assigned spiritual obligations responsibly.', 15],
        ['Spiritual Discipline', 'Shows perseverance in spiritual commitments.', 16],
        ['Spiritual Discipline', 'Uses time effectively for spiritual growth.', 17],
        ['Spiritual Discipline', 'Demonstrates consistency in spiritual practices.', 18],
        ['Relationship with God & Vocation', "Shows a growing awareness of God's presence.", 19],
        ['Relationship with God & Vocation', "Demonstrates trust in God's providence.", 20],
        ['Relationship with God & Vocation', 'Reflects seriously on his vocation.', 21],
        ['Relationship with God & Vocation', 'Displays commitment to priestly formation.', 22],
        ['Relationship with God & Vocation', "Shows openness to God's will.", 23],
        ['Relationship with God & Vocation', 'Demonstrates authenticity in his vocational discernment.', 24],
        ['Christian Virtues & Community Spirituality', 'Demonstrates humility in daily interactions.', 25],
        ['Christian Virtues & Community Spirituality', 'Practices charity toward others.', 26],
        ['Christian Virtues & Community Spirituality', 'Shows patience during challenges.', 27],
        ['Christian Virtues & Community Spirituality', 'Forgives others readily and sincerely.', 28],
        ['Christian Virtues & Community Spirituality', 'Promotes peace and unity within the community.', 29],
        ['Christian Virtues & Community Spirituality', 'Serves as a positive spiritual example to fellow seminarians.', 30],
    ];

    $stmt_ins_sq = $conn->prepare("INSERT INTO spiritual_questions (category, question_text, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    foreach ($s_questions as $q) {
        $cat = (string)$q[0];
        $text = (string)$q[1];
        $ord = (int)$q[2];
        $stmt_ins_sq->bind_param('ssi', $cat, $text, $ord);
        $stmt_ins_sq->execute();
    }
}

$human_tables = [
    [
        'table' => 'human_evaluations',
        'sql' => "CREATE TABLE human_evaluations (\n"
            . "evaluation_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "formator_id INT NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "evaluation_date DATE NOT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "archived_at DATETIME NULL,\n"
            . "KEY idx_he_student (seminarian_id),\n"
            . "KEY idx_he_term (academic_year, semester),\n"
            . "KEY idx_he_formator (formator_id),\n"
            . "CONSTRAINT fk_he_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_he_formator FOREIGN KEY (formator_id) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'human_questions',
        'sql' => "CREATE TABLE human_questions (\n"
            . "question_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "category VARCHAR(80) NOT NULL,\n"
            . "question_text VARCHAR(255) NOT NULL,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "KEY idx_hq_active (is_active),\n"
            . "KEY idx_hq_sort (sort_order)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'human_responses',
        'sql' => "CREATE TABLE human_responses (\n"
            . "response_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "question_id INT NOT NULL,\n"
            . "rating TINYINT NOT NULL,\n"
            . "UNIQUE KEY uniq_hresp (evaluation_id, question_id),\n"
            . "KEY idx_hresp_eval (evaluation_id),\n"
            . "KEY idx_hresp_q (question_id),\n"
            . "CONSTRAINT fk_hresp_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_hresp_q FOREIGN KEY (question_id) REFERENCES human_questions(question_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'human_remarks',
        'sql' => "CREATE TABLE human_remarks (\n"
            . "remark_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "strengths TEXT NULL,\n"
            . "weaknesses TEXT NULL,\n"
            . "behavioral_concerns TEXT NULL,\n"
            . "recommendations TEXT NULL,\n"
            . "follow_up_actions TEXT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_hrem_eval (evaluation_id),\n"
            . "CONSTRAINT fk_hrem_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'human_results',
        'sql' => "CREATE TABLE human_results (\n"
            . "evaluation_id INT PRIMARY KEY,\n"
            . "overall_score INT NOT NULL,\n"
            . "average_rating DECIMAL(4,2) NOT NULL,\n"
            . "interpretation VARCHAR(60) NOT NULL,\n"
            . "responsibility_avg DECIMAL(4,2) NULL,\n"
            . "integrity_avg DECIMAL(4,2) NULL,\n"
            . "emotional_maturity_avg DECIMAL(4,2) NULL,\n"
            . "interpersonal_avg DECIMAL(4,2) NULL,\n"
            . "leadership_avg DECIMAL(4,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "CONSTRAINT fk_hres_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'human_analytics',
        'sql' => "CREATE TABLE human_analytics (\n"
            . "analytics_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "previous_score DECIMAL(4,2) NULL,\n"
            . "current_score DECIMAL(4,2) NULL,\n"
            . "growth_status ENUM('Improving','Stable','Declining') NULL,\n"
            . "growth_percentage DECIMAL(6,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_ha_eval (evaluation_id),\n"
            . "KEY idx_ha_student (seminarian_id),\n"
            . "CONSTRAINT fk_ha_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_ha_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($human_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$hq_count_res = $conn->query("SELECT COUNT(*) as c FROM human_questions");
if ($hq_count_res && (int)($hq_count_res->fetch_assoc()['c'] ?? 0) === 0) {
    $h_questions = [
        ['Personal Responsibility', 'Demonstrates accountability for his actions and decisions.', 1],
        ['Personal Responsibility', 'Completes assigned responsibilities on time.', 2],
        ['Personal Responsibility', 'Manages personal tasks without constant supervision.', 3],
        ['Personal Responsibility', 'Shows initiative in carrying out assigned duties.', 4],
        ['Personal Responsibility', 'Accepts correction with humility and openness.', 5],
        ['Personal Responsibility', 'Demonstrates honesty in reporting accomplishments and failures.', 6],
        ['Moral Character and Integrity', 'Acts according to moral and ethical principles.', 7],
        ['Moral Character and Integrity', 'Demonstrates honesty in daily interactions.', 8],
        ['Moral Character and Integrity', 'Shows consistency between words and actions.', 9],
        ['Moral Character and Integrity', 'Respects institutional rules and policies.', 10],
        ['Moral Character and Integrity', 'Avoids behaviors that may cause scandal or conflict.', 11],
        ['Moral Character and Integrity', 'Displays integrity even when not being observed.', 12],
        ['Emotional Maturity', 'Maintains emotional stability under pressure.', 13],
        ['Emotional Maturity', 'Responds constructively to criticism.', 14],
        ['Emotional Maturity', 'Handles disappointments appropriately.', 15],
        ['Emotional Maturity', 'Demonstrates patience in challenging situations.', 16],
        ['Emotional Maturity', 'Shows self-control during conflicts.', 17],
        ['Emotional Maturity', 'Displays healthy emotional awareness and expression.', 18],
        ['Interpersonal Relationships', 'Treats others with respect and dignity.', 19],
        ['Interpersonal Relationships', 'Works effectively with fellow seminarians.', 20],
        ['Interpersonal Relationships', 'Demonstrates empathy toward others.', 21],
        ['Interpersonal Relationships', 'Resolves conflicts peacefully and maturely.', 22],
        ['Interpersonal Relationships', 'Maintains healthy relationships within the seminary community.', 23],
        ['Interpersonal Relationships', 'Communicates respectfully with superiors, peers, and staff.', 24],
        ['Leadership and Community Participation', 'Demonstrates willingness to serve others.', 25],
        ['Leadership and Community Participation', 'Participates actively in community activities.', 26],
        ['Leadership and Community Participation', 'Shows leadership when opportunities arise.', 27],
        ['Leadership and Community Participation', 'Encourages unity and cooperation among peers.', 28],
        ['Leadership and Community Participation', 'Contributes positively to the seminary environment.', 29],
        ['Leadership and Community Participation', 'Serves as a good example to fellow seminarians.', 30],
    ];

    $stmt_ins_hq = $conn->prepare("INSERT INTO human_questions (category, question_text, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    foreach ($h_questions as $q) {
        $cat = (string)$q[0];
        $text = (string)$q[1];
        $ord = (int)$q[2];
        $stmt_ins_hq->bind_param('ssi', $cat, $text, $ord);
        $stmt_ins_hq->execute();
    }
}

$pastoral_tables = [
    [
        'table' => 'pastoral_evaluations',
        'sql' => "CREATE TABLE pastoral_evaluations (\n"
            . "evaluation_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "formator_id INT NOT NULL,\n"
            . "academic_year VARCHAR(9) NOT NULL,\n"
            . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
            . "evaluation_date DATE NOT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "archived_at DATETIME NULL,\n"
            . "KEY idx_pe_student (seminarian_id),\n"
            . "KEY idx_pe_term (academic_year, semester),\n"
            . "KEY idx_pe_formator (formator_id),\n"
            . "CONSTRAINT fk_pe_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_pe_formator FOREIGN KEY (formator_id) REFERENCES users(id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'pastoral_questions',
        'sql' => "CREATE TABLE pastoral_questions (\n"
            . "question_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "category VARCHAR(80) NOT NULL,\n"
            . "question_text VARCHAR(255) NOT NULL,\n"
            . "sort_order INT NOT NULL DEFAULT 0,\n"
            . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "KEY idx_pq_active (is_active),\n"
            . "KEY idx_pq_sort (sort_order)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'pastoral_responses',
        'sql' => "CREATE TABLE pastoral_responses (\n"
            . "response_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "question_id INT NOT NULL,\n"
            . "rating TINYINT NOT NULL,\n"
            . "UNIQUE KEY uniq_presp (evaluation_id, question_id),\n"
            . "KEY idx_presp_eval (evaluation_id),\n"
            . "KEY idx_presp_q (question_id),\n"
            . "CONSTRAINT fk_presp_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_presp_q FOREIGN KEY (question_id) REFERENCES pastoral_questions(question_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'pastoral_remarks',
        'sql' => "CREATE TABLE pastoral_remarks (\n"
            . "remark_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "strengths TEXT NULL,\n"
            . "areas_for_improvement TEXT NULL,\n"
            . "challenges TEXT NULL,\n"
            . "recommendations TEXT NULL,\n"
            . "ministry_development_plan TEXT NULL,\n"
            . "follow_up_actions TEXT NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NULL,\n"
            . "UNIQUE KEY uniq_prem_eval (evaluation_id),\n"
            . "CONSTRAINT fk_prem_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'pastoral_results',
        'sql' => "CREATE TABLE pastoral_results (\n"
            . "evaluation_id INT PRIMARY KEY,\n"
            . "total_score INT NOT NULL,\n"
            . "average_rating DECIMAL(4,2) NOT NULL,\n"
            . "interpretation VARCHAR(60) NOT NULL,\n"
            . "pastoral_ministry_avg DECIMAL(4,2) NULL,\n"
            . "leadership_avg DECIMAL(4,2) NULL,\n"
            . "communication_avg DECIMAL(4,2) NULL,\n"
            . "community_engagement_avg DECIMAL(4,2) NULL,\n"
            . "apostolic_zeal_avg DECIMAL(4,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "CONSTRAINT fk_pres_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'table' => 'pastoral_analytics',
        'sql' => "CREATE TABLE pastoral_analytics (\n"
            . "analytics_id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "seminarian_id INT NOT NULL,\n"
            . "evaluation_id INT NOT NULL,\n"
            . "previous_score DECIMAL(4,2) NULL,\n"
            . "current_score DECIMAL(4,2) NULL,\n"
            . "growth_status ENUM('Growing','Stable','Declining') NULL,\n"
            . "growth_percentage DECIMAL(6,2) NULL,\n"
            . "computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "UNIQUE KEY uniq_pa_eval (evaluation_id),\n"
            . "KEY idx_pa_student (seminarian_id),\n"
            . "CONSTRAINT fk_pa_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,\n"
            . "CONSTRAINT fk_pa_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
];

foreach ($pastoral_tables as $t) {
    $table_check = $conn->query(
        "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($t['table']) . "'"
    );
    if ($table_check) {
        $has_table = (int)($table_check->fetch_assoc()['c'] ?? 0);
        if ($has_table === 0) {
            $conn->query($t['sql']);
        }
    }
}

$pq_count_res = $conn->query("SELECT COUNT(*) as c FROM pastoral_questions");
if ($pq_count_res && (int)($pq_count_res->fetch_assoc()['c'] ?? 0) === 0) {
    $p_questions = [
        ['Pastoral Ministry and Service', 'Demonstrates dedication in assigned pastoral ministries.', 1],
        ['Pastoral Ministry and Service', 'Performs pastoral responsibilities faithfully.', 2],
        ['Pastoral Ministry and Service', 'Shows concern for the spiritual welfare of others.', 3],
        ['Pastoral Ministry and Service', 'Participates actively in ministry activities.', 4],
        ['Pastoral Ministry and Service', 'Displays initiative in serving communities.', 5],
        ['Pastoral Ministry and Service', 'Demonstrates commitment to pastoral assignments.', 6],
        ['Leadership and Responsibility', 'Accepts leadership responsibilities willingly.', 7],
        ['Leadership and Responsibility', 'Demonstrates accountability in ministry work.', 8],
        ['Leadership and Responsibility', 'Makes sound decisions during pastoral activities.', 9],
        ['Leadership and Responsibility', 'Inspires cooperation among peers.', 10],
        ['Leadership and Responsibility', 'Organizes activities effectively.', 11],
        ['Leadership and Responsibility', 'Serves as a positive role model in ministry.', 12],
        ['Communication and Interpersonal Skills', 'Communicates clearly and respectfully.', 13],
        ['Communication and Interpersonal Skills', 'Listens attentively to others.', 14],
        ['Communication and Interpersonal Skills', 'Demonstrates empathy when interacting with people.', 15],
        ['Communication and Interpersonal Skills', 'Handles conflicts constructively.', 16],
        ['Communication and Interpersonal Skills', 'Shows confidence in public speaking situations.', 17],
        ['Communication and Interpersonal Skills', 'Establishes healthy relationships with parishioners and community members.', 18],
        ['Community Engagement and Collaboration', 'Participates actively in community outreach programs.', 19],
        ['Community Engagement and Collaboration', 'Works effectively with teams and ministry groups.', 20],
        ['Community Engagement and Collaboration', 'Demonstrates respect for diverse backgrounds and experiences.', 21],
        ['Community Engagement and Collaboration', 'Supports fellow seminarians in ministry activities.', 22],
        ['Community Engagement and Collaboration', 'Contributes positively to community-building efforts.', 23],
        ['Community Engagement and Collaboration', 'Cooperates with formators and pastoral supervisors.', 24],
        ['Apostolic Zeal and Mission Awareness', 'Demonstrates enthusiasm for evangelization.', 25],
        ['Apostolic Zeal and Mission Awareness', 'Shows willingness to serve marginalized communities.', 26],
        ['Apostolic Zeal and Mission Awareness', 'Displays genuine concern for the needs of others.', 27],
        ['Apostolic Zeal and Mission Awareness', 'Promotes the mission of the Church through actions and words.', 28],
        ['Apostolic Zeal and Mission Awareness', 'Demonstrates perseverance in ministry challenges.', 29],
        ['Apostolic Zeal and Mission Awareness', 'Shows commitment to ongoing pastoral growth and development.', 30],
    ];

    $stmt_ins_pq = $conn->prepare("INSERT INTO pastoral_questions (category, question_text, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    foreach ($p_questions as $q) {
        $cat = (string)$q[0];
        $text = (string)$q[1];
        $ord = (int)$q[2];
        $stmt_ins_pq->bind_param('ssi', $cat, $text, $ord);
        $stmt_ins_pq->execute();
    }
}

} // End !$skip_runtime_schema_sync

// ---- Module 2: Student ID helpers ----
if (!function_exists('is_student_id_taken')) {
    /**
     * Returns true if the given student ID is already used by another profile.
     */
    function is_student_id_taken(mysqli $conn, string $student_id, int $exclude_user_id = 0): bool
    {
        $student_id = trim($student_id);
        if ($student_id === '') {
            return false;
        }
        $stmt = $conn->prepare('SELECT COUNT(*) as c FROM user_profiles WHERE student_id = ? AND user_id <> ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $student_id, $exclude_user_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    }
}

if (!function_exists('generate_unique_student_id')) {
    /**
     * Generates the next free student ID in the format SEM-YYYY-0001.
     */
    function generate_unique_student_id(mysqli $conn, ?int $year = null): string
    {
        $year = $year ?? (int)date('Y');
        $prefix = 'SEM-' . $year . '-';
        $like = $prefix . '%';
        $next = 1;
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(student_id, ?) AS UNSIGNED)) as m FROM user_profiles WHERE student_id LIKE ?");
        if ($stmt) {
            $start = strlen($prefix) + 1;
            $stmt->bind_param('is', $start, $like);
            $stmt->execute();
            $next = (int)($stmt->get_result()->fetch_assoc()['m'] ?? 0) + 1;
        }
        do {
            $candidate = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (is_student_id_taken($conn, $candidate));
        return $candidate;
    }
}

require_once __DIR__ . '/registrar_decoupling.php';
require_once __DIR__ . '/audit_trail.php';
if (!$skip_runtime_schema_sync) {
    ensure_audit_trail_schema($conn);
}
require_once __DIR__ . '/seminarian_profile_helper.php';
if (!$skip_runtime_schema_sync) {
    ensure_seminarian_profile_schema($conn);
}
?>
