<?php
declare(strict_types=1);
define('ADMIN_PAGE', 'login');
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));

if (current_admin()) {
    redirect('/admin/index.php');
}

$error = '';
$username = '';

if (is_post()) {
    if (!verify_csrf()) {
        $error = '页面已过期，请重试。';
    } else {
        $username = post('username');
        $password = post('password');
        $pdo = db();

        if (!login_allowed($pdo)) {
            $error = '登录失败次数过多，请 15 分钟后再试。';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && verify_password($password, $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id'];
                record_login_attempt($pdo, $username, true);
                log_admin_action($pdo, 'login', '后台登录');
                if ((int)$admin['must_change_password'] === 1) {
                    flash('error', '首次登录请尽快修改默认密码。');
                    redirect('/admin/account.php');
                }
                redirect('/admin/index.php');
            }

            record_login_attempt($pdo, $username, false);
            $error = '用户名或密码不正确。';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台登录</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-page">
<div class="card login-card">
    <h1>管理后台登录</h1>
    <p class="hint">兑换码、日志与异常码管理</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label><span>用户名</span><input name="username" value="<?= e($username) ?>" required autocomplete="username"></label>
        <label><span>密码</span><input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn primary" style="width:100%" type="submit">登录</button>
    </form>
    <p class="hint"><a href="/">← 返回查询页</a></p>
</div>
</body>
</html>
