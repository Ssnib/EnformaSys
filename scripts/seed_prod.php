<?php
/**
 * EnformaSys Production Seed Script (`seed:prod`)
 *
 * Idempotently seeds ONLY:
 *  - Default Administrator (`admin`, role `Dean`) account (plus optional `--with-rector` / `--with-staff`)
 *  - Essential School Lookup Data:
 *      * Academic Year (2026-2027 current, plus 2025-2026 if missing)
 *      * Formation Pillar Questionnaires (Academic, Spiritual, Human, Pastoral — 30 items each)
 *      * Courses / Programs & Master Subjects (`AB Philosophy v1`, `Propaedeutic v1`)
 *      * Default Fee Structures (`cashier_fee_schedules` & `cashier_fee_items` for current AY)
 *      * Default Dormitory Room (`lodging_rooms`)
 *
 * Usage:
 *   php scripts/seed_prod.php --admin-password="StrongPassword123!"
 *   ADMIN_INITIAL_PASSWORD="StrongPassword123!" php scripts/seed_prod.php
 *   php scripts/seed_prod.php --admin-password="..." --with-rector --rector-password="..."
 *   php scripts/seed_prod.php --admin-password="..." --reset-admin-password
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once __DIR__ . '/../includes/env.php';

$args = array_slice($argv ?? [], 1);
$options = [
    'admin-password' => null,
    'rector-password' => null,
    'reset-admin-password' => false,
    'with-rector' => false,
    'help' => false,
];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        $options['help'] = true;
    } elseif ($arg === '--reset-admin-password') {
        $options['reset-admin-password'] = true;
    } elseif ($arg === '--with-rector') {
        $options['with-rector'] = true;
    } elseif (strpos($arg, '--admin-password=') === 0) {
        $options['admin-password'] = substr($arg, strlen('--admin-password='));
    } elseif (strpos($arg, '--rector-password=') === 0) {
        $options['rector-password'] = substr($arg, strlen('--rector-password='));
    }
}

if ($options['help']) {
    echo "EnformaSys Production Seed (`seed:prod`)\n";
    echo "Usage:\n";
    echo "  php scripts/seed_prod.php --admin-password=<password> [--with-rector --rector-password=<password>] [--reset-admin-password]\n\n";
    echo "Environment Variables:\n";
    echo "  ADMIN_INITIAL_PASSWORD   Initial password for the default `admin` (Dean) account\n";
    echo "  RECTOR_INITIAL_PASSWORD  Optional password for the `rector` account when `--with-rector` is passed\n";
    exit(0);
}

$isProd = enformasys_is_production();
$adminPassword = $options['admin-password'] ?? enformasys_env('ADMIN_INITIAL_PASSWORD', '');
if ($adminPassword === '') {
    $fallbackDev = !$isProd ? enformasys_env('DEFAULT_SEED_PASSWORD', '') : '';
    $adminPassword = $fallbackDev;
}

if ($adminPassword === '') {
    // Check if `admin` already exists and password reset was NOT requested
    // We still validate before DB work when creating a fresh admin account
    $adminPasswordNeededOnCreate = true;
} else {
    $adminPasswordNeededOnCreate = false;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = (string)enformasys_env('DB_HOST', '127.0.0.1');
$dbPort = (int)enformasys_env('DB_PORT', '3306');
$dbUser = (string)enformasys_env('DB_USER', 'root');
$dbPass = (string)enformasys_env('DB_PASS', '');
$dbName = (string)enformasys_env('DB_NAME', 'seminary_db');

// Load includes/db.php only AFTER verifying whether ADMIN_INITIAL_PASSWORD is set;
// includes/db.php idempotently ensures schema + canonical subjects + curricula + 4 pillar questionnaires.
// Notice: includes/db.php respects ALLOW_DEFAULT_SEED and no longer creates any Student users.
if (!defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC')) {
    define('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC', true);
}
require_once __DIR__ . '/../includes/db.php';

echo "============================================================\n";
echo "EnformaSys Production Seed (`seed:prod`)\n";
echo "Environment: " . (enformasys_is_production() ? 'production' : enformasys_env('APP_ENV', 'development')) . "\n";
echo "Database:    {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n";
echo "============================================================\n\n";

$summary = [];

// 1. Seed or verify Default Admin (`admin`, role `Dean`)
$stmtFindAdmin = $conn->prepare("SELECT id, password, role FROM users WHERE username = 'admin' LIMIT 1");
$stmtFindAdmin->execute();
$existingAdmin = $stmtFindAdmin->get_result()->fetch_assoc();
$stmtFindAdmin->close();

$adminId = 0;
if (!$existingAdmin) {
    if ($adminPasswordNeededOnCreate) {
        fwrite(STDERR, "ERROR: `admin` account does not exist yet. Provide --admin-password=... or set ADMIN_INITIAL_PASSWORD.\n");
        exit(1);
    }
    if (strlen($adminPassword) < 8 && $isProd) {
        fwrite(STDERR, "ERROR: Production admin password must be at least 8 characters.\n");
        exit(1);
    }
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $stmtInsAdmin = $conn->prepare("INSERT INTO users (username, password, role) VALUES ('admin', ?, 'Dean')");
    $stmtInsAdmin->bind_param('s', $hash);
    $stmtInsAdmin->execute();
    $adminId = (int)$conn->insert_id;
    $stmtInsAdmin->close();
    $summary[] = "Created default Administrator account `admin` (role: Dean, id: #{$adminId}).";
} else {
    $adminId = (int)$existingAdmin['id'];
    if ($options['reset-admin-password']) {
        if ($adminPassword === '') {
            fwrite(STDERR, "ERROR: --reset-admin-password requires --admin-password=... or ADMIN_INITIAL_PASSWORD.\n");
            exit(1);
        }
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmtUpdAdmin = $conn->prepare("UPDATE users SET password = ?, role = 'Dean' WHERE id = ?");
        $stmtUpdAdmin->bind_param('si', $hash, $adminId);
        $stmtUpdAdmin->execute();
        $stmtUpdAdmin->close();
        $summary[] = "Updated password for default Administrator account `admin` (id: #{$adminId}).";
    } else {
        $summary[] = "Preserved existing Administrator account `admin` (role: {$existingAdmin['role']}, id: #{$adminId}).";
    }
}

// Optional Rector account
if ($options['with-rector']) {
    $rectorPassword = $options['rector-password'] ?? enformasys_env('RECTOR_INITIAL_PASSWORD', '');
    $stmtFindRector = $conn->prepare("SELECT id FROM users WHERE username = 'rector' LIMIT 1");
    $stmtFindRector->execute();
    $existingRector = $stmtFindRector->get_result()->fetch_assoc();
    $stmtFindRector->close();

    if (!$existingRector) {
        if ($rectorPassword === '') {
            fwrite(STDERR, "ERROR: --with-rector requires --rector-password=... or RECTOR_INITIAL_PASSWORD.\n");
            exit(1);
        }
        $rHash = password_hash($rectorPassword, PASSWORD_DEFAULT);
        $stmtInsRector = $conn->prepare("INSERT INTO users (username, password, role) VALUES ('rector', ?, 'Rector')");
        $stmtInsRector->bind_param('s', $rHash);
        $stmtInsRector->execute();
        $rectorId = (int)$conn->insert_id;
        $stmtInsRector->close();
        $summary[] = "Created Rector account `rector` (role: Rector, id: #{$rectorId}).";
    } else {
        $summary[] = "Preserved existing Rector account `rector` (id: #{$existingRector['id']}).";
    }
}

// 2. Ensure Essential Academic Year (`2026-2027` current if no current AY is flagged)
$curAyCount = (int)$conn->query("SELECT COUNT(*) AS c FROM academic_years WHERE is_current = 1")->fetch_assoc()['c'];
if ($curAyCount === 0) {
    $ayStmt = $conn->prepare("
        INSERT INTO academic_years (label, start_year, end_year, start_date, end_date, is_active, is_current)
        VALUES ('2026-2027', 2026, 2027, '2026-06-01', '2027-05-31', 1, 1)
        ON DUPLICATE KEY UPDATE is_active = 1, is_current = 1
    ");
    $ayStmt->execute();
    $ayStmt->close();
    $summary[] = "Ensured default Academic Year `2026-2027` is active and current.";
}
$activeAyLabel = function_exists('get_current_academic_year') ? get_current_academic_year() : '2026-2027';
$summary[] = "Verified Academic Years lookup (`{$activeAyLabel}` current).";

// 3. Ensure Default Cashier Fee Schedules & Items for current Academic Year (if empty)
$feeSchedCount = (int)$conn->query("SELECT COUNT(*) AS c FROM cashier_fee_schedules")->fetch_assoc()['c'];
if ($feeSchedCount === 0) {
    $defaultFeeItems = [
        ['Board and Lodging Fee', 20000.00, 1],
        ['Bus Fee', 1500.00, 2],
        ['Computers Fee', 1000.00, 3],
        ['Miscellaneous Fee', 500.00, 4],
        ['Rice Fee', 0.00, 5],
    ];
    $stmtSched = $conn->prepare("INSERT INTO cashier_fee_schedules (academic_year, semester, program, year_level, is_active, created_by) VALUES (?, ?, NULL, NULL, 1, ?)");
    $stmtItem = $conn->prepare("INSERT INTO cashier_fee_items (schedule_id, description, amount, sort_order) VALUES (?, ?, ?, ?)");
    foreach (['1st', '2nd'] as $sem) {
        $stmtSched->bind_param('ssi', $activeAyLabel, $sem, $adminId);
        $stmtSched->execute();
        $schedId = (int)$conn->insert_id;
        foreach ($defaultFeeItems as [$desc, $amt, $ord]) {
            $stmtItem->bind_param('isdi', $schedId, $desc, $amt, $ord);
            $stmtItem->execute();
        }
    }
    $stmtSched->close();
    $stmtItem->close();
    $summary[] = "Seeded default Cashier fee schedules & fee items for AY {$activeAyLabel} (1st & 2nd Semesters).";
} else {
    $summary[] = "Verified existing Cashier fee schedules ({$feeSchedCount} schedules preserved).";
}

// 4. Verify Default Lodging Room
$roomCount = (int)$conn->query("SELECT COUNT(*) AS c FROM lodging_rooms")->fetch_assoc()['c'];
if ($roomCount === 0) {
    $conn->query("INSERT INTO lodging_rooms (name, capacity, monthly_rate, is_active) VALUES ('Dormitory', 1, 0.00, 1)");
    $roomCount = 1;
}
$summary[] = "Verified Lodging Rooms lookup ({$roomCount} active room record(s)).";

// 5. Verify Master Subjects, Curricula, and 4 Formation Pillar Questionnaires
$lookupChecks = [
    'subjects' => 'Courses / Master Subjects',
    'curriculum_versions' => 'Curriculum Programs',
    'curriculum_subjects' => 'Curriculum Subject Mappings',
    'academic_evaluation_questions' => 'Academic Pillar Questions',
    'human_questions' => 'Human Pillar Questions',
    'spiritual_questions' => 'Spiritual Pillar Questions',
    'pastoral_questions' => 'Pastoral Pillar Questions',
];
foreach ($lookupChecks as $tbl => $label) {
    $c = (int)$conn->query("SELECT COUNT(*) AS c FROM `{$tbl}`")->fetch_assoc()['c'];
    $summary[] = sprintf("Verified %-32s %d rows.", $label . ':', $c);
}

echo "\nResults:\n";
foreach ($summary as $line) {
    echo "  - {$line}\n";
}
echo "\nProduction seed complete (zero dummy students or test transactions created).\n";
exit(0);
