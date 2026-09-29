<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/views.php';

Auth::requireLogin();
$pdo = db();
$adminId = Auth::id();
$admin = Auth::user();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $st = $pdo->prepare('SELECT password FROM admins WHERE id = ?');
        $st->execute([$adminId]);
        $hash = (string) $st->fetchColumn();

        if (!password_verify($old, $hash)) {
            flash('error', '当前密码不正确');
        } elseif (strlen($new) < 8) {
            flash('error', '新密码至少 8 位');
        } elseif ($new !== $confirm) {
            flash('error', '两次输入的新密码不一致');
        } else {
            Auth::changePassword($adminId, password_hash($new, PASSWORD_DEFAULT));
            flash('success', '密码已更新');
        }
        redirect('profile.php');
    }

    if ($do === 'email') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !valid_email($email)) {
            flash('error', '邮箱格式不正确');
        } else {
            Auth::updateEmail($adminId, $email);
            flash('success', '默认接收邮箱已保存');
        }
        redirect('profile.php');
    }
}

admin_header('账号设置', '');
?>
<div class="panel" style="max-width:520px">
  <h2>修改密码</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <label class="field"><span>当前密码</span><input type="password" name="old_password" required></label>
    <label class="field"><span>新密码（至少 8 位）</span><input type="password" name="new_password" minlength="8" required></label>
    <label class="field"><span>确认新密码</span><input type="password" name="confirm_password" minlength="8" required></label>
    <button class="btn btn-primary" type="submit">保存密码</button>
  </form>
</div>

<div class="panel" style="max-width:520px">
  <h2>默认验证邮箱</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="email">
    <label class="field">
      <span>改绑手机时接收验证码的邮箱</span>
      <input type="email" name="email" value="<?= h($admin['email'] ?? '') ?>" placeholder="留空则每次改绑时手动输入">
    </label>
    <button class="btn btn-primary" type="submit">保存邮箱</button>
  </form>
</div>
<?php admin_footer(); ?>
