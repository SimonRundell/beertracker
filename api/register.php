<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ['error' => 'Method not allowed']);
}

$body = readJsonBody();
$name = trim((string)($body['name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($name === '' || $email === '' || $password === '') {
    respondJson(400, ['error' => 'Missing name, email, or password']);
}

try {
    $conn = db();

    // Ensure unique email
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        respondJson(409, ['error' => 'Email already registered']);
    }
    $stmt->close();

    $hash = hashPassword($password);
    $status = 'user';
    $stmt = $conn->prepare('INSERT INTO users (name, email, password_md5, status, avatar_base64) VALUES (?, ?, ?, ?, NULL)');
    $stmt->bind_param('ssss', $name, $email, $hash, $status);
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    $token = bin2hex(random_bytes(16));
    respondJson(201, [
        'token' => $token,
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'status' => $status,
        'avatar_base64' => null,
    ]);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Registration failed', 'message' => $e->getMessage()]);
}
