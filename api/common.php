<?php
declare(strict_types=1);

// Polyfill for PHP <8 where str_starts_with is missing.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

require_once __DIR__ . '/cors.php';

/** @var array<string,mixed>|null Decoded api/config.json, cached after first load. */
$GLOBALS['__config'] = null;

/**
 * Load and decode api/config.json once per request.
 * @return array<string,mixed>
 */
function loadConfig(): array {
    if ($GLOBALS['__config'] !== null) {
        return $GLOBALS['__config'];
    }

    $path = __DIR__ . '/config.json';
    if (!file_exists($path)) {
        respondJson(500, ['error' => 'Missing api/config.json. Copy api/config.example.json and fill in real values.']);
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        respondJson(500, ['error' => 'api/config.json is not valid JSON.']);
    }

    $GLOBALS['__config'] = $decoded;
    return $decoded;
}

/**
 * Read a value from api/config.json using dot-path notation, e.g. config('db.host').
 * @param string $path Dot-separated key path.
 * @param mixed $default Value returned when the path is missing.
 * @return mixed
 */
function config(string $path, mixed $default = null): mixed {
    $node = loadConfig();
    foreach (explode('.', $path) as $segment) {
        if (!is_array($node) || !array_key_exists($segment, $node)) {
            return $default;
        }
        $node = $node[$segment];
    }
    return $node === '' ? $default : $node;
}

/**
 * Send a JSON response with the given HTTP status and terminate the request.
 * @param int $status HTTP status code.
 * @param array<string,mixed> $payload Response body, JSON-encoded.
 */
function respondJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Open a MySQL connection using api/config.json credentials.
 * @throws mysqli_sql_exception On connection failure (via MYSQLI_REPORT_ERROR).
 */
function db(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $host = (string)config('db.host', 'localhost');
    $name = (string)config('db.name', 'beertracker');
    $user = (string)config('db.user', '');
    $pass = (string)config('db.pass', '');
    $port = (int)config('db.port', 3306);
    $conn = new mysqli($host, $user, $pass, $name, $port);
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Decode the raw JSON request body into an associative array.
 * @return array<string,mixed>
 */
function readJsonBody(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Hash a password for storage. MD5 is used for compatibility with existing
 * demo data only; replace with password_hash() before any real deployment.
 */
function hashPassword(string $password): string {
    return md5($password);
}

/**
 * Generate a new opaque session token and its expiry.
 * @return array{0:string,1:string} [token, expiresAtSqlDateTime]
 */
function newSessionToken(): array {
    $token = bin2hex(random_bytes(16));
    $expires = (new DateTime('+30 days'))->format('Y-m-d H:i:s');
    return [$token, $expires];
}

/**
 * Require a valid Authorization: Bearer <token> header and return the
 * authenticated user row. Responds 401 and exits when missing/invalid/expired.
 * @return array<string,mixed>
 */
function requireAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        respondJson(401, ['error' => 'Missing or malformed Authorization header']);
    }
    $token = $m[1];

    $conn = db();
    $stmt = $conn->prepare(
        'SELECT id, name, email, status, avatar_base64, password_md5, token_expires_at
         FROM users WHERE token = ? LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respondJson(401, ['error' => 'Invalid session token']);
    }
    if ($row['token_expires_at'] === null || strtotime($row['token_expires_at']) < time()) {
        respondJson(401, ['error' => 'Session token expired, please log in again']);
    }

    return $row;
}
