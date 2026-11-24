<?php
require_once '../includes/session_fix.php';
require_roles(['SpiritualDirector', 'Registrar', 'Dean']);
require_once '../includes/db.php';
require_once '../includes/spiritual_evaluation.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$is_director = (string)($_SESSION['role'] ?? '') === 'SpiritualDirector';
$director_user_id = (int)($_SESSION['user'] ?? 0);

$current_ay = get_current_academic_year();
$filter_academic_year = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : '';
if ($filter_academic_year === '') $filter_academic_year = $current_ay;
$filter_semester = isset($_GET['semester']) ? enformasys_normalize_semester((string)$_GET['semester']) : '';
if (!in_array($filter_semester, ['1st','2nd','Summer'], true)) $filter_semester = sfe_get_default_semester();

$year_level_options = eval_year_level_filter_options();
$filter_year_level = eval_normalize_year_level_option($_GET['year_level'] ?? '');
$yl_aliases = eval_year_level_sql_aliases($filter_year_level);

$director_filter = '';
$types = 'ss';
$params = [$filter_academic_year, $filter_semester];
if ($is_director) {
    $director_filter = " AND EXISTS (
        SELECT 1 FROM seminarian_assignments sa
        WHERE sa.seminarian_id = e.seminarian_id
          AND sa.assignment_type = 'Spiritual'
          AND sa.assigned_user_id = ?
          AND sa.active = 1
    )";
    $types .= 'i';
    $params[] = $director_user_id;
}
if (!empty($yl_aliases)) {
    $yl_ph = implode(',', array_fill(0, count($yl_aliases), '?'));
    $director_filter .= " AND s.year_level IN ({$yl_ph})";
    $types .= str_repeat('s', count($yl_aliases));
    $params = array_merge($params, $yl_aliases);
}

$stmt = $conn->prepare("SELECT e.evaluation_id, e.seminarian_id, e.evaluation_date,
        r.average_rating, r.interpretation,
        a.growth_status, a.growth_percentage,
        s.year_level,
        u.username, up.full_name
    FROM spiritual_evaluations e
    JOIN seminarians s ON s.id = e.seminarian_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id
    LEFT JOIN spiritual_analytics a ON a.evaluation_id = e.evaluation_id
    WHERE e.archived_at IS NULL AND e.academic_year = ? AND e.semester = ?{$director_filter}
    ORDER BY e.evaluation_date DESC, e.evaluation_id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

$seminarians_list = [];
ob_start();
if ($rows->num_rows === 0) { ?>
    <tr><td colspan="6" style="color: var(--text-secondary);">No reports found<?php echo $filter_year_level !== '' ? ' for ' . htmlspecialchars($filter_year_level) : ''; ?>.</td></tr>
<?php }
while ($r = $rows->fetch_assoc()) {
    $name = trim((string)($r['full_name'] ?? ''));
    if ($name === '') $name = (string)($r['username'] ?? '');
    $gs = (string)($r['growth_status'] ?? '');
    if ($gs === 'Growing') $gs = 'Growing Spiritually';
    $seminarians_list[] = [
        'evaluation_id' => (int)$r['evaluation_id'],
        'seminarian_id' => (int)$r['seminarian_id'],
        'full_name' => $name,
        'year_level' => (string)($r['year_level'] ?? ''),
        'average_rating' => $r['average_rating'] !== null ? (float)$r['average_rating'] : null,
        'interpretation' => (string)($r['interpretation'] ?? ''),
        'growth_status' => $gs,
        'evaluation_date' => (string)($r['evaluation_date'] ?? ''),
    ];
?>
    <tr class="rep-row">
        <td class="rep-name"><strong><?php echo htmlspecialchars($name); ?></strong></td>
        <td><?php echo $r['average_rating'] === null ? '-' : "<span class='badge badge-primary'>" . htmlspecialchars(number_format((float)$r['average_rating'], 2)) . "</span>"; ?></td>
        <td><?php echo htmlspecialchars((string)($r['interpretation'] ?? '')); ?></td>
        <td><?php echo $gs !== '' ? htmlspecialchars($gs) : '-'; ?></td>
        <td style="color: var(--text-secondary);"><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['evaluation_date']))); ?></td>
        <td style="white-space: nowrap;">
            <a class="capstone-btn-secondary" style="padding: 0.4rem 0.6rem; text-decoration: none;" href="sfe_report.php?evaluation_id=<?php echo (int)$r['evaluation_id']; ?>">
                <i class="fas fa-file-lines"></i> View
            </a>
        </td>
    </tr>
<?php }
$rows_html = (string)ob_get_clean();

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'success' => true,
        'year_level' => $filter_year_level,
        'year_level_label' => $filter_year_level !== '' ? $filter_year_level : 'All Year Levels',
        'count' => count($seminarians_list),
        'seminarians' => $seminarians_list,
        'rows_html' => $rows_html,
    ]);
    exit;
}
?>
<?php
$pageTitle = 'Spiritual Reports';
$activeNav = 'sfe_reports';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0.25rem;"><i class="fas fa-file-lines"></i> Spiritual Formation Reports</h1>
                    <div id="reportYearLevelSubtitle" style="color: var(--text-secondary); font-weight: 800;"><?php echo $filter_year_level !== '' ? htmlspecialchars($filter_year_level) : 'All Year Levels'; ?></div>
                </div>
                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <?php if ($is_director) { ?>
                        <a href="sfe_evaluation_form.php" class="capstone-btn-primary" style="text-decoration: none;">
                            <i class="fas fa-plus"></i> New Evaluation
                        </a>
                    <?php } ?>
                    <a href="sfe_dashboard.php" class="capstone-btn-secondary" style="text-decoration: none;">
                        <i class="fas fa-chart-simple"></i> Spiritual Dashboard
                    </a>
                </div>
            </div>

            <div class="data-card">
                <div class="data-card-header" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                    <h3 style="margin: 0;"><i class="fas fa-table"></i> Individual Reports</h3>
                    <form method="get" action="sfe_reports.php" id="reportYearLevelFilterForm" class="capstone-form-row capstone-filter-bar" style="display: inline-flex; flex-direction: row; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin: 0; padding: 0; background: transparent; border: none; box-shadow: none;">
                        <label for="yearLevelFilter" class="text-xs font-extrabold uppercase tracking-wider text-[#8B7168]" style="margin: 0;">Year Level</label>
                        <select name="year_level" id="yearLevelFilter" aria-label="Year Level" class="px-3.5 py-2 text-xs font-bold text-[#59443F] bg-[#FDF9F9] border border-[#E2C9C8] rounded-xl focus:outline-none focus:border-[#59443F] cursor-pointer" style="width: auto; min-width: 175px; margin: 0;">
                            <?php foreach ($year_level_options as $yl_opt) { ?>
                                <option value="<?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yl_opt === $filter_year_level ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="px-3.5 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-xs transition-all cursor-pointer" style="width: auto; margin: 0;">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="sfe_reports.php" id="reportYearLevelResetBtn" class="px-3 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold transition-all <?php echo $filter_year_level !== '' ? 'inline-flex' : 'hidden'; ?> items-center gap-1" style="text-decoration: none; width: auto; margin: 0;" title="Reset Year Level Filter">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    </form>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Seminarian</th>
                                <th>Average</th>
                                <th>Interpretation</th>
                                <th>Growth</th>
                                <th>Date</th>
                                <th>Report</th>
                            </tr>
                        </thead>
                        <tbody id="reportRowsBody">
                            <?php echo $rows_html; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const form = document.getElementById('reportYearLevelFilterForm');
            const select = document.getElementById('yearLevelFilter');
            const resetBtn = document.getElementById('reportYearLevelResetBtn');
            const tbody = document.getElementById('reportRowsBody');
            const subtitle = document.getElementById('reportYearLevelSubtitle');
            let activeCtrl = null;

            function fetchReportsByYearLevel(yearLevel) {
                const url = new URL('sfe_reports.php', window.location.href);
                if (yearLevel) {
                    url.searchParams.set('year_level', yearLevel);
                } else {
                    url.searchParams.delete('year_level');
                }
                const cleanUrl = new URL(url.toString());
                url.searchParams.set('ajax', '1');

                if (activeCtrl) activeCtrl.abort();
                activeCtrl = new AbortController();

                if (tbody) tbody.style.opacity = '0.6';

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
                    if (tbody && typeof data.rows_html === 'string') {
                        tbody.innerHTML = data.rows_html;
                    }
                    if (subtitle && typeof data.year_level_label === 'string') {
                        subtitle.textContent = data.year_level_label;
                    }
                    if (resetBtn) {
                        resetBtn.classList.toggle('hidden', !data.year_level);
                        resetBtn.classList.toggle('inline-flex', !!data.year_level);
                    }
                    window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    window.location.href = cleanUrl.toString();
                })
                .finally(function () {
                    if (tbody) tbody.style.opacity = '1';
                });
            }

            if (select) {
                select.addEventListener('change', function () {
                    fetchReportsByYearLevel(select.value);
                });
            }
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    fetchReportsByYearLevel(select ? select.value : '');
                });
            }
            if (resetBtn) {
                resetBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetchReportsByYearLevel('');
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
