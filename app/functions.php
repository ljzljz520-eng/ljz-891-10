<?php
declare(strict_types=1);

/* ---------- 基础工具 ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now(): int
{
    return time();
}

function fmt_time(?int $ts): string
{
    if ($ts === null || $ts <= 0) {
        return '永久有效';
    }
    return date('Y-m-d H:i', $ts);
}

/** 兑换码归一化：去空格、横杠并转大写 */
function normalize_code(string $code): string
{
    $code = trim($code);
    $code = str_replace(['-', ' '], '', $code);
    return strtoupper($code);
}

function valid_phone(string $phone): bool
{
    return (bool) preg_match('/^1[3-9]\d{9}$/', $phone);
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** 手机号脱敏 138****1234 */
function mask_phone(?string $phone): string
{
    if (!$phone || strlen($phone) !== 11) {
        return (string)$phone;
    }
    return substr($phone, 0, 3) . '****' . substr($phone, 7);
}

/** 邮箱脱敏 ab***@example.com */
function mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $shown = mb_substr($name, 0, 2) . '***';
    return $shown . '@' . $parts[1];
}

function gen_code(int $len = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混淆字符 I O 0 1
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function gen_digits(int $len = 6): string
{
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= (string) random_int(0, 9);
    }
    return $out;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('表单已过期，请返回重试。');
    }
}

/* ---------- Flash ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- 数据库 ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = $GLOBALS['CFG'];
    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $cfg['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec('PRAGMA foreign_keys = ON');

    foreach (require __DIR__ . '/schema.php' as $sql) {
        $pdo->exec($sql);
    }

    // 首次安装：写入默认管理员
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO admins (username, password, email, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $cfg['admin']['username'],
            password_hash($cfg['admin']['password'], PASSWORD_DEFAULT),
            '',
            now(),
        ]);
    }

    return $pdo;
}

/* ---------- 日志 ---------- */

function add_log(string $code, string $result, string $action = 'query', ?int $adminId = null, string $phone = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO query_logs (code, phone, result, ip, user_agent, admin_id, action, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $stmt->execute([$code, $phone, $result, client_ip(), $ua, $adminId, $action, now()]);
}

/* ---------- 请求限流 ---------- */

/**
 * 基于日志表的简单限流：统计某 IP 在指定秒数内的 action 次数。
 * 返回 true 表示允许，false 表示超限。
 */
function rate_check(string $action, int $limit, int $windowSeconds, string $ip = ''): bool
{
    $ip = $ip ?: client_ip();
    $since = now() - $windowSeconds;
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM query_logs WHERE action = ? AND ip = ? AND created_at >= ?'
    );
    $stmt->execute([$action, $ip, $since]);
    return ((int) $stmt->fetchColumn()) < $limit;
}

/* ---------- JSON ---------- */

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_request_json(): array
{
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}
