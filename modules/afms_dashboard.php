<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Academic Formator', 'Registrar', 'Dean']);
include '../includes/db.php';
include '../includes/academic_module.php';

$role = (string)($_SESSION['role'] ?? '');
$can_edit = ($role === 'AcademicFormator' || $role === 'Academic Formator');

$current_ay = get_current_academic_year();
$default_semester = function_exists('get_active_semester') ? get_active_semester() : '1st';
if (!in_array($default_semester, ['1st', '2nd', 'Summer'], true)) {
    $default_semester = '1st';
}

$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') {
    $filter_academic_year = $current_ay;
}
$filter_semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st', '2nd', 'Summer'], true)) {
    $filter_semester = $default_semester;
}

$ay_options = [$current_ay];
$ay_res = $conn->query("(SELECT DISTINCT academic_year FROM gpa_records WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM student_grades WHERE academic_year IS NOT NULL AND academic_year <> '') UNION (SELECT DISTINCT academic_year FROM semester_subjects WHERE academic_year IS NOT NULL AND academic_year <> '') ORDER BY academic_year DESC");
if ($ay_res) {
    while ($r = $ay_res->fetch_assoc()) {
        $v = trim((string)($r['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

$total_seminarians = 0;
$regular_count = 0;
$irregular_count = 0;
$stmt_total = $conn->prepare("SELECT COUNT(*) as c FROM seminarians s JOIN users u ON u.id = s.user_id WHERE u.role = 'Student'");
$stmt_total->execute();
$total_seminarians = (int)($stmt_total->get_result()->fetch_assoc()['c'] ?? 0);

$stmt_status = $conn->prepare("SELECT academic_status, COUNT(*) as c
    FROM seminarians s JOIN users u ON u.id = s.user_id
    WHERE u.role = 'Student'
    GROUP BY academic_status");
$stmt_status->execute();
$status_res = $stmt_status->get_result();
while ($r = $status_res->fetch_assoc()) {
    $st = (string)($r['academic_status'] ?? '');
    $c = (int)($r['c'] ?? 0);
    if ($st === 'Irregular') $irregular_count = $c;
    else $regular_count += $c;
}

$under_monitoring = 0;
$stmt_monitor = $conn->prepare("SELECT COUNT(DISTINCT student_id) as c
    FROM gpa_records
    WHERE academic_year = ? AND semester = ? AND semester_gpa IS NOT NULL AND semester_gpa > 3.00");
$stmt_monitor->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_monitor->execute();
$under_monitoring = (int)($stmt_monitor->get_result()->fetch_assoc()['c'] ?? 0);

$growth_count = 0;
$stmt_growth = $conn->prepare("SELECT COUNT(*) as c
    FROM academic_progress
    WHERE academic_year = ? AND semester = ? AND growth_status = 'Growing'");
$stmt_growth->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_growth->execute();
$growth_count = (int)($stmt_growth->get_result()->fetch_assoc()['c'] ?? 0);

$avg_gpa = null;
$stmt_avg = $conn->prepare("SELECT AVG(semester_gpa) as g FROM gpa_records WHERE academic_year = ? AND semester = ? AND semester_gpa IS NOT NULL");
$stmt_avg->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_avg->execute();
$avg_row = $stmt_avg->get_result()->fetch_assoc();
if ($avg_row && $avg_row['g'] !== null) {
    $avg_gpa = (float)$avg_row['g'];
}

$recent_updates = [];
$stmt_recent = $conn->prepare("SELECT sg.grade_id, sg.updated_at, sg.created_at, sg.academic_year, sg.semester,
        u.username, up.full_name, sub.subject_code, sub.subject_name, sg.final_computed_grade
    FROM student_grades sg
    JOIN seminarians sem ON sem.id = sg.student_id
    JOIN users u ON u.id = sem.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    JOIN subjects sub ON sub.subject_id = sg.subject_id
    WHERE sg.academic_year = ? AND sg.semester = ?
    ORDER BY COALESCE(sg.updated_at, sg.created_at) DESC, sg.grade_id DESC
    LIMIT 10");
$stmt_recent->bind_param('ss', $filter_academic_year, $filter_semester);
$stmt_recent->execute();
$res_recent = $stmt_recent->get_result();
while ($row = $res_recent->fetch_assoc()) {
    $recent_updates[] = $row;
}
?>
<?php
$pageTitle = 'Academic Formation Management';
$activeNav = 'afms_dashboard';
$suppressHeader = true;
$headerMbClass = 'mb-0';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <!-- Main Content Area (Everything to the right of the sidebar): Responsive 2-Column Grid Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start font-['Inter',sans-serif]">
            <!-- LEFT COLUMN (lg:col-span-2): Top Header, Stat Cards, Recent Grade Updates & Action Sections -->
            <div class="lg:col-span-2 flex flex-col gap-6 min-w-0">
                <!-- 1. Top Header ('Academic Formation Management', search bar, and term filters) -->
                <?php require __DIR__ . '/../includes/header.php'; ?>

                <!-- 2. 4 Stat Cards in a Responsive Horizontal Row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <!-- Card 1: Seminarians -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <span class="block text-[10px] font-bold text-[#8B7168] tracking-wider uppercase truncate">Seminarians</span>
                            <div class="text-xl font-black text-[#59443F] leading-none mt-1">
                                <?= htmlspecialchars((string)((int)($total_seminarians ?? 0))) ?>
                            </div>
                            <span class="text-[10px] font-medium text-[#8B7168] mt-1 block truncate">Total Enrolled</span>
                        </div>
                        <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-2xs shrink-0">
                            <i class="fa-solid fa-users text-[#59443F]"></i>
                        </div>
                    </div>

                    <!-- Card 2: Regular -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <span class="block text-[10px] font-bold text-[#8B7168] tracking-wider uppercase truncate">Regular</span>
                            <div class="text-xl font-black text-[#59443F] leading-none mt-1">
                                <?= htmlspecialchars((string)((int)($regular_count ?? 0))) ?>
                            </div>
                            <span class="text-[10px] font-medium text-[#8B7168] mt-1 block truncate">Good Standing</span>
                        </div>
                        <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-2xs shrink-0">
                            <i class="fa-solid fa-user-check text-[#59443F]"></i>
                        </div>
                    </div>

                    <!-- Card 3: Irregular -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <span class="block text-[10px] font-bold text-[#8B7168] tracking-wider uppercase truncate">Irregular</span>
                            <div class="text-xl font-black text-[#59443F] leading-none mt-1">
                                <?= htmlspecialchars((string)((int)($irregular_count ?? 0))) ?>
                            </div>
                            <span class="text-[10px] font-medium text-[#8B7168] mt-1 block truncate"><?= ((int)($irregular_count ?? 0)) > 0 ? 'Deficient / Backload' : 'All Regular' ?></span>
                        </div>
                        <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-2xs shrink-0">
                            <i class="fa-solid fa-triangle-exclamation text-[#59443F]"></i>
                        </div>
                    </div>

                    <!-- Card 4: Monitoring -->
                    <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <span class="block text-[10px] font-bold text-[#8B7168] tracking-wider uppercase truncate">Monitoring</span>
                            <div class="text-xl font-black text-[#59443F] leading-none mt-1">
                                <?= htmlspecialchars((string)((int)($under_monitoring ?? 0))) ?>
                            </div>
                            <span class="text-[10px] font-medium text-[#8B7168] mt-1 block truncate">Observation</span>
                        </div>
                        <div class="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-2xs shrink-0">
                            <i class="fa-solid fa-eye text-[#59443F]"></i>
                        </div>
                    </div>
                </div>

                <!-- 4. Recent Grade Updates Table -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-xl overflow-hidden font-['Inter',sans-serif]">
                    <div class="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-solid fa-clock-rotate-left text-base text-[#59443F]"></i>
                            </div>
                            <div>
                                <h2 class="font-heading font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">Recent Grade Updates</h2>
                                <p class="text-xs text-[#8B7168] font-medium">Latest evaluated and posted academic scores</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2.5 self-start sm:self-center flex-wrap sm:flex-nowrap">
                            <div class="relative w-full sm:w-56">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-[#8B7168]"></i>
                                <input type="text" id="gradeUpdatesSearch" onkeyup="filterGradeUpdates(this.value)" class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]" placeholder="Filter grades...">
                            </div>
                            <a href="afms_grades.php?academic_year=<?php echo htmlspecialchars(urlencode($filter_academic_year)); ?>&semester=<?php echo htmlspecialchars(urlencode($filter_semester)); ?>" 
                               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition shadow-xs self-start sm:self-center whitespace-nowrap">
                                <i class="fa-solid fa-pen-to-square text-xs"></i> Grade Manager
                            </a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="recentGradeUpdatesTable" class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                                    <th class="py-3 px-5 sm:px-6">Seminarian</th>
                                    <th class="py-3 px-4">Subject</th>
                                    <th class="py-3 px-4 text-center">Final Grade</th>
                                    <th class="py-3 px-5 sm:px-6 text-right">Updated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/20">
                                <?php if (count($recent_updates) === 0) { ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-10 px-4 text-[#8B7168] font-medium text-xs">
                                            <i class="fa-solid fa-folder-open text-2xl mb-2 block text-[#D6A6A6]"></i>
                                            No grade updates recorded yet for this term.
                                        </td>
                                    </tr>
                                <?php } ?>
                                <?php foreach ($recent_updates as $r) {
                                    $name = trim((string)($r['full_name'] ?? ''));
                                    if ($name === '') $name = (string)($r['username'] ?? '');
                                    $dt = (string)($r['updated_at'] ?? $r['created_at'] ?? '');
                                ?>
                                    <tr class="hover:bg-[#F8EEEE]/40 transition-colors">
                                        <td class="py-3.5 px-5 sm:px-6">
                                            <div class="font-bold text-[#59443F] text-sm"><?php echo htmlspecialchars($name); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-[#59443F] text-xs tracking-tight"><?php echo htmlspecialchars((string)($r['subject_code'] ?? '')); ?></div>
                                            <div class="text-[11px] text-[#8B7168] font-medium truncate max-w-xs"><?php echo htmlspecialchars((string)($r['subject_name'] ?? '')); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <?php if ($r['final_computed_grade'] === null): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60">N/A</span>
                                            <?php else: 
                                                $score = (float)$r['final_computed_grade'];
                                                $isPct = $score > 5.0;
                                                $isPass = $isPct ? ($score >= 75.0) : ($score <= 3.0);
                                                $badgeClass = $isPass ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200';
                                            ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-black border <?php echo $badgeClass; ?>">
                                                    <?php echo htmlspecialchars(number_format($score, 2)) . ($isPct ? '%' : ''); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-5 sm:px-6 text-right text-xs font-semibold text-[#8B7168]">
                                            <?php echo $dt !== '' ? htmlspecialchars(date('M d, Y', strtotime($dt))) : '—'; ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN (lg:col-span-1): Calendar Widget at the Very Top Right (Flush with Top Header) -->
            <div class="lg:col-span-1 flex flex-col gap-6 min-w-0 self-start">
                <div id="academicCalendarWidget"></div>
            </div>
        </div>

        <script src="<?php echo htmlspecialchars(capstone_url('assets/academic_calendar.js')); ?>?v=<?php echo file_exists(__DIR__ . '/../assets/academic_calendar.js') ? filemtime(__DIR__ . '/../assets/academic_calendar.js') : '1'; ?>"></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('academicCalendarWidget') && window.AcademicCalendar) {
                new AcademicCalendar('academicCalendarWidget', { 
                    baseUrl: 'ajax.php',
                    timetableUrl: 'timetable.php',
                    readOnly: true,
                    selectable: false,
                    canManage: false,
                    enableDummyData: true
                });
            }
        });

        function filterGradeUpdates(q) {
            q = (q || '').toLowerCase();
            document.querySelectorAll('#recentGradeUpdatesTable tbody tr').forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        }
        </script>

<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
