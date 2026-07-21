<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

function parseDate(?string $value, string $field = 'date'): ?string {
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    $isValid = $dt && $dt->format('Y-m-d') === $value;
    if (!$isValid) {
        respondJson(400, ['error' => "{$field} must be a valid date in YYYY-MM-DD format"]);
    }
    return $dt->format('Y-m-d');
}

function normalizeName(string $s): string {
    return trim(mb_strtolower($s));
}

function decodePhotos(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$authUser = requireAuth();
$userId = (int)$authUser['id'];

try {
    if ($method === 'GET') {
        $beer   = isset($_GET['beer']) ? normalizeName((string)$_GET['beer']) : null;
        $brewery= isset($_GET['brewery']) ? normalizeName((string)$_GET['brewery']) : null;

        $conn = db();

        // List all beers for the user when beer/brewery are not provided
        if ($beer === null && $brewery === null) {
            $stmt = $conn->prepare('SELECT beer, brewery, drank, tasting_location, date_tasted, photos_json, updated_at FROM user_beers WHERE user_id = ? ORDER BY updated_at DESC');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'beer' => $row['beer'],
                    'brewery' => $row['brewery'],
                    'drank' => (bool)$row['drank'],
                    'tasting_location' => (string)($row['tasting_location'] ?? ''),
                    'photos' => decodePhotos($row['photos_json'] ?? ''),
                    'date_tasted' => $row['date_tasted'],
                    'updated_at' => $row['updated_at'],
                ];
            }
            $stmt->close();
            respondJson(200, ['items' => $rows]);
        }

        // Otherwise require beer and brewery to fetch a single log entry
        if (!$beer || !$brewery) {
            respondJson(400, ['error' => 'beer and brewery are required for a single entry']);
        }

        $stmt = $conn->prepare('SELECT drank, user_notes, tasting_location, date_tasted, photos_json, updated_at FROM user_beers WHERE user_id = ? AND beer = ? AND brewery = ? LIMIT 1');
        $stmt->bind_param('iss', $userId, $beer, $brewery);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            respondJson(200, ['exists' => false]);
        }

        respondJson(200, [
            'exists' => true,
            'drank' => (bool)$row['drank'],
            'user_notes' => (string)($row['user_notes'] ?? ''),
            'tasting_location' => (string)($row['tasting_location'] ?? ''),
            'photos' => decodePhotos($row['photos_json'] ?? ''),
            'date_tasted' => $row['date_tasted'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    if ($method === 'POST') {
        $body = readJsonBody();
        $beer = normalizeName((string)($body['beer'] ?? ''));
        $brewery = normalizeName((string)($body['brewery'] ?? ''));
        $drank = !empty($body['drank']) ? 1 : 0;
        $notes = trim((string)($body['user_notes'] ?? ''));
        $location = trim((string)($body['tasting_location'] ?? ''));
        $dateTasted = parseDate(isset($body['date_tasted']) ? (string)$body['date_tasted'] : null, 'date_tasted');

        if ($beer === '' || $brewery === '') {
            respondJson(400, ['error' => 'beer and brewery are required']);
        }

        $conn = db();
        $stmt = $conn->prepare(
            'INSERT INTO user_beers (user_id, beer, brewery, drank, user_notes, tasting_location, date_tasted)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE drank = VALUES(drank), user_notes = VALUES(user_notes), tasting_location = VALUES(tasting_location), date_tasted = VALUES(date_tasted)'
        );
        $stmt->bind_param('ississs', $userId, $beer, $brewery, $drank, $notes, $location, $dateTasted);
        $stmt->execute();
        $stmt->close();

        respondJson(200, ['status' => 'ok']);
    }

    respondJson(405, ['error' => 'Method not allowed']);
} catch (Throwable $e) {
    respondJson(500, ['error' => 'Request failed', 'message' => $e->getMessage()]);
}
