<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Login endpoint.
 *
 * Endpoint: POST /api/login.php
 * Body: {"email":string, "password":string}
 * Response: {"token":string, "id":number, "name":string, "email":string, "status":string, "avatar_base64":string|null}
 *
 * Issues a new session token (see newSessionToken() in common.php) on success,
 * which the client must send as `Authorization: Bearer <token>` on every
 * subsequent authenticated request.
 *
 * Rate limited: 5 failed attempts per 15 minutes per IP and per email address
 * (429 afterwards). A successful login clears that email's failure count.
 */

const LOGIN_MAX_FAILURES = 5;
const LOGIN_WINDOW_SECONDS = 900;

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
    $ipBucket = 'login:ip:' . clientIp();
    $emailBucket = 'login:email:' . sha1(mb_strtolower($email));
    enforceRateLimit($ipBucket, LOGIN_MAX_FAILURES * 4, LOGIN_WINDOW_SECONDS);
    enforceRateLimit($emailBucket, LOGIN_MAX_FAILURES, LOGIN_WINDOW_SECONDS);

    $conn = db();
    $stmt = $conn->prepare('SELECT id, name, email, password_hash, password_md5, status, avatar_base64 FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Hash anyway for unknown emails so response time doesn't reveal which exist.
    $valid = $row ? checkPassword($conn, $row, $password) : (hashPassword($password) === '');

    if (!$valid) {
        recordRateLimitHit($ipBucket);
        recordRateLimitHit($emailBucket);
        respondJson(401, ['error' => 'Invalid credentials']);
    }
    clearRateLimit($emailBucket);

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
    respondServerError($e, 'login.php');
}
