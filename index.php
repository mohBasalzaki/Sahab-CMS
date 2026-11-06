<?php
declare(strict_types=1);

// PHP built-in server: serve only whitelisted static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php'
        && preg_match('#^/(uploads/|admin/assets/|themes/[^/]+/(assets/|screenshot\.)|plugins/[^/]+/assets/)#', $path)
        && !str_contains($path, '..')) {
        return false;
    }
}

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Sahab CMS requires PHP 8.1 or newer.');
}
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Missing vendor/ directory. Run "composer install --no-dev" or upload the release package.');
}
require __DIR__ . '/vendor/autoload.php';

(new Sahab\Kernel(__DIR__))->run();
