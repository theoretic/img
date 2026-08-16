<?php

/**
 * Router for `php -S`, standing in for the site's two .htaccess files.
 *
 * Anything under /img/ is handed to the endpoint with the rest of the path as
 * the request — the same shape the RewriteRule produces, and without QSA, so a
 * client-supplied ?request= cannot override it.
 */

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve the real @atispro/core build, so client.html exercises the shipped
// engine rather than a copy of it.
if (str_starts_with($path, '/core/')) {
    $file = dirname(__DIR__, 3) . '/frontend/js/_core/src/' . basename($path);
    if (is_file($file)) {
        header('Content-Type: application/javascript');
        readfile($file);

        return true;
    }

    header('HTTP/1.1 404 Not Found');

    return true;
}

if (str_starts_with($path, '/img/')) {
    $request = rawurldecode(substr($path, 5));

    // Rebuild $_GET the way the rewrite would: the path is the only thing taken
    // from the URL, plus the debug switch the stub also honours.
    $debug = $_GET['debug'] ?? null;
    $_GET = ['request' => $request];
    if ($debug !== null) {
        $_GET['debug'] = $debug;
    }

    require __DIR__ . '/api.php';

    return true;
}

return false; // index.php and static files are served by the built-in server
