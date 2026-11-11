<?php
// Local dev only: `php -S localhost:8000 router.php`.
// PHP's built-in server ignores .htaccess, so this copies its rules: clean URLs and blocked dotfiles.
// Apache never runs this file (its requests use .htaccess), and a request for /router.php just gets a 404.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_SERVER['QUERY_STRING'] ?? '';
$qs = $query === '' ? '' : '?' . $query;

// Dotfiles (.env, .git, ...) and this router itself.
if (preg_match('#/\.#', $path) || $path === '/router.php') {
    http_response_code(404);
    exit('Not found');
}

// /cadastro.html → /cadastro, /index.html → /descubra (301, query string kept).
$redirects = ['/cadastro.html' => '/cadastro', '/index.html' => '/descubra'];
if (isset($redirects[$path])) {
    header('Location: ' . $redirects[$path] . $qs, true, 301);
    exit;
}

// Clean URLs.
$pages = ['/descubra' => 'index.html', '/cadastro' => 'cadastro.html'];
$clean = rtrim($path, '/');
if (isset($pages[$clean])) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/' . $pages[$clean]);
    exit;
}

// Everything else (/, assets, api/*.php): let the built-in server handle it normally.
return false;
