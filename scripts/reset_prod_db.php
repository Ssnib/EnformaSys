<?php
/**
 * EnformaSys Production Database Cleanup Script
 *
 * Safely removes all dummy/test transactional records while preserving
 * core Administrator / Rector / Staff accounts and essential lookup/master data.
 *
 * Usage:
 *   php scripts/reset_prod_db.php --dry-run
 *   php scripts/reset_prod_db.php --confirm
 *   php scripts/reset_prod_db.php --confirm --admin-rector-only
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once __DIR__ . '/../includes/env.php';

$args = array_slice($argv ?? [], 1);
$confirm = in_array('--confirm', $args, true);
$dryRun = in_array('--dry-run', $args, true) || !$confirm;
$adminRectorOnly = in_array('--admin-rector-only', $args, true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = (string)enformasys_env('DB_HOST', '127.0.0.1');
$dbPort = (int)enformasys_env('DB_PORT', '3306');
$dbUser = (string)enformasys_env('DB_USER', 'root');
$dbPass = (string)enformasys_env('DB_PASS', '');
$dbName = (string)enformasys_env('DB_NAME', 'seminary_db');

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$conn->set_charset('utf8mb4');

/**
 * Transactional/test tables to wipe completely and reset AUTO_INCREMENT = 1.
 * Note: Trigger-bearing tables are deleted BEFORE `history_logs` and `activities`
 * so `BEFORE DELETE` trigger entries are also cleared cleanly.
 */
$transactionalTables = [
    // Evaluation responses, results, remarks, analytics, and evaluations (triggers -> history_logs)
    'academic_evaluation_responses',
    'academic_evaluation_results',
    'academic_evaluations',
    'human_analytics',
    'human_remarks',
    'human_responses',
    'human_results',
    'human_evaluations',
    'pastoral_analytics',
    'pastoral_remarks',
    'pastoral_responses',
    'pastoral_results',
    'pastoral_evaluations',
    'spiritual_analytics',
    'spiritual_remarks',
    'spiritual_responses',
    'spiritual_results',
    'spiritual_evaluations',
    'evaluations',

    // Academic progress, grades, enrollments, and assignments (triggers -> history_logs)
    'academic_notifications',
    'academic_progress',
    'gpa_records',
    'grade_edit_requests',
    'grades',
    'irregular_students',
    'student_enrolled_subjects',
    'student_grades',
    'student_schedules',
    'subject_teacher_assignments',
    'enrollment_subjects',
    'enrollments',

    // Financial collections, invoices, SOA requests, and lodging stays
    'cashier_payment_items',
    'cashier_soa_requests',
    'lodging_payment_allocations',
    'lodging_invoice_items',
    'lodging_payments',
    'lodging_invoices',
    'lodging_stays',

    // Student profiles, applicants, requests, timetable test events, and backups
    'applicants',
    'seminarian_access_requests',
    'seminarian_assignments',
    'seminarian_documents',
    'seminarians_coupled_backup',
    'seminarians',
    'students',
    'timetable',
    'user_event_notifications_read',
    'user_profiles',

    // Security & Audit logs (MUST run after trigger-bearing tables)
    'login_attempts',
    'activities',
    'history_logs',
];

$preservedLookupTables = [
    'academic_years',
    'academic_evaluation_questions',
    'human_questions',
    'pastoral_questions',
    'spiritual_questions',
    'subjects',
    'curriculum_versions',
    'curriculum_subjects',
    'semester_subjects',
    'cashier_fee_schedules',
    'cashier_fee_items',
    'lodging_rooms',
    'schema_migrations',
];

function existingTablesMap(mysqli $conn): array
{
    $map = [];
    $res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $res->fetch_row()) {
        $map[$row[0]] = true;
    }
    return $map;
}

$existingTables = existingTablesMap($conn);

echo "============================================================\n";
echo "EnformaSys Production Database Reset (" . ($dryRun ? "DRY-RUN" : "EXECUTE") . ")\n";
echo "Target DB: {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n";
echo "============================================================\n\n";

echo "[1/4] Current Preserved Master/Lookup Tables:\n";
foreach ($preservedLookupTables as $tbl) {
    if (!isset($existingTables[$tbl])) {
        continue;
    }
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM `{$tbl}`")->fetch_assoc()['c'];
    echo sprintf("  %-36s %d rows (PRESERVE)\n", $tbl, $cnt);
}

$userWhereDelete = $adminRectorOnly
    ? "role NOT IN ('Dean', 'Rector')"
    : "role = 'Student'";

$usersKeepRes = $conn->query("SELECT id, username, role FROM users WHERE NOT ({$userWhereDelete}) ORDER BY id");
$usersKeep = [];
while ($u = $usersKeepRes->fetch_assoc()) {
    $usersKeep[] = $u;
}
$usersDeleteCnt = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE {$userWhereDelete}")->fetch_assoc()['c'];

echo "\n[2/4] User Accounts Plan:\n";
echo "  Users to KEEP (" . count($usersKeep) . "):\n";
foreach ($usersKeep as $u) {
    echo "    - #{$u['id']} {$u['username']} ({$u['role']})\n";
}
echo "  Users to DELETE: {$usersDeleteCnt} (condition: {$userWhereDelete})\n";

echo "\n[3/4] Transactional/Test Tables Plan:\n";
$totalRowsToClear = 0;
foreach ($transactionalTables as $tbl) {
    if (!isset($existingTables[$tbl])) {
        continue;
    }
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM `{$tbl}`")->fetch_assoc()['c'];
    $totalRowsToClear += $cnt;
    echo sprintf("  %-36s %d rows -> 0 (AUTO_INCREMENT=1)\n", $tbl, $cnt);
}

if ($dryRun) {
    echo "\nDRY-RUN complete. Total transactional rows targeted: {$totalRowsToClear} (+ {$usersDeleteCnt} users).\n";
    echo "Re-run with --confirm to create a pre-cleanup backup and apply changes.\n";
    exit(0);
}

// Create pre-cleanup SQL backup before modifying data
$backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}
$backupFile = $backupDir . DIRECTORY_SEPARATOR . 'pre_prod_cleanup_' . date('Ymd_His') . '.sql';

$mysqldumpCandidates = [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'mysqldump',
];
$mysqldumpBin = null;
foreach ($mysqldumpCandidates as $cand) {
    if ($cand === 'mysqldump' || file_exists($cand)) {
        $mysqldumpBin = $cand;
        break;
    }
}

$backupCreated = false;
if ($mysqldumpBin !== null) {
    $cmd = sprintf(
        '"%s" --host=%s --port=%d --user=%s %s --single-transaction --routines --triggers %s > "%s"',
        $mysqldumpBin,
        $dbHost,
        $dbPort,
        $dbUser,
        $dbPass !== '' ? ('--password=' . escapeshellarg($dbPass)) : '',
        escapeshellarg($dbName),
        $backupFile
    );
    @exec($cmd, $outLines, $exitCode);
    if ($exitCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
        $backupCreated = true;
    }
}

if (!$backupCreated) {
    fwrite(STDERR, "ERROR: Failed to generate pre-cleanup mysqldump backup at {$backupFile}. Aborting cleanup.\n");
    exit(1);
}
echo "\nPre-cleanup backup saved: {$backupFile} (" . filesize($backupFile) . " bytes)\n";

// Find primary admin ID to preserve ownership on fee schedules
$primaryAdminId = null;
$admRes = $conn->query("SELECT id FROM users WHERE role IN ('Dean', 'Rector') ORDER BY FIELD(username, 'admin', 'rector') ASC, id ASC LIMIT 1");
if ($admRow = $admRes->fetch_assoc()) {
    $primaryAdminId = (int)$admRow['id'];
}

if ($primaryAdminId === null) {
    fwrite(STDERR, "ERROR: No Administrator (Dean/Rector) account found to retain. Aborting.\n");
    exit(1);
}

echo "\n[4/4] Executing cleanup...\n";
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
try {
    // Reassign master fee schedule creator references away from accounts being deleted
    if (isset($existingTables['cashier_fee_schedules'])) {
        $stmtFee = $conn->prepare("UPDATE cashier_fee_schedules SET created_by = ? WHERE created_by IS NULL OR created_by IN (SELECT id FROM users WHERE {$userWhereDelete})");
        $stmtFee->bind_param('i', $primaryAdminId);
        $stmtFee->execute();
        $stmtFee->close();
    }

    // Clear transactional tables (before clearing history_logs & activities)
    foreach ($transactionalTables as $tbl) {
        if (!isset($existingTables[$tbl])) {
            continue;
        }
        $conn->query("DELETE FROM `{$tbl}`");
        $conn->query("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
    }

    // Delete student/test accounts while preserving Admin / Rector / Staff
    $conn->query("DELETE FROM users WHERE {$userWhereDelete}");
    $maxUid = (int)$conn->query("SELECT COALESCE(MAX(id), 0) AS m FROM users")->fetch_assoc()['m'];
    $nextUserAi = max(1, $maxUid + 1);
    $conn->query("ALTER TABLE users AUTO_INCREMENT = {$nextUserAi}");
} finally {
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

// Clean dummy uploaded files (keep index.html placeholders)
$uploadDirs = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_pictures',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents',
];
$removedFiles = 0;
foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || strtolower($entry) === 'index.html' || $entry === '.gitkeep') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($full) && @unlink($full)) {
            $removedFiles++;
        }
    }
}

echo "\nCleanup complete. Uploaded test files removed: {$removedFiles}\n";
echo "Verification summary:\n";
foreach ($transactionalTables as $tbl) {
    if (!isset($existingTables[$tbl])) {
        continue;
    }
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM `{$tbl}`")->fetch_assoc()['c'];
    if ($cnt !== 0) {
        fwrite(STDERR, "  WARNING: {$tbl} still has {$cnt} rows!\n");
    }
}
$remUsers = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
echo "  Remaining users: {$remUsers} (next AUTO_INCREMENT: {$nextUserAi})\n";
echo "  Preserved lookup tables verified intact.\n";
exit(0);
