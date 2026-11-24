<?php
function pfe_get_default_semester(): string {
    if (function_exists('get_active_semester')) {
        $active = function_exists('enformasys_normalize_semester')
            ? enformasys_normalize_semester((string)get_active_semester())
            : trim((string)get_active_semester());
        if (in_array($active, ['1st', '2nd', 'Summer'], true)) {
            return $active;
        }
    }
    return '1st';
}

require_once __DIR__ . '/eval_ui_helpers.php';

function pfe_interpretation($avg): ?string {
    if ($avg === null || $avg === '' || !is_numeric($avg)) {
        return null;
    }
    return getFormationInterpretation((float)$avg)['label'];
}

function pfe_likert_label($v): string {
    $n = (int)$v;
    if ($n === 5) return 'Outstanding';
    if ($n === 4) return 'Very Good';
    if ($n === 3) return 'Satisfactory';
    if ($n === 2) return 'Needs Improvement';
    if ($n === 1) return 'Poor';
    return '';
}

function pfe_compute_growth(?float $previous, ?float $current): array {
    if ($previous === null || $current === null) {
        return ['status' => null, 'percent' => null];
    }
    if ($previous <= 0) {
        return ['status' => 'Stable', 'percent' => null];
    }
    $diff = $current - $previous;
    $pct = ($diff / $previous) * 100.0;
    $status = 'Stable';
    if ($diff >= 0.20) {
        $status = 'Growing';
    } elseif ($diff <= -0.20) {
        $status = 'Declining';
    }
    return ['status' => $status, 'percent' => $pct];
}

function pfe_compute_results(array $questions, array $ratings): array {
    $total = 0;
    $count = 0;
    $cat_totals = [];
    $cat_counts = [];

    foreach ($questions as $q) {
        $qid = (int)($q['question_id'] ?? $q['id'] ?? 0);
        $cat = (string)($q['category'] ?? '');
        $val = isset($ratings[$qid]) ? (int)$ratings[$qid] : 0;
        if ($val < 1 || $val > 5) {
            continue;
        }
        $total += $val;
        $count += 1;
        if (!isset($cat_totals[$cat])) $cat_totals[$cat] = 0;
        if (!isset($cat_counts[$cat])) $cat_counts[$cat] = 0;
        $cat_totals[$cat] += $val;
        $cat_counts[$cat] += 1;
    }

    $avg = $count > 0 ? ($total / $count) : 0.0;
    $interp = pfe_interpretation($avg);

    $cat_avgs = [];
    foreach ($cat_totals as $cat => $sum) {
        $c = (int)($cat_counts[$cat] ?? 0);
        $cat_avgs[$cat] = $c > 0 ? ($sum / $c) : null;
    }

    return [
        'count' => $count,
        'total' => $total,
        'average' => $avg,
        'interpretation' => $interp,
        'category_avgs' => $cat_avgs,
    ];
}
?>
