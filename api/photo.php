<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Authenticated photo download. Requires Authorization: Bearer <token>.
 *
 * GET /api/photo.php?file=<filename>
 * Response: the image bytes (PNG/JPG/GIF), cached privately by the browser.
 *
 * The folder is taken from the authenticated user's id, never from the
 * request, so users can only ever read their own photos. Photos live outside
 * the web root; this is the only way to fetch them.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respondJson(405, ['error' => 'Method not allowed']);
}

try {
    $authUser = requireAuth();
    $userId = (int)$authUser['id'];
    $filename = basename((string)($_GET['file'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_]+\.(png|jpg|gif)$/', $filename, $m)) {
        respondJson(400, ['error' => 'Invalid photo name']);
    }

    $path = photoStorageRoot() . '/' . $userId . '/' . $filename;
    if (!is_file($path)) {
        respondJson(404, ['error' => 'Photo not found']);
    }

    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];
    header('Content-Type: ' . $types[$m[1]]);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    respondServerError($e, 'photo.php');
}
