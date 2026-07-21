<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

function detectImageType(string $binary): ?string {
    if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($binary, "GIF87a") || str_starts_with($binary, "GIF89a")) {
        return 'image/gif';
    }
    // JPEG: FF D8 ...
    if (substr($binary, 0, 2) === "\xFF\xD8") {
        return 'image/jpeg';
    }
    return null;
}

function normalizeAvatar(?string $raw, int $maxBytes = 10485760): ?string {
    if ($raw === null) {
        // Caller decides whether null means clear or no change.
        return null;
    }
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return null;
    }

    $payload = $trimmed;
    $mime = null;
    if (str_starts_with($trimmed, 'data:')) {
        $parts = explode(',', $trimmed, 2);
        if (count($parts) !== 2) {
            respondJson(400, ['error' => 'Invalid avatar data URI']);
        }
        $meta = $parts[0];
        $payload = $parts[1];
        if (!str_ends_with($meta, ';base64')) {
            respondJson(400, ['error' => 'Avatar must be base64-encoded']);
        }
        $mime = substr($meta, 5, -7); // strip data: and ;base64
    }

    $binary = base64_decode($payload, true);
    if ($binary === false) {
        respondJson(400, ['error' => 'Avatar must be valid base64']);
    }

    if (strlen($binary) > $maxBytes) {
        respondJson(400, ['error' => 'Avatar exceeds 10MB limit']);
    }

    $detected = detectImageType($binary);
    $finalMime = $mime ?: $detected;
    $allowed = ['image/png', 'image/jpeg', 'image/gif'];
    if (!$finalMime || !in_array($finalMime, $allowed, true)) {
        respondJson(400, ['error' => 'Avatar must be PNG, JPG, or GIF']);
    }

    // Re-encode to ensure clean storage and consistent MIME
    $clean = base64_encode($binary);
    return "data:{$finalMime};base64,{$clean}";
}

function fetchUser(mysqli $conn, int $userId): array {
    $stmt = $conn->prepare('SELECT id, name, email, status, avatar_base64, password_md5 FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respondJson(404, ['error' => 'User not found']);
    }

    return $row;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$authUser = requireAuth();
$userId = (int)$authUser['id'];

try {
    if ($method === 'GET') {
        $conn = db();
        $user = fetchUser($conn, $userId);

        respondJson(200, [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'status' => $user['status'],
            'avatar_base64' => $user['avatar_base64'] ?? null,
        ]);
    }

    if ($method === 'POST') {
        $body = readJsonBody();
        $name = array_key_exists('name', $body) ? trim((string)$body['name']) : null;
        $avatarProvided = array_key_exists('avatar_base64', $body);
        $avatarValue = $avatarProvided ? normalizeAvatar($body['avatar_base64'] ?? null) : null;
        $currentPassword = $body['current_password'] ?? null;
        $newPassword = $body['new_password'] ?? null;

        if ($name !== null && $name === '') {
            respondJson(400, ['error' => 'Name cannot be empty']);
        }
        if ($newPassword !== null && $newPassword === '') {
            respondJson(400, ['error' => 'New password cannot be empty']);
        }

        $conn = db();
        $user = fetchUser($conn, $userId);

        $sets = [];
        $types = '';
        $params = [];

        if ($name !== null) {
            $sets[] = 'name = ?';
            $types .= 's';
            $params[] = $name;
        }

        if ($avatarProvided) {
            $sets[] = 'avatar_base64 = ?';
            $types .= 's';
            $params[] = $avatarValue; // May be null to clear
        }

        if ($newPassword !== null) {
            if ($currentPassword === null || hashPassword((string)$currentPassword) !== $user['password_md5']) {
                respondJson(400, ['error' => 'Current password is incorrect']);
            }
            $sets[] = 'password_md5 = ?';
            $types .= 's';
            $params[] = hashPassword((string)$newPassword);
        }

        if (count($sets) === 0) {
            respondJson(400, ['error' => 'Nothing to update']);
        }

        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $query = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $conn->prepare($query);

        $types .= 'i';
        $params[] = $userId;
        $bindParams = [];
        foreach ($params as $k => $v) {
            $bindParams[$k] = &$params[$k];
        }
        array_unshift($bindParams, $types);
        call_user_func_array([$stmt, 'bind_param'], $bindParams);

        $stmt->execute();
        $stmt->close();

        // Return fresh user info
        $updated = fetchUser($conn, $userId);
        respondJson(200, [
            'id' => (int)$updated['id'],
            'name' => $updated['name'],
            'email' => $updated['email'],
            'status' => $updated['status'],
            'avatar_base64' => $updated['avatar_base64'] ?? null,
        ]);
    }

    respondJson(405, ['error' => 'Method not allowed']);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Request failed', 'message' => $e->getMessage()]);
}
