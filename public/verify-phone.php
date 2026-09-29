<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));

$id = (int)($_SESSION['phone_change_id'] ?? 0);
if (!$id) {
    flash('error', '请先申请邮箱验证码。');
    redirect('/change-phone.php');
}

$pdo = db();
$stmt = $pdo->prepare(<<<SQL
SELECT ev.*, rc.code, rc.status AS code_status
FROM email_verifications ev
JOIN redeem_codes rc ON rc.id = ev.redeem_code_id
WHERE ev.id = ?
SQL);
$stmt->execute([$id]);
$verification = $stmt->fetch();

if (!$verification) {
    unset($_SESSION['phone_change_id']);
    flash('error', '验证流程已失效，请重新开始。');
    redirect('/change-phone.php');
}

$error = '';
$codeInput = '';

if (is_post()) {
    if (!verify_csrf()) {
        flash('error', '页面已过期，请重新输入。');
        redirect('/verify-phone.php');
    }

    $codeInput = post('code');
    if (!preg_match('/^\d{6}$/', $codeInput)) {
        $error = '请输入 6 位数字验证码。';
    } else {
        $result = confirm_phone_change($verification, $codeInput);
        if ($result['ok']) {
            unset($_SESSION['phone_change_id']);
            flash('success', '绑定手机号已更新，请使用新手机号查询。');
            redirect('/');
        }
        $error = $result['message'];
        // Refresh after attempts increment.
        $stmt->execute([$id]);
        $verification = $stmt->fetch();
    }
}

function confirm_phone_change(array $verification, string $plainCode): array
{
    $pdo = db();

    if ((int)$verification['consumed'] === 1) {
        return ['ok' => false, 'message' => '验证码已使用，请重新申请。'];
    }
    if ($verification['expires_at'] <= now()) {
        return ['ok' => false, 'message' => '验证码已过期，请重新申请。'];
    }
    if ((int)$verification['attempts'] >= 5) {
        return ['ok' => false, 'message' => '错误次数过多，请重新申请验证码。'];
    }
    if ($verification['code_status'] === 'disabled') {
        return ['ok' => false, 'message' => '兑换码已停用，无法修改。'];
    }

    if (!hash_equals($verification['code_hash'], hash('sha256', $plainCode))) {
        $stmt = $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?');
        $stmt->execute([$verification['id']]);
        record_verify_log($verification, 'bad_code');
        return ['ok' => false, 'message' => '验证码不正确。'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE redeem_codes SET bound_phone = ?, activated_at = COALESCE(activated_at, ?), updated_at = ? WHERE id = ? AND status = 'active'");
        $stmt->execute([$verification['new_phone'], now(), now(), $verification['redeem_code_id']]);

        $stmt = $pdo->prepare('UPDATE email_verifications SET consumed = 1, consumed_at = ? WHERE id = ?');
        $stmt->execute([now(), $verification['id']]);

        $stmt = $pdo->prepare('INSERT INTO query_logs (code_text, phone, ip, user_agent, success, reason, redeem_code_id, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
        $stmt->execute([
            $verification['code'],
            $verification['new_phone'],
            client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'phone_changed',
            $verification['redeem_code_id'],
            now(),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => '更新失败，请稍后再试。'];
    }

    return ['ok' => true];
}

function record_verify_log(array $verification, string $reason): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO query_logs (code_text, phone, ip, user_agent, success, reason, redeem_code_id, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)');
    $stmt->execute([$verification['code'], $verification['new_phone'], client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $reason, $verification['redeem_code_id'], now()]);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>验证邮箱验证码</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="public-page">
<div class="shell narrow">
    <header class="hero compact"><h1>输入邮箱验证码</h1></header>
    <main class="card">
        <p>验证码已发送至 <strong><?= e(mask_email($verification['email'])) ?></strong>，将绑定新手机号 <strong><?= e(mask_phone($verification['new_phone'])) ?></strong>。</p>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>
                <span>6 位验证码</span>
                <input class="code-input" name="code" value="<?= e($codeInput) ?>" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
            </label>
            <button class="btn primary" type="submit">确认修改</button>
        </form>
        <p class="hint">验证码有效期 <?= (int)config('code_ttl_seconds') / 60 ?> 分钟。如未收到，请等待 1 分钟后 <a href="/change-phone.php">重新发送</a>。</p>
    </main>
</div>
</body>
</html>
