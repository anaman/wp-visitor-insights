<?php
/**
 * Router for PHP's built-in server (wp-visitor-insights.service).
 * Blocks secrets/dotfiles; serves PHP via include, static via the server.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// never expose config, dotfiles, or the bin/lib trees
if (preg_match('#(^|/)(\.|config\.php$|lib/|geoip/|bin/|docs/)#i', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "forbidden";
    return true;
}

if ($path === '/' || $path === '') {
    $path = '/index.html';
}

$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file === false || strpos($file, $root) !== 0 || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "not found";
    return true;
}

if (substr($file, -4) === '.php') {
    require $file;
    return true;
}

return false; // let the built-in server stream the static file
