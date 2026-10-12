<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

/**
 * Add or update a beer the AI search couldn't find. Requires Authorization: Bearer <token>.
 *
 * POST /api/manual_beer.php
 * Body: {"beer":string, "brewery":string, "abv":number|null, "description":string|null}
 * Response: a beer.php-style result with "manual": true, plus "description".
 *
 * The details are stored on the caller's own user_beers row (created if
 * needed) and never touch the shared AI cache. Existing log fields such as
 * drank, notes and photos are left as they are. Later beer.php searches for
 * the same beer return this entry before asking the AI.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respondJson(405, ['error' => 'Method not allowed']);
}

try {
    $userId = (int)requireAuth()['id'];
    $body = readJsonBody();

    $beerTyped = trim((string)($body['beer'] ?? ''));
    $breweryTyped = trim((string)($body['brewery'] ?? ''));
    if ($beerTyped === '' || $breweryTyped === '') {
        respondJson(400, ['error' => 'beer and brewery are required']);
    }
    if (mb_strlen($beerTyped) > 255 || mb_strlen($breweryTyped) > 255) {
        respondJson(400, ['error' => 'beer and brewery must be 255 characters or fewer']);
    }

    $abv = null;
    $abvRaw = $body['abv'] ?? null;
    if ($abvRaw !== null && $abvRaw !== '') {
        if (!is_numeric($abvRaw) || (float)$abvRaw < 0 || (float)$abvRaw > 100) {
            respondJson(400, ['error' => 'ABV must be a number between 0 and 100']);
        }
        $abv = round((float)$abvRaw, 2);
    }

    $description = trim((string)($body['description'] ?? ''));
    if (mb_strlen($description) > 1000) {
        respondJson(400, ['error' => 'Description must be 1000 characters or fewer']);
    }
    $descriptionOrNull = $description === '' ? null : $description;

    $beer = normalizeName($beerTyped);
    $brewery = normalizeName($breweryTyped);

    $stmt = db()->prepare(
        'INSERT INTO user_beers (user_id, beer, brewery, is_manual, abv, description)
         VALUES (?, ?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE is_manual = 1, abv = VALUES(abv), description = VALUES(description)'
    );
    $stmt->bind_param('issds', $userId, $beer, $brewery, $abv, $descriptionOrNull);
    $stmt->execute();
    $stmt->close();

    respondJson(200, manualBeerResponse($beerTyped, $breweryTyped, $abv, $descriptionOrNull));
} catch (Throwable $e) {
    respondServerError($e, 'manual_beer.php');
}
