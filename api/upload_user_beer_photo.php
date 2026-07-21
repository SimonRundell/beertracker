<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

function assertInt($value, string $field): int {
    if (!is_numeric($value)) {
        respondJson(400, ['error' => "{$field} must be numeric"]);
    }
    return (int)$value;
}

function normalizeName(string $s): string {
    return trim(mb_strtolower($s));
}

function decodePhotos(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
}

function ensureDir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            respondJson(500, ['error' => 'Failed to create upload directory']);
        }
    }
}

function detectExtension(string $mime): ?string {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        default => null,
    };
}

function photoStorageRoot(): string {
    $base = env('FILE_LOCATION', __DIR__ . '/../uploads');
    return rtrim($base, "\\/");
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respondJson(405, ['error' => 'Method not allowed']);
    }

    $userId = assertInt($_POST['user_id'] ?? null, 'user_id');
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
        respondJson(400, ['error' => 'Upload failed', 'code' => $file['error']]);
    }

    $maxBytes = 10 * 1024 * 1024; // 10MB limit
    if ($file['size'] > $maxBytes) {
        respondJson(400, ['error' => 'Photo exceeds 10MB limit']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $ext = detectExtension($mime);
    if ($ext === null) {
        respondJson(400, ['error' => 'Photo must be PNG, JPG, or GIF']);
    }

    $root = photoStorageRoot();
    $userDir = $root . '/' . $userId;
    ensureDir($userDir);

    $timestamp = gmdate('Ymd_His');
    $random = bin2hex(random_bytes(4));
    $filename = $timestamp . '_' . $random . '.' . $ext;
    $targetPath = $userDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        respondJson(500, ['error' => 'Failed to store uploaded file']);
    }

    // Update DB record with filename
    $conn = db();
    $stmt = $conn->prepare('SELECT photos_json FROM user_beers WHERE user_id = ? AND beer = ? AND brewery = ? LIMIT 1');
    $stmt->bind_param('iss', $userId, $beer, $brewery);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    $photos = decodePhotos($row['photos_json'] ?? '');
    $photos[] = $filename;
    $photosJson = json_encode($photos, JSON_UNESCAPED_SLASHES);

    // Upsert entry to ensure record exists
    $stmt = $conn->prepare(
        'INSERT INTO user_beers (user_id, beer, brewery, photos_json)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE photos_json = VALUES(photos_json)'
    );
    $stmt->bind_param('isss', $userId, $beer, $brewery, $photosJson);
    $stmt->execute();
    $stmt->close();

    respondJson(200, [
        'status' => 'ok',
        'filename' => $filename,
        'photos' => $photos,
    ]);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Upload failed', 'message' => $e->getMessage()]);
}
