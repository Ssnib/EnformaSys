<?php
/**
 * Idempotent Performance Index Migration for Aiven MySQL / Production DB
 * Checks INFORMATION_SCHEMA.STATISTICS before adding indexes so re-running is safe.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$indexes = [
    ['table' => 'grades', 'name' => 'idx_grades_sem_archived', 'cols' => 'seminarian_id, archived_at'],
    ['table' => 'evaluations', 'name' => 'idx_eval_sem_arch_type', 'cols' => 'seminarian_id, archived_at, type'],
    ['table' => 'seminarian_access_requests', 'name' => 'idx_sar_sem_type_status_read', 'cols' => 'seminarian_id, assignment_type, status, is_read'],
    ['table' => 'cashier_soa_requests', 'name' => 'idx_csr_sem_status_read', 'cols' => 'seminarian_id, status, is_read'],
    ['table' => 'student_enrolled_subjects', 'name' => 'idx_ses_ay_sem_status', 'cols' => 'academic_year, semester, status'],
    ['table' => 'student_enrolled_subjects', 'name' => 'idx_ses_stu_ay_sem_status', 'cols' => 'student_id, academic_year, semester, status'],
    ['table' => 'student_grades', 'name' => 'idx_sg_stu_sub_status', 'cols' => 'student_id, subject_id, status'],
    ['table' => 'lodging_invoices', 'name' => 'idx_li_sem_ay_sem_status', 'cols' => 'seminarian_id, academic_year, semester, status'],
    ['table' => 'lodging_payments', 'name' => 'idx_lp_sem_ay_sem', 'cols' => 'seminarian_id, academic_year, semester'],
    ['table' => 'curriculum_subjects', 'name' => 'idx_cs_ay_yl_sem', 'cols' => 'academic_year_id, year_level, semester'],
];

$created = 0;
$skipped = 0;

foreach ($indexes as $idx) {
    $table = $idx['table'];
    $name = $idx['name'];
    $cols = $idx['cols'];

    $stmtTbl = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmtTbl->bind_param('s', $table);
    $stmtTbl->execute();
    $hasTable = (int)($stmtTbl->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmtTbl->close();
    if (!$hasTable) {
        continue;
    }

    $stmtIdx = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmtIdx->bind_param('ss', $table, $name);
    $stmtIdx->execute();
    $exists = (int)($stmtIdx->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmtIdx->close();

    if ($exists) {
        $skipped++;
        continue;
    }

    $sql = "CREATE INDEX `{$name}` ON `{$table}` ({$cols})";
    if ($conn->query($sql)) {
        echo "CREATED {$table}.{$name} ({$cols})\n";
        $created++;
    } else {
        echo "WARN {$table}.{$name}: {$conn->error}\n";
    }
}

echo "DONE created={$created} skipped={$skipped}\n";
