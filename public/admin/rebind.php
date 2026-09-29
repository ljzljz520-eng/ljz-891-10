<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/views.php';

Auth::requireLogin();
$pdo = db();
$cfg = $GLOBALS['CFG'];
$adminId = Auth::id();
$admin = Auth::user();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM codes WHERE id = ?');
$stmt->execute([$id]);
$codeRow = $stmt->fetch();

if (!$codeRow) {
    flash('error', '兑换码不存在');
    redirect('codes.php');
}
if (!$codeRow['bound_phone']) {
    flash('error', '该兑换码尚未激活，无需改绑');
    redirect('codes.php');
}

/* ---------------- 发送验证码 ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['do'] ?? '') === 'send') {
    csrf_check();
    $email    = trim((string)($_POST['email'] ?? ''));
    $newPhone = trim((string)($_POST['new_phone'] ?? ''));

    if (!valid_email($email)) {
        flash('error', '请填写有效的邮箱地址');
        redirect('rebind.php?id=' . $id);
    }
    if (!valid_phone($newPhone)) {
        flash('error', '新手机号格式不正确');
        redirect('rebind.php?id=' . $id);
    }
    if ($newPhone === $codeRow['bound_phone']) {
        flash('error', '新手机号与当前绑定号码相同');
        redirect('rebind.php?id=' . $id);
    }

    // 发送冷却
    $coolStmt = $pdo->prepare(
        'SELECT created_at FROM email_verifications WHERE code_id = ? ORDER BY id DESC LIMIT 1'
    );
    $coolStmt->execute([$id]);
    $lastAt = (int) ($coolStmt->fetchColumn() ?: 0);
    if ($lastAt && now() - $lastAt < $cfg['email_code_cooldown']) {
        $wait = $cfg['email_code_cooldown'] - (now() - $lastAt);
        flash('error', "发送过于频繁，请 {$wait} 秒后再试");
        redirect('rebind.php?id=' . $id);
    }

    $digits = gen_digits(6);
    $expires = now() + $cfg['email_code_ttl'];
    $ins = $pdo->prepare(
        'INSERT INTO email_verifications (code_id, email, code, new_phone, consumed, expires_at, created_at)
         VALUES (?, ?, ?, ?, 0, ?, ?)'
    );
    $ins->execute([$id, $email, password_hash($digits, PASSWORD_DEFAULT), $newPhone, $expires, now()]);
    $verifyId = (int) $pdo->lastInsertId();

    // 记住管理员最近使用的邮箱，方便下次回填
    if ($admin['email'] !== $email) {
        Auth::updateEmail($adminId, $email);
    }

    $subject = '【云课堂】兑换码改绑手机验证码';
    $body =
        '<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px">'
      . '<h2 style="font-size:18px">兑换码改绑手机验证</h2>'
      . '<p>您正在为兑换码 <strong>' . h($codeRow['code']) . '</strong>（' . h($codeRow['package']) . '）修改绑定手机号。</p>'
      . '<p>本次验证码为：</p>'
      . '<p style="font-size:30px;font-weight:700;letter-spacing:8px;color:#4f6ef7">' . h($digits) . '</p>'
      . '<p>验证码 10 分钟内有效，请勿向任何人泄露。如非本人操作，请忽略此邮件。</p>'
      . '</div>';

    $mailer = make_mailer();
    $err = $mailer->send($email, $subject, $body);
    if ($err !== '') {
        // 邮件发送失败：作废记录
        $pdo->prepare('DELETE FROM email_verifications WHERE id = ?')->execute([$verifyId]);
        error_log('[mail] ' . $err);
        flash('error', '验证码发送失败：' . $err . '。如使用本地邮件驱动，请检查 data/mail 目录。');
        redirect('rebind.php?id=' . $id);
    }

    $_SESSION['rebind_pending'] = [
        'verify_id' => $verifyId,
        'code_id'   => $id,
        'email'     => $email,
        'new_phone' => $newPhone,
        'expires'   => $expires,
    ];
    add_log($codeRow['code'], "改绑验证码已发送至 {$email}", 'admin_rebind', $adminId, $codeRow['bound_phone']);
    flash('success', '验证码已发送至 ' . mask_email($email) . '，请查收（10 分钟内有效）');
    redirect('rebind.php?id=' . $id . '&step=verify');
}

/* ---------------- 校验验证码并改绑 ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['do'] ?? '') === 'verify') {
    csrf_check();
    $digits = trim((string)($_POST['verify_code'] ?? ''));
    $pending = $_SESSION['rebind_pending'] ?? null;

    if (!$pending || (int)($pending['code_id'] ?? 0) !== $id) {
        flash('error', '验证会话已失效，请重新获取验证码');
        redirect('rebind.php?id=' . $id);
    }
    if (!preg_match('/^\d{6}$/', $digits)) {
        flash('error', '请输入 6 位数字验证码');
        redirect('rebind.php?id=' . $id . '&step=verify');
    }

    $vs = $pdo->prepare(
        'SELECT * FROM email_verifications WHERE id = ? AND code_id = ? AND consumed = 0 ORDER BY id DESC LIMIT 1'
    );
    $vs->execute([$pending['verify_id'], $id]);
    $ver = $vs->fetch();

    if (!$ver || (int)$ver['expires_at'] < now() || !password_verify($digits, $ver['code'])) {
        flash('error', '验证码错误或已过期');
        redirect('rebind.php?id=' . $id . '&step=verify');
    }
    if ($ver['new_phone'] !== $pending['new_phone'] || $ver['email'] !== $pending['email']) {
        flash('error', '验证信息不一致，请重新操作');
        redirect('rebind.php?id=' . $id);
    }

    // 改绑 + 作废该码所有未用验证码
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE codes SET bound_phone = ?, updated_at = ? WHERE id = ?')
            ->execute([$ver['new_phone'], now(), $id]);
        $pdo->prepare('UPDATE email_verifications SET consumed = 1 WHERE code_id = ? AND consumed = 0')
            ->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    unset($_SESSION['rebind_pending']);
    add_log(
        $codeRow['code'],
        '改绑手机：' . mask_phone($codeRow['bound_phone']) . ' → ' . mask_phone($ver['new_phone']) . "（验证邮箱 {$ver['email']}）",
        'admin_rebind',
        $adminId,
        $ver['new_phone'],
    );
    flash('success', '绑定手机号已更新为 ' . mask_phone($ver['new_phone']));
    redirect('codes.php');
}

/* ---------------- 页面展示 ---------------- */
$step = (string)($_GET['step'] ?? 'send');
$pending = $_SESSION['rebind_pending'] ?? null;
$showVerify = $step === 'verify' && $pending && (int)($pending['code_id'] ?? 0) === $id && (int)($pending['expires'] ?? 0) > now();
$presetEmail = $pending['email'] ?? ($admin['email'] ?? '');
$presetPhone = $pending['new_phone'] ?? '';

admin_header('修改绑定手机号', 'codes.php');
?>
<div class="panel" style="max-width:560px">
  <div class="steps">
    <div class="step <?= $showVerify ? 'on' : '' ?>">1. 填写新号码并获取验证码</div>
    <div class="step <?= $showVerify ? 'on' : '' ?>">2. 输入邮箱验证码确认</div>
  </div>

  <table class="table" style="margin-bottom:18px">
    <tr><th style="width:110px">兑换码</th><td><code><?= h($codeRow['code']) ?></code></td></tr>
    <tr><th>课程包</th><td><?= h($codeRow['package']) ?></td></tr>
    <tr><th>当前绑定</th><td><?= h(mask_phone($codeRow['bound_phone'])) ?></td></tr>
  </table>

  <?php if (!$showVerify): ?>
  <form method="post" action="rebind.php?id=<?= (int)$codeRow['id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="send">
    <label class="field">
      <span>管理员邮箱（用于接收验证码）</span>
      <input type="email" name="email" value="<?= h($presetEmail) ?>" required placeholder="name@example.com">
    </label>
    <label class="field">
      <span>新绑定手机号</span>
      <input type="tel" name="new_phone" maxlength="11" required placeholder="11 位中国大陆手机号">
    </label>
    <button class="btn btn-primary" type="submit">发送验证码</button>
    <a class="btn" href="codes.php">取消</a>
  </form>
  <?php else: ?>
  <form method="post" action="rebind.php?id=<?= (int)$codeRow['id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="verify">
    <p class="muted" style="margin-top:0">
      验证码已发送至 <strong><?= h(mask_email($pending['email'])) ?></strong>，
      新绑定号码：<strong><?= h(mask_phone($pending['new_phone'])) ?></strong>
    </p>
    <label class="field">
      <span>6 位邮箱验证码</span>
      <input type="text" name="verify_code" maxlength="6" inputmode="numeric"
             pattern="\d{6}" required autofocus placeholder="请输入验证码"
             style="letter-spacing:6px;font-size:18px">
    </label>
    <button class="btn btn-primary" type="submit">确认改绑</button>
    <a class="btn" href="codes.php">取消</a>
    <p class="muted" style="margin-top:12px">
      没收到？<?= $cfg['mail']['driver'] === 'file'
        ? '当前为本地开发模式，验证码邮件保存在服务器 data/mail 目录。'
        : '请检查垃圾邮件，或稍后重新发送（两次发送间隔 60 秒）。' ?>
    </p>
  </form>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
