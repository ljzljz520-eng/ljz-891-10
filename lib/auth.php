<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function start_session_securely(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('COURSE_SESSION');
    session_start();
}

function verify_password(string $password, string $storedHash): bool
{
    // Supports standard bcrypt/argon via password_hash, and PBKDF2 for the no-dependency demo hash.
    if (str_starts_with($storedHash, '$2') || str_starts_with($storedHash, '$argon2')) {
        return password_verify($password, $storedHash);
    }

    $parts = explode('$', $storedHash);
    if (count($parts) !== 5 || $parts[0] !== 'pbkdf2') {
        return false;
    }

    [, $algo, $iterations, $salt, $hash] = $parts;
    if (!in_array($algo, ['sha256', 'sha512'], true) || (int)$iterations < 10000) {
        return false;
    }

    $calculated = hash_pbkdf2($algo, $password, $salt, (int)$iterations, strlen($hash));
    return hash_equals($hash, $calculated);
}

function password_hash_custom(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function login_allowed(PDO $pdo): bool
{
    $since = date('Y-m-d H:i:s', time() - 900);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?");
    $stmt->execute([client_ip(), $since]);
    return (int)$stmt->fetchColumn() < 8;
}

function record_login_attempt(PDO $pdo, string $username, bool $success): void
{
    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip, success, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, client_ip(), $success ? 1 : 0, now()]);
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        flash('error', '请先登录后台。');
        redirect('/admin/login.php');
    }
    return $admin;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
