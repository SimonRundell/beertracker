<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Logout endpoint. Requires Authorization: Bearer <token>.
 *
 * Endpoint: POST /api/logout.php
 * Response: {"status":"ok"}
 *
 * Invalidates the caller's session token server-side, so a copied or leaked
 * token stops working immediately instead of lingering until expiry.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ['error' => 'Method not allowed']);
}

try {
    $user = requireAuth();
    $userId = (int)$user['id'];
    $stmt = db()->prepare('UPDATE users SET token = NULL, token_expires_at = NULL WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    respondJson(200, ['status' => 'ok']);
} catch (Throwable $e) {
    respondServerError($e, 'logout.php');
}
