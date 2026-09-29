<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));

$error = '';
$result = null;
$input = ['code' => '', 'phone' => ''];

if (is_post()) {
    if (!verify_csrf()) {
        flash('error', '页面已过期，请重新提交。');
        redirect('/');
    }

    $input['code'] = normalize_code(post('code'));
    $input['phone'] = post('phone');

    if ($input['code'] === '' || !is_valid_phone($input['phone'])) {
        record_public_failure($input['code'], $input['phone'], 'invalid_input');
        $error = '请输入正确的兑换码和 11 位手机号。';
    } elseif (!public_query_allowed()) {
        record_public_failure($input['code'], $input['phone'], 'rate_limited');
        $error = '尝试过于频繁，请 10 分钟后再试。';
    } else {
        $queryResult = query_redeem_code($input['code'], $input['phone']);
        if ($queryResult['ok']) {
            $_SESSION['query_result'] = $queryResult['data'];
            redirect('/?queried=1');
        }
        $error = $queryResult['message'];
    }
}

if (get('queried') === '1' && isset($_SESSION['query_result'])) {
    $result = $_SESSION['query_result'];
    unset($_SESSION['query_result']);
}

$flash = pull_flash();
if ($flash && !$error) {
    $error = $flash['type'] === 'error' ? $flash['message'] : '';
}

function query_redeem_code(string $codeText, string $phone): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM redeem_codes WHERE code = ?');
    $stmt->execute([$codeText]);
    $code = $stmt->fetch();

    $generic = '兑换码或手机号不正确。';

    if (!$code) {
        record_public_failure($codeText, $phone, 'not_found');
        return ['ok' => false, 'message' => $generic];
    }

    $boundPhone = $code['bound_phone'] ? trim($code['bound_phone']) : null;
    if ($boundPhone === null && !config('auto_bind', true)) {
        record_public_failure($codeText, $phone, 'not_bound', (int)$code['id']);
        return ['ok' => false, 'message' => $generic];
    }

    if ($boundPhone !== null && !hash_equals($boundPhone, $phone)) {
        record_public_failure($codeText, $phone, 'phone_mismatch', (int)$code['id']);
        return ['ok' => false, 'message' => $generic];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM redeem_codes WHERE id = ?');
        $stmt->execute([$code['id']]);
        $code = $stmt->fetch();

        if ($code['status'] === 'disabled') {
            insert_query_log($pdo, $code, $codeText, $phone, false, 'disabled');
            $pdo->commit();
            return ['ok' => false, 'message' => '该兑换码已被停用，请联系管理员。'];
        }

        $preliminaryRemaining = max(0, (int)$code['total_quota'] - (int)$code['used_count']);
        $eligible = $code['expires_at'] > now() && $preliminaryRemaining > 0;

        if ($eligible) {
            if ($code['bound_phone'] === null) {
                $stmt = $pdo->prepare('UPDATE redeem_codes SET bound_phone = ?, activated_at = COALESCE(activated_at, ?), updated_at = ? WHERE id = ?');
                $stmt->execute([$phone, now(), now(), $code['id']]);
            } elseif ($code['activated_at'] === null) {
                $stmt = $pdo->prepare('UPDATE redeem_codes SET activated_at = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([now(), now(), $code['id']]);
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM redeem_codes WHERE id = ?');
        $stmt->execute([$code['id']]);
        $code = $stmt->fetch();
        $activated = $code['activated_at'] !== null;
        $remaining = max(0, (int)$code['total_quota'] - (int)$code['used_count']);
        $runtime = status_label($code['status'], $code['expires_at'], $remaining);
        $logReason = 'success';
        if ($runtime === '已过期') {
            $logReason = 'expired';
        } elseif ($runtime === '次数已用尽') {
            $logReason = 'quota_exhausted';
        }

        insert_query_log($pdo, $code, $codeText, $phone, $eligible, $logReason);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => '系统繁忙，请稍后再试。'];
    }

    if ($runtime === '正常' && !$activated) {
        $runtime = '待激活';
    }

    return [
        'ok' => true,
        'data' => [
            'code' => $code['code'],
            'package_name' => $code['package_name'],
            'status' => $runtime,
            'activated_at' => $code['activated_at'],
            'expires_at' => $code['expires_at'],
            'total_quota' => (int)$code['total_quota'],
            'used_count' => (int)$code['used_count'],
            'remaining' => $remaining,
            'bound_phone' => mask_phone($code['bound_phone']),
            'can_change' => !empty($code['bound_email']),
        ],
    ];
}

function insert_query_log(PDO $pdo, array $code, string $codeText, string $phone, bool $success, string $reason): void
{
    $stmt = $pdo->prepare('INSERT INTO query_logs (code_text, phone, ip, user_agent, success, reason, redeem_code_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $codeText,
        $phone,
        client_ip(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $success ? 1 : 0,
        $reason,
        $code['id'],
        now(),
    ]);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string)config('app_name')) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="public-page">
<div class="shell narrow">
    <header class="hero">
        <p class="eyebrow">ONLINE COURSE</p>
        <h1>在线课程兑换码查询</h1>
        <p>输入兑换码与绑定手机号，查看课程包、激活状态、过期时间及可用次数。</p>
    </header>

    <main class="card">
        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <label>
                <span>兑换码</span>
                <input type="text" name="code" value="<?= e($input['code']) ?>" placeholder="例如 ABCD-EFGH-JKLM" autocomplete="one-time-code" required>
            </label>
            <label>
                <span>手机号</span>
                <input type="tel" name="phone" value="<?= e($input['phone']) ?>" placeholder="请输入 11 位手机号" autocomplete="tel" inputmode="numeric" maxlength="11" required>
            </label>
            <button class="btn primary" type="submit">立即查询</button>
        </form>

        <?php if ($result): ?>
            <section class="result" aria-live="polite">
                <div class="result-title">
                    <h2>查询结果</h2>
                    <span class="badge <?= e(status_badge_class($result['status'])) ?>"><?= e($result['status']) ?></span>
                </div>
                <dl class="details">
                    <div><dt>课程包</dt><dd><?= e($result['package_name']) ?></dd></div>
                    <div><dt>兑换码</dt><dd class="mono"><?= e($result['code']) ?></dd></div>
                    <div><dt>绑定手机</dt><dd><?= e($result['bound_phone']) ?></dd></div>
                    <div><dt>激活时间</dt><dd><?= e($result['activated_at'] ?: '首次查询后激活') ?></dd></div>
                    <div><dt>过期时间</dt><dd><?= e($result['expires_at']) ?></dd></div>
                    <div>
                        <dt>可用次数</dt>
                        <dd><strong><?= (int)$result['remaining'] ?></strong> / <?= (int)$result['total_quota'] ?> 次（已用 <?= (int)$result['used_count'] ?> 次）</dd>
                    </div>
                </dl>
                <div class="actions-inline">
                    <a class="btn ghost" href="/change-phone.php?code=<?= urlencode($result['code']) ?>">修改绑定手机号</a>
                </div>
                <p class="hint">修改手机号需向兑换码绑定邮箱发送验证码；未设置邮箱时请联系管理员。</p>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="/admin/login.php">管理后台</a>
        <span>轻量 PHP + SQLite</span>
    </footer>
</div>
</body>
</html>
