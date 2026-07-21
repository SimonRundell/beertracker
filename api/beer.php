<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Single-file Beer Lookup API (Gemini) for PHP backend.
 *
 * Endpoint: POST /api/beer.php
 * Body: {"prompt":"Beer Name" OR "Beer Name — Brewery"}
 * Response: {"brewery":..., "beer":..., "brewery details":..., "abv":..., "tastingnotes":...}
 *
 * Config (api/config.json, see api/config.example.json):
 *   gemini.provider = "devapi" | "vertex"
 *   gemini.api_key, gemini.model                      (devapi)
 *   gemini.gcp_project_id, gemini.gcp_region, gemini.gcp_access_token  (vertex)
 */

// ---- Helpers ---------------------------------------------------------------

if (!function_exists('normalizeName')) {
    function normalizeName(string $s): string {
        return trim(mb_strtolower($s));
    }
}

if (!function_exists('parsePromptGuess')) {
    function parsePromptGuess(string $prompt): array {
        $parts = preg_split('/\s+[\-—]\s+/', $prompt, 2);
        $beer = trim((string)($parts[0] ?? ''));
        $brewery = trim((string)($parts[1] ?? ''));
        return [$beer, $brewery];
    }
}

/**
 * Basic allowlist validation for HTML tags in tastingnotes.
 * We *reject* if any disallowed tags appear (safer than attempting to “clean” in backend without a real sanitizer).
 */
function validateTastingNotesHtml(string $html): void {
    $allowed = ['h3','p','ul','li','strong','em','br'];
    preg_match_all('/<\/?([a-zA-Z0-9]+)(\s[^>]*)?>/', $html, $matches);
    foreach ($matches[1] as $tag) {
        $t = strtolower($tag);
        if (!in_array($t, $allowed, true)) {
            throw new RuntimeException("tastingnotes contains disallowed HTML tag: <{$t}>");
        }
    }
    // Disallow attributes (keep it clean)
    if (preg_match('/<\s*[a-zA-Z0-9]+\s+[^>]+>/', $html)) {
        throw new RuntimeException("tastingnotes must not include HTML attributes.");
    }
}

/**
 * Extracts the model text output from typical Gemini responses.
 * Returns the raw string that should itself be JSON.
 */
function extractCandidateText(array $data): string {
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('No candidate text returned by model.');
    }
    return $text;
}

/**
 * Resolve a CA bundle path from env to avoid relying on a stale php.ini setting.
 */
function resolveCaBundle(): ?string {
    $candidates = [config('tls.curl_ca_bundle'), config('tls.ssl_cert_file')];
    foreach ($candidates as $path) {
        if ($path && file_exists($path)) {
            return $path;
        }
    }
    return null;
}

// ---- Core: schema + instructions ------------------------------------------

function beerSchema(): array {
    // Embedded canonical schema
    return [
        "type" => "object",
        "additionalProperties" => false,
        "required" => ["brewery", "beer", "brewery details", "abv", "tastingnotes"],
        "properties" => [
            "brewery" => ["type" => "string"],
            "beer" => ["type" => "string"],
            "brewery details" => [
                "type" => "string",
                "description" => "Must include: Location: ...; Founded: ...; Notes: ..."
            ],
            "abv" => [
                "type" => "number",
                "description" => "ABV as numeric value without percent symbol. Use -1 if unknown."
            ],
            "tastingnotes" => [
                "type" => "string",
                "description" => "Single HTML string using only h3, p, ul, li, strong, em, br."
            ]
        ]
    ];
}

function instructionText(): string {
    return <<<TXT
Input may be just a beer name OR "beer — brewery". Use the brewery if provided to disambiguate.

Return ONLY valid JSON that matches the provided schema. No markdown, no commentary, no extra keys.

Rules:
- If unknown: brewery/beer => "Unknown"; abv => -1.
- "brewery details" MUST include: Location: ...; Founded: ...; Notes: ...
- tastingnotes must be a single HTML string using only:
  <h3>, <p>, <ul>, <li>, <strong>, <em>, <br>
  Sections required in order:
  Style, Appearance, Aroma, Flavour, Mouthfeel, Finish, Food Pairing.
TXT;
}

// ---- Gemini calls ----------------------------------------------------------

function callGeminiDevApi(string $prompt): array {
    $apiKey = config('gemini.api_key');
    if (!$apiKey) throw new RuntimeException('Missing gemini.api_key in api/config.json');

    $model  = (string)config('gemini.model', 'gemini-2.0-flash');
    $schema = beerSchema();

    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => instructionText() . "\n\nINPUT: " . $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type"   => "application/json",
            "response_json_schema" => $schema
        ]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    return executeStreamJson($url, $payload, null);
}

function callGeminiVertex(string $prompt): array {
    $project = config('gemini.gcp_project_id');
    $region  = config('gemini.gcp_region');
    $token   = config('gemini.gcp_access_token');
    if (!$project || !$region || !$token) {
        throw new RuntimeException('Missing Vertex config: gemini.gcp_project_id, gemini.gcp_region, gemini.gcp_access_token');
    }

    $model  = (string)config('gemini.model', 'gemini-2.0-flash');
    $schema = beerSchema();

    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => instructionText() . "\n\nINPUT: " . $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "response_schema"    => $schema
        ]
    ];

    $url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$project}/locations/{$region}/publishers/google/models/{$model}:generateContent";
    return executeStreamJson($url, $payload, $token);
}

function executeStreamJson(string $url, array $payload, ?string $bearerToken): array {
    $headers = [
        'Content-Type: application/json'
    ];
    if ($bearerToken) $headers[] = 'Authorization: Bearer ' . $bearerToken;

    error_log('beer.php stream url=' . $url);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true // let us read body on non-2xx
        ]
    ];

    $caFile = resolveCaBundle();
    if ($caFile) {
        error_log('beer.php using CA bundle override=' . $caFile);
        $opts['ssl'] = ['cafile' => $caFile, 'verify_peer' => true, 'verify_peer_name' => true];
    }

    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);

    // Parse HTTP status from $http_response_header
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s(\d{3})#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    error_log('beer.php stream status=' . $status);

    if ($raw === false) {
        $err = error_get_last();
        throw new RuntimeException('HTTP request failed: ' . ($err['message'] ?? 'unknown'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Upstream returned non-JSON response (HTTP {$status}).");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Upstream error (HTTP {$status}): " . json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    $jsonText = extractCandidateText($data);

    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Model did not return valid JSON in candidate text.');
    }

    return $decoded;
}

// ---- Validation ------------------------------------------------------------

function validateBeerResult(array $r): void {
    $required = ["brewery", "beer", "brewery details", "abv", "tastingnotes"];
    foreach ($required as $k) {
        if (!array_key_exists($k, $r)) throw new RuntimeException("Missing key: {$k}");
    }

    if (!is_string($r["brewery"]) || $r["brewery"] === '') throw new RuntimeException("Invalid brewery");
    if (!is_string($r["beer"]) || $r["beer"] === '') throw new RuntimeException("Invalid beer");
    if (!is_string($r["brewery details"]) || $r["brewery details"] === '') throw new RuntimeException("Invalid brewery details");
    if (!is_numeric($r["abv"])) throw new RuntimeException("Invalid abv (must be number)");
    if (!is_string($r["tastingnotes"]) || $r["tastingnotes"] === '') throw new RuntimeException("Invalid tastingnotes");

    // Enforce recommended brewery details shape
    $bd = $r["brewery details"];
    if (stripos($bd, 'Location:') === false || stripos($bd, 'Founded:') === false) {
        throw new RuntimeException('"brewery details" must include "Location:" and "Founded:"');
    }

    validateTastingNotesHtml($r["tastingnotes"]);

    // No extra keys (defensive)
    $allowedKeys = array_flip($required);
    foreach ($r as $k => $_) {
        if (!isset($allowedKeys[$k])) throw new RuntimeException("Unexpected key in output: {$k}");
    }
}

// ---- Caching ---------------------------------------------------------------

function fetchCachedBeer(?string $beer, ?string $brewery): ?array {
    if ($beer === null || $beer === '') return null;
    if (!function_exists('db')) return null;
    $conn = db();
    $beerN = normalizeName($beer);
    $brewN = $brewery ? normalizeName($brewery) : '';

    if ($brewN === '') {
        // allow lookup by beer only if unique; fallback to first match
        $stmt = $conn->prepare('SELECT beer, brewery, brewery_details, abv, tastingnotes FROM beers WHERE beer = ? LIMIT 1');
        $stmt->bind_param('s', $beerN);
    } else {
        $stmt = $conn->prepare('SELECT beer, brewery, brewery_details, abv, tastingnotes FROM beers WHERE beer = ? AND brewery = ? LIMIT 1');
        $stmt->bind_param('ss', $beerN, $brewN);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function saveBeerResult(array $r): void {
    if (!function_exists('db')) return;
    $conn = db();
    $beerN = normalizeName($r['beer']);
    $brewN = normalizeName($r['brewery']);
    $bd = $r['brewery details'];
    $abv = (float)$r['abv'];
    $tn = $r['tastingnotes'];
    $json = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare(
        'INSERT INTO beers (beer, brewery, brewery_details, abv, tastingnotes, raw_json) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE brewery_details=VALUES(brewery_details), abv=VALUES(abv), tastingnotes=VALUES(tastingnotes), raw_json=VALUES(raw_json)'
    );
    $stmt->bind_param('sssdss', $beerN, $brewN, $bd, $abv, $tn, $json);
    $stmt->execute();
    $stmt->close();
}

// ---- Request handling ------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ["error" => "Method not allowed"]);
}

$rawBody = file_get_contents('php://input') ?: '';
$body = json_decode($rawBody, true);
$prompt = is_array($body) ? trim((string)($body['prompt'] ?? '')) : '';

if ($prompt === '') {
    respondJson(400, ["error" => "Missing prompt"]);
}

[$guessBeer, $guessBrewery] = parsePromptGuess($prompt);

// Try cache first
try {
    $cached = fetchCachedBeer($guessBeer, $guessBrewery);
    if ($cached) {
        respondJson(200, [
            'brewery' => $cached['brewery'],
            'beer' => $cached['beer'],
            'brewery details' => $cached['brewery_details'],
            'abv' => (float)$cached['abv'],
            'tastingnotes' => $cached['tastingnotes'],
        ]);
    }
} catch (Throwable $e) {
    error_log('beer.php cache lookup failed: ' . $e->getMessage());
}

$provider = (string)config('gemini.provider', 'devapi');

try {
    $result = ($provider === 'vertex')
        ? callGeminiVertex($prompt)
        : callGeminiDevApi($prompt);

    validateBeerResult($result);

    try {
        saveBeerResult($result);
    } catch (Throwable $e) {
        error_log('beer.php cache save failed: ' . $e->getMessage());
    }

    respondJson(200, $result);
} catch (Throwable $e) {
    // Don’t leak secrets; provide actionable error
    respondJson(502, [
        "error" => "Unable to find that beer. Please check the name and brewery, or try again later.",
        "message" => $e->getMessage()
    ]);
}
