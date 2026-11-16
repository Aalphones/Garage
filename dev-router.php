<?php

declare(strict_types=1);

// Router für den lokalen PHP-Server (wird nicht hochgeladen). Start im Repo-Ordner:
//   php -S localhost:8081 dev-router.php
// Bildet Brücke (/garage/, /.well-known/oauth-*/garage…) und Sperre (/garage-app/) nach.

const STATIC_TYPES = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json',
    'webmanifest' => 'application/manifest+json',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'webp' => 'image/webp',
];

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if (str_starts_with($path, '/garage-app/')) {
    http_response_code(403);

    return true;
}

$isWellKnown = preg_match('#^/\.well-known/[a-z-]+/garage(/|$)#', $path) === 1;
if ($path !== '/garage' && !str_starts_with($path, '/garage/') && !$isWellKnown) {
    http_response_code(404);

    return true;
}

// Statische Dateien aus web/ wie Apache ausliefern (DirectoryIndex index.html), PHP-Dateien nie direkt.
$relative = $isWellKnown ? '' : substr($path, strlen('/garage'));
if ($relative === '' || str_ends_with($relative, '/')) {
    $relative .= 'index.html';
}
$file = realpath(__DIR__ . '/web' . $relative);
$webDir = realpath(__DIR__ . '/web');
$extension = $file === false ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!$isWellKnown && $file !== false && $webDir !== false && str_starts_with($file, $webDir) && isset(STATIC_TYPES[$extension])) {
    header('Content-Type: ' . STATIC_TYPES[$extension]);
    readfile($file);

    return true;
}

// Nur dieser Router setzt die Variable: lokal gilt app/.env.local, nie app/.env.
putenv('GARAGE_ENV_FILE=' . __DIR__ . '/app/.env.local');
require __DIR__ . '/app/index.php';

return true;
