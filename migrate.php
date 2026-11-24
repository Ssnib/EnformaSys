<?php
include __DIR__ . '/includes/session_fix.php';
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['Registrar', 'Dean'])) {
    header('Location: index.php');
    exit;
}

if (!defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC')) {
    define('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC', true);
}
include __DIR__ . '/includes/db.php';

function ensure_schema_migrations_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS schema_migrations ("
        . "version VARCHAR(100) PRIMARY KEY,"
        . "applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function migration_applied(mysqli $conn, string $version): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) as c FROM schema_migrations WHERE version = ?');
    $stmt->bind_param('s', $version);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

function mark_migration_applied(mysqli $conn, string $version): void
{
    $stmt = $conn->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
    $stmt->bind_param('s', $version);
    $stmt->execute();
}

function apply_lodging_billing_migration(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS lodging_rooms (\n"
        . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "name VARCHAR(100) NOT NULL,\n"
        . "capacity INT NOT NULL DEFAULT 1,\n"
        . "monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
        . "is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
        . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query("CREATE TABLE IF NOT EXISTS lodging_stays (\n"
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
    );

    $conn->query("CREATE TABLE IF NOT EXISTS lodging_invoices (\n"
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
    );

    $conn->query("CREATE TABLE IF NOT EXISTS lodging_invoice_items (\n"
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
    );

    $conn->query("CREATE TABLE IF NOT EXISTS lodging_payments (\n"
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
    );

    $conn->query("CREATE TABLE IF NOT EXISTS lodging_payment_allocations (\n"
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
    );

    $rooms_count_res = $conn->query("SELECT COUNT(*) as c FROM lodging_rooms");
    if ($rooms_count_res) {
        $rooms_count = (int)($rooms_count_res->fetch_assoc()['c'] ?? 0);
        if ($rooms_count === 0) {
            $conn->query("INSERT INTO lodging_rooms (name, capacity, monthly_rate, is_active) VALUES ('Dormitory', 1, 0.00, 1)");
        }
    }
}

function apply_user_profiles_migration(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS user_profiles (\n"
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

function apply_applicants_user_link_migration(mysqli $conn): void
{
	$col_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'user_id'");
	if ($col_check) {
		$has_col = (int)($col_check->fetch_assoc()['c'] ?? 0);
		if ($has_col === 0) {
			$conn->query('ALTER TABLE applicants ADD COLUMN user_id INT NULL');
		}
	}

	$idx_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND INDEX_NAME = 'idx_applicants_user_id'");
	if ($idx_check) {
		$has_idx = (int)($idx_check->fetch_assoc()['c'] ?? 0);
		if ($has_idx === 0) {
			$conn->query('CREATE INDEX idx_applicants_user_id ON applicants(user_id)');
		}
	}

	$fk_check = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND CONSTRAINT_NAME = 'fk_applicants_user'");
	if ($fk_check) {
		$has_fk = (int)($fk_check->fetch_assoc()['c'] ?? 0);
		if ($has_fk === 0) {
			$conn->query('ALTER TABLE applicants ADD CONSTRAINT fk_applicants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
		}
	}

	$res_unlinked = $conn->query("SELECT id, full_name FROM applicants WHERE user_id IS NULL AND status = 'Enrolled'");
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

function apply_timetable_calendar_migration(mysqli $conn): void
{
    // Add end_time column if missing
    $r = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'end_time'");
    if ($r && (int)($r->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE timetable ADD COLUMN end_time TIME NULL AFTER time");
    }

    // Add created_by column if missing
    $r = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'created_by'");
    if ($r && (int)($r->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE timetable ADD COLUMN created_by INT NULL AFTER note");
        // Add FK only if it doesn't exist
        $fk = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND CONSTRAINT_NAME = 'fk_timetable_created_by'");
        if ($fk && (int)($fk->fetch_assoc()['c'] ?? 0) === 0) {
            $conn->query("ALTER TABLE timetable ADD CONSTRAINT fk_timetable_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        }
    }

    // Add visibility column if missing
    $r = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND COLUMN_NAME = 'visibility'");
    if ($r && (int)($r->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE timetable ADD COLUMN visibility ENUM('all','admin_only') NOT NULL DEFAULT 'all' AFTER created_by");
    }

    // Add index on date if missing
    $idx = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable' AND INDEX_NAME = 'idx_timetable_date'");
    if ($idx && (int)($idx->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE timetable ADD KEY idx_timetable_date (date)");
    }
}

function apply_module2_academics_migration(mysqli $conn): void
{
    // 1) Subject teacher assignments (teacher per subject per AY/semester)
    $conn->query("CREATE TABLE IF NOT EXISTS subject_teacher_assignments (\n"
        . "id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "subject_id INT NOT NULL,\n"
        . "academic_year VARCHAR(20) NOT NULL,\n"
        . "semester ENUM('1st','2nd','Summer') NOT NULL,\n"
        . "teacher_user_id INT NULL,\n"
        . "teacher_name_override VARCHAR(150) NULL,\n"
        . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,\n"
        . "UNIQUE KEY uniq_sta_subject_term (subject_id, academic_year, semester),\n"
        . "KEY idx_sta_teacher (teacher_user_id)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Foreign keys are best-effort (column type mismatches on legacy tables should not block the migration)
    $fks = [
        'fk_sta_subject' => "ALTER TABLE subject_teacher_assignments ADD CONSTRAINT fk_sta_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE",
        'fk_sta_teacher' => "ALTER TABLE subject_teacher_assignments ADD CONSTRAINT fk_sta_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE SET NULL",
    ];
    foreach ($fks as $name => $sql) {
        $fk = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_teacher_assignments' AND CONSTRAINT_NAME = '" . $conn->real_escape_string($name) . "'");
        if ($fk && (int)($fk->fetch_assoc()['c'] ?? 0) === 0) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
                // ignore – FK is optional
            }
        }
    }

    // 2) Unique student_id on user_profiles
    $col = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'student_id'");
    if ($col && (int)($col->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE user_profiles ADD COLUMN student_id VARCHAR(40) NULL");
    }

    // Normalize blanks to NULL so they don't collide under the UNIQUE key
    $conn->query("UPDATE user_profiles SET student_id = NULL WHERE student_id IS NOT NULL AND TRIM(student_id) = ''");

    $idx = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND INDEX_NAME = 'uniq_user_profiles_student_id'");
    if ($idx && (int)($idx->fetch_assoc()['c'] ?? 0) === 0) {
        $dup = $conn->query("SELECT student_id, COUNT(*) as c FROM user_profiles WHERE student_id IS NOT NULL GROUP BY student_id HAVING c > 1");
        if ($dup && $dup->num_rows > 0) {
            $list = [];
            while ($d = $dup->fetch_assoc()) {
                $list[] = $d['student_id'] . ' (x' . $d['c'] . ')';
            }
            throw new RuntimeException('Duplicate student IDs found; resolve them before re-running: ' . implode(', ', $list));
        }
        $conn->query("ALTER TABLE user_profiles ADD UNIQUE KEY uniq_user_profiles_student_id (student_id)");
    }
}

function apply_module3_cashier_migration(mysqli $conn): void
{
    // 1) Add amount_tendered column to lodging_payments
    $col_at = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_payments' AND COLUMN_NAME = 'amount_tendered'");
    if ($col_at && (int)($col_at->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE lodging_payments ADD COLUMN amount_tendered DECIMAL(10,2) NULL AFTER amount");
    }

    // 2) Ensure unique index on or_number
    $idx_or = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_payments' AND INDEX_NAME = 'uniq_lodging_payments_or_number'");
    if ($idx_or && (int)($idx_or->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE lodging_payments ADD UNIQUE KEY uniq_lodging_payments_or_number (or_number)");
    }

    // 3) Add sort_order column to cashier_payment_items
    $col_so = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashier_payment_items' AND COLUMN_NAME = 'sort_order'");
    if ($col_so && (int)($col_so->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE cashier_payment_items ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER amount");
    }

    // 4) Add index on (payment_id, sort_order) in cashier_payment_items
    $idx_so = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashier_payment_items' AND INDEX_NAME = 'idx_cpi_sort'");
    if ($idx_so && (int)($idx_so->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE cashier_payment_items ADD KEY idx_cpi_sort (payment_id, sort_order)");
    }
}

function apply_applicants_fields_migration(mysqli $conn): void
{
    $col_prog = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'program'");
    if ($col_prog && (int)($col_prog->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE applicants ADD COLUMN program VARCHAR(80) NULL AFTER academic_year");
    }

    $col_cat = $conn->query("SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'created_at'");
    if ($col_cat && (int)($col_cat->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE applicants ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
}

function apply_performance_composite_indexes_migration(mysqli $conn): void
{
    $indexes = [
        ['table' => 'activities', 'index' => 'idx_activities_ts_id', 'cols' => '`timestamp`, `id`'],
        ['table' => 'history_logs', 'index' => 'idx_history_logs_changed_at', 'cols' => '`changed_at`'],
        ['table' => 'enrollments', 'index' => 'idx_enrollments_term', 'cols' => '`academic_year`, `semester`, `enrollment_status`'],
        ['table' => 'evaluations', 'index' => 'idx_eval_sem_arch_type', 'cols' => '`seminarian_id`, `archived_at`, `type`'],
        ['table' => 'academic_evaluations', 'index' => 'idx_ae_stu_term_arch', 'cols' => '`student_id`, `academic_year`, `semester`, `archived_at`'],
        ['table' => 'human_evaluations', 'index' => 'idx_he_sem_term_arch', 'cols' => '`seminarian_id`, `academic_year`, `semester`, `archived_at`'],
        ['table' => 'pastoral_evaluations', 'index' => 'idx_pe_sem_term_arch', 'cols' => '`seminarian_id`, `academic_year`, `semester`, `archived_at`'],
        ['table' => 'spiritual_evaluations', 'index' => 'idx_se_sem_term_arch', 'cols' => '`seminarian_id`, `academic_year`, `semester`, `archived_at`'],
    ];

    $stmtTbl = $conn->prepare(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmtIdx = $conn->prepare(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    if (!$stmtTbl || !$stmtIdx) {
        return;
    }

    foreach ($indexes as $def) {
        $tbl = $def['table'];
        $idx = $def['index'];
        $stmtTbl->bind_param('s', $tbl);
        $stmtTbl->execute();
        if ((int)($stmtTbl->get_result()->fetch_assoc()['c'] ?? 0) === 0) {
            continue;
        }
        $stmtIdx->bind_param('ss', $tbl, $idx);
        $stmtIdx->execute();
        if ((int)($stmtIdx->get_result()->fetch_assoc()['c'] ?? 0) === 0) {
            @$conn->query("CREATE INDEX `{$idx}` ON `{$tbl}` ({$def['cols']})");
        }
    }
    $stmtTbl->close();
    $stmtIdx->close();
}

ensure_schema_migrations_table($conn);

$migrations = [
    [
        'version' => '2026_04_20_user_profiles',
        'name' => 'User Profiles (profile fields for accounts)',
        'apply' => function (mysqli $conn): void {
            apply_user_profiles_migration($conn);
        }
    ],
	[
		'version' => '2026_04_20_applicants_user_id',
		'name' => 'Applicants link to users (user_id FK)',
		'apply' => function (mysqli $conn): void {
			apply_applicants_user_link_migration($conn);
		}
	],
    [
        'version' => '2026_04_18_lodging_billing',
        'name' => 'Lodging Billing (tables + defaults)',
        'apply' => function (mysqli $conn): void {
            apply_lodging_billing_migration($conn);
        }
    ],
    [
        'version' => '2026_09_28_timetable_calendar_fields',
        'name' => 'Timetable: end_time, created_by, visibility columns + date index',
        'apply' => function (mysqli $conn): void {
            apply_timetable_calendar_migration($conn);
        }
    ],
    [
        'version' => '2026_09_28_module2_academics',
        'name' => 'Module 2: subject_teacher_assignments table + student_id unique key',
        'apply' => function (mysqli $conn): void {
            apply_module2_academics_migration($conn);
        }
    ],
    [
        'version' => '2026_10_06_module3_cashier_payments',
        'name' => 'Module 3: Cashier payments amount_tendered, sort_order, and OR locking',
        'apply' => function (mysqli $conn): void {
            apply_module3_cashier_migration($conn);
        }
    ],
    [
        'version' => '2026_10_11_applicants_program_created_at',
        'name' => 'Applicants: program and created_at columns',
        'apply' => function (mysqli $conn): void {
            apply_applicants_fields_migration($conn);
        }
    ],
    [
        'version' => '2026_11_06_decouple_students_and_enrollments',
        'name' => 'Registrar Refactor: Separate students (Static Profile) and enrollments (Historical Term Records)',
        'apply' => function (mysqli $conn): void {
            require_once __DIR__ . '/includes/registrar_decoupling.php';
            registrar_sync_and_migrate($conn, true);
        }
    ],
    [
        'version' => '2026_11_24_performance_composite_indexes',
        'name' => 'Performance: Composite indexes on activities, history_logs, enrollments, and evaluation tables',
        'apply' => function (mysqli $conn): void {
            apply_performance_composite_indexes_migration($conn);
        }
    ],
];

$results = [];
if (isset($_POST['run_migrations'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    foreach ($migrations as $m) {
        $version = (string)$m['version'];
        if (migration_applied($conn, $version)) {
            $results[] = ['version' => $version, 'status' => 'skipped', 'message' => 'Already applied'];
            continue;
        }
        try {
            $m['apply']($conn);
            mark_migration_applied($conn, $version);
            $results[] = ['version' => $version, 'status' => 'applied', 'message' => 'Applied successfully'];
            enformasys_audit_log($conn, (int)($_SESSION['user'] ?? 0), "Applied database migration {$version}: {$m['name']}", 'Migration', null, 'migration_applied', null, $version);
        } catch (Throwable $e) {
            $errMsg = enformasys_is_production() ? 'Migration failed (see server logs).' : $e->getMessage();
            error_log("Migration {$version} error: " . $e->getMessage());
            $results[] = ['version' => $version, 'status' => 'failed', 'message' => $errMsg];
            enformasys_audit_log($conn, (int)($_SESSION['user'] ?? 0), "[SECURITY] Database migration failed ({$version})", 'Migration', null, 'migration_failed', null, $version);
            break;
        }
    }
}

$applied_map = [];
foreach ($migrations as $m) {
    $applied_map[$m['version']] = migration_applied($conn, $m['version']);
}
?>
<?php
$pageTitle = 'Database Migrations';
$activeNav = 'migrations';
require_once __DIR__ . '/includes/layout_app_start.php';
?>
            <h1 class="page-title"><i class="fas fa-database"></i> Database Migrations</h1>

            <div class="card" style="margin-bottom: 2rem;">
                <h3><i class="fas fa-list-check"></i> Migration List</h3>
                <div style="overflow-x: auto; margin-top: 1rem;">
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($migrations as $m) {
                                $is_applied = (bool)($applied_map[$m['version']] ?? false);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($m['version']); ?></strong></td>
                                <td><?php echo htmlspecialchars($m['name']); ?></td>
                                <td>
                                    <?php if ($is_applied) { ?>
                                        <span class="badge badge-success">APPLIED</span>
                                    <?php } else { ?>
                                        <span class="badge badge-warning">PENDING</span>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <form method="post" style="margin-top: 1rem; box-shadow: none; padding: 0; background: none; border: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button type="submit" name="run_migrations" style="width: auto; padding: 0.7rem 1.25rem;">
                        <i class="fas fa-play"></i> Run Pending Migrations
                    </button>
                </form>
            </div>

            <?php if (!empty($results)) { ?>
            <div class="data-card">
                <div class="data-card-header">
                    <h3><i class="fas fa-terminal"></i> Results</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Result</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $r) {
                                $cls = 'badge-primary';
                                if ($r['status'] === 'applied') $cls = 'badge-success';
                                if ($r['status'] === 'failed') $cls = 'badge-warning';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($r['version']); ?></strong></td>
                                <td><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars(strtoupper($r['status'])); ?></span></td>
                                <td><?php echo htmlspecialchars($r['message']); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php } ?>
<?php require_once __DIR__ . '/includes/layout_app_end.php'; ?>
<?php exit; ?>
