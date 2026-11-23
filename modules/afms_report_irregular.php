<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';

$academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
$semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if ($academic_year === '' || !in_array($semester, ['1st','2nd','Summer'], true)) {
    header('Location: afms_reports.php');
    exit;
}

$stmt = $conn->prepare("SELECT ir.student_id, ir.reason, ir.date_flagged,
        u.username, up.full_name, s.year_level, s.formation_stage
    FROM irregular_students ir
    JOIN seminarians s ON s.id = ir.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE ir.status = 'Active'
      AND ir.academic_year = ?
      AND ir.semester = ?
    ORDER BY COALESCE(up.full_name, u.username) ASC");
$stmt->bind_param('ss', $academic_year, $semester);
$stmt->execute();
$rows = $stmt->get_result();
?>
<?php
$pageTitle = 'Irregular Student Report';
$activeNav = 'afms_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <style>
                @media print {
                    #sidebar, #sidebarBackdrop, .topbar, .back-link, .no-print { display: none !important; }
                    body { background: #fff !important; }
                    .content { margin: 0 !important; padding: 0 !important; }
                    .content-body { padding: 0 !important; }
                    .card, .data-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
                }
            </style>

            <div class="no-print" style="display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem;">
                <a href="afms_reports.php" class="back-link" style="margin: 0;"><i class="fas fa-arrow-left"></i> Back</a>
                <button type="button" onclick="window.print()" style="width: auto; padding: 0.55rem 0.9rem;"><i class="fas fa-print"></i> Print</button>
            </div>

            <div class="card" style="margin-top: 0;">
                <h1 style="margin: 0; font-size: 1.35rem; font-weight: 900; color: #0f172a;">Irregular Student Report</h1>
                <div style="margin-top: 0.35rem; color: #475569; font-weight: 800;"><?php echo htmlspecialchars($academic_year); ?> · <?php echo htmlspecialchars($semester); ?> Semester</div>
            </div>

            <div class="data-card" style="margin-top: 1rem;">
                <div class="data-card-header">
                    <h3><i class="fas fa-triangle-exclamation"></i> Irregular Students</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Year Level</th>
                                <th>Formation Stage</th>
                                <th>Causes of Irregularity</th>
                                <th>Date Flagged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows->num_rows === 0) { ?>
                                <tr><td colspan="5" style="color: var(--text-secondary);">No irregular students for this term.</td></tr>
                            <?php } ?>
                            <?php while ($r = $rows->fetch_assoc()) {
                                $name = trim((string)($r['full_name'] ?? ''));
                                if ($name === '') $name = (string)($r['username'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 900;"><?php echo htmlspecialchars($name); ?></div>
                                        <div style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700;">#<?php echo (int)$r['student_id']; ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($r['year_level'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['formation_stage'] ?? '')); ?></td>
                                    <td style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)$r['reason']); ?></td>
                                    <td style="color: var(--text-secondary);"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['date_flagged']))); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
