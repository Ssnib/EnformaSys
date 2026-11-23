<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Registrar', 'Dean', 'Rector']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/eval_ui_helpers.php';

$is_rector = ($_SESSION['role'] ?? '') === 'Rector';

$current_ay = get_current_academic_year();
$ay_options = get_all_academic_years(true);
if (!in_array($current_ay, $ay_options, true)) {
    array_unshift($ay_options, $current_ay);
}

// Active Filter Values
$filter_ay = isset($_GET['academic_year']) ? trim((string)$_GET['academic_year']) : $current_ay;
$filter_sem = isset($_GET['semester']) ? trim((string)$_GET['semester']) : 'all';
$filter_yl = eval_normalize_year_level_option($_GET['year_level'] ?? '');
if ($filter_yl === '') {
    $filter_yl = 'all';
}

// Year Level Filter Options
$yl_options = eval_year_level_filter_options();

/**
 * Fetch formation summary records filtered by academic year, semester, and year level.
 */
function fetch_formation_summary_data($conn, string $filter_ay, string $filter_sem, string $filter_yl): array {
    $sem_sql = "SELECT s.id as seminarian_id, u.username, up.full_name, s.year_level, s.program
        FROM seminarians s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE u.role = 'Student'";

    if ($filter_yl !== '' && $filter_yl !== 'all') {
        $yl_aliases = eval_year_level_sql_aliases($filter_yl);
        if (!empty($yl_aliases)) {
            $escaped_aliases = array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $yl_aliases);
            $sem_sql .= " AND s.year_level IN (" . implode(',', $escaped_aliases) . ")";
        } else {
            $sem_sql .= " AND s.year_level = '" . $conn->real_escape_string($filter_yl) . "'";
        }
    }

    if ($filter_ay !== '' && $filter_ay !== 'all') {
        $eay = $conn->real_escape_string($filter_ay);
        $sem_sql .= " AND (
            s.current_academic_year = '{$eay}'
            OR EXISTS (SELECT 1 FROM gpa_records g WHERE g.student_id = s.id AND g.academic_year = '{$eay}')
            OR EXISTS (SELECT 1 FROM academic_evaluations ae WHERE ae.student_id = s.id AND ae.academic_year = '{$eay}' AND ae.archived_at IS NULL)
            OR EXISTS (SELECT 1 FROM spiritual_evaluations se WHERE se.seminarian_id = s.id AND se.academic_year = '{$eay}' AND se.archived_at IS NULL)
            OR EXISTS (SELECT 1 FROM human_evaluations he WHERE he.seminarian_id = s.id AND he.academic_year = '{$eay}' AND he.archived_at IS NULL)
            OR EXISTS (SELECT 1 FROM pastoral_evaluations pe WHERE pe.seminarian_id = s.id AND pe.academic_year = '{$eay}' AND pe.archived_at IS NULL)
        )";
    }

    $sem_sql .= " ORDER BY COALESCE(up.full_name, u.username) ASC";
    $res = $conn->query($sem_sql);

    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $sid = (int)($r['seminarian_id'] ?? 0);
            if ($sid <= 0) continue;

            // 1. GPA (Filtered by AY and Semester)
            $gpa = null;
            $gpa_sql = "SELECT cumulative_gpa, semester_gpa FROM gpa_records WHERE student_id = ? AND cumulative_gpa IS NOT NULL";
            $gpa_params = [$sid];
            $gpa_types = 'i';
            if ($filter_ay !== '' && $filter_ay !== 'all') {
                $gpa_sql .= " AND academic_year = ?";
                $gpa_params[] = $filter_ay;
                $gpa_types .= 's';
            }
            if ($filter_sem !== '' && $filter_sem !== 'all') {
                $gpa_sql .= " AND semester = ?";
                $gpa_params[] = $filter_sem;
                $gpa_types .= 's';
            }
            $gpa_sql .= " ORDER BY computed_at DESC, gpa_id DESC LIMIT 1";
            $stmt_gpa = $conn->prepare($gpa_sql);
            $stmt_gpa->bind_param($gpa_types, ...$gpa_params);
            $stmt_gpa->execute();
            $gpa_res = $stmt_gpa->get_result();
            if ($gpa_res && $gr = $gpa_res->fetch_assoc()) {
                $gpa = $gr['cumulative_gpa'] !== null ? (float)$gr['cumulative_gpa'] : null;
            }

            // 2. Academic Evaluation (Filtered by AY and Semester)
            $acad_eval = null;
            $ae_sql = "SELECT r.average_score, r.rating_label, e.id as eval_id
                FROM academic_evaluations e
                JOIN academic_evaluation_results r ON r.evaluation_id = e.id
                WHERE e.archived_at IS NULL AND e.student_id = ?";
            $ae_params = [$sid];
            $ae_types = 'i';
            if ($filter_ay !== '' && $filter_ay !== 'all') {
                $ae_sql .= " AND e.academic_year = ?";
                $ae_params[] = $filter_ay;
                $ae_types .= 's';
            }
            if ($filter_sem !== '' && $filter_sem !== 'all') {
                $ae_sql .= " AND e.semester = ?";
                $ae_params[] = $filter_sem;
                $ae_types .= 's';
            }
            $ae_sql .= " ORDER BY e.evaluation_date DESC, e.id DESC LIMIT 1";
            $stmt_ae = $conn->prepare($ae_sql);
            $stmt_ae->bind_param($ae_types, ...$ae_params);
            $stmt_ae->execute();
            $ae_res = $stmt_ae->get_result();
            if ($ae_res && $ar = $ae_res->fetch_assoc()) {
                $acad_eval = [
                    'avg' => $ar['average_score'] !== null ? (float)$ar['average_score'] : null,
                    'label' => (string)($ar['rating_label'] ?? ''),
                    'eval_id' => (int)($ar['eval_id'] ?? 0),
                ];
            }

            // 3. Spiritual Evaluation (Filtered by AY and Semester)
            $spiritual = null;
            $se_sql = "SELECT r.average_rating, r.interpretation, e.evaluation_id
                FROM spiritual_evaluations e
                JOIN spiritual_results r ON r.evaluation_id = e.evaluation_id
                WHERE e.archived_at IS NULL AND e.seminarian_id = ?";
            $se_params = [$sid];
            $se_types = 'i';
            if ($filter_ay !== '' && $filter_ay !== 'all') {
                $se_sql .= " AND e.academic_year = ?";
                $se_params[] = $filter_ay;
                $se_types .= 's';
            }
            if ($filter_sem !== '' && $filter_sem !== 'all') {
                $se_sql .= " AND e.semester = ?";
                $se_params[] = $filter_sem;
                $se_types .= 's';
            }
            $se_sql .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";
            $stmt_se = $conn->prepare($se_sql);
            $stmt_se->bind_param($se_types, ...$se_params);
            $stmt_se->execute();
            $se_res = $stmt_se->get_result();
            if ($se_res && $sr = $se_res->fetch_assoc()) {
                $spiritual = [
                    'avg' => $sr['average_rating'] !== null ? (float)$sr['average_rating'] : null,
                    'label' => (string)($sr['interpretation'] ?? ''),
                    'eval_id' => (int)($sr['evaluation_id'] ?? 0),
                ];
            }

            // 4. Human Evaluation (Filtered by AY and Semester)
            $human = null;
            $he_sql = "SELECT r.average_rating, r.interpretation, e.evaluation_id
                FROM human_evaluations e
                JOIN human_results r ON r.evaluation_id = e.evaluation_id
                WHERE e.archived_at IS NULL AND e.seminarian_id = ?";
            $he_params = [$sid];
            $he_types = 'i';
            if ($filter_ay !== '' && $filter_ay !== 'all') {
                $he_sql .= " AND e.academic_year = ?";
                $he_params[] = $filter_ay;
                $he_types .= 's';
            }
            if ($filter_sem !== '' && $filter_sem !== 'all') {
                $he_sql .= " AND e.semester = ?";
                $he_params[] = $filter_sem;
                $he_types .= 's';
            }
            $he_sql .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";
            $stmt_he = $conn->prepare($he_sql);
            $stmt_he->bind_param($he_types, ...$he_params);
            $stmt_he->execute();
            $he_res = $stmt_he->get_result();
            if ($he_res && $hr = $he_res->fetch_assoc()) {
                $human = [
                    'avg' => $hr['average_rating'] !== null ? (float)$hr['average_rating'] : null,
                    'label' => (string)($hr['interpretation'] ?? ''),
                    'eval_id' => (int)($hr['evaluation_id'] ?? 0),
                ];
            }

            // 5. Pastoral Evaluation (Filtered by AY and Semester)
            $pastoral = null;
            $pe_sql = "SELECT r.average_rating, r.interpretation, e.evaluation_id
                FROM pastoral_evaluations e
                JOIN pastoral_results r ON r.evaluation_id = e.evaluation_id
                WHERE e.archived_at IS NULL AND e.seminarian_id = ?";
            $pe_params = [$sid];
            $pe_types = 'i';
            if ($filter_ay !== '' && $filter_ay !== 'all') {
                $pe_sql .= " AND e.academic_year = ?";
                $pe_params[] = $filter_ay;
                $pe_types .= 's';
            }
            if ($filter_sem !== '' && $filter_sem !== 'all') {
                $pe_sql .= " AND e.semester = ?";
                $pe_params[] = $filter_sem;
                $pe_types .= 's';
            }
            $pe_sql .= " ORDER BY e.evaluation_date DESC, e.evaluation_id DESC LIMIT 1";
            $stmt_pe = $conn->prepare($pe_sql);
            $stmt_pe->bind_param($pe_types, ...$pe_params);
            $stmt_pe->execute();
            $pe_res = $stmt_pe->get_result();
            if ($pe_res && $pr = $pe_res->fetch_assoc()) {
                $pastoral = [
                    'avg' => $pr['average_rating'] !== null ? (float)$pr['average_rating'] : null,
                    'label' => (string)($pr['interpretation'] ?? ''),
                    'eval_id' => (int)($pr['evaluation_id'] ?? 0),
                ];
            }

            $name = trim((string)($r['full_name'] ?? ''));
            if ($name === '') $name = (string)($r['username'] ?? '');

            $rows[] = [
                'seminarian_id' => $sid,
                'name' => $name,
                'year_level' => (string)($r['year_level'] ?? ''),
                'program' => (string)($r['program'] ?? ''),
                'gpa' => $gpa,
                'acad_eval' => $acad_eval,
                'spiritual' => $spiritual,
                'human' => $human,
                'pastoral' => $pastoral,
            ];
        }
    }
    return $rows;
}

/**
 * Calculate overall formation average and status from the 4 pillar evaluations.
 *
 * Uses the unified 5-point formation rating interpretation scale via getFormationInterpretation():
 *   - Pending           : ANY of the 4 pillars is missing, null, or <= 0
 *   - 4.50 – 5.00       : Outstanding       (bg-emerald-100 text-emerald-700)
 *   - 3.50 – 4.49       : Very Good         (bg-green-100 text-green-700)
 *   - 2.50 – 3.49       : Satisfactory      (bg-blue-100 text-blue-700)
 *   - 1.50 – 2.49       : Needs Improvement (bg-amber-100 text-amber-700)
 *   - 1.00 – 1.49 (<1.5): Poor              (bg-red-100 text-red-700)
 *
 * @param array<string, mixed> $evaluations Map keyed by 'academic', 'spiritual', 'human', 'pastoral'.
 * @return array<string, mixed>
 */
function calculateOverallStatus(array $evaluations): array {
    $PASSING_THRESHOLD         = 3.00;
    $PILLAR_CRITICAL_THRESHOLD = 2.50;

    $pillars = ['academic', 'spiritual', 'human', 'pastoral'];
    $valid_scores = [];
    $raw_scores = [];

    foreach ($pillars as $key) {
        $entry = $evaluations[$key] ?? null;
        $val = is_array($entry) ? ($entry['avg'] ?? null) : $entry;
        if ($val !== null && $val !== '' && is_numeric($val) && (float)$val > 0) {
            $rounded_score = round((float)$val, 2, PHP_ROUND_HALF_UP);
            $valid_scores[$key] = $rounded_score;
            $raw_scores[$key] = $rounded_score;
        } else {
            $raw_scores[$key] = null;
        }
    }

    $evaluated_count = count($valid_scores);
    $is_complete = ($evaluated_count === count($pillars));

    // Any missing, null, or zero pillar -> strictly Pending (hide average / Incomplete)
    if (!$is_complete) {
        $pending_interp = getFormationInterpretation(null);
        return [
            'status' => $pending_interp['key'],
            'status_label' => $pending_interp['label'],
            'badge_class' => $pending_interp['badge_class'],
            'badge_bg' => $pending_interp['badge_bg'],
            'badge_text' => $pending_interp['badge_text'],
            'badge_border' => $pending_interp['badge_border'],
            'badge_icon' => $pending_interp['badge_icon'],
            'is_complete' => false,
            'needs_attention' => false,
            'overall_avg' => null,
            'evaluated_count' => $evaluated_count,
            'substandard_count' => 0,
            'warning_count' => 0,
            'scores' => $valid_scores,
            'acad_score' => $raw_scores['academic'],
            'academic_score' => $raw_scores['academic'],
            'spiritual_score' => $raw_scores['spiritual'],
            'human_score' => $raw_scores['human'],
            'pastoral_score' => $raw_scores['pastoral'],
            'acad_for_review' => ($raw_scores['academic'] !== null && $raw_scores['academic'] < $PILLAR_CRITICAL_THRESHOLD),
            'academic_for_review' => ($raw_scores['academic'] !== null && $raw_scores['academic'] < $PILLAR_CRITICAL_THRESHOLD),
            'spiritual_for_review' => ($raw_scores['spiritual'] !== null && $raw_scores['spiritual'] < $PILLAR_CRITICAL_THRESHOLD),
            'human_for_review' => ($raw_scores['human'] !== null && $raw_scores['human'] < $PILLAR_CRITICAL_THRESHOLD),
            'pastoral_for_review' => ($raw_scores['pastoral'] !== null && $raw_scores['pastoral'] < $PILLAR_CRITICAL_THRESHOLD),
        ];
    }

    // Precision-safe average rounded strictly to 2 decimal places
    $sum_cents = 0;
    $critical_count = 0;
    $warning_count = 0;

    foreach ($valid_scores as $score) {
        $sum_cents += (int)round($score * 100);
        if ($score < $PILLAR_CRITICAL_THRESHOLD) {
            $critical_count++;
        }
        if ($score < $PASSING_THRESHOLD) {
            $warning_count++;
        }
    }

    $overall_avg = round($sum_cents / 400, 2, PHP_ROUND_HALF_UP);
    $interp = getFormationInterpretation($overall_avg);

    return [
        'status' => $interp['key'],
        'status_label' => $interp['label'],
        'badge_class' => $interp['badge_class'],
        'badge_bg' => $interp['badge_bg'],
        'badge_text' => $interp['badge_text'],
        'badge_border' => $interp['badge_border'],
        'badge_icon' => $interp['badge_icon'],
        'is_complete' => true,
        'needs_attention' => ($overall_avg < $PILLAR_CRITICAL_THRESHOLD || $critical_count > 0),
        'overall_avg' => $overall_avg,
        'evaluated_count' => $evaluated_count,
        'substandard_count' => $critical_count,
        'warning_count' => $warning_count,
        'scores' => $valid_scores,
        'acad_score' => $raw_scores['academic'],
        'academic_score' => $raw_scores['academic'],
        'spiritual_score' => $raw_scores['spiritual'],
        'human_score' => $raw_scores['human'],
        'pastoral_score' => $raw_scores['pastoral'],
        'acad_for_review' => ($raw_scores['academic'] < $PILLAR_CRITICAL_THRESHOLD),
        'academic_for_review' => ($raw_scores['academic'] < $PILLAR_CRITICAL_THRESHOLD),
        'spiritual_for_review' => ($raw_scores['spiritual'] < $PILLAR_CRITICAL_THRESHOLD),
        'human_for_review' => ($raw_scores['human'] < $PILLAR_CRITICAL_THRESHOLD),
        'pastoral_for_review' => ($raw_scores['pastoral'] < $PILLAR_CRITICAL_THRESHOLD),
    ];
}

/**
 * Compute individual seminarian formative assessment profile and status classification.
 */
function get_seminarian_formation_profile(array $r): array {
    return calculateOverallStatus([
        'academic'  => $r['acad_eval']['avg'] ?? null,
        'spiritual' => $r['spiritual']['avg'] ?? null,
        'human'     => $r['human']['avg'] ?? null,
        'pastoral'  => $r['pastoral']['avg'] ?? null,
    ]);
}

/**
 * Compute aggregate analytics metrics across the seminarian cohort.
 */
function compute_formation_summary_analytics(array $rows): array {
    $total_students = count($rows);
    $counts = [
        'total' => $total_students,
        'outstanding' => 0,
        'very_good' => 0,
        'satisfactory' => 0,
        'needs_improvement' => 0,
        'poor' => 0,
        'pending' => 0,
    ];
    
    $pillar_data = [
        'academic' => [
            'scores' => [],
            'for_review' => 0,
            'label' => 'Academic Formator',
            'pillar_title' => 'Academic Formation',
            'icon' => 'fa-graduation-cap',
            'color' => '#2563eb',
            'bg' => '#eff6ff',
            'border' => '#bfdbfe',
            'bar_color' => '#3b82f6',
            'desc' => 'Intellectual growth & academic performance'
        ],
        'spiritual' => [
            'scores' => [],
            'for_review' => 0,
            'label' => 'Spiritual Formator',
            'pillar_title' => 'Spiritual Formation',
            'icon' => 'fa-cross',
            'color' => '#7c3aed',
            'bg' => '#f5f3ff',
            'border' => '#ddd6fe',
            'bar_color' => '#8b5cf6',
            'desc' => 'Prayer life, recollections & discernment'
        ],
        'human' => [
            'scores' => [],
            'for_review' => 0,
            'label' => 'Human Formator',
            'pillar_title' => 'Human Formation',
            'icon' => 'fa-heart-pulse',
            'color' => '#d97706',
            'bg' => '#fffbeb',
            'border' => '#fde68a',
            'bar_color' => '#f59e0b',
            'desc' => 'Character maturity & community life'
        ],
        'pastoral' => [
            'scores' => [],
            'for_review' => 0,
            'label' => 'Pastoral Formator',
            'pillar_title' => 'Pastoral Formation',
            'icon' => 'fa-hands-holding-child',
            'color' => '#059669',
            'bg' => '#ecfdf5',
            'border' => '#a7f3d0',
            'bar_color' => '#10b981',
            'desc' => 'Apostolate service & pastoral readiness'
        ],
    ];

    foreach ($rows as $r) {
        $p = get_seminarian_formation_profile($r);
        $st = $p['status'];
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
        
        foreach (['academic', 'spiritual', 'human', 'pastoral'] as $dim) {
            $score_key = $dim . '_score';
            if ($p[$score_key] !== null) {
                $pillar_data[$dim]['scores'][] = (float)$p[$score_key];
                if ($p[$dim . '_for_review']) {
                    $pillar_data[$dim]['for_review']++;
                }
            }
        }
    }
    
    // Compute pillar averages and percentages
    $pillar_summary = [];
    foreach ($pillar_data as $dim => $d) {
        $cnt = count($d['scores']);
        $avg = $cnt > 0 ? (array_sum($d['scores']) / $cnt) : 0.0;
        $pct = $avg > 0 ? round(($avg / 5.0) * 100) : 0;
        $pillar_summary[$dim] = [
            'name' => $dim,
            'label' => $d['label'],
            'pillar_title' => $d['pillar_title'],
            'icon' => $d['icon'],
            'color' => $d['color'],
            'bg' => $d['bg'],
            'border' => $d['border'],
            'bar_color' => $d['bar_color'],
            'desc' => $d['desc'],
            'evaluated_count' => $cnt,
            'for_review_count' => $d['for_review'],
            'average_score' => round($avg, 2),
            'percentage' => (int)$pct,
        ];
    }
    
    $eval_total = ($counts['outstanding'] ?? 0) + ($counts['very_good'] ?? 0) + ($counts['satisfactory'] ?? 0) + ($counts['needs_improvement'] ?? 0) + ($counts['poor'] ?? 0);
    $status_pct = [
        'outstanding' => $total_students > 0 ? round((($counts['outstanding'] ?? 0) / $total_students) * 100, 1) : 0,
        'very_good' => $total_students > 0 ? round((($counts['very_good'] ?? 0) / $total_students) * 100, 1) : 0,
        'satisfactory' => $total_students > 0 ? round((($counts['satisfactory'] ?? 0) / $total_students) * 100, 1) : 0,
        'needs_improvement' => $total_students > 0 ? round((($counts['needs_improvement'] ?? 0) / $total_students) * 100, 1) : 0,
        'poor' => $total_students > 0 ? round((($counts['poor'] ?? 0) / $total_students) * 100, 1) : 0,
        'pending' => $total_students > 0 ? round((($counts['pending'] ?? 0) / $total_students) * 100, 1) : 0,
    ];
    
    return [
        'total' => $total_students,
        'evaluated_total' => $eval_total,
        'counts' => $counts,
        'status_pct' => $status_pct,
        'pillars' => $pillar_summary,
    ];
}

/**
 * Render the Top Visual Analytics Dashboard HTML.
 */
function render_formation_summary_analytics(array $analytics, string $filter_ay, string $filter_sem, string $current_ay, bool $is_rector = false): string {
    $total = $analytics['total'] ?? 0;
    $counts = $analytics['counts'] ?? ['outstanding' => 0, 'very_good' => 0, 'satisfactory' => 0, 'needs_improvement' => 0, 'poor' => 0, 'pending' => 0];
    $status_pct = $analytics['status_pct'] ?? ['outstanding' => 0, 'very_good' => 0, 'satisfactory' => 0, 'needs_improvement' => 0, 'poor' => 0, 'pending' => 0];
    $pillars = $analytics['pillars'] ?? [];
    $evaluated_total = $analytics['evaluated_total'] ?? 0;
    $needs_attention_count = ($counts['needs_improvement'] ?? 0) + ($counts['poor'] ?? 0);
    
    $term_label = ($filter_ay === 'all' || $filter_ay === '') ? 'All Academic Years' : 'A.Y. ' . $filter_ay;
    if ($filter_sem !== 'all' && $filter_sem !== '') {
        $term_label .= ' • ' . $filter_sem . ' Semester';
    }

    ob_start();
    ?>
    <div class="fs-analytics-wrapper" style="margin-bottom: 1.5rem;">
        <div style="background: #ffffff; border-radius: 1.25rem; border: 1px solid #E8C7C5; box-shadow: 0 4px 20px -2px rgba(89, 68, 63, 0.05), 0 2px 6px -1px rgba(89, 68, 63, 0.03); overflow: hidden;">
            
            <!-- Analytics Header & Dimension Quick Filter Tabs -->
            <div style="padding: 1.15rem 1.5rem; background: #FAF8F6; border-bottom: 1px solid #f1e5e4; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 12px; background: #F8EEEE; color: #59443F; border: 1px solid #E8C7C5; box-shadow: 0 1px 3px rgba(89,68,63,0.06);">
                        <i class="fa-solid fa-chart-line" style="font-size: 1.05rem;"></i>
                    </span>
                    <div>
                        <h2 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #59443F; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; align-items: center; gap: 0.5rem;">
                            Formation Analytics Dashboard
                        </h2>
                        <div style="font-size: 0.78rem; color: #8B7168; font-weight: 600; margin-top: 2px;">
                            Cohort formative evaluation across all four pillars &bull; <span style="color: #59443F; font-weight: 700;"><?php echo htmlspecialchars($term_label); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Dimension Filter Tabs (Header Controls) -->
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <div class="fs-dim-tabs" style="display: inline-flex; align-items: center; background: #ffffff; border: 1px solid #E8C7C5; padding: 3px; border-radius: 12px; gap: 2px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.03);">
                        <button type="button" class="fs-dim-tab active" data-dimension="all" style="border: none; background: #59443F; color: #ffffff; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Show all dimensions">
                            All Pillars (<?php echo $total; ?>)
                        </button>
                        <button type="button" class="fs-dim-tab" data-dimension="academic" style="border: none; background: transparent; color: #59443F; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Filter by Academic Formator">
                            <i class="fa-solid fa-graduation-cap" style="color: #2563eb; margin-right: 3px;"></i> Academic
                        </button>
                        <button type="button" class="fs-dim-tab" data-dimension="spiritual" style="border: none; background: transparent; color: #59443F; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Filter by Spiritual Formator">
                            <i class="fa-solid fa-cross" style="color: #7c3aed; margin-right: 3px;"></i> Spiritual
                        </button>
                        <button type="button" class="fs-dim-tab" data-dimension="human" style="border: none; background: transparent; color: #59443F; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Filter by Human Formator">
                            <i class="fa-solid fa-heart-pulse" style="color: #d97706; margin-right: 3px;"></i> Human
                        </button>
                        <button type="button" class="fs-dim-tab" data-dimension="pastoral" style="border: none; background: transparent; color: #59443F; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Filter by Pastoral Formator">
                            <i class="fa-solid fa-hands-holding-child" style="color: #059669; margin-right: 3px;"></i> Pastoral
                        </button>
                        <button type="button" class="fs-dim-tab" data-dimension="needs_attention" style="border: none; background: transparent; color: #991b1b; padding: 0.35rem 0.75rem; border-radius: 9px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;" title="Show all seminarians needing attention">
                            <i class="fa-solid fa-triangle-exclamation" style="margin-right: 3px;"></i> Needs Attention (<?php echo $needs_attention_count; ?>)
                        </button>
                    </div>
                </div>
            </div>

            <!-- Analytics Content -->
            <div style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 1.35rem;">
                
                <!-- TOP SECTION: Overall Formation Status Distribution -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div style="font-size: 0.85rem; font-weight: 800; color: #59443F; display: flex; align-items: center; gap: 0.45rem;">
                            <i class="fa-solid fa-chart-pie" style="color: #8B7168;"></i> Overall Formation Status Distribution
                        </div>
                        <div style="font-size: 0.75rem; color: #8B7168; font-weight: 700;">
                            <?php echo $evaluated_total; ?> of <?php echo $total; ?> Evaluated (<?php echo $total > 0 ? round(($evaluated_total / $total) * 100) : 0; ?>%)
                        </div>
                    </div>

                    <!-- Visual Multi-segment Progress Bar -->
                    <div style="width: 100%; height: 12px; background: #f1f5f9; border-radius: 9999px; overflow: hidden; display: flex; box-shadow: inset 0 1px 2px rgba(0,0,0,0.06); margin-bottom: 0.85rem;" title="Status distribution across 5-point scale">
                        <?php if (($status_pct['outstanding'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['outstanding']; ?>%; background: #10b981; height: 100%; transition: width 0.3s ease;" title="Outstanding: <?php echo $counts['outstanding']; ?> seminarians (<?php echo $status_pct['outstanding']; ?>%)"></div>
                        <?php endif; ?>
                        <?php if (($status_pct['very_good'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['very_good']; ?>%; background: #22c55e; height: 100%; transition: width 0.3s ease;" title="Very Good: <?php echo $counts['very_good']; ?> seminarians (<?php echo $status_pct['very_good']; ?>%)"></div>
                        <?php endif; ?>
                        <?php if (($status_pct['satisfactory'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['satisfactory']; ?>%; background: #3b82f6; height: 100%; transition: width 0.3s ease;" title="Satisfactory: <?php echo $counts['satisfactory']; ?> seminarians (<?php echo $status_pct['satisfactory']; ?>%)"></div>
                        <?php endif; ?>
                        <?php if (($status_pct['needs_improvement'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['needs_improvement']; ?>%; background: #f59e0b; height: 100%; transition: width 0.3s ease;" title="Needs Improvement: <?php echo $counts['needs_improvement']; ?> seminarians (<?php echo $status_pct['needs_improvement']; ?>%)"></div>
                        <?php endif; ?>
                        <?php if (($status_pct['poor'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['poor']; ?>%; background: #ef4444; height: 100%; transition: width 0.3s ease;" title="Poor: <?php echo $counts['poor']; ?> seminarians (<?php echo $status_pct['poor']; ?>%)"></div>
                        <?php endif; ?>
                        <?php if (($status_pct['pending'] ?? 0) > 0): ?>
                            <div style="width: <?php echo $status_pct['pending']; ?>%; background: #cbd5e1; height: 100%; transition: width 0.3s ease;" title="Pending: <?php echo $counts['pending']; ?> seminarians (<?php echo $status_pct['pending']; ?>%)"></div>
                        <?php endif; ?>
                    </div>

                    <!-- Interactive Status Cards Row -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
                        <!-- Outstanding Card -->
                        <div class="fs-status-card" data-status-filter="outstanding" role="button" tabindex="0" title="Click to filter table: Outstanding seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #047857; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-award" style="color: #10b981;"></i> Outstanding
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">
                                    <?php echo $status_pct['outstanding']; ?>%
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #064e3b; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $counts['outstanding']; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Avg 4.50 &ndash; 5.00
                            </div>
                        </div>

                        <!-- Very Good Card -->
                        <div class="fs-status-card" data-status-filter="very_good" role="button" tabindex="0" title="Click to filter table: Very Good seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #15803d; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-circle-check" style="color: #22c55e;"></i> Very Good
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-green-100 text-green-700">
                                    <?php echo $status_pct['very_good']; ?>%
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #14532d; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $counts['very_good']; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Avg 3.50 &ndash; 4.49
                            </div>
                        </div>

                        <!-- Satisfactory Card -->
                        <div class="fs-status-card" data-status-filter="satisfactory" role="button" tabindex="0" title="Click to filter table: Satisfactory seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #1d4ed8; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-thumbs-up" style="color: #3b82f6;"></i> Satisfactory
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700">
                                    <?php echo $status_pct['satisfactory']; ?>%
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #1e3a8a; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $counts['satisfactory']; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Avg 2.50 &ndash; 3.49
                            </div>
                        </div>

                        <!-- Needs Improvement Card -->
                        <div class="fs-status-card" data-status-filter="needs_improvement" role="button" tabindex="0" title="Click to filter table: Needs Improvement seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #b45309; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-triangle-exclamation" style="color: #f59e0b;"></i> Needs Imprv.
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700">
                                    <?php echo $status_pct['needs_improvement']; ?>%
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #78350f; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $counts['needs_improvement']; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Avg 1.50 &ndash; 2.49
                            </div>
                        </div>

                        <!-- Poor Card -->
                        <div class="fs-status-card" data-status-filter="poor" role="button" tabindex="0" title="Click to filter table: Poor seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #b91c1c; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-circle-exclamation" style="color: #ef4444;"></i> Poor
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700">
                                    <?php echo $status_pct['poor']; ?>%
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #7f1d1d; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $counts['poor']; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Avg 1.00 &ndash; 1.49
                            </div>
                        </div>

                        <!-- Total / Reset Card -->
                        <div class="fs-status-card active-all" data-status-filter="all" role="button" tabindex="0" title="Click to show all seminarians" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #59443F; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-users" style="color: #8B7168;"></i> Total Cohort
                                </span>
                                <span class="px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600">
                                    <?php echo $counts['pending']; ?> pend.
                                </span>
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 800; color: #59443F; margin: 0.35rem 0 0.15rem 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                <?php echo $total; ?>
                            </div>
                            <div style="font-size: 0.72rem; color: #8B7168; font-weight: 600;">
                                Show all &bull; Reset
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Four-Pillar Average Ratings (Academic, Spiritual, Human, Pastoral) -->
                <div style="padding-top: 1.1rem; border-top: 1px solid #f1e5e4;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div style="font-size: 0.85rem; font-weight: 800; color: #59443F; display: flex; align-items: center; gap: 0.45rem;">
                            <i class="fa-solid fa-scale-balanced" style="color: #8B7168;"></i> Four-Pillar Average Ratings
                        </div>
                        <div style="font-size: 0.75rem; color: #8B7168; font-weight: 600;">
                            Comparative formative averages (1.00 &ndash; 5.00 Scale) &bull; Click card to inspect
                        </div>
                    </div>

                    <!-- 4 Pillar Cards Grid -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 0.85rem;">
                        <?php foreach (['academic', 'spiritual', 'human', 'pastoral'] as $p_key): 
                            $p_info = $pillars[$p_key] ?? [];
                            $p_avg = (float)($p_info['average_score'] ?? 0.0);
                            $p_pct = (int)($p_info['percentage'] ?? 0);
                            $p_eval_cnt = (int)($p_info['evaluated_count'] ?? 0);
                            $p_review_cnt = (int)($p_info['for_review_count'] ?? 0);
                        ?>
                        <div class="fs-pillar-card" data-pillar="<?php echo $p_key; ?>" role="button" tabindex="0" title="Click to filter table to <?php echo htmlspecialchars($p_info['label'] ?? ''); ?>" style="background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 12px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.15s ease; position: relative;">
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.45rem;">
                                <div style="display: flex; align-items: center; gap: 0.45rem;">
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px; background: <?php echo $p_info['bg']; ?>; color: <?php echo $p_info['color']; ?>; border: 1px solid <?php echo $p_info['border']; ?>; font-size: 0.8rem;">
                                        <i class="fa-solid <?php echo $p_info['icon']; ?>"></i>
                                    </span>
                                    <div>
                                        <div style="font-size: 0.8rem; font-weight: 800; color: #59443F;"><?php echo htmlspecialchars($p_info['pillar_title']); ?></div>
                                        <div style="font-size: 0.68rem; color: #8B7168; font-weight: 600;"><?php echo htmlspecialchars($p_info['label']); ?></div>
                                    </div>
                                </div>
                                <span style="font-size: 0.72rem; font-weight: 800; color: <?php echo $p_info['color']; ?>; background: <?php echo $p_info['bg']; ?>; border: 1px solid <?php echo $p_info['border']; ?>; padding: 1px 6px; border-radius: 9999px;">
                                    <?php echo $p_pct; ?>%
                                </span>
                            </div>

                            <!-- Score and Progress Bar -->
                            <div style="display: flex; align-items: baseline; gap: 0.35rem; margin-top: 0.35rem;">
                                <span style="font-size: 1.4rem; font-weight: 800; color: #59443F; font-family: 'Plus Jakarta Sans', sans-serif;">
                                    <?php echo $p_eval_cnt > 0 ? number_format($p_avg, 2) : '—'; ?>
                                </span>
                                <?php if ($p_eval_cnt > 0): ?>
                                    <span style="font-size: 0.75rem; color: #8B7168; font-weight: 700;">/ 5.00</span>
                                <?php endif; ?>
                            </div>

                            <!-- Progress Bar -->
                            <div style="width: 100%; height: 6px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; margin: 0.45rem 0 0.55rem 0;">
                                <div style="width: <?php echo $p_pct; ?>%; background: <?php echo $p_info['bar_color']; ?>; height: 100%; border-radius: 9999px; transition: width 0.3s ease;"></div>
                            </div>

                            <!-- Footer Counts & Subfilter Action -->
                            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.7rem; color: #8B7168; font-weight: 600;">
                                <span><?php echo $p_eval_cnt; ?> of <?php echo $total; ?> assessed</span>
                                <?php if ($p_review_cnt > 0): ?>
                                    <span class="fs-pillar-subfilter badge" data-pillar="<?php echo $p_key; ?>" data-filter-type="review" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 0.68rem; font-weight: 700; cursor: pointer; padding: 1px 6px; border-radius: 9999px;" title="Click to filter to <?php echo $p_review_cnt; ?> seminarians needing attention in this pillar">
                                        <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $p_review_cnt; ?> For Review
                                    </span>
                                <?php else: ?>
                                    <span style="color: #059669; font-weight: 700;">
                                        <i class="fa-solid fa-check"></i> On Track
                                    </span>
                                <?php endif; ?>
                            </div>

                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render a single evaluation pillar cell with centered numeric score and unified rating badge below.
 */
function render_formation_score_cell(?array $eval, string $report_page): string {
    if (!$eval || $eval['avg'] === null || (float)$eval['avg'] <= 0) {
        return '<td class="py-3.5 px-4 text-center whitespace-nowrap"><div class="flex flex-col items-center justify-center"><span class="text-sm font-medium text-gray-400">—</span></div></td>';
    }
    $avg_val = round((float)$eval['avg'], 2, PHP_ROUND_HALF_UP);
    $score_text = htmlspecialchars(number_format($avg_val, 2));
    $interp = getFormationInterpretation($avg_val);
    $label_text = (string)($interp['label'] ?? '');
    $badge_cls = (string)($interp['badge_class'] ?? 'bg-gray-100 text-gray-600');
    $eval_id = (int)($eval['eval_id'] ?? 0);

    $score_html = $eval_id > 0
        ? '<a href="' . htmlspecialchars($report_page . '?evaluation_id=' . $eval_id) . '" class="text-sm font-bold text-gray-800 hover:text-teal-700 hover:underline transition-colors no-underline" title="View full evaluation report">' . $score_text . '</a>'
        : '<span class="text-sm font-bold text-gray-800">' . $score_text . '</span>';

    $rating_html = $label_text !== ''
        ? '<span class="inline-block px-2 py-0.5 rounded-full ' . htmlspecialchars($badge_cls) . ' text-[10px] font-semibold uppercase tracking-wider whitespace-nowrap">' . htmlspecialchars($label_text) . '</span>'
        : '';

    return '<td class="py-3.5 px-4 text-center whitespace-nowrap"><div class="flex flex-col items-center justify-center gap-1">' . $score_html . $rating_html . '</div></td>';
}

/**
 * Render formation summary table rows HTML.
 */
function render_formation_summary_rows(array $rows, string $filter_ay): string {
    ob_start();
    if (count($rows) === 0) {
        ?>
        <tr><td colspan="6" class="text-center text-sm text-gray-500 py-10 px-6">No seminarians found for the selected filter criteria.</td></tr>
        <?php
    }
    ?>
    <tr id="fsNoMatchRow" style="display: none;">
        <td colspan="6" class="text-center text-sm text-gray-500 py-10 px-6">
            <i class="fas fa-search text-2xl text-gray-400 block mb-2"></i>
            No seminarians match your search query or card filter.
        </td>
    </tr>
    <?php
    foreach ($rows as $r) {
        $sid = (int)($r['seminarian_id'] ?? 0);
        $name = (string)($r['name'] ?? '');
        $yl = (string)($r['year_level'] ?? '');
        $prog = (string)($r['program'] ?? '');
        $ae = $r['acad_eval'];
        $s = $r['spiritual'];
        $h = $r['human'];
        $p = $r['pastoral'];
        $profile_href = 'seminarian_profile.php?seminarian_id=' . $sid . (($filter_ay !== '' && $filter_ay !== 'all') ? '&academic_year=' . urlencode($filter_ay) : '');
        $prof = get_seminarian_formation_profile($r);
        ?>
        <tr class="fs-row border-b border-gray-100 hover:bg-gray-50 transition-colors"
            data-year-level="<?php echo htmlspecialchars($yl); ?>"
            data-status="<?php echo htmlspecialchars($prof['status']); ?>"
            data-needs-attention="<?php echo !empty($prof['needs_attention']) ? '1' : '0'; ?>"
            data-acad-score="<?php echo $prof['acad_score'] !== null ? htmlspecialchars((string)$prof['acad_score']) : ''; ?>"
            data-spiritual-score="<?php echo $prof['spiritual_score'] !== null ? htmlspecialchars((string)$prof['spiritual_score']) : ''; ?>"
            data-human-score="<?php echo $prof['human_score'] !== null ? htmlspecialchars((string)$prof['human_score']) : ''; ?>"
            data-pastoral-score="<?php echo $prof['pastoral_score'] !== null ? htmlspecialchars((string)$prof['pastoral_score']) : ''; ?>"
            data-acad-for-review="<?php echo $prof['acad_for_review'] ? '1' : '0'; ?>"
            data-spiritual-for-review="<?php echo $prof['spiritual_for_review'] ? '1' : '0'; ?>"
            data-human-for-review="<?php echo $prof['human_for_review'] ? '1' : '0'; ?>"
            data-pastoral-for-review="<?php echo $prof['pastoral_for_review'] ? '1' : '0'; ?>"
            data-acad-evaluated="<?php echo $prof['acad_score'] !== null ? '1' : '0'; ?>"
            data-spiritual-evaluated="<?php echo $prof['spiritual_score'] !== null ? '1' : '0'; ?>"
            data-human-evaluated="<?php echo $prof['human_score'] !== null ? '1' : '0'; ?>"
            data-pastoral-evaluated="<?php echo $prof['pastoral_score'] !== null ? '1' : '0'; ?>"
        >
            <td class="fs-name py-3.5 px-4 text-left align-middle">
                <a href="<?php echo htmlspecialchars($profile_href); ?>" class="font-semibold text-sm text-gray-900 hover:text-teal-700 transition-colors no-underline block" title="Click to view full Seminarian Profile">
                    <?php echo htmlspecialchars($name); ?>
                </a>
                <div class="text-xs text-gray-500 flex flex-wrap items-center gap-1 mt-0.5">
                    <span>ID #<?php echo $sid; ?></span>
                    <?php if ($yl !== ''): ?>
                        <span>&bull;</span>
                        <span><?php echo htmlspecialchars($yl); ?></span>
                    <?php endif; ?>
                    <?php if ($prog !== ''): ?>
                        <span>&bull;</span>
                        <span><?php echo htmlspecialchars($prog); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <?php echo render_formation_score_cell($ae, 'afe_report.php'); ?>
            <?php echo render_formation_score_cell($s, 'sfe_report.php'); ?>
            <?php echo render_formation_score_cell($h, 'hfe_report.php'); ?>
            <?php echo render_formation_score_cell($p, 'pfe_report.php'); ?>
            <td class="py-3.5 px-4 text-center align-middle whitespace-nowrap">
                <div class="flex flex-col items-center justify-center gap-1">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold whitespace-nowrap <?php echo htmlspecialchars((string)($prof['badge_class'] ?? 'bg-gray-100 text-gray-600')); ?>">
                        <i class="fa-solid <?php echo htmlspecialchars((string)($prof['badge_icon'] ?? 'fa-hourglass-half')); ?>"></i> <?php echo htmlspecialchars($prof['status_label']); ?>
                    </span>
                    <?php if (!empty($prof['is_complete']) && $prof['overall_avg'] !== null): ?>
                        <span class="text-xs text-gray-500 font-medium whitespace-nowrap" title="Composite 4-Pillar Average">
                            Avg: <?php echo number_format((float)$prof['overall_avg'], 2); ?>
                        </span>
                    <?php else: ?>
                        <span class="text-xs text-gray-500 whitespace-nowrap" title="Waiting for all 4 pillar evaluations">
                            Incomplete (<?php echo (int)$prof['evaluated_count']; ?>/4)
                        </span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }
    return ob_get_clean();
}

/**
 * Render active filter badges HTML.
 */
function render_formation_summary_badges(string $filter_ay, string $filter_sem, string $filter_yl, string $current_ay): string {
    ob_start();
    if ($filter_ay !== 'all' && $filter_ay !== '') {
        ?>
        <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; font-size: 0.72rem; padding: 0.25rem 0.55rem;">
            <i class="fas fa-calendar-days"></i> AY <?php echo htmlspecialchars($filter_ay); ?>
        </span>
        <?php
    }
    if ($filter_sem !== 'all' && $filter_sem !== '') {
        ?>
        <span class="badge" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 700; font-size: 0.72rem; padding: 0.25rem 0.55rem;">
            <i class="fas fa-clock"></i> <?php echo htmlspecialchars($filter_sem); ?> Sem
        </span>
        <?php
    }
    if ($filter_yl !== 'all' && $filter_yl !== '') {
        ?>
        <span class="badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-weight: 700; font-size: 0.72rem; padding: 0.25rem 0.55rem;">
            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($filter_yl); ?>
        </span>
        <?php
    }
    return ob_get_clean();
}

// Handle AJAX Request for Dynamic Filtering
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $rows = fetch_formation_summary_data($conn, $filter_ay, $filter_sem, $filter_yl);
    $html = render_formation_summary_rows($rows, $filter_ay);
    $badges = render_formation_summary_badges($filter_ay, $filter_sem, $filter_yl, $current_ay);
    $analytics = compute_formation_summary_analytics($rows);
    $analytics_html = render_formation_summary_analytics($analytics, $filter_ay, $filter_sem, $current_ay, $is_rector);
    $has_filter_active = ($filter_ay !== $current_ay && $filter_ay !== 'all') 
        || ($filter_sem !== 'all' && $filter_sem !== '') 
        || ($filter_yl !== 'all' && $filter_yl !== '');

    echo json_encode([
        'ok' => true,
        'success' => true,
        'count' => count($rows),
        'total' => count($rows),
        'academic_year' => $filter_ay,
        'semester' => $filter_sem,
        'year_level' => $filter_yl,
        'seminarians' => $rows,
        'html' => $html,
        'badges_html' => $badges,
        'analytics_html' => $analytics_html,
        'has_filter_active' => $has_filter_active,
    ]);
    exit;
}

// Regular Initial Page Request
$rows = fetch_formation_summary_data($conn, $filter_ay, $filter_sem, $filter_yl);
$analytics = compute_formation_summary_analytics($rows);
$analytics_html = render_formation_summary_analytics($analytics, $filter_ay, $filter_sem, $current_ay, $is_rector);
$has_filter_active = ($filter_ay !== $current_ay && $filter_ay !== 'all') 
    || ($filter_sem !== 'all' && $filter_sem !== '') 
    || ($filter_yl !== 'all' && $filter_yl !== '');
?>
<?php
$pageTitle = 'Formation Summary';
$activeNav = 'formation_summary';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <style>
            .fs-status-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 14px rgba(89, 68, 63, 0.08);
            }
            .fs-pillar-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 14px rgba(89, 68, 63, 0.08);
            }
            .fs-status-card.is-active {
                border-color: #59443F !important;
                box-shadow: 0 0 0 2px #59443F, 0 6px 16px rgba(89, 68, 63, 0.12) !important;
                background: #ffffff !important;
            }
            .fs-pillar-card.is-active {
                border-color: #59443F !important;
                box-shadow: 0 0 0 2px #59443F, 0 6px 16px rgba(89, 68, 63, 0.12) !important;
                background: #ffffff !important;
            }
            .fs-dim-tab.active {
                background: #59443F !important;
                color: #ffffff !important;
            }
            .fs-dim-tab:not(.active):hover {
                background: #F8EEEE !important;
            }
        </style>

        <div class="content-body" style="padding: 1.5rem 2rem;">
            <!-- Compact Header Section -->
            <div style="display:flex; align-items:center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                <div>
                    <h1 class="page-title" style="margin: 0; font-size: 1.5rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-layer-group" style="color: #0f766e;"></i> Formation Summary
                    </h1>
                    <div style="color: #64748b; font-size: 0.85rem; font-weight: 600; margin-top: 0.2rem;">
                        Cross-pillar evaluation metrics across Academic, Spiritual, Human, and Pastoral formation.
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
                    <span class="status-badge status-info" style="font-size: 0.82rem; padding: 0.4rem 0.85rem; background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4; font-weight: 700; border-radius: 9999px;">
                        <i class="fas fa-users" style="margin-right: 0.35rem;"></i> Total Seminarians: <strong id="fsTotalCount"><?php echo count($rows); ?></strong>
                    </span>
                </div>
            </div>

            <!-- Top Visual Analytics Dashboard -->
            <div id="fsAnalyticsContainer">
                <?php echo $analytics_html; ?>
            </div>

            <!-- Formation Evaluation Results Card with Integrated Header Toolbar -->
            <div class="data-card" style="margin-top: 0.5rem; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden;">
                
                <!-- Table Header Row: Title, Active Filter Pills & Integrated Year Level Filter -->
                <div class="data-card-header" style="padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; background: #ffffff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.85rem;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.45rem;">
                            <i class="fas fa-table" style="color: #0f766e;"></i> Formation Evaluation Results
                        </h3>
                        <div style="font-size: 0.78rem; color: #64748b; font-weight: 600; margin-top: 2px;">
                            Showing <span id="fsVisibleCount" style="font-weight: 800; color: #0f766e;"><?php echo count($rows); ?></span> records &bull; Click any seminarian to view full profile
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <!-- Active Filter Indicators -->
                        <div id="fsFilterBadges" style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                            <?php echo render_formation_summary_badges($filter_ay, $filter_sem, $filter_yl, $current_ay); ?>
                        </div>

                        <!-- Integrated Year Level Filter Controls Form -->
                        <form method="get" id="formationFiltersForm" class="capstone-form-row capstone-filter-bar" style="display: inline-flex; flex-direction: row; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin: 0; padding: 0; background: transparent; border: none; box-shadow: none;">
                            <input type="hidden" name="academic_year" id="fsAcademicYearHidden" value="<?php echo htmlspecialchars($filter_ay); ?>">
                            <input type="hidden" name="semester" id="fsSemesterHidden" value="<?php echo htmlspecialchars($filter_sem); ?>">

                            <label for="yearLevelFilter" class="text-xs font-extrabold uppercase tracking-wider text-[#8B7168]" style="margin: 0;">Year Level</label>
                            <select name="year_level" id="yearLevelFilter" aria-label="Year Level" class="minimal-select px-3.5 py-2 text-xs font-bold text-[#59443F] bg-[#FDF9F9] border border-[#E2C9C8] rounded-xl focus:outline-none focus:border-[#59443F] cursor-pointer" style="width: auto; min-width: 175px; margin: 0;" title="Filter by Year Level">
                                <?php foreach ($yl_options as $yl): ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $filter_yl === $yl ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yl); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="px-3.5 py-2 rounded-xl bg-[#59443F] hover:bg-[#43322E] text-white text-xs font-bold shadow-xs transition-all cursor-pointer" style="width: auto; margin: 0;">
                                <i class="fas fa-filter"></i> Apply
                            </button>

                            <!-- Reset Button: Clears back to default when any filter is active -->
                            <a href="formation_summary.php" id="fsResetBtn" class="pill-btn px-3 py-2 rounded-xl bg-[#F8EEEE] hover:bg-[#EFE0DF] text-[#59443F] border border-[#E2C9C8] text-xs font-bold" style="text-decoration: none; width: auto; margin: 0; display: <?php echo $has_filter_active ? 'inline-flex' : 'none'; ?>; align-items: center; gap: 0.35rem;" title="Reset Filters to Default">
                                <i class="fas fa-arrow-rotate-left"></i> Reset
                            </a>
                        </form>
                    </div>
                </div>

                <!-- Active Card/Dimension Filter Banner -->
                <div id="fsActiveFilterBanner" style="display: none; margin: 0.85rem 1.25rem 0 1.25rem; padding: 0.65rem 1rem; background: #FAF8F6; border: 1px solid #E8C7C5; border-radius: 10px; align-items: center; justify-content: space-between; font-size: 0.82rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; color: #59443F; font-weight: 700;">
                        <i class="fa-solid fa-filter" style="color: #8B7168;"></i>
                        <span>Active Filter: <strong id="fsActiveFilterLabel" style="color: #59443F;">Passed</strong></span>
                        <span id="fsActiveFilterCountBadge" class="badge" style="background: #ffffff; color: #59443F; border: 1px solid #E8C7C5; font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 9999px;">0</span>
                    </div>
                    <button type="button" id="fsClearCardFilterBtn" style="background: #ffffff; color: #59443F; border: 1px solid #cbd5e1; padding: 0.25rem 0.7rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.15s ease;">
                        <i class="fa-solid fa-xmark"></i> Clear Card Filter
                    </button>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto relative">
                    <table class="w-full min-w-full border-collapse !mt-0">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Seminarian</th>
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Academic Eval</th>
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Spiritual Eval</th>
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Human Eval</th>
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Pastoral Eval</th>
                                <th class="bg-gray-50 text-gray-500 text-xs font-medium uppercase tracking-wider text-center py-3 px-4 whitespace-nowrap">Overall Status</th>
                            </tr>
                        </thead>
                        <tbody id="fsRowsTbody" class="divide-y divide-gray-100 bg-white transition-opacity duration-200">
                            <?php echo render_formation_summary_rows($rows, $filter_ay); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const fsSearchInput = null;
            const fsVisibleCountEl = document.getElementById('fsVisibleCount');
            const fsTotalCountEl = document.getElementById('fsTotalCount');
            const fsRowsTbody = document.getElementById('fsRowsTbody');
            const fsBadgesContainer = document.getElementById('fsFilterBadges');
            const fsResetBtn = document.getElementById('fsResetBtn');
            const fsAySelect = document.getElementById('fsAcademicYear');
            const fsAyHidden = document.getElementById('fsAcademicYearHidden');
            const fsYlSelect = document.getElementById('yearLevelFilter');
            const fsSemSelect = document.getElementById('fsSemester') || document.getElementById('fsSemesterHidden');
            const defaultAy = <?php echo json_encode($current_ay); ?>;

            // Interactive Card/Tab Filters State
            let activeStatusFilter = 'all'; // 'all', 'passed', 'for_review', 'retaining', 'at_risk'
            let activePillarFilter = 'all'; // 'all', 'academic', 'spiritual', 'human', 'pastoral'
            let activePillarReviewOnly = false;
            let activeDimensionTab = 'all'; // 'all', 'academic', 'spiritual', 'human', 'pastoral', 'needs_attention'

            let currentAbortController = null;

            function getActiveAyValue() {
                if (fsAySelect) return fsAySelect.value;
                if (fsAyHidden) return fsAyHidden.value;
                return defaultAy;
            }

            // Cross-filtering combining Search Query, Status Card, Pillar Card, and Dimension Tabs
            function applyAllFilters() {
                const q = (fsSearchInput ? fsSearchInput.value : '').toLowerCase().trim();
                const currentRows = document.querySelectorAll('#fsRowsTbody .fs-row');
                const noMatchRow = document.getElementById('fsNoMatchRow');
                let visible = 0;

                currentRows.forEach(function (row) {
                    let match = true;

                    // 1. Text Search Filter
                    if (q !== '') {
                        const text = row.textContent.toLowerCase();
                        if (!text.includes(q)) match = false;
                    }

                    // 2. Status Card Filter
                    if (match && activeStatusFilter !== 'all') {
                        const st = row.getAttribute('data-status') || '';
                        if (st !== activeStatusFilter) match = false;
                    }

                    // 3. Pillar Card Filter
                    if (match && activePillarFilter !== 'all') {
                        if (activePillarReviewOnly) {
                            const isReview = row.getAttribute('data-' + activePillarFilter + '-for-review') === '1';
                            if (!isReview) match = false;
                        } else {
                            const isEval = row.getAttribute('data-' + activePillarFilter + '-evaluated') === '1';
                            if (!isEval) match = false;
                        }
                    }

                    // 4. Dimension Tab Filter
                    if (match && activeDimensionTab !== 'all') {
                        if (activeDimensionTab === 'needs_attention') {
                            const st = row.getAttribute('data-status') || '';
                            const na = row.getAttribute('data-needs-attention') === '1';
                            if (!na && st !== 'needs_improvement' && st !== 'poor') {
                                match = false;
                            }
                        } else {
                            const isEval = row.getAttribute('data-' + activeDimensionTab + '-evaluated') === '1';
                            if (!isEval) match = false;
                        }
                    }

                    row.style.display = match ? '' : 'none';
                    if (match) visible++;
                });

                if (noMatchRow) {
                    noMatchRow.style.display = (visible === 0 && currentRows.length > 0) ? '' : 'none';
                }
                if (fsVisibleCountEl) {
                    fsVisibleCountEl.textContent = visible;
                }

                updateActiveFilterBanner(visible);
            }

            function updateActiveFilterBanner(visible) {
                const banner = document.getElementById('fsActiveFilterBanner');
                const labelEl = document.getElementById('fsActiveFilterLabel');
                const countBadge = document.getElementById('fsActiveFilterCountBadge');
                if (!banner) return;

                let filterDesc = '';
                if (activeStatusFilter !== 'all') {
                    const labels = {
                        'outstanding': 'Overall Status: Outstanding',
                        'very_good': 'Overall Status: Very Good',
                        'satisfactory': 'Overall Status: Satisfactory',
                        'needs_improvement': 'Overall Status: Needs Improvement',
                        'poor': 'Overall Status: Poor',
                        'pending': 'Overall Status: Pending'
                    };
                    filterDesc = labels[activeStatusFilter] || activeStatusFilter;
                } else if (activePillarFilter !== 'all') {
                    const pillarLabels = {
                        'academic': 'Academic Pillar',
                        'spiritual': 'Spiritual Pillar',
                        'human': 'Human Pillar',
                        'pastoral': 'Pastoral Pillar'
                    };
                    filterDesc = (pillarLabels[activePillarFilter] || activePillarFilter) + (activePillarReviewOnly ? ' (Needs Attention)' : ' (Evaluated)');
                } else if (activeDimensionTab !== 'all') {
                    if (activeDimensionTab === 'needs_attention') {
                        filterDesc = 'Seminarians Needing Attention (Needs Improvement / Poor)';
                    } else {
                        const dimNames = {
                            'academic': 'Academic Formator',
                            'spiritual': 'Spiritual Formator',
                            'human': 'Human Formator',
                            'pastoral': 'Pastoral Formator'
                        };
                        filterDesc = dimNames[activeDimensionTab] || activeDimensionTab;
                    }
                }

                if (filterDesc !== '') {
                    banner.style.display = 'flex';
                    if (labelEl) labelEl.textContent = filterDesc;
                    if (countBadge) countBadge.textContent = visible + ' seminarians';
                } else {
                    banner.style.display = 'none';
                }
            }

            function highlightActiveCards() {
                // Status Cards
                document.querySelectorAll('.fs-status-card').forEach(function(card) {
                    const sf = card.getAttribute('data-status-filter') || 'all';
                    const isSelected = (sf === activeStatusFilter && activeStatusFilter !== 'all');
                    card.classList.toggle('is-active', isSelected);
                });

                // Pillar Cards
                document.querySelectorAll('.fs-pillar-card').forEach(function(card) {
                    const pf = card.getAttribute('data-pillar') || '';
                    const isSelected = (pf === activePillarFilter);
                    card.classList.toggle('is-active', isSelected);
                });

                // Dimension Tabs
                document.querySelectorAll('.fs-dim-tab').forEach(function(tab) {
                    const dim = tab.getAttribute('data-dimension') || 'all';
                    const isSelected = (dim === activeDimensionTab);
                    tab.classList.toggle('active', isSelected);
                });
            }

            function bindAnalyticsInteractions() {
                // Status Cards Click
                document.querySelectorAll('.fs-status-card').forEach(function(card) {
                    card.addEventListener('click', function() {
                        const status = this.getAttribute('data-status-filter') || 'all';
                        if (activeStatusFilter === status && status !== 'all') {
                            activeStatusFilter = 'all';
                        } else {
                            activeStatusFilter = status;
                        }
                        activePillarFilter = 'all';
                        activePillarReviewOnly = false;
                        activeDimensionTab = 'all';
                        highlightActiveCards();
                        applyAllFilters();
                    });
                });

                // Pillar Cards Click
                document.querySelectorAll('.fs-pillar-card').forEach(function(card) {
                    card.addEventListener('click', function(e) {
                        const reviewSubBadge = e.target.closest('.fs-pillar-subfilter');
                        const pillar = this.getAttribute('data-pillar') || '';
                        
                        if (reviewSubBadge) {
                            e.stopPropagation();
                            activePillarFilter = pillar;
                            activePillarReviewOnly = true;
                            activeStatusFilter = 'all';
                            activeDimensionTab = 'all';
                        } else {
                            if (activePillarFilter === pillar && !activePillarReviewOnly) {
                                activePillarFilter = 'all';
                            } else {
                                activePillarFilter = pillar;
                                activePillarReviewOnly = false;
                            }
                            activeStatusFilter = 'all';
                            activeDimensionTab = 'all';
                        }
                        highlightActiveCards();
                        applyAllFilters();
                    });
                });

                // Dimension Tabs Click
                document.querySelectorAll('.fs-dim-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        const dim = this.getAttribute('data-dimension') || 'all';
                        activeDimensionTab = dim;
                        activeStatusFilter = 'all';
                        activePillarFilter = 'all';
                        activePillarReviewOnly = false;
                        highlightActiveCards();
                        applyAllFilters();
                    });
                });

                // Clear Filter Banner Button
                const clearBannerBtn = document.getElementById('fsClearCardFilterBtn');
                if (clearBannerBtn) {
                    clearBannerBtn.addEventListener('click', function() {
                        activeStatusFilter = 'all';
                        activePillarFilter = 'all';
                        activePillarReviewOnly = false;
                        activeDimensionTab = 'all';
                        highlightActiveCards();
                        applyAllFilters();
                    });
                }
            }

            // Quick search input listeners
            if (fsSearchInput) {
                fsSearchInput.addEventListener('input', applyAllFilters);
                fsSearchInput.addEventListener('keyup', applyAllFilters);
            }

            // Global hotkey '/' to quickly focus search input
            window.addEventListener('keydown', function(e) {
                if (e.key === '/' && document.activeElement !== fsSearchInput) {
                    const tag = (document.activeElement.tagName || '').toLowerCase();
                    if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
                        e.preventDefault();
                        if (fsSearchInput) {
                            fsSearchInput.focus();
                            fsSearchInput.select();
                        }
                    }
                }
            });

            // Dynamic AJAX filter fetcher
            function fetchFilteredFormationData(ylOverride) {
                const ay = getActiveAyValue();
                const yl = typeof ylOverride === 'string' ? ylOverride : (fsYlSelect ? fsYlSelect.value : '');
                const sem = fsSemSelect ? fsSemSelect.value : '';

                // Build query params
                const params = new URLSearchParams();
                params.set('ajax', '1');
                if (ay) params.set('academic_year', ay);
                if (yl) params.set('year_level', yl);
                if (sem) params.set('semester', sem);

                // Cancel previous pending request
                if (currentAbortController) {
                    currentAbortController.abort();
                }
                currentAbortController = new AbortController();

                // Dim tbody during fetch for clean visual feedback
                if (fsRowsTbody) {
                    fsRowsTbody.style.opacity = '0.4';
                }

                fetch('formation_summary.php?' + params.toString(), {
                    signal: currentAbortController.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) {
                    if (!res.ok) throw new Error('Network error: ' + res.status);
                    return res.json();
                })
                .then(function(data) {
                    if (fsRowsTbody) {
                        fsRowsTbody.innerHTML = data.html || '';
                        fsRowsTbody.style.opacity = '1';
                    }
                    if (fsVisibleCountEl) {
                        fsVisibleCountEl.textContent = data.count;
                    }
                    if (fsTotalCountEl) {
                        fsTotalCountEl.textContent = data.total;
                    }
                    if (fsBadgesContainer) {
                        fsBadgesContainer.innerHTML = data.badges_html || '';
                    }
                    if (fsResetBtn) {
                        fsResetBtn.style.display = data.has_filter_active ? 'inline-flex' : 'none';
                    }

                    // Update Top Analytics Dashboard
                    const analyticsContainer = document.getElementById('fsAnalyticsContainer');
                    if (analyticsContainer && data.analytics_html) {
                        analyticsContainer.innerHTML = data.analytics_html;
                        bindAnalyticsInteractions();
                    }

                    // Reset card filters on AY/Sem/YL switch
                    activeStatusFilter = 'all';
                    activePillarFilter = 'all';
                    activePillarReviewOnly = false;
                    activeDimensionTab = 'all';
                    highlightActiveCards();

                    // Update browser address bar without full page reload
                    const pageParams = new URLSearchParams();
                    if (ay && ay !== 'all' && ay !== defaultAy) pageParams.set('academic_year', ay);
                    if (ay === 'all') pageParams.set('academic_year', 'all');
                    if (yl && yl !== 'all') pageParams.set('year_level', yl);
                    if (sem && sem !== 'all') pageParams.set('semester', sem);
                    const newUrl = window.location.pathname + (pageParams.toString() ? '?' + pageParams.toString() : '');
                    window.history.pushState(null, '', newUrl);

                    // Re-apply filters
                    applyAllFilters();
                })
                .catch(function(err) {
                    if (err.name !== 'AbortError') {
                        console.error('AJAX Filter error:', err);
                        if (fsRowsTbody) fsRowsTbody.style.opacity = '1';
                        // Fallback to form submit
                        const form = document.getElementById('formationFiltersForm');
                        if (form) form.submit();
                    }
                });
            }

            // Attach AJAX change & submit listeners
            const fsFilterForm = document.getElementById('formationFiltersForm');
            if (fsFilterForm) {
                fsFilterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    fetchFilteredFormationData();
                });
            }
            if (fsAySelect) fsAySelect.addEventListener('change', function() { fetchFilteredFormationData(); });
            if (fsYlSelect) fsYlSelect.addEventListener('change', function() { fetchFilteredFormationData(); });
            if (fsSemSelect) fsSemSelect.addEventListener('change', function() { fetchFilteredFormationData(); });

            // AJAX Reset button handler
            if (fsResetBtn) {
                fsResetBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (fsAySelect) fsAySelect.value = defaultAy;
                    if (fsAyHidden) fsAyHidden.value = defaultAy;
                    if (fsYlSelect) fsYlSelect.selectedIndex = 0;
                    if (fsSemSelect) fsSemSelect.value = 'all';
                    fetchFilteredFormationData('all');
                });
            }

            // Handle browser Back / Forward history navigation
            window.addEventListener('popstate', function() {
                const urlParams = new URLSearchParams(window.location.search);
                const ay = urlParams.get('academic_year') || defaultAy;
                const yl = urlParams.get('year_level') || 'all';
                const sem = urlParams.get('semester') || 'all';

                if (fsAySelect) fsAySelect.value = ay;
                if (fsAyHidden) fsAyHidden.value = ay;
                if (fsYlSelect && yl !== 'all') fsYlSelect.value = yl;
                if (fsSemSelect) fsSemSelect.value = sem;

                fetchFilteredFormationData(yl);
            });

            // Initial bind of analytics interactions
            bindAnalyticsInteractions();
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>