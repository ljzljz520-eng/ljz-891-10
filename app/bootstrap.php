<?php
declare(strict_types=1);

error_reporting(E_ALL);

date_default_timezone_set('Asia/Shanghai');

$GLOBALS['CFG'] = require __DIR__ . '/config.php';

if ($GLOBALS['CFG']['debug']) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

require __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/views.php';

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// Session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $secure,
    'samesite' => 'Lax',
]);
session_name('COURSE_SESS');
session_start();

// 初始化数据库（建表 + 默认管理员）
db();

set_exception_handler(function (Throwable $e): void {
    if (($GLOBALS['CFG']['debug'] ?? false)) {
        http_response_code(500);
        echo '<pre>' . h($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
        return;
    }
    error_log('[course] ' . $e->getMessage());
    http_response_code(500);
    $isApi = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
          || str_contains($_SERVER['REQUEST_URI'] ?? '', 'api/');
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => '服务器内部错误']);
    } else {
        echo '服务器内部错误，请稍后再试。';
    }
});
