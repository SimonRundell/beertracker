<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Single-file Beer Lookup API (Gemini) for PHP backend.
 *
 * Endpoint: POST /api/beer.php. Requires Authorization: Bearer <token>.
 * Calls that reach Gemini (cache misses) are limited to 30 per hour per user (429).
 *
 * Details mode (default) — full lookup for one specific beer:
 *   Body: {"beer":"Beer Name", "brewery":"Brewery (optional)"}
 *   Response: {"brewery":..., "beer":..., "brewery details":..., "abv":..., "tastingnotes":...}
 *
 * Candidates mode — list possible matches for a bare beer name (no brewery),
 * prioritising UK breweries when ambiguous, for a "Did you mean...?" picker:
 *   Body: {"beer":"Beer Name", "mode":"candidates"}
 *   Response: {"candidates":[{"beer":..., "brewery":..., "country":...}, ...]}
 *
 * Config (api/.config.json, see api/.config.example.json):
 *   gemini.provider = "devapi" | "vertex"
 *   gemini.api_key, gemini.model                      (devapi)
 *   gemini.gcp_project_id, gemini.gcp_region, gemini.gcp_access_token  (vertex)
 */

// ---- Helpers ---------------------------------------------------------------

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

// ---- Core: schemas + instructions ------------------------------------------

function beerSchema(): array {
    return json_decode((string)file_get_contents(__DIR__ . '/beer_schema.json'), true);
}

function candidateSchema(): array {
    return json_decode((string)file_get_contents(__DIR__ . '/beer_candidates_schema.json'), true);
}

function instructionText(): string {
    return <<<TXT
Input is a specific "beer — brewery" pair (or occasionally just a beer name that
already uniquely identifies one beer). Look up that exact beer.

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

function candidateInstructionText(): string {
    return <<<TXT
Input is a beer name with no brewery specified. List up to 5 distinct real
beer+brewery matches for that name, ranked most-likely first.

Rules:
- When the name is ambiguous across countries, prioritise UK-based breweries
  over others; among UK matches prefer the most widely known/popular beer.
- Only include real, known beers — do not invent matches.
- If you are confident there is only one real match, return a single-item list.
- Return ONLY valid JSON matching the provided schema. No markdown, no commentary.
TXT;
}

// ---- Gemini calls ----------------------------------------------------------

/**
 * Send $fullText to Gemini (devapi or vertex, per config('gemini.provider'))
 * constrained to $schema, and return the decoded JSON result.
 * @throws RuntimeException
 */
function callGemini(string $fullText, array $schema): array {
    $provider = (string)config('gemini.provider', 'devapi');
    return $provider === 'vertex'
        ? callGeminiVertex($fullText, $schema)
        : callGeminiDevApi($fullText, $schema);
}

function callGeminiDevApi(string $fullText, array $schema): array {
    $apiKey = config('gemini.api_key');
    if (!$apiKey) throw new RuntimeException('Missing gemini.api_key in api/.config.json');

    $model = (string)config('gemini.model', 'gemini-flash-latest');

    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $fullText]
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

function callGeminiVertex(string $fullText, array $schema): array {
    $project = config('gemini.gcp_project_id');
    $region  = config('gemini.gcp_region');
    $token   = config('gemini.gcp_access_token');
    if (!$project || !$region || !$token) {
        throw new RuntimeException('Missing Vertex config: gemini.gcp_project_id, gemini.gcp_region, gemini.gcp_access_token');
    }

    $model = (string)config('gemini.model', 'gemini-flash-latest');

    $payload = [
        "contents" => [
            [
                "role" => "user",
                "parts" => [
                    ["text" => $fullText]
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

/**
 * Validate and clean a candidates-mode model response into a plain list of
 * {beer, brewery, country}, dropping any malformed entries rather than
 * failing the whole request.
 * @param array<string,mixed> $r
 * @return array<int,array{beer:string,brewery:string,country:string}>
 */
function validateAndCleanCandidates(array $r): array {
    $items = $r['candidates'] ?? null;
    if (!is_array($items)) throw new RuntimeException('Missing candidates array in model output');

    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $beer = is_string($item['beer'] ?? null) ? trim($item['beer']) : '';
        $brewery = is_string($item['brewery'] ?? null) ? trim($item['brewery']) : '';
        $country = is_string($item['country'] ?? null) ? trim($item['country']) : '';
        if ($beer === '' || $brewery === '') continue;
        $clean[] = ['beer' => $beer, 'brewery' => $brewery, 'country' => $country ?: 'Unknown'];
        if (count($clean) >= 5) break;
    }
    return $clean;
}

// ---- Caching ---------------------------------------------------------------

/** Shape a cached `beers` row (or fresh result) as the public response. */
function cachedRowToResponse(array $row): array {
    // raw_json keeps the model's original capitalisation; the indexed columns are lowercased.
    $original = json_decode((string)$row['raw_json'], true);
    return is_array($original) ? $original : [
        'brewery' => $row['brewery'],
        'beer' => $row['beer'],
        'brewery details' => $row['brewery_details'],
        'abv' => (float)$row['abv'],
        'tastingnotes' => $row['tastingnotes'],
    ];
}

/**
 * Look up a cached beer by what the user typed: first an exact beer+brewery
 * match, then a recorded alias. A bare beer name (no brewery) only hits when
 * exactly one cached beer has that name, since it is otherwise ambiguous.
 * @return array<string,mixed>|null
 */
function fetchCachedBeer(string $beer, string $brewery): ?array {
    $conn = db();
    $beerN = normalizeName($beer);
    $brewN = normalizeName($brewery);

    if ($brewN === '') {
        $stmt = $conn->prepare('SELECT * FROM beers WHERE beer = ? LIMIT 2');
        $stmt->bind_param('s', $beerN);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return count($rows) === 1 ? $rows[0] : null;
    }

    $stmt = $conn->prepare(
        'SELECT b.* FROM beers b WHERE b.beer = ? AND b.brewery = ?
         UNION ALL
         SELECT b.* FROM beer_aliases a JOIN beers b ON b.id = a.beer_id WHERE a.alias_beer = ? AND a.alias_brewery = ?
         LIMIT 1'
    );
    $stmt->bind_param('ssss', $beerN, $brewN, $beerN, $brewN);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Find a beer this user added by hand (see manual_beer.php). Checked before
 * the shared cache and Gemini so the user's own data always wins. A bare beer
 * name only matches when exactly one of their manual beers has that name.
 * @return array<string,mixed>|null
 */
function fetchManualBeer(int $userId, string $beer, string $brewery): ?array {
    $beerN = normalizeName($beer);
    $brewN = normalizeName($brewery);
    $sql = 'SELECT beer, brewery, abv, description FROM user_beers WHERE user_id = ? AND is_manual = 1 AND beer = ?';
    if ($brewN === '') {
        $stmt = db()->prepare($sql . ' LIMIT 2');
        $stmt->bind_param('is', $userId, $beerN);
    } else {
        $stmt = db()->prepare($sql . ' AND brewery = ? LIMIT 1');
        $stmt->bind_param('iss', $userId, $beerN, $brewN);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($rows) !== 1) return null;
    $r = $rows[0];
    return manualBeerResponse($r['beer'], $r['brewery'], $r['abv'] !== null ? (float)$r['abv'] : null, $r['description']);
}

/** True when the model reported the beer as unknown, so the result must not be cached. */
function isUnknownResult(array $r): bool {
    return strtolower($r['beer']) === 'unknown' || strtolower($r['brewery']) === 'unknown' || $r['abv'] < 0;
}

/**
 * Cache a fresh result under its canonical names, plus an alias for what the
 * user actually typed when that differs (misspellings, missing "The", etc.).
 */
function saveBeerResult(array $r, string $typedBeer, string $typedBrewery): void {
    $conn = db();
    $beerN = normalizeName($r['beer']);
    $brewN = normalizeName($r['brewery']);
    $bd = $r['brewery details'];
    $abv = (float)$r['abv'];
    $tn = $r['tastingnotes'];
    $json = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare(
        'INSERT INTO beers (beer, brewery, brewery_details, abv, tastingnotes, raw_json) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), brewery_details=VALUES(brewery_details), abv=VALUES(abv), tastingnotes=VALUES(tastingnotes), raw_json=VALUES(raw_json)'
    );
    $stmt->bind_param('sssdss', $beerN, $brewN, $bd, $abv, $tn, $json);
    $stmt->execute();
    $beerId = $stmt->insert_id;
    $stmt->close();

    $aliasBeer = normalizeName($typedBeer);
    $aliasBrewery = normalizeName($typedBrewery);
    if ($aliasBrewery !== '' && ($aliasBeer !== $beerN || $aliasBrewery !== $brewN)) {
        $stmt = $conn->prepare('INSERT IGNORE INTO beer_aliases (alias_beer, alias_brewery, beer_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $aliasBeer, $aliasBrewery, $beerId);
        $stmt->execute();
        $stmt->close();
    }
}

// ---- Request handling ------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ["error" => "Method not allowed"]);
}

$authUser = requireAuth();
$userId = (int)$authUser['id'];

$body = readJsonBody();
$beerInput = trim((string)($body['beer'] ?? ''));
$breweryInput = trim((string)($body['brewery'] ?? ''));
$mode = (string)($body['mode'] ?? 'details');

if ($beerInput === '') {
    respondJson(400, ["error" => "Missing beer name"]);
}
if (mb_strlen($beerInput) > 200 || mb_strlen($breweryInput) > 200) {
    respondJson(400, ["error" => "Beer and brewery names must be 200 characters or fewer"]);
}

// Gemini calls cost money and quota, so cap them per user (cache hits are free).
const GEMINI_MAX_CALLS = 30;
const GEMINI_WINDOW_SECONDS = 3600;

// ---- Candidates mode: list possible beer+brewery matches for a bare beer name ----
if ($mode === 'candidates') {
    try {
        consumeRateLimit('gemini:user:' . $userId, GEMINI_MAX_CALLS, GEMINI_WINDOW_SECONDS);
        $raw = callGemini(candidateInstructionText() . "\n\nINPUT: " . $beerInput, candidateSchema());
        respondJson(200, ['candidates' => validateAndCleanCandidates($raw)]);
    } catch (Throwable $e) {
        respondServerError($e, 'beer.php candidates lookup', 502, 'Unable to search for that beer. Please try again later.');
    }
}

// ---- Details mode: full lookup for a specific beer + brewery -------------
try {
    $manual = fetchManualBeer($userId, $beerInput, $breweryInput);
    if ($manual) {
        respondJson(200, $manual);
    }
    $cached = fetchCachedBeer($beerInput, $breweryInput);
    if ($cached) {
        respondJson(200, cachedRowToResponse($cached));
    }
} catch (Throwable $e) {
    logDiagnostic('beer.php cache lookup failed: ' . $e->getMessage());
}

try {
    consumeRateLimit('gemini:user:' . $userId, GEMINI_MAX_CALLS, GEMINI_WINDOW_SECONDS);

    $input = $breweryInput !== '' ? "{$beerInput} — {$breweryInput}" : $beerInput;
    $result = validateAndCleanBeerResult(callGemini(instructionText() . "\n\nINPUT: " . $input, beerSchema()));

    if (!isUnknownResult($result)) {
        try {
            saveBeerResult($result, $beerInput, $breweryInput);
        } catch (Throwable $e) {
            logDiagnostic('beer.php cache save failed: ' . $e->getMessage());
        }
    }

    respondJson(200, $result);
} catch (Throwable $e) {
    respondServerError($e, 'beer.php lookup', 502, 'Unable to find that beer. Please check the name and brewery, or try again later.');
}
