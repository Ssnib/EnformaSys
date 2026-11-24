<?php
function afe_get_default_semester(): string {
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

function afe_get_latest_gpa(mysqli $conn, int $student_id) {
    $stmt = $conn->prepare("SELECT cumulative_gpa, semester_gpa
        FROM gpa_records
        WHERE student_id = ? AND (cumulative_gpa IS NOT NULL OR semester_gpa IS NOT NULL)
        ORDER BY academic_year DESC, FIELD(semester,'Summer','2nd','1st') DESC
        LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    if ($row['cumulative_gpa'] !== null && is_numeric($row['cumulative_gpa'])) {
        return (float)$row['cumulative_gpa'];
    }
    if ($row['semester_gpa'] !== null && is_numeric($row['semester_gpa'])) {
        return (float)$row['semester_gpa'];
    }
    return null;
}

require_once __DIR__ . '/eval_ui_helpers.php';

function afe_rating_label($avg) {
    if ($avg === null || $avg === '' || !is_numeric($avg)) {
        return null;
    }
    return getFormationInterpretation((float)$avg)['label'];
}

function afe_recommendation_for_label(?string $label): ?string {
    if ($label === null) return null;
    if ($label === 'Outstanding') return 'Sustain and mentor peers';
    if ($label === 'Very Good' || $label === 'Very Satisfactory') return 'Maintain consistency';
    if ($label === 'Satisfactory') return 'Strengthen study habits';
    if ($label === 'Needs Improvement') return 'Academic intervention plan';
    if ($label === 'Poor' || $label === 'Unsatisfactory') return 'Immediate academic support';
    return null;
}

function afe_compute_results(array $ratings): array {
    $valid = [];
    foreach ($ratings as $r) {
        if (is_numeric($r)) {
            $n = (int)$r;
            if ($n >= 1 && $n <= 5) {
                $valid[] = $n;
            }
        }
    }

    $count = count($valid);
    $total = array_sum($valid);
    $avg = $count > 0 ? ($total / $count) : 0.0;
    $label = afe_rating_label($avg);
    $rec = afe_recommendation_for_label($label);

    return [
        'count' => $count,
        'total' => $total,
        'average' => $avg,
        'label' => $label,
        'recommendation' => $rec,
    ];
}
