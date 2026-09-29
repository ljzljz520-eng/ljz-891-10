<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mailer.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));

$error = '';
$input = ['code' => normalize_code(get('code')), 'current_phone' => '', 'new_phone' => ''];

if (is_post()) {
    if (!verify_csrf()) {
        flash('error', '页面已过期，请重新提交。');
        redirect('/change-phone.php');
    }

    $input['code'] = normalize_code(post('code'));
    $input['current_phone'] = post('current_phone');
    $input['new_phone'] = post('new_phone');

    if (!$input['code'] || !is_valid_phone($input['current_phone']) || !is_valid_phone($input['new_phone'])) {
        $error = '请填写正确的兑换码、原手机号和新手机号。';
    } elseif ($input['current_phone'] === $input['new_phone']) {
        $error = '新手机号不能与原手机号相同。';
    } elseif (!public_query_allowed()) {
        $error = '操作过于频繁，请稍后再试。';
    } else {
        $result = start_phone_change($input['code'], $input['current_phone'], $input['new_phone']);
        if ($result['ok']) {
            $_SESSION['phone_change_id'] = $result['id'];
            redirect('/verify-phone.php');
        }
        $error = $result['message'];
    }
}

function start_phone_change(string $codeText, string $currentPhone, string $newPhone): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM redeem_codes WHERE code = ?');
    $stmt->execute([$codeText]);
    $code = $stmt->fetch();
    $generic = '兑换码或原手机号不正确，或该兑换码未设置绑定邮箱。';

    if (!$code || empty($code['bound_phone']) || !hash_equals($code['bound_phone'], $currentPhone) || empty($code['bound_email'])) {
        record_change_log($codeText, $currentPhone, 'change_verify_invalid');
        return ['ok' => false, 'message' => $generic];
    }
    if ($code['status'] === 'disabled') {
        record_change_log($codeText, $currentPhone, 'change_disabled');
        return ['ok' => false, 'message' => '兑换码已停用，暂不能修改手机号。'];
    }

    if (!verification_send_allowed($pdo, (int)$code['id'])) {
        return ['ok' => false, 'message' => '验证码发送过于频繁，请 1 分钟后重试。'];
    }

    $plain = generate_digits(6);
    $hash = hash('sha256', $plain);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)config('code_ttl_seconds', 600));

    try {
        send_verification_code($code, $code['bound_email'], $newPhone, $plain);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => '验证码邮件发送失败，请稍后联系管理员。'];
    }

    $stmt = $pdo->prepare('INSERT INTO email_verifications (redeem_code_id, email, code_hash, new_phone, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$code['id'], $code['bound_email'], $hash, $newPhone, $expiresAt, now()]);
    $verificationId = (int)$pdo->lastInsertId();

    record_change_log($codeText, $currentPhone, 'code_sent', (int)$code['id']);
    return ['ok' => true, 'id' => $verificationId];
}

function verification_send_allowed(PDO $pdo, int $codeId): bool
{
    $stmt = $pdo->prepare('SELECT created_at FROM email_verifications WHERE redeem_code_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$codeId]);
    $last = $stmt->fetchColumn();
    if ($last && strtotime((string)$last) > time() - 60) {
        return false;
    }

    $since = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_verifications WHERE redeem_code_id = ? AND created_at >= ?');
    $stmt->execute([$codeId, $since]);
    return (int)$stmt->fetchColumn() < 5;
}

function record_change_log(string $code, string $phone, string $reason, ?int $codeId = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO query_logs (code_text, phone, ip, user_agent, success, reason, redeem_code_id, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)');
    $stmt->execute([$code, $phone, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $reason, $codeId, now()]);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>修改绑定手机号</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="public-page">
<div class="shell narrow">
    <header class="hero compact">
        <h1>修改绑定手机号</h1>
        <p>系统将向兑换码绑定邮箱发送 6 位验证码。</p>
    </header>
    <main class="card">
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <label>
                <span>兑换码</span>
                <input type="text" name="code" value="<?= e($input['code']) ?>" required autocomplete="one-time-code">
            </label>
            <label>
                <span>原手机号</span>
                <input type="tel" name="current_phone" value="<?= e($input['current_phone']) ?>" maxlength="11" required autocomplete="tel">
            </label>
            <label>
                <span>新手机号</span>
                <input type="tel" name="new_phone" value="<?= e($input['new_phone']) ?>" maxlength="11" required autocomplete="tel">
            </label>
            <button class="btn primary" type="submit">发送邮箱验证码</button>
        </form>
        <p class="hint"><a href="/">返回查询页</a></p>
    </main>
</div>
</body>
</html>
