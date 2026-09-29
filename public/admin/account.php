<?php
declare(strict_types=1);
define('ADMIN_PAGE', true);
$pageTitle = '账号设置';
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));
$admin = require_admin();
$pdo = db();

if (is_post()) {
    if (!verify_csrf()) {
        flash('error', '表单已过期，请重试。');
        redirect('/admin/account.php');
    }
    $current = post('current_password');
    $new = post('new_password');
    $confirm = post('confirm_password');

    if (!verify_password($current, $admin['password_hash'])) {
        flash('error', '当前密码不正确。');
    } elseif (strlen($new) < 10) {
        flash('error', '新密码至少 10 位。');
    } elseif ($new !== $confirm) {
        flash('error', '两次输入的新密码不一致。');
    } else {
        $hash = password_hash_custom($new);
        $stmt = $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?');
        $stmt->execute([$hash, now(), $admin['id']]);
        log_admin_action($pdo, 'password_change', '修改后台密码');
        flash('success', '密码已修改。');
    }
    redirect('/admin/account.php');
}

$flash = pull_flash();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><h1>账号设置</h1></div>
<div style="max-width:560px">
    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <div class="card">
        <h2 style="margin-top:0">修改登录密码</h2>
        <form method="post">
            <?= csrf_field() ?>
            <label><span>当前密码</span><input type="password" name="current_password" autocomplete="current-password" required></label>
            <label><span>新密码</span><input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
            <label><span>确认新密码</span><input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required></label>
            <button class="btn primary" type="submit">保存新密码</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
