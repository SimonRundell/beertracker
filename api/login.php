<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ['error' => 'Method not allowed']);
}

$body = readJsonBody();
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    respondJson(400, ['error' => 'Missing email or password']);
}

try {
    $conn = db();
    $stmt = $conn->prepare('SELECT id, name, email, password_md5, status, avatar_base64 FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respondJson(401, ['error' => 'Invalid credentials']);
    }

    $hash = hashPassword($password);
    if (!hash_equals($row['password_md5'], $hash)) {
        respondJson(401, ['error' => 'Invalid credentials']);
    }

    [$token, $expires] = newSessionToken();
    $update = $conn->prepare('UPDATE users SET token = ?, token_expires_at = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->bind_param('ssi', $token, $expires, $row['id']);
    $update->execute();
    $update->close();

    respondJson(200, [
        'token' => $token,
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'status' => $row['status'],
        'avatar_base64' => $row['avatar_base64'] ?? null,
    ]);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Login failed', 'message' => $e->getMessage()]);
}
