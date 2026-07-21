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

/** Lowercase + trim a name for cache-key comparisons. */
function normalizeName(string $s): string {
    return trim(mb_strtolower($s));
}

/** Split a "beer — brewery" prompt into its two parts. */
function parsePromptGuess(string $prompt): array {
    $parts = preg_split('/\s+[\-—]\s+/', $prompt, 2);
    $beer = trim((string)($parts[0] ?? ''));
    $brewery = trim((string)($parts[1] ?? ''));
    return [$beer, $brewery];
}

/**
 * Strip any HTML tag/attribute not on the allowlist instead of rejecting the
 * whole response — a single stray tag from the model shouldn't turn into a
 * user-facing 502.
 */
function sanitizeTastingNotesHtml(string $html): string {
    $allowedTags = '<h3><p><ul><li><strong><em><br>';
    $stripped = strip_tags($html, $allowedTags);
    // Drop attributes on the tags we do keep (defense in depth; none are ever needed here).
    return (string)preg_replace('/<(h3|p|ul|li|strong|em|br)\b[^>]*>/i', '<$1>', $stripped);
}

/**
 * Extract the model's text output from a Gemini generateContent response,
 * explaining *why* it's missing (safety block, truncation, empty candidates)
 * rather than a generic parse failure.
 * @throws RuntimeException
 */
function extractCandidateText(array $data): string {
    $candidates = $data['candidates'] ?? null;
    if (!is_array($candidates) || count($candidates) === 0) {
        $blockReason = $data['promptFeedback']['blockReason'] ?? null;
        if ($blockReason) {
            throw new RuntimeException("Request blocked by model safety filters (blockReason={$blockReason}).");
        }
        throw new RuntimeException('Model returned no candidates.');
    }

    $finishReason = $candidates[0]['finishReason'] ?? null;
    if (in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT'], true)) {
        throw new RuntimeException("Model declined to answer (finishReason={$finishReason}).");
    }

    $text = $candidates[0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        if ($finishReason === 'MAX_TOKENS') {
            throw new RuntimeException('Model response was truncated (finishReason=MAX_TOKENS).');
        }
        throw new RuntimeException('No candidate text returned by model.');
    }
    return $text;
}

/**
 * Log a diagnostic line via error_log() AND append it to api/debug.log.
 * The dual write exists because PHP's error_log destination varies by local
 * server setup (Apache module vs php-cgi vs built-in server) and is easy to
 * lose track of; api/debug.log is always in a known, fixed location.
 */
function logDiagnostic(string $message): void {
    error_log($message);
    $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/debug.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Resolve a CA bundle path from config to avoid relying on a stale php.ini setting.
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
    return json_decode((string)file_get_contents(__DIR__ . '/beer_schema.json'), true);
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

    $model  = (string)config('gemini.model', 'gemini-2.5-flash');
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

    $model  = (string)config('gemini.model', 'gemini-2.5-flash');
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

/**
 * POST $payload to $url and return the decoded JSON object embedded in the
 * model's candidate text. Never logs the URL (it carries the API key on the
 * devapi path) — only the HTTP status is logged for diagnostics.
 * @throws RuntimeException
 */
function executeStreamJson(string $url, array $payload, ?string $bearerToken): array {
    $headers = ['Content-Type: application/json'];
    if ($bearerToken) $headers[] = 'Authorization: Bearer ' . $bearerToken;

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true, // let us read body on non-2xx
        ]
    ];

    $caFile = resolveCaBundle();
    if ($caFile) {
        $opts['ssl'] = ['cafile' => $caFile, 'verify_peer' => true, 'verify_peer_name' => true];
    }

    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s(\d{3})#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    logDiagnostic("beer.php gemini call status={$status}");

    if ($raw === false) {
        $err = error_get_last();
        throw new RuntimeException('HTTP request to Gemini failed: ' . ($err['message'] ?? 'unknown'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Gemini returned a non-JSON response (HTTP {$status}).");
    }
    if ($status < 200 || $status >= 300) {
        $upstreamError = $data['error']['message'] ?? json_encode($data, JSON_UNESCAPED_SLASHES);
        throw new RuntimeException("Gemini returned HTTP {$status}: {$upstreamError}");
    }

    $jsonText = trim(extractCandidateText($data));

    // Defensive: the schema constraint should prevent this, but strip markdown
    // fences if the model wraps its JSON in them anyway.
    if (str_starts_with($jsonText, '```')) {
        $jsonText = (string)preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $jsonText);
    }

    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Model did not return valid JSON in candidate text: ' . substr($jsonText, 0, 300));
    }

    return $decoded;
}

// ---- Validation ------------------------------------------------------------

/**
 * Validate required keys/types and sanitize tastingnotes in place.
 * Only throws for genuinely broken structure (missing/wrong-typed required
 * fields) — cosmetic issues (extra keys, missing "Location:"/"Founded:"
 * labels, disallowed HTML) are normalized rather than rejected outright.
 * @param array<string,mixed> $r
 * @throws RuntimeException
 */
function validateAndCleanBeerResult(array $r): array {
    $required = ["brewery", "beer", "brewery details", "abv", "tastingnotes"];
    foreach ($required as $k) {
        if (!array_key_exists($k, $r)) throw new RuntimeException("Missing key in model output: {$k}");
    }

    if (!is_string($r["brewery"]) || $r["brewery"] === '') throw new RuntimeException("Invalid brewery in model output");
    if (!is_string($r["beer"]) || $r["beer"] === '') throw new RuntimeException("Invalid beer in model output");
    if (!is_string($r["brewery details"]) || $r["brewery details"] === '') throw new RuntimeException("Invalid brewery details in model output");
    if (!is_numeric($r["abv"])) throw new RuntimeException("Invalid abv in model output (must be numeric)");
    if (!is_string($r["tastingnotes"]) || $r["tastingnotes"] === '') throw new RuntimeException("Invalid tastingnotes in model output");

    return [
        'brewery' => $r['brewery'],
        'beer' => $r['beer'],
        'brewery details' => $r['brewery details'],
        'abv' => (float)$r['abv'],
        'tastingnotes' => sanitizeTastingNotesHtml($r['tastingnotes']),
    ];
}

// ---- Caching ---------------------------------------------------------------

function fetchCachedBeer(?string $beer, ?string $brewery): ?array {
    if ($beer === null || $beer === '') return null;
    $conn = db();
    $beerN = normalizeName($beer);
    $brewN = $brewery ? normalizeName($brewery) : '';

    if ($brewN === '') {
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
    logDiagnostic('beer.php cache lookup failed: ' . $e->getMessage());
}

$provider = (string)config('gemini.provider', 'devapi');

try {
    $raw = ($provider === 'vertex')
        ? callGeminiVertex($prompt)
        : callGeminiDevApi($prompt);

    $result = validateAndCleanBeerResult($raw);

    try {
        saveBeerResult($result);
    } catch (Throwable $e) {
        logDiagnostic('beer.php cache save failed: ' . $e->getMessage());
    }

    respondJson(200, $result);
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(4));
    logDiagnostic("beer.php lookup failed [{$requestId}]: " . $e->getMessage());
    respondJson(502, [
        "error" => "Unable to find that beer. Please check the name and brewery, or try again later.",
        "request_id" => $requestId,
    ]);
}
