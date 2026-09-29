<?php
declare(strict_types=1);

class Auth
{
    public const SESSION_KEY = 'admin_id';
    public const FAIL_KEY = 'login_fails'; // [count => n, until => ts]

    public static function check(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function id(): ?int
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        return $id === null ? null : (int) $id;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $stmt = db()->prepare('SELECT id, username, email, created_at FROM admins WHERE id = ?');
        $stmt->execute([self::id()]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login.php');
        }
    }

    /**
     * 尝试登录。成功返回 null，失败返回错误提示。
     */
    public static function attempt(string $username, string $password): ?string
    {
        $cfg = $GLOBALS['CFG'];
        $fails = $_SESSION[self::FAIL_KEY] ?? ['count' => 0, 'until' => 0];

        if ($fails['count'] >= $cfg['login_max_fail'] && now() < $fails['until']) {
            $mins = (int) ceil(($fails['until'] - now()) / 60);
            return "失败次数过多，请 {$mins} 分钟后再试。";
        }
        if ($fails['until'] > 0 && now() >= $fails['until']) {
            $fails = ['count' => 0, 'until' => 0];
        }

        $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password'])) {
            $fails['count']++;
            if ($fails['count'] >= $cfg['login_max_fail']) {
                $fails['until'] = now() + $cfg['login_lock_seconds'];
            }
            $_SESSION[self::FAIL_KEY] = $fails;
            $left = $cfg['login_max_fail'] - $fails['count'];
            return $left > 0 ? "账号或密码错误，还可尝试 {$left} 次。" : '失败次数过多，账号已临时锁定 15 分钟。';
        }

        unset($_SESSION[self::FAIL_KEY]);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $admin['id'];
        return null;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /** 修改当前管理员密码 */
    public static function changePassword(int $id, string $newHash): void
    {
        $stmt = db()->prepare('UPDATE admins SET password = ? WHERE id = ?');
        $stmt->execute([$newHash, $id]);
    }

    public static function updateEmail(int $id, string $email): void
    {
        $stmt = db()->prepare('UPDATE admins SET email = ? WHERE id = ?');
        $stmt->execute([$email, $id]);
    }
}
