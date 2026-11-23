<?php

function capstone_access_request_get_pending(mysqli $conn, int $seminarian_id, string $assignment_type): ?array
{
    $stmt = $conn->prepare("SELECT id, status, requested_at, request_scope, requested_subjects
        FROM seminarian_access_requests
        WHERE seminarian_id = ? AND assignment_type = ? AND status = 'Pending'
        ORDER BY requested_at DESC, id DESC
        LIMIT 1");
    $stmt->bind_param('is', $seminarian_id, $assignment_type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function capstone_access_request_create(
    mysqli $conn,
    int $seminarian_id,
    string $assignment_type,
    ?int $requested_by_user_id = null,
    ?string $message = null,
    string $request_scope = 'whole',
    $requested_subjects = null
): array
{
    $assignment_type = trim($assignment_type);
    if (!in_array($assignment_type, ['Academic', 'Spiritual', 'Human', 'Pastoral'], true)) {
        return ['ok' => false, 'error' => 'Invalid request type.'];
    }

    $clean_message = trim((string)($message ?? ''));

    $pending = capstone_access_request_get_pending($conn, $seminarian_id, $assignment_type);
    if ($pending) {
        return ['ok' => false, 'error' => 'You already have a pending request.'];
    }

    $request_scope = in_array($request_scope, ['whole', 'selected'], true) ? $request_scope : 'whole';
    $subjects_json = null;
    if ($request_scope === 'selected') {
        if (is_array($requested_subjects) && !empty($requested_subjects)) {
            $subjects_json = json_encode(array_values($requested_subjects));
        } else {
            return ['ok' => false, 'error' => 'Please select at least one subject for the request.'];
        }
    }

    $assignee_user_id = 0;
    $stmt_as = $conn->prepare("SELECT assigned_user_id
        FROM seminarian_assignments
        WHERE seminarian_id = ? AND assignment_type = ? AND active = 1
        LIMIT 1");
    $stmt_as->bind_param('is', $seminarian_id, $assignment_type);
    $stmt_as->execute();
    $as = $stmt_as->get_result()->fetch_assoc();
    if ($as && $as['assigned_user_id'] !== null) {
        $assignee_user_id = (int)$as['assigned_user_id'];
    }

    $stmt = $conn->prepare("INSERT INTO seminarian_access_requests (seminarian_id, assignment_type, assigned_user_id, status, requested_by, message, request_scope, requested_subjects, requested_at)
        VALUES (?, ?, NULLIF(?,0), 'Pending', ?, NULLIF(?,''), ?, ?, NOW())");
    $stmt->bind_param('isiisss', $seminarian_id, $assignment_type, $assignee_user_id, $requested_by_user_id, $clean_message, $request_scope, $subjects_json);
    $ok = $stmt->execute();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Failed to submit request: ' . $stmt->error];
    }
    return ['ok' => true];
}

