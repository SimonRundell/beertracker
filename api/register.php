<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Registration endpoint.
 *
 * Endpoint: POST /api/register.php
 * Body: {"name":string, "email":string, "password":string}
 * Response: {"token":string, "id":number, "name":string, "email":string, "status":string, "avatar_base64":null}
 *
 * Creates a new user and immediately issues a session token, same contract as login.php.
 * Disabled (403) unless "allow_registration": true is set in api/.config.json.
 * Rate limited to 5 registrations per hour per IP.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ['error' => 'Method not allowed']);
}

if (config('allow_registration', false) !== true) {
    respondJson(403, ['error' => 'Registration is currently closed']);
}

$body = readJsonBody();
$name = trim((string)($body['name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($name === '' || $email === '' || $password === '') {
    respondJson(400, ['error' => 'Missing name, email, or password']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    respondJson(400, ['error' => 'Please enter a valid email address']);
}
if (mb_strlen($name) > 120) {
    respondJson(400, ['error' => 'Name must be 120 characters or fewer']);
}
requireStrongPassword($password);

try {
    consumeRateLimit('register:ip:' . clientIp(), 5, 3600);

    $conn = db();
    $hash = hashPassword($password);
    $status = 'user';
    [$token, $expires] = newSessionToken();

    // The unique email key is the single source of truth, so there is no
    // check-then-insert race: a duplicate surfaces as MySQL error 1062.
    $stmt = $conn->prepare(
        'INSERT INTO users (name, email, password_hash, status, avatar_base64, token, token_expires_at)
         VALUES (?, ?, ?, ?, NULL, ?, ?)'
    );
    $stmt->bind_param('ssssss', $name, $email, $hash, $status, $token, $expires);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            respondJson(409, ['error' => 'Email already registered']);
        }
        throw $e;
    }
    $userId = $stmt->insert_id;
    $stmt->close();

    respondJson(201, [
        'token' => $token,
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'status' => $status,
        'avatar_base64' => null,
    ]);
} catch (Throwable $e) {
    respondServerError($e, 'register.php');
}
