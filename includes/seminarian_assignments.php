<?php
if (!function_exists('get_default_assignee_user_id')) {
    function get_default_assignee_user_id(mysqli $conn, string $assignment_type): int {
        $type = strtolower(trim($assignment_type));
        $role = null;

        if ($type === 'academic') {
            $role = 'AcademicFormator';
        } elseif ($type === 'human') {
            $role = 'HumanFormator';
        } elseif ($type === 'spiritual') {
            $role = 'SpiritualDirector';
        } elseif ($type === 'pastoral') {
            $role = 'PastoralFormator';
        } elseif ($type === 'cashier') {
            $role = 'Cashier';
        }

        if ($role === null) {
            return 0;
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE role = ? ORDER BY id ASC LIMIT 1");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['id'] ?? 0);
    }
}

if (!function_exists('create_default_seminarian_assignments')) {
    function create_default_seminarian_assignments(mysqli $conn, int $seminarian_id, ?int $assigned_by_user_id = null): void {
        $types = ['Academic', 'Human', 'Spiritual', 'Pastoral', 'Cashier'];
        $assigned_by = $assigned_by_user_id !== null ? (int)$assigned_by_user_id : 0;

        $stmt = $conn->prepare(
            "INSERT INTO seminarian_assignments (seminarian_id, assignment_type, assigned_user_id, assigned_by, active)
            VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), 1)
            ON DUPLICATE KEY UPDATE
                assigned_user_id = VALUES(assigned_user_id),
                assigned_by = VALUES(assigned_by),
                active = VALUES(active)"
        );

        foreach ($types as $t) {
            $default_uid = get_default_assignee_user_id($conn, $t);
            $uid = $default_uid > 0 ? $default_uid : 0;
            $stmt->bind_param('isii', $seminarian_id, $t, $uid, $assigned_by);
            $stmt->execute();
        }
    }
}

if (!function_exists('build_assigned_seminarian_where')) {
    function build_assigned_seminarian_where(mysqli $conn, string $assignment_type, int $assigned_user_id): array {
        $type = trim($assignment_type);
        $uid = (int)$assigned_user_id;
        return [
            'sql' => "EXISTS (
                SELECT 1 FROM seminarian_assignments sa
                WHERE sa.seminarian_id = s.id
                  AND sa.assignment_type = ?
                  AND sa.assigned_user_id = ?
                  AND sa.active = 1
            )",
            'types' => 'si',
            'params' => [$type, $uid],
        ];
    }
}
