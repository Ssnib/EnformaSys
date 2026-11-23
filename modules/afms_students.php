<?php
include '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
include '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';

$role = (string)($_SESSION['role'] ?? '');
$can_edit = $role === 'AcademicFormator';

if ($can_edit && isset($_POST['update_student'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $student_id = (int)($_POST['student_id'] ?? 0);
    $year_level = trim((string)($_POST['year_level'] ?? ''));
    $formation_stage = trim((string)($_POST['formation_stage'] ?? ''));
    $enrollment_status = trim((string)($_POST['enrollment_status'] ?? ''));
    $academic_remarks = trim((string)($_POST['academic_remarks'] ?? ''));
    $academic_view_enabled = isset($_POST['academic_view_enabled']) ? 1 : 0;

    if ($student_id > 0) {
        $stmt = $conn->prepare("UPDATE seminarians SET year_level = NULLIF(?,''), formation_stage = NULLIF(?,''), enrollment_status = NULLIF(?,''), academic_remarks = NULLIF(?,''), academic_view_enabled = ? WHERE id = ?");
        $stmt->bind_param('ssssii', $year_level, $formation_stage, $enrollment_status, $academic_remarks, $academic_view_enabled, $student_id);
        $stmt->execute();
        $success = 'Academic record updated.';
    }
}

// Single source of truth: all Student seminarians aligned with Registrar & Cashier via enrollments
$students = afms_get_canonical_students($conn);

// Fetch available academic years (include active enrollment years)
$ay_options = get_all_academic_years();
$res_enr_ays = $conn->query("SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
if ($res_enr_ays) {
    while ($ayr = $res_enr_ays->fetch_assoc()) {
        $v = trim((string)($ayr['academic_year'] ?? ''));
        if ($v !== '' && !in_array($v, $ay_options, true)) {
            $ay_options[] = $v;
        }
    }
}

// Filter parameters from GET if provided
$filter_ay = trim((string)($_GET['academic_year'] ?? ''));
$filter_sem = trim((string)($_GET['semester'] ?? ''));
if ($filter_sem !== '') {
    $filter_sem = afms_normalize_semester_value($filter_sem);
}
$filter_yl = trim((string)($_GET['year_level'] ?? ''));
$filter_standing = trim((string)($_GET['standing'] ?? 'all'));
if (!in_array($filter_standing, ['all', 'regular', 'irregular', 'portal'], true)) {
    $filter_standing = 'all';
}

// Build student terms map across enrollments, enrolled subjects, grades, and profile
$student_terms_map = [];
$add_student_term = function (int $sid, string $ay, string $sem, string $yl = '') use (&$student_terms_map): void {
    $ay = trim($ay);
    if ($sid <= 0 || $ay === '') {
        return;
    }
    $norm_sem = $sem !== '' ? afms_normalize_semester_value($sem) : '';
    $yl = trim($yl);
    if (!empty($student_terms_map[$sid])) {
        foreach ($student_terms_map[$sid] as &$ex) {
            if ($ex['ay'] === $ay && ($ex['sem'] === $norm_sem || $norm_sem === '')) {
                if ($ex['yl'] === '' && $yl !== '') {
                    $ex['yl'] = $yl;
                }
                return;
            }
        }
        unset($ex);
    }
    $student_terms_map[$sid][] = [
        'ay' => $ay,
        'sem' => $norm_sem,
        'yl' => $yl,
    ];
};

// 1. Canonical Enrollments table (matches Registrar & Cashier)
$res_enr_tbl = $conn->query("SELECT student_id, academic_year, semester, year_level FROM enrollments WHERE academic_year IS NOT NULL AND academic_year != ''");
if ($res_enr_tbl) {
    while ($t = $res_enr_tbl->fetch_assoc()) {
        $add_student_term((int)$t['student_id'], (string)$t['academic_year'], (string)$t['semester'], (string)$t['year_level']);
    }
}

// 2. Enrolled subjects
$res_enr = $conn->query("SELECT student_id, academic_year, semester, year_level FROM student_enrolled_subjects WHERE academic_year IS NOT NULL AND academic_year != ''");
if ($res_enr) {
    while ($t = $res_enr->fetch_assoc()) {
        $add_student_term((int)$t['student_id'], (string)$t['academic_year'], (string)$t['semester'], (string)$t['year_level']);
    }
}

// 3. Student grades
$res_grd = $conn->query("SELECT student_id, academic_year, semester FROM student_grades WHERE academic_year IS NOT NULL AND academic_year != ''");
if ($res_grd) {
    while ($t = $res_grd->fetch_assoc()) {
        $add_student_term((int)$t['student_id'], (string)$t['academic_year'], (string)$t['semester'], '');
    }
}

// 4. Legacy grades
$res_leg = $conn->query("SELECT seminarian_id, school_year, term FROM grades WHERE school_year IS NOT NULL AND school_year != ''");
if ($res_leg) {
    while ($t = $res_leg->fetch_assoc()) {
        $add_student_term((int)$t['seminarian_id'], (string)$t['school_year'], (string)$t['term'], '');
    }
}

// 5. Current profile fallback
$active_sem = get_active_semester();
foreach ($students as $s) {
    $sid = (int)$s['student_id'];
    $cur_ay = trim((string)($s['current_academic_year'] ?? ''));
    $cur_yl = trim((string)($s['year_level'] ?? ''));
    if ($cur_ay !== '') {
        $add_student_term($sid, $cur_ay, $active_sem, $cur_yl);
    }
}

?>
<?php
$pageTitle = 'Academic Records';
$activeNav = 'afms_students';
require_once __DIR__ . '/../includes/layout_app_start.php';

/**
 * Compute 1-2 character uppercase initials for student avatar circle.
 */
function afms_student_initials(string $name): string {
    $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($clean === '') return 'S';
    $parts = explode(' ', $clean);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(mb_substr($clean, 0, min(2, mb_strlen($clean))));
}

$pillCls = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap';
$ghostBtnCls = 'inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors p-2 rounded-md cursor-pointer bg-transparent border-0 no-underline';
$activeSegCls = 'bg-white text-gray-900 shadow-xs font-semibold';
$inactiveSegCls = 'text-gray-500 hover:text-gray-800 font-medium';
?>
        <div class="space-y-5">
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 tracking-tight m-0 flex items-center gap-2.5">
                        <i class="fas fa-users text-teal-700"></i>
                        <span>Student Academic Records</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium mt-1 mb-0">
                        Monitor year level advancement, standing, and portal visibility.
                    </p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/70 self-start sm:self-center">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                    <span>Total: <span id="studentCountDisplay" class="font-bold"><?php echo count($students); ?></span> Seminarians</span>
                </span>
            </div>

            <?php if (isset($success)) { ?>
                <div class="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2.5 shadow-2xs">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php } ?>

            <!-- Consolidated Table Card -->
            <div class="bg-white rounded-xl border border-gray-200/90 shadow-sm overflow-hidden">
                <!-- Unified Action Bar: Segmented Standing Tabs + Search + Dropdown Filters + Reset -->
                <div class="bg-white p-4 rounded-t-xl border-b border-gray-100 flex flex-wrap gap-3 items-center justify-between">
                    <!-- Sleek Segmented Control -->
                    <div class="inline-flex items-center p-1 bg-gray-100 rounded-lg gap-1" id="studentStatusPills" role="tablist" aria-label="Filter by academic standing">
                        <button type="button" style="width: auto;" class="pill-btn px-3 py-1.5 rounded-md text-xs transition cursor-pointer border-0 <?php echo $filter_standing === 'all' ? 'active ' . $activeSegCls : $inactiveSegCls; ?>" data-filter="all">All</button>
                        <button type="button" style="width: auto;" class="pill-btn px-3 py-1.5 rounded-md text-xs transition cursor-pointer border-0 <?php echo $filter_standing === 'regular' ? 'active ' . $activeSegCls : $inactiveSegCls; ?>" data-filter="regular">Regular</button>
                        <button type="button" style="width: auto;" class="pill-btn px-3 py-1.5 rounded-md text-xs transition cursor-pointer border-0 <?php echo $filter_standing === 'irregular' ? 'active ' . $activeSegCls : $inactiveSegCls; ?>" data-filter="irregular">Irregular</button>
                        <button type="button" style="width: auto;" class="pill-btn px-3 py-1.5 rounded-md text-xs transition cursor-pointer border-0 <?php echo $filter_standing === 'portal' ? 'active ' . $activeSegCls : $inactiveSegCls; ?>" data-filter="portal">Portal Active</button>
                    </div>

                    <!-- Search + Term Dropdown Filters + Reset -->
                    <div class="flex flex-wrap items-center gap-2.5 flex-1 justify-end">
                        <div class="relative w-full sm:w-64">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                            <input type="text"
                                   id="studentSearch"
                                   placeholder="Search name, ID, username..."
                                   autocomplete="off"
                                   class="w-full pl-8 pr-8 py-2 text-xs text-gray-800 bg-gray-50 border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                            <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[10px] font-semibold text-gray-400 bg-white border border-gray-200 rounded absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none">/</kbd>
                        </div>

                        <select id="filterAcademicYear" title="Academic Year" class="w-full sm:w-auto px-3 py-2 text-xs font-medium text-gray-700 bg-gray-50 border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition cursor-pointer">
                            <option value="">All Academic Years</option>
                            <?php foreach ($ay_options as $ay) { ?>
                                <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $filter_ay ? 'selected' : ''; ?>>
                                    AY <?php echo htmlspecialchars($ay); ?>
                                </option>
                            <?php } ?>
                        </select>

                        <select id="filterSemester" title="Semester" class="w-full sm:w-auto px-3 py-2 text-xs font-medium text-gray-700 bg-gray-50 border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition cursor-pointer">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo $filter_sem === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $filter_sem === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                            <option value="Summer" <?php echo $filter_sem === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                        </select>

                        <select id="filterYearLevel" title="Year Level / Stage" class="w-full sm:w-auto px-3 py-2 text-xs font-medium text-gray-700 bg-gray-50 border !border-gray-200 !rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition cursor-pointer">
                            <option value="">All Levels</option>
                            <option value="Propaedeutic Stage" <?php echo $filter_yl === 'Propaedeutic Stage' ? 'selected' : ''; ?>>Propaedeutic Stage</option>
                            <option value="1st Year" <?php echo $filter_yl === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2nd Year" <?php echo $filter_yl === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3rd Year" <?php echo $filter_yl === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4th Year" <?php echo $filter_yl === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                        </select>

                        <button type="button" id="resetFiltersBtn" style="display: none; width: auto;" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 border-0 transition cursor-pointer" title="Reset all filters">
                            <i class="fas fa-undo text-[11px]"></i>
                            <span>Reset</span>
                        </button>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full border-collapse !mt-0 text-left" id="studentsTable">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap min-w-[230px]">Student Information</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap">Year Level</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap">Formation Stage</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap">Academic Standing</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap">Enrollment</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap">Portal View</th>
                                <th class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider py-3.5 px-4 whitespace-nowrap text-right min-w-[130px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php if (count($students) === 0) { ?>
                                <tr>
                                    <td colspan="7" class="py-10 px-6 text-center text-sm text-gray-500">No seminarians found.</td>
                                </tr>
                            <?php } else { foreach ($students as $s) {
                                $sid = (int)$s['student_id'];
                                $name = trim((string)($s['full_name'] ?? ''));
                                if ($name === '') $name = (string)($s['username'] ?? '');
                                $initials = afms_student_initials($name);
                                $is_irregular = (string)($s['academic_status'] ?? '') === 'Irregular';
                                $portal_on = (int)($s['academic_view_enabled'] ?? 0) === 1;
                                $enr_status = trim((string)($s['enrollment_status'] ?? ''));
                                $is_enrolled_active = in_array($enr_status, ['Enrolled', 'Active'], true);
                                $st_terms = $student_terms_map[$sid] ?? [];
                            ?>
                                <tr class="student-row hover:bg-gray-50/80 transition-colors"
                                    data-search="<?php echo htmlspecialchars(strtolower($name . ' ' . $s['username'] . ' ' . $sid . ' ' . ($s['year_level'] ?? ''))); ?>"
                                    data-standing="<?php echo $is_irregular ? 'irregular' : 'regular'; ?>"
                                    data-portal="<?php echo $portal_on ? '1' : '0'; ?>"
                                    data-terms='<?php echo htmlspecialchars(json_encode($st_terms), ENT_QUOTES, 'UTF-8'); ?>'
                                    data-current-ay="<?php echo htmlspecialchars((string)($s['current_academic_year'] ?? '')); ?>"
                                    data-current-yl="<?php echo htmlspecialchars((string)($s['year_level'] ?? '')); ?>">
                                    <td class="student-name py-4 px-4 align-middle">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-slate-100 text-slate-700 border border-slate-200/80 flex items-center justify-center text-xs font-bold shrink-0">
                                                <?php echo htmlspecialchars($initials); ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-semibold text-sm text-gray-900 leading-tight truncate"><?php echo htmlspecialchars($name); ?></div>
                                                <div class="text-xs text-gray-500 mt-0.5 truncate">
                                                    #<?php echo (int)$s['student_id']; ?> &bull; <span class="font-mono"><?php echo htmlspecialchars((string)$s['username']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-4 px-4 align-middle whitespace-nowrap">
                                        <span class="<?php echo $pillCls; ?> bg-slate-100 text-slate-700 border border-slate-200/70">
                                            <?php echo htmlspecialchars(trim((string)($s['year_level'] ?? '')) !== '' ? (string)$s['year_level'] : '—'); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 align-middle">
                                        <span class="text-xs font-medium text-gray-700"><?php echo htmlspecialchars(trim((string)($s['formation_stage'] ?? '')) !== '' ? (string)$s['formation_stage'] : '—'); ?></span>
                                    </td>
                                    <td class="py-4 px-4 align-middle whitespace-nowrap">
                                        <?php if ($is_irregular) { ?>
                                            <span class="<?php echo $pillCls; ?> bg-rose-50 text-rose-700 border border-rose-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                                Irregular
                                            </span>
                                        <?php } else { ?>
                                            <span class="<?php echo $pillCls; ?> bg-emerald-50 text-emerald-700 border border-emerald-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Regular
                                            </span>
                                        <?php } ?>
                                    </td>
                                    <td class="py-4 px-4 align-middle whitespace-nowrap">
                                        <?php if ($is_enrolled_active) { ?>
                                            <span class="<?php echo $pillCls; ?> bg-blue-50 text-blue-700 border border-blue-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                                <?php echo htmlspecialchars($enr_status); ?>
                                            </span>
                                        <?php } else { ?>
                                            <span class="<?php echo $pillCls; ?> bg-gray-100 text-gray-600 border border-gray-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                                <?php echo htmlspecialchars($enr_status !== '' ? $enr_status : '—'); ?>
                                            </span>
                                        <?php } ?>
                                    </td>
                                    <td class="py-4 px-4 align-middle whitespace-nowrap">
                                        <?php if ($portal_on) { ?>
                                            <span class="<?php echo $pillCls; ?> bg-emerald-50 text-emerald-700 border border-emerald-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Enabled
                                            </span>
                                        <?php } else { ?>
                                            <span class="<?php echo $pillCls; ?> bg-amber-50 text-amber-700 border border-amber-200/70">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                Disabled
                                            </span>
                                        <?php } ?>
                                    </td>
                                    <td class="py-4 px-4 align-middle text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1">
                                            <a class="<?php echo $ghostBtnCls; ?>" href="afms_student_record.php?student_id=<?php echo (int)$s['student_id']; ?>" title="View Academic Record">
                                                <i class="fas fa-folder-open text-xs"></i>
                                                <span>Record</span>
                                            </a>
                                            <?php if ($can_edit) { ?>
                                                <button type="button"
                                                    style="width: auto;"
                                                    class="editStudentBtn <?php echo $ghostBtnCls; ?>"
                                                    title="Edit Academic Record"
                                                    data-student-id="<?php echo (int)$s['student_id']; ?>"
                                                    data-student-year-level="<?php echo htmlspecialchars((string)($s['year_level'] ?? '')); ?>"
                                                    data-student-formation-stage="<?php echo htmlspecialchars((string)($s['formation_stage'] ?? '')); ?>"
                                                    data-student-enrollment-status="<?php echo htmlspecialchars((string)($s['enrollment_status'] ?? '')); ?>"
                                                    data-student-remarks="<?php echo htmlspecialchars((string)($s['academic_remarks'] ?? '')); ?>"
                                                    data-student-portal="<?php echo (int)($s['academic_view_enabled'] ?? 0); ?>">
                                                    <i class="fas fa-pen text-xs"></i>
                                                </button>
                                            <?php } ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php } } ?>
                            <tr id="noResultsRow" style="display: none;">
                                <td colspan="7" class="py-10 px-6 text-center text-sm text-gray-500">
                                    <i class="fas fa-filter text-lg mb-2 block text-gray-400"></i>
                                    No seminarians match the selected filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($can_edit) { ?>
        <div class="modal" data-global-modal="1" id="editStudentModal" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editStudentTitle">
                <div class="modal-header">
                    <div class="modal-title" id="editStudentTitle"><i class="fas fa-pen"></i> Update Academic Record</div>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="post" id="editStudentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="student_id" id="editStudentId" value="">

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                            <div class="field">
                                <label>Year Level</label>
                                <input type="text" name="year_level" id="editYearLevel" placeholder="e.g. 1st Year">
                            </div>
                            <div class="field">
                                <label>Formation Stage</label>
                                <input type="text" name="formation_stage" id="editFormationStage" placeholder="e.g. Propaedeutic">
                            </div>
                            <div class="field">
                                <label>Enrollment Status</label>
                                <input type="text" name="enrollment_status" id="editEnrollmentStatus" placeholder="Enrolled">
                            </div>
                            <div class="field" style="display: flex; align-items: center; gap: 0.75rem;">
                                <input type="checkbox" id="editPortalEnabled" name="academic_view_enabled" value="1" style="width: 20px; height: 20px;">
                                <label for="editPortalEnabled" style="margin: 0;">Allow student view-only access</label>
                            </div>
                        </div>

                        <div class="field" style="margin-top: 1rem;">
                            <label>Academic Remarks</label>
                            <textarea name="academic_remarks" id="editAcademicRemarks" rows="4" placeholder="Formator remarks..."></textarea>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="modal-close" data-modal-close>Cancel</button>
                            <button type="submit" name="update_student">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php } ?>

        <script>
        (function() {
            const searchInput = document.getElementById('studentSearch');
            const pills = document.querySelectorAll('#studentStatusPills .pill-btn');
            const aySelect = document.getElementById('filterAcademicYear');
            const semSelect = document.getElementById('filterSemester');
            const ylSelect = document.getElementById('filterYearLevel');
            const resetBtn = document.getElementById('resetFiltersBtn');
            const rows = document.querySelectorAll('.student-row');
            const countDisplay = document.getElementById('studentCountDisplay');
            const noResultsRow = document.getElementById('noResultsRow');

            let currentFilter = document.querySelector('#studentStatusPills .pill-btn.active')?.getAttribute('data-filter') || 'all';

            function normalizeYearLevel(yl) {
                if (!yl) return '';
                const s = yl.toString().toLowerCase().trim();
                if (s.includes('propaedeutic')) return 'Propaedeutic Stage';
                if (s.includes('first') || s.includes('1st') || s === '1') return '1st Year';
                if (s.includes('second') || s.includes('2nd') || s === '2') return '2nd Year';
                if (s.includes('third') || s.includes('3rd') || s === '3') return '3rd Year';
                if (s.includes('fourth') || s.includes('4th') || s === '4') return '4th Year';
                return s;
            }

            function normalizeSemester(sem) {
                if (!sem) return '';
                const s = sem.toString().toLowerCase().trim();
                if (s.startsWith('1') || s.includes('first')) return '1st';
                if (s.startsWith('2') || s.includes('second')) return '2nd';
                if (s.includes('summer')) return 'Summer';
                return s;
            }

            function normalizeAcademicYear(ay) {
                if (!ay) return '';
                return ay.toString().replace(/^ay\s*/i, '').trim();
            }

            function filterStudents() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                const filterAy = normalizeAcademicYear(aySelect?.value || '');
                const filterSem = normalizeSemester(semSelect?.value || '');
                const filterYl = normalizeYearLevel(ylSelect?.value || '');

                let visibleCount = 0;

                rows.forEach(row => {
                    const searchData = (row.getAttribute('data-search') || '').toLowerCase();
                    const standing = row.getAttribute('data-standing') || 'regular';
                    const portal = row.getAttribute('data-portal') || '0';
                    const defaultYl = row.getAttribute('data-current-yl') || '';

                    // 1. Search Query Match
                    const matchesSearch = !q || searchData.includes(q);

                    // 2. Standing / Status Filter Match
                    let matchesStanding = true;
                    if (currentFilter === 'regular') matchesStanding = (standing === 'regular');
                    else if (currentFilter === 'irregular') matchesStanding = (standing === 'irregular');
                    else if (currentFilter === 'portal') matchesStanding = (portal === '1');

                    // 3. Dropdown Term Filters Match (Simultaneous!)
                    let matchesTerms = true;
                    if (filterAy || filterSem || filterYl) {
                        let terms = [];
                        try {
                            terms = JSON.parse(row.getAttribute('data-terms') || '[]');
                        } catch (e) {
                            terms = [];
                        }

                        matchesTerms = terms.some(t => {
                            const tAy = normalizeAcademicYear(t.ay || '');
                            const tSem = normalizeSemester(t.sem || '');
                            const tYl = normalizeYearLevel(t.yl || defaultYl || '');

                            const ayOk = !filterAy || (tAy === filterAy);
                            const semOk = !filterSem || (tSem === filterSem);
                            const ylOk = !filterYl || (tYl === filterYl);

                            return ayOk && semOk && ylOk;
                        });
                    }

                    const isVisible = matchesSearch && matchesStanding && matchesTerms;
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });

                if (countDisplay) {
                    countDisplay.textContent = visibleCount;
                }

                if (noResultsRow) {
                    noResultsRow.style.display = (visibleCount === 0) ? '' : 'none';
                }

                // Toggle Reset button visibility
                const isFiltered = (q !== '') || (currentFilter !== 'all') || (aySelect && aySelect.value !== '') || (semSelect && semSelect.value !== '') || (ylSelect && ylSelect.value !== '');
                if (resetBtn) {
                    resetBtn.style.display = isFiltered ? 'inline-flex' : 'none';
                }

                // Keep URL search params synchronized (browser history friendly)
                try {
                    const url = new URL(window.location.href);
                    if (filterAy && aySelect) url.searchParams.set('academic_year', aySelect.value); else url.searchParams.delete('academic_year');
                    if (filterSem && semSelect) url.searchParams.set('semester', semSelect.value); else url.searchParams.delete('semester');
                    if (filterYl && ylSelect) url.searchParams.set('year_level', ylSelect.value); else url.searchParams.delete('year_level');
                    if (currentFilter && currentFilter !== 'all') url.searchParams.set('standing', currentFilter); else url.searchParams.delete('standing');
                    window.history.replaceState({}, '', url.toString());
                } catch (e) {}
            }

            searchInput?.addEventListener('input', filterStudents);
            aySelect?.addEventListener('change', filterStudents);
            semSelect?.addEventListener('change', filterStudents);
            ylSelect?.addEventListener('change', filterStudents);

            const ACTIVE_SEG_CLASSES = ['active', 'bg-white', 'text-gray-900', 'shadow-xs', 'font-semibold'];
            const INACTIVE_SEG_CLASSES = ['text-gray-500', 'hover:text-gray-800', 'font-medium'];

            function setActiveSegmentedPill(targetFilter) {
                pills.forEach(p => {
                    const isAct = (p.getAttribute('data-filter') || 'all') === targetFilter;
                    ACTIVE_SEG_CLASSES.forEach(c => p.classList.toggle(c, isAct));
                    INACTIVE_SEG_CLASSES.forEach(c => p.classList.toggle(c, !isAct));
                });
            }

            pills.forEach(pill => {
                pill.addEventListener('click', () => {
                    currentFilter = pill.getAttribute('data-filter') || 'all';
                    setActiveSegmentedPill(currentFilter);
                    filterStudents();
                });
            });

            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    currentFilter = 'all';
                    setActiveSegmentedPill('all');
                    if (aySelect) aySelect.value = '';
                    if (semSelect) semSelect.value = '';
                    if (ylSelect) ylSelect.value = '';
                    filterStudents();
                });
            }

            // Run initial filter on page load
            filterStudents();
        })();

        (function () {
            const modal = document.getElementById('editStudentModal');
            if (!modal) return;

            const idEl = document.getElementById('editStudentId');
            const yearEl = document.getElementById('editYearLevel');
            const stageEl = document.getElementById('editFormationStage');
            const enrollEl = document.getElementById('editEnrollmentStatus');
            const remarksEl = document.getElementById('editAcademicRemarks');
            const portalEl = document.getElementById('editPortalEnabled');

            function openModal(btn) {
                idEl.value = btn.getAttribute('data-student-id') || '';
                yearEl.value = btn.getAttribute('data-student-year-level') || '';
                stageEl.value = btn.getAttribute('data-student-formation-stage') || '';
                enrollEl.value = btn.getAttribute('data-student-enrollment-status') || '';
                remarksEl.value = btn.getAttribute('data-student-remarks') || '';
                portalEl.checked = (btn.getAttribute('data-student-portal') || '0') === '1';
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                yearEl.focus();
            }

            function closeModal() {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            }

            document.querySelectorAll('.editStudentBtn').forEach(function (btn) {
                btn.addEventListener('click', function () { openModal(btn); });
            });
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
            modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
                btn.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
            });
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
