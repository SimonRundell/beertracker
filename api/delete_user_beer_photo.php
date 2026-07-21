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

function photoStorageRoot(): string {
    $base = (string)config('file_location', __DIR__ . '/../uploads');
    return rtrim($base, "\\/");
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respondJson(405, ['error' => 'Method not allowed']);
    }

    $userId = assertInt($_POST['user_id'] ?? null, 'user_id');
    $beer = normalizeName((string)($_POST['beer'] ?? ''));
    $brewery = normalizeName((string)($_POST['brewery'] ?? ''));
    $filename = basename((string)($_POST['filename'] ?? ''));

    if ($beer === '' || $brewery === '' || $filename === '') {
        respondJson(400, ['error' => 'user_id, beer, brewery, and filename are required']);
    }

    $conn = db();
    $stmt = $conn->prepare('SELECT photos_json FROM user_beers WHERE user_id = ? AND beer = ? AND brewery = ? LIMIT 1');
    $stmt->bind_param('iss', $userId, $beer, $brewery);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    $photos = decodePhotos($row['photos_json'] ?? '');
    if (!in_array($filename, $photos, true)) {
        respondJson(404, ['error' => 'Photo not found']);
    }

    $photos = array_values(array_filter($photos, fn($p) => $p !== $filename));
    $photosJson = json_encode($photos, JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare(
        'UPDATE user_beers SET photos_json = ? WHERE user_id = ? AND beer = ? AND brewery = ?'
    );
    $stmt->bind_param('siss', $photosJson, $userId, $beer, $brewery);
    $stmt->execute();
    $stmt->close();

    // Attempt to delete the file from disk (best effort)
    $path = photoStorageRoot() . '/' . $userId . '/' . $filename;
    if (is_file($path)) {
        @unlink($path);
    }

    respondJson(200, ['status' => 'ok', 'photos' => $photos]);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Delete failed', 'message' => $e->getMessage()]);
}
