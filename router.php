<?php
// Router for `php -S` dev server.
// Serves real files as-is; renders the custom 404 page for unknown URLs.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Service worker: must never be edge/browser-cached, so SW updates apply instantly.
if ($uri === '/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Service-Worker-Allowed: /');
    readfile(__DIR__ . '/sw.js');
    return true;
}

// PWA manifest with the correct MIME type.
if ($uri === '/manifest.webmanifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    readfile(__DIR__ . '/manifest.webmanifest');
    return true;
}

// Real static file or PHP file → let the built-in server handle it
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Root → index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// Mobile REST API: /api/... → api/index.php (handles its own sub-routing)
if ($uri === '/api' || strpos($uri, '/api/') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Directory with index.php → render that
if (is_dir($file)) {
    $indexFile = rtrim($file, '/') . '/index.php';
    if (is_file($indexFile)) {
        $_SERVER['SCRIPT_NAME']     = rtrim($uri, '/') . '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $indexFile;
        require $indexFile;
        return true;
    }
}

// Anything else → custom 404
http_response_code(404);
require __DIR__ . '/404.php';
return true;
