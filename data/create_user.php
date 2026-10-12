<?php
declare(strict_types=1);

/**
 * Command-line tool to create (or reset the password of) a user without opening
 * public registration.
 *
 * Usage: php data/create_user.php "Name" email@example.com "password"
 *
 * Refuses to run under a web server.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/common.php';

[$script, $name, $email, $password] = array_pad($argv, 4, '');
if ($name === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Usage: php data/create_user.php \"Name\" email@example.com \"password\"\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address\n");
    exit(1);
}
if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, 'Password must be at least ' . MIN_PASSWORD_LENGTH . " characters\n");
    exit(1);
}

$hash = hashPassword($password);
$stmt = db()->prepare(
    "INSERT INTO users (name, email, password_hash, status) VALUES (?, ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), password_md5 = NULL, token = NULL, token_expires_at = NULL"
);
$stmt->bind_param('sss', $name, $email, $hash);
$stmt->execute();
echo ($stmt->affected_rows === 1 ? 'Created' : 'Updated') . " user {$email}\n";
