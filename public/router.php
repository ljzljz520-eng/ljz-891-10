<?php
// Run from project root: php -S 127.0.0.1:8000 -t public public/router.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}
if ($uri === '/') {
    require __DIR__ . '/index.php';
    return true;
}

$script = __DIR__ . rtrim($uri, '/') . '.php';
if (is_file($script)) {
    require $script;
    return true;
}

http_response_code(404);
echo 'Not Found';
