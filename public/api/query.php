<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'message' => '仅支持 POST 请求'], 405);
}

$cfg  = $GLOBALS['CFG'];
$data = read_request_json();
$rawCode = trim((string)($data['code'] ?? ''));
$phone   = trim((string)($data['phone'] ?? ''));
$code    = normalize_code($rawCode);

// 参数校验
if ($code === '' || $phone === '') {
    json_out(['ok' => false, 'message' => '请输入兑换码和手机号'], 422);
}
if (strlen($code) < $cfg['code_min_len'] || strlen($code) > $cfg['code_max_len'] || !ctype_alnum($code)) {
    json_out(['ok' => false, 'message' => '兑换码格式不正确（6-32 位字母/数字）'], 422);
}
if (!valid_phone($phone)) {
    json_out(['ok' => false, 'message' => '手机号格式不正确'], 422);
}

// 限流
if (!rate_check('query', $cfg['query_rate_per_minute'], 60)) {
    json_out(['ok' => false, 'message' => '查询过于频繁，请稍后再试'], 429);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM codes WHERE code = ?');
$stmt->execute([$code]);
$row = $stmt->fetch();

if (!$row) {
    add_log($code, '兑换码不存在', 'query', null, $phone);
    json_out(['ok' => false, 'message' => '兑换码不存在，请检查后重试'], 404);
}

// 已绑定则手机号必须匹配
if ($row['bound_phone'] !== null && $row['bound_phone'] !== $phone) {
    add_log($code, '手机号不匹配（输入：' . mask_phone($phone) . '）', 'query', null, $phone);
    json_out(['ok' => false, 'message' => '手机号与该兑换码绑定的号码不一致'], 403);
}

$state = code_state($row);

// 首次查询：有效码自动绑定并激活
if ($row['bound_phone'] === null) {
    if ($state === 'active') {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE codes SET bound_phone = ?, used_times = used_times + 1, updated_at = ?
                 WHERE id = ? AND bound_phone IS NULL AND used_times < total_times'
            );
            $upd->execute([$phone, now(), $row['id']]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('绑定失败');
            }
            add_log($code, '首次激活并绑定 ' . mask_phone($phone), 'query', null, $phone);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        $state = code_state($row);
    } else {
        add_log($code, '异常码首次查询，拒绝绑定：' . state_label($state), 'query', null, $phone);
        json_out([
            'ok' => false,
            'message' => '该兑换码' . state_label($state) . '，无法激活，如有疑问请联系客服',
        ], 403);
    }
} else {
    add_log($code, '查询成功：' . state_label($state), 'query', null, $phone);
}

$available = max(0, (int)$row['total_times'] - (int)$row['used_times']);

json_out([
    'ok' => true,
    'data' => [
        'package'      => $row['package'],
        'status'       => $state,
        'status_label' => state_label($state),
        'bound_phone'  => mask_phone($row['bound_phone']),
        'expires_at'   => $row['expires_at'] !== null
                            ? date('Y-m-d H:i', (int)$row['expires_at'])
                            : null,
        'expires_text' => fmt_time($row['expires_at'] !== null ? (int)$row['expires_at'] : null),
        'total_times'  => (int)$row['total_times'],
        'used_times'   => (int)$row['used_times'],
        'available'    => $available,
    ],
]);
