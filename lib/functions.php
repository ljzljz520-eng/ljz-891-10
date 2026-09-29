<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    static $config;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.local.php';
        if (!is_file($path)) {
            $path = dirname(__DIR__) . '/config.php';
        }
        $config = require $path;
    }
    return $config[$key] ?? $default;
}

function app_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function get(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function normalize_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function is_valid_phone(string $phone): bool
{
    return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
}

function is_valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generate_code(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return implode('-', str_split($code, 4));
}

function generate_digits(int $length = 6): string
{
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string)random_int(0, 9);
    }
    return $code;
}

function mask_phone(?string $phone): string
{
    if (!$phone || strlen($phone) !== 11) {
        return (string)$phone;
    }
    return substr($phone, 0, 3) . '****' . substr($phone, 7);
}

function mask_email(?string $email): string
{
    if (!$email || !str_contains($email, '@')) {
        return (string)$email;
    }
    [$name, $domain] = explode('@', $email, 2);
    if (strlen($name) <= 1) {
        return '*@' . $domain;
    }
    return substr($name, 0, 1) . '***' . substr($name, -1) . '@' . $domain;
}

function db_path(): string
{
    return app_path('data/app.sqlite');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = app_path('data');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $pdo = new PDO('sqlite:' . db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS redeem_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    package_name TEXT NOT NULL,
    bound_phone TEXT,
    bound_email TEXT,
    status TEXT NOT NULL DEFAULT 'active', -- active, disabled
    activated_at TEXT,
    expires_at TEXT NOT NULL,
    total_quota INTEGER NOT NULL,
    used_count INTEGER NOT NULL DEFAULT 0,
    note TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS query_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_text TEXT NOT NULL,
    phone TEXT NOT NULL,
    ip TEXT NOT NULL,
    user_agent TEXT DEFAULT '',
    success INTEGER NOT NULL DEFAULT 0,
    reason TEXT DEFAULT '',
    redeem_code_id INTEGER,
    created_at TEXT NOT NULL,
    FOREIGN KEY(redeem_code_id) REFERENCES redeem_codes(id) ON DELETE SET NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS email_verifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    redeem_code_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    new_phone TEXT NOT NULL,
    consumed INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    consumed_at TEXT,
    FOREIGN KEY(redeem_code_id) REFERENCES redeem_codes(id) ON DELETE CASCADE
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    email TEXT DEFAULT '',
    must_change_password INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS admin_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    action TEXT NOT NULL,
    detail TEXT DEFAULT '',
    ip TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS mail_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    to_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);

    create_indexes($pdo);
    seed_default_admin($pdo);
}

function create_indexes(PDO $pdo): void
{
    $indexes = [
        'idx_redeem_codes_code' => 'CREATE INDEX IF NOT EXISTS idx_redeem_codes_code ON redeem_codes(code)',
        'idx_redeem_codes_status' => 'CREATE INDEX IF NOT EXISTS idx_redeem_codes_status ON redeem_codes(status)',
        'idx_query_logs_created' => 'CREATE INDEX IF NOT EXISTS idx_query_logs_created ON query_logs(created_at)',
        'idx_query_logs_code' => 'CREATE INDEX IF NOT EXISTS idx_query_logs_code ON query_logs(code_text)',
        'idx_email_verifications_code' => 'CREATE INDEX IF NOT EXISTS idx_email_verifications_code ON email_verifications(redeem_code_id)',
        'idx_login_attempts_ip' => 'CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, created_at)',
    ];
    foreach ($indexes as $sql) {
        $pdo->exec($sql);
    }
}

function seed_default_admin(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count > 0) {
        return;
    }

    // bcrypt hash for ChangeMe!2026. It must be replaced after first login.
    $defaultHash = '$2b$10$n.o2XryQEnAAGVx/GmFisuzai0UOHVoSAjWz9I.fois9T5oHKKtZK';
    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, email, must_change_password, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)');
    $stmt->execute([config('admin_user'), $defaultHash, '', now(), now()]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    return is_post() && hash_equals(csrf_token(), post('csrf'));
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return substr($ip, 0, 45);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function status_label(string $status, ?string $expiresAt = null, int $remaining = PHP_INT_MAX): string
{
    if ($status === 'disabled') {
        return '已停用';
    }
    if ($expiresAt !== null && $expiresAt <= now()) {
        return '已过期';
    }
    if ($remaining <= 0) {
        return '次数已用尽';
    }
    return '正常';
}

function status_badge_class(string $runtimeStatus): string
{
    return match ($runtimeStatus) {
        '正常' => 'ok',
        '已停用' => 'danger',
        default => 'muted',
    };
}

function log_admin_action(PDO $pdo, string $action, string $detail = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO admin_actions (admin_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$_SESSION['admin_id'] ?? null, $action, $detail, client_ip(), now()]);
}

function mask_code_text(string $code): string
{
    $code = normalize_code($code);
    if (strlen($code) <= 4) {
        return $code;
    }
    return substr($code, 0, 4) . str_repeat('•', max(0, strlen($code) - 4));
}

function log_reason_label(string $reason): string
{
    return match ($reason) {
        'success' => '成功',
        'not_found' => '不存在',
        'phone_mismatch' => '手机号不匹配',
        'invalid_input' => '输入无效',
        'rate_limited' => '请求过频',
        'disabled' => '兑换码停用',
        'not_bound' => '未绑定',
        'expired' => '已过期',
        'quota_exhausted' => '次数用尽',
        'code_sent' => '验证码已发送',
        'change_verify_invalid' => '改绑校验失败',
        'change_disabled' => '停用码改绑',
        'bad_code' => '验证码错误',
        'phone_changed' => '手机号已修改',
        default => $reason,
    };
}

function pagination_page(): int
{
    return max(1, (int)get('page', '1'));
}

function pagination_url(int $page, array $params = []): string
{
    return '?' . http_build_query(array_merge($_GET, $params, ['page' => $page]));
}

function render_pagination(int $page, int $total, int $perPage): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<div class="pagination"><span class="text-muted">第 ' . $page . ' / ' . $pages . ' 页</span>';
    if ($page > 1) {
        $html .= '<a class="btn small" href="' . e(pagination_url($page - 1)) . '">上一页</a>';
    }
    if ($page < $pages) {
        $html .= '<a class="btn small" href="' . e(pagination_url($page + 1)) . '">下一页</a>';
    }
    return $html . '</div>';
}

function public_query_allowed(): bool
{
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - 600);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM query_logs WHERE ip = ? AND success = 0 AND created_at >= ?");
    $stmt->execute([client_ip(), $since]);
    return (int)$stmt->fetchColumn() < 10;
}

function record_public_failure(string $code, string $phone, string $reason, ?int $codeId = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO query_logs (code_text, phone, ip, user_agent, success, reason, redeem_code_id, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)');
    $stmt->execute([
        $code,
        $phone,
        client_ip(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $reason,
        $codeId,
        now(),
    ]);
}

function truncate_text(string $text, int $limit = 60): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit - 1) . '…';
}
