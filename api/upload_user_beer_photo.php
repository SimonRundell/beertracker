<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Photo upload endpoint. Requires Authorization: Bearer <token>.
 *
 * POST /api/upload_user_beer_photo.php (multipart/form-data)
 * Fields: beer, brewery, photo (file, PNG/JPG/GIF, max 10MB)
 * Response: {"status":"ok", "filename":string, "photos":string[]}
 *
 * A beer can hold at most MAX_PHOTOS_PER_BEER photos (400 beyond that).
 * Files are stored outside the web root and only served by photo.php.
 * Rate limited to 60 uploads per hour per user.
 */

/** Create $path (recursively) if it doesn't already exist, or respond 500 and exit. */
function ensureDir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            respondJson(500, ['error' => 'Failed to create upload directory']);
        }
    }
}

/** Map an allowed image MIME type to its file extension. */
function detectExtension(string $mime): ?string {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        default => null,
    };
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respondJson(405, ['error' => 'Method not allowed']);
    }

    $authUser = requireAuth();
    $userId = (int)$authUser['id'];
    $beer = normalizeName((string)($_POST['beer'] ?? ''));
    $brewery = normalizeName((string)($_POST['brewery'] ?? ''));

    if ($beer === '' || $brewery === '') {
        respondJson(400, ['error' => 'beer and brewery are required']);
    }

    if (!isset($_FILES['photo'])) {
        respondJson(400, ['error' => 'photo file is required']);
    }

    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $tooBig = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE;
        respondJson(400, ['error' => $tooBig ? 'Photo exceeds the server upload limit' : 'Upload failed', 'code' => $file['error']]);
    }

    $maxBytes = 10 * 1024 * 1024; // 10MB limit
    if ($file['size'] > $maxBytes) {
        respondJson(400, ['error' => 'Photo exceeds 10MB limit']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $ext = detectExtension((string)$finfo->file($file['tmp_name']));
    if ($ext === null) {
        respondJson(400, ['error' => 'Photo must be PNG, JPG, or GIF']);
    }

    consumeRateLimit('upload:user:' . $userId, 60, 3600);

    $conn = db();
    $stmt = $conn->prepare('SELECT photos_json FROM user_beers WHERE user_id = ? AND beer = ? AND brewery = ? LIMIT 1');
    $stmt->bind_param('iss', $userId, $beer, $brewery);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $photos = decodePhotos($row['photos_json'] ?? '');
    if (count($photos) >= MAX_PHOTOS_PER_BEER) {
        respondJson(400, ['error' => 'A beer can have at most ' . MAX_PHOTOS_PER_BEER . ' photos. Remove one first.']);
    }

    $userDir = photoStorageRoot() . '/' . $userId;
    ensureDir($userDir);

    $filename = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $userDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        respondJson(500, ['error' => 'Failed to store uploaded file']);
    }

    $photos[] = $filename;
    $photosJson = json_encode($photos, JSON_UNESCAPED_SLASHES);

    // Upsert entry to ensure the record exists. If this fails, remove the
    // file so no orphan is left on disk.
    try {
        $stmt = $conn->prepare(
            'INSERT INTO user_beers (user_id, beer, brewery, photos_json)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE photos_json = VALUES(photos_json)'
        );
        $stmt->bind_param('isss', $userId, $beer, $brewery, $photosJson);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        @unlink($targetPath);
        throw $e;
    }

    respondJson(200, [
        'status' => 'ok',
        'filename' => $filename,
        'photos' => $photos,
    ]);
} catch (Throwable $e) {
    respondServerError($e, 'upload_user_beer_photo.php');
}
