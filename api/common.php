<?php
declare(strict_types=1);

// Polyfills for PHP <8 where these are missing.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

require_once __DIR__ . '/cors.php';

/** Maximum number of photos a user may attach to one beer. */
const MAX_PHOTOS_PER_BEER = 2;

/** Minimum accepted length for a new password. */
const MIN_PASSWORD_LENGTH = 8;

// ---- Config ----------------------------------------------------------------

/** @var array<string,mixed>|null Decoded api/.config.json, cached after first load. */
$GLOBALS['__config'] = null;

/**
 * Load and decode api/.config.json once per request.
 * @return array<string,mixed>
 */
function loadConfig(): array {
    if ($GLOBALS['__config'] !== null) {
        return $GLOBALS['__config'];
    }

    $path = __DIR__ . '/.config.json';
    if (!file_exists($path)) {
        logDiagnostic('Missing api/.config.json');
        respondJson(500, ['error' => 'Server is not configured. Copy api/.config.example.json to api/.config.json and fill in real values.']);
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        logDiagnostic('api/.config.json is not valid JSON');
        respondJson(500, ['error' => 'Server configuration is not valid JSON.']);
    }

    $GLOBALS['__config'] = $decoded;
    return $decoded;
}

/**
 * Read a value from api/.config.json using dot-path notation, e.g. config('db.host').
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

/** True when "debug": true is set in api/.config.json (never enable in production). */
function isDebug(): bool {
    return config('debug', false) === true;
}

// ---- Responses and error handling ------------------------------------------

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
 * Log a diagnostic line via error_log() AND append it to api/debug.log.
 * The dual write exists because PHP's error_log destination varies by local
 * server setup and is easy to lose track of; api/debug.log is always in a
 * known, fixed location (and is blocked from web access by api/.htaccess).
 */
function logDiagnostic(string $message): void {
    error_log($message);
    $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/debug.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log an unexpected failure and respond with a generic message plus a
 * request_id the user can quote. The real exception text is only included
 * (as "detail") when "debug": true is set in api/.config.json.
 * @param Throwable $e The failure.
 * @param string $context Short label for the log line, e.g. "user_beers.php".
 * @param int $status HTTP status to send (500 by default).
 * @param string|null $publicMessage Safe message for the client; generic if null.
 */
function respondServerError(Throwable $e, string $context, int $status = 500, ?string $publicMessage = null): void {
    $requestId = bin2hex(random_bytes(4));
    logDiagnostic(sprintf(
        '%s failed [%s]: %s: %s at %s:%d',
        $context, $requestId, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()
    ));

    $payload = [
        'error' => $publicMessage ?? 'Something went wrong on the server. Please try again.',
        'request_id' => $requestId,
    ];
    if (isDebug()) {
        $payload['detail'] = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    respondJson($status, $payload);
}

// Anything an endpoint forgets to catch still gets a clean JSON 500.
set_exception_handler(static function (Throwable $e): void {
    respondServerError($e, 'uncaught');
});

// ---- Database --------------------------------------------------------------

/**
 * Return the shared MySQL connection for this request, opening it on first use.
 * @throws mysqli_sql_exception On connection failure (via MYSQLI_REPORT_ERROR).
 */
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            (string)config('db.host', 'localhost'),
            (string)config('db.user', ''),
            (string)config('db.pass', ''),
            (string)config('db.name', 'beertracker'),
            (int)config('db.port', 3306)
        );
        $conn->set_charset('utf8mb4');
    }
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

// ---- Shared beer/photo helpers ---------------------------------------------

/** Lowercase + trim a name for consistent lookup/storage. */
function normalizeName(string $s): string {
    return trim(mb_strtolower($s));
}

/**
 * Decode the photos_json column into a plain list of filenames.
 * @return string[]
 */
function decodePhotos(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
}

/**
 * Absolute root directory for uploaded photos, from config('file_location').
 * Relative paths are resolved against the api/ directory. This should point
 * outside any web-served folder; photos are only ever served by photo.php.
 */
function photoStorageRoot(): string {
    $base = (string)config('file_location', __DIR__ . '/../storage/uploads');
    $isAbsolute = preg_match('#^([A-Za-z]:)?[\\\\/]#', $base) === 1;
    return rtrim($isAbsolute ? $base : __DIR__ . '/' . $base, "\\/");
}

/**
 * Shape a user's hand-entered beer like a beer.php search result, so the
 * frontend can show it the same way.
 * @return array<string,mixed>
 */
function manualBeerResponse(string $beer, string $brewery, ?float $abv, ?string $description): array {
    return [
        'brewery' => $brewery,
        'beer' => $beer,
        'brewery details' => '',
        'abv' => $abv,
        'tastingnotes' => '',
        'description' => (string)($description ?? ''),
        'manual' => true,
    ];
}

// ---- Passwords -------------------------------------------------------------

/**
 * Hash a password for storage: Argon2id when PHP has it, otherwise bcrypt (cost 12).
 * Salting is handled by password_hash().
 */
function hashPassword(string $password): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Check a password against a users row (needs password_hash and password_md5
 * columns). Accounts still carrying a legacy unsalted MD5 hash are upgraded
 * to a modern hash transparently on first successful check, and strong hashes
 * are re-hashed whenever the preferred algorithm or cost has changed.
 * @param array<string,mixed> $row Row with id, password_hash, password_md5.
 */
function checkPassword(mysqli $conn, array $row, string $password): bool {
    $current = (string)($row['password_hash'] ?? '');
    $legacy = (string)($row['password_md5'] ?? '');
    $valid = false;
    $needsUpgrade = false;

    if ($current !== '') {
        $valid = password_verify($password, $current);
        $needsUpgrade = $valid && password_needs_rehash($current, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    } elseif ($legacy !== '') {
        $valid = hash_equals($legacy, md5($password));
        $needsUpgrade = $valid;
    }

    if ($needsUpgrade) {
        $new = hashPassword($password);
        $id = (int)$row['id'];
        $stmt = $conn->prepare('UPDATE users SET password_hash = ?, password_md5 = NULL WHERE id = ?');
        $stmt->bind_param('si', $new, $id);
        $stmt->execute();
        $stmt->close();
    }
    return $valid;
}

/** Respond 400 and exit unless $password meets the minimum length. */
function requireStrongPassword(string $password): void {
    if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        respondJson(400, ['error' => 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters']);
    }
}

// ---- Sessions --------------------------------------------------------------

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
 * Read the Authorization header across PHP SAPIs. $_SERVER['HTTP_AUTHORIZATION']
 * is often stripped by PHP-FPM/FastCGI setups before PHP ever sees it, so this
 * checks every fallback PHP exposes it under.
 */
function getAuthorizationHeader(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header !== '') return $header;

    foreach (['apache_request_headers', 'getallheaders'] as $reader) {
        if (function_exists($reader)) {
            $headers = $reader();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if ($header !== '') return $header;
        }
    }
    return '';
}

/**
 * Require a valid Authorization: Bearer <token> header and return the
 * authenticated user row (id, name, email, status, token_expires_at only;
 * endpoints that need avatars or password hashes query for them explicitly).
 * Responds 401 and exits when missing/invalid/expired.
 * @return array<string,mixed>
 */
function requireAuth(): array {
    $header = getAuthorizationHeader();
    if ($header === '') {
        respondJson(401, ['error' => isDebug()
            ? 'No Authorization header reached PHP. On FastCGI/PHP-FPM setups the web server must forward it (see api/.htaccess).'
            : 'Authentication required']);
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        respondJson(401, ['error' => 'Malformed Authorization header']);
    }
    $token = $m[1];

    $stmt = db()->prepare('SELECT id, name, email, status, token_expires_at FROM users WHERE token = ? LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respondJson(401, ['error' => 'Invalid session token']);
    }
    if ($row['token_expires_at'] === null || strtotime($row['token_expires_at']) < time()) {
        respondJson(401, ['error' => 'Session expired, please log in again']);
    }

    return $row;
}

// ---- Rate limiting ---------------------------------------------------------

/**
 * Best-effort client IP. Uses REMOTE_ADDR only: X-Forwarded-For is
 * client-controlled unless a trusted proxy sets it, so it is not consulted.
 */
function clientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Respond 429 (with Retry-After) and exit if $bucket already has $max or more
 * recorded hits within the last $windowSeconds. Does not record a hit itself.
 */
function enforceRateLimit(string $bucket, int $max, int $windowSeconds): void {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS hits, TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) AS age
         FROM rate_limits WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->bind_param('si', $bucket, $windowSeconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)$row['hits'] >= $max) {
        $retryAfter = max(1, $windowSeconds - (int)($row['age'] ?? 0));
        header('Retry-After: ' . $retryAfter);
        $minutes = (int)ceil($retryAfter / 60);
        respondJson(429, ['error' => "Too many attempts. Try again in about {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.']);
    }
}

/** Record one hit against $bucket (occasionally pruning day-old rows). */
function recordRateLimitHit(string $bucket): void {
    $conn = db();
    $stmt = $conn->prepare('INSERT INTO rate_limits (bucket) VALUES (?)');
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    $stmt->close();

    if (random_int(1, 50) === 1) {
        $conn->query('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

/** Forget all hits for $bucket (e.g. after a successful login). */
function clearRateLimit(string $bucket): void {
    $stmt = db()->prepare('DELETE FROM rate_limits WHERE bucket = ?');
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    $stmt->close();
}

/** Enforce the limit for $bucket and, if allowed, record this request against it. */
function consumeRateLimit(string $bucket, int $max, int $windowSeconds): void {
    enforceRateLimit($bucket, $max, $windowSeconds);
    recordRateLimitHit($bucket);
}
