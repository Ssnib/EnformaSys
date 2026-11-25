<?php
require_once '../includes/session_fix.php';
require_roles(['AcademicFormator', 'Registrar', 'Dean']);
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/academic_module.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = get_active_academic_year();
$filter_semester = isset($_GET['semester']) ? afms_normalize_semester_value((string)$_GET['semester']) : afms_normalize_semester_value(get_active_semester());

$year_level_options = eval_year_level_filter_options();
$filter_year_level = eval_normalize_year_level_option($_GET['year_level'] ?? '');
$yl_aliases = eval_year_level_sql_aliases($filter_year_level);

$canonical_students = afms_get_canonical_students($conn, $filter_academic_year, $filter_semester);
$students = [];
foreach ($canonical_students as $row) {
    $yl = trim((string)($row['year_level'] ?? ''));
    if (!empty($yl_aliases) && !in_array($yl, $yl_aliases, true)) {
        continue;
    }
    $row['id'] = (int)$row['student_id'];
    $row['display_name'] = (string)$row['full_name'];
    $students[] = $row;
}

ob_start();
?>
<option value="">Select student</option>
<?php foreach ($students as $s) { ?>
    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['display_name']); ?> (#<?php echo (int)$s['id']; ?>)</option>
<?php }
$options_html = (string)ob_get_clean();

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'success' => true,
        'year_level' => $filter_year_level,
        'year_level_label' => $filter_year_level !== '' ? $filter_year_level : 'All Year Levels',
        'count' => count($students),
        'seminarians' => $students,
        'options_html' => $options_html,
    ]);
    exit;
}
?>
<?php
$pageTitle = 'Academic Reports';
$activeNav = 'afms_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="w-full">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Card 1: Individual Grade Report -->
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 m-0">
                        <i class="fas fa-user text-indigo-600"></i>
                        <span>Individual Grade Report</span>
                    </h3>
                    <p class="text-sm text-gray-500 mt-2 mb-6">
                        Includes student info, semester grades, final grades, academic status, growth indicator, and formator remarks.
                    </p>

                    <div class="mt-auto space-y-3">
                        <div class="flex flex-col sm:flex-row gap-3 items-end">
                            <div class="w-full sm:w-44 shrink-0">
                                <label for="yearLevelFilter" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Year Level</label>
                                <select name="year_level" id="yearLevelFilter" aria-label="Year Level"
                                        class="w-full h-10 bg-gray-50 border !border-gray-200 text-sm text-gray-800 !rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#5c3a31] transition cursor-pointer">
                                    <option value="">All Year Levels</option>
                                    <?php foreach ($year_level_options as $yl_opt): ?>
                                        <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yl_opt === $filter_year_level ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="w-full flex-1 min-w-0">
                                <label for="indStudent" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Student</label>
                                <select id="indStudent" aria-label="Student"
                                        class="w-full h-10 bg-gray-50 border !border-gray-200 text-sm text-gray-800 !rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#5c3a31] transition cursor-pointer">
                                    <?php echo $options_html; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="button" id="openIndividualReport" style="width: auto;"
                                    class="btn-seminary-primary bg-[#5c3a31] hover:bg-[#4a2c24] text-white text-sm font-semibold rounded-xl px-5 py-2.5 flex items-center gap-2 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all border-0 cursor-pointer">
                                <i class="fas fa-print text-xs"></i>
                                <span>Generate</span>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Card 2: Irregular Student Report -->
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 m-0">
                        <i class="fas fa-triangle-exclamation text-amber-500"></i>
                        <span>Irregular Student Report</span>
                    </h3>
                    <p class="text-sm text-gray-500 mt-2 mb-6">
                        Lists irregular students, causes of irregularity, and term flagged.
                    </p>

                    <div class="mt-auto flex justify-end pt-1">
                        <button type="button" id="openIrregularReport" style="width: auto;"
                                class="btn-seminary-primary bg-[#5c3a31] hover:bg-[#4a2c24] text-white text-sm font-semibold rounded-xl px-5 py-2.5 flex items-center gap-2 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all border-0 cursor-pointer">
                            <i class="fas fa-print text-xs"></i>
                            <span>Generate</span>
                        </button>
                    </div>
                </section>

                <!-- Card 3: Academic Performance Report -->
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 m-0">
                        <i class="fas fa-ranking-star text-emerald-600"></i>
                        <span>Academic Performance Report</span>
                    </h3>
                    <p class="text-sm text-gray-500 mt-2 mb-6">
                        Shows top performing seminarians, students needing intervention, and final grade rankings.
                    </p>

                    <div class="mt-auto flex justify-end pt-1">
                        <button type="button" id="openPerformanceReport" style="width: auto;"
                                class="btn-seminary-primary bg-[#5c3a31] hover:bg-[#4a2c24] text-white text-sm font-semibold rounded-xl px-5 py-2.5 flex items-center gap-2 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all border-0 cursor-pointer">
                            <i class="fas fa-print text-xs"></i>
                            <span>Generate</span>
                        </button>
                    </div>
                </section>

                <!-- Card 4: Standard Grade Standing Report -->
                <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 m-0">
                        <i class="fas fa-clipboard-check text-teal-600"></i>
                        <span>Standard Grade Standing Report</span>
                    </h3>
                    <p class="text-sm text-gray-500 mt-2 mb-6">
                        Lists seminarians categorized by their final grade standing: Passed (&ge; 88), Passed - Below Standard (75&ndash;87), and Failed (&le; 74).
                    </p>

                    <div class="mt-auto space-y-3">
                        <div class="flex flex-col sm:flex-row gap-3 items-end">
                            <div class="w-full sm:flex-1 min-w-0">
                                <label for="standingYearLevel" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Year Level</label>
                                <select id="standingYearLevel" aria-label="Year Level"
                                        class="w-full h-10 bg-gray-50 border !border-gray-200 text-sm text-gray-800 !rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#5c3a31] transition cursor-pointer">
                                    <option value="">All Year Levels</option>
                                    <?php foreach ($year_level_options as $yl_opt): ?>
                                        <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="w-full sm:flex-1 min-w-0">
                                <label for="standingGradeFilter" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Grade Standing</label>
                                <select id="standingGradeFilter" aria-label="Grade Standing"
                                        class="w-full h-10 bg-gray-50 border !border-gray-200 text-sm text-gray-800 !rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#5c3a31] transition cursor-pointer">
                                    <option value="all">All Standings</option>
                                    <option value="passed">Passed (&ge; 88)</option>
                                    <option value="below_standard">Passed - Below Standard (75&ndash;87)</option>
                                    <option value="failed">Failed (&le; 74)</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="button" id="openStandingReport" style="width: auto;"
                                    class="btn-seminary-primary bg-[#5c3a31] hover:bg-[#4a2c24] text-white text-sm font-semibold rounded-xl px-5 py-2.5 flex items-center gap-2 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all border-0 cursor-pointer">
                                <i class="fas fa-print text-xs"></i>
                                <span>Generate</span>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <script>
        (function () {
            const ay = <?php echo json_encode($filter_academic_year); ?>;
            const sem = <?php echo json_encode($filter_semester); ?>;

            function getSavedGradingFormula() {
                try {
                    const saved = window.localStorage.getItem('enformasys_grading_formula');
                    return (saved === 'weighted' || saved === 'simple') ? saved : 'stored';
                } catch (e) {
                    return 'stored';
                }
            }

            document.getElementById('openIndividualReport').addEventListener('click', function () {
                const sid = document.getElementById('indStudent').value;
                if (!sid) {
                    alert('Please select a student.');
                    return;
                }
                const url = new URL('afms_report_individual.php', window.location.href);
                url.searchParams.set('student_id', sid);
                url.searchParams.set('academic_year', ay);
                url.searchParams.set('semester', sem);
                url.searchParams.set('formula', getSavedGradingFormula());
                window.open(url.toString(), '_blank');
            });

            document.getElementById('openIrregularReport').addEventListener('click', function () {
                const url = new URL('afms_report_irregular.php', window.location.href);
                url.searchParams.set('academic_year', ay);
                url.searchParams.set('semester', sem);
                window.open(url.toString(), '_blank');
            });

            document.getElementById('openPerformanceReport').addEventListener('click', function () {
                const url = new URL('afms_report_performance.php', window.location.href);
                url.searchParams.set('academic_year', ay);
                url.searchParams.set('semester', sem);
                url.searchParams.set('formula', getSavedGradingFormula());
                window.open(url.toString(), '_blank');
            });

            document.getElementById('openStandingReport').addEventListener('click', function () {
                const url = new URL('afms_report_standing.php', window.location.href);
                url.searchParams.set('academic_year', ay);
                url.searchParams.set('semester', sem);
                const yl = document.getElementById('standingYearLevel')?.value || '';
                const st = document.getElementById('standingGradeFilter')?.value || 'all';
                if (yl) url.searchParams.set('year_level', yl);
                if (st) url.searchParams.set('standing', st);
                window.open(url.toString(), '_blank');
            });

            const select = document.getElementById('yearLevelFilter');
            const studentSelect = document.getElementById('indStudent');
            let activeCtrl = null;

            function fetchStudentsByYearLevel(yearLevel) {
                const url = new URL('afms_reports.php', window.location.href);
                if (yearLevel) {
                    url.searchParams.set('year_level', yearLevel);
                } else {
                    url.searchParams.delete('year_level');
                }
                const cleanUrl = new URL(url.toString());
                url.searchParams.set('ajax', '1');

                if (activeCtrl) activeCtrl.abort();
                activeCtrl = new AbortController();

                fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: activeCtrl.signal
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || (!data.ok && !data.success)) return;
                    if (studentSelect && typeof data.options_html === 'string') {
                        studentSelect.innerHTML = data.options_html;
                    }
                    window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    window.location.href = cleanUrl.toString();
                });
            }

            if (select) {
                select.addEventListener('change', function () {
                    fetchStudentsByYearLevel(select.value);
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
