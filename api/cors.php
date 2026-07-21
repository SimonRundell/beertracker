<?php
declare(strict_types=1);

/**
 * Shared CORS handling, included first by every API endpoint.
 * Reflects the request Origin when it is localhost on any port (dev-friendly),
 * sets the standard JSON/CORS headers, and short-circuits OPTIONS preflight.
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('#^https?://localhost(:\d+)?$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}
