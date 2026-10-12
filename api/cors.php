<?php
declare(strict_types=1);

/**
 * Shared CORS handling, included first by every API endpoint.
 *
 * Only two kinds of origin are allowed, and each is reflected back exactly:
 *   - localhost on any port (http or https), for Vite and Laragon dev
 *   - the exact production origins listed in $allowedOrigins
 * Any other origin gets no Access-Control-Allow-Origin header, so browsers
 * block the response; an OPTIONS preflight from one is refused with 403.
 * Requests with no Origin header (curl, server-to-server) are unaffected.
 */

// Exact-match production origins. Add new deployments here.
$allowedOrigins = [
    'https://beertracker.codemonkey.design',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isLocalhost = $origin !== '' && preg_match('#^https?://localhost(:\d+)?$#i', $origin) === 1;
$isAllowedOrigin = $origin !== '' && in_array($origin, $allowedOrigins, true);

header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

if ($isLocalhost || $isAllowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code($origin === '' || $isLocalhost || $isAllowedOrigin ? 200 : 403);
    exit;
}
