<?php
declare(strict_types=1);

function default_code_form(): array
{
    return [
        'code' => generate_code(12),
        'package_name' => '',
        'bound_phone' => '',
        'bound_email' => '',
        'status' => 'active',
        'expires_at' => date('Y-m-d\TH:i', strtotime('+1 year')),
        'total_quota' => 10,
        'used_count' => 0,
        'note' => '',
    ];
}

function code_to_form(array $code): array
{
    return [
        'code' => $code['code'],
        'package_name' => $code['package_name'],
        'bound_phone' => (string)$code['bound_phone'],
        'bound_email' => (string)$code['bound_email'],
        'status' => $code['status'],
        'expires_at' => substr((string)$code['expires_at'], 0, 16),
        'total_quota' => (int)$code['total_quota'],
        'used_count' => (int)$code['used_count'],
        'note' => (string)$code['note'],
    ];
}

function read_code_form(): array
{
    return [
        'code' => normalize_code(post('code')),
        'package_name' => post('package_name'),
        'bound_phone' => post('bound_phone'),
        'bound_email' => strtolower(post('bound_email')),
        'status' => post('status') === 'disabled' ? 'disabled' : 'active',
        'expires_at' => post('expires_at'),
        'total_quota' => (int)post('total_quota', '0'),
        'used_count' => (int)post('used_count', '0'),
        'note' => post('note'),
    ];
}

function validate_code_form(array $form): array
{
    $errors = [];
    if (strlen($form['code']) < 8 || !preg_match('/^[A-Z0-9]+$/', $form['code'])) {
        $errors[] = '兑换码至少 8 位，仅支持字母和数字。';
    }
    if ($form['package_name'] === '') {
        $errors[] = '请填写课程包名称。';
    }
    if ($form['bound_phone'] !== '' && !is_valid_phone($form['bound_phone'])) {
        $errors[] = '绑定手机号格式不正确。';
    }
    if ($form['bound_email'] !== '' && !is_valid_email($form['bound_email'])) {
        $errors[] = '绑定邮箱格式不正确。';
    }
    if ($form['expires_at'] === '' || strtotime($form['expires_at']) === false) {
        $errors[] = '请选择过期时间。';
    }
    if ($form['total_quota'] < 1 || $form['total_quota'] > 99999) {
        $errors[] = '总次数需在 1 到 99999 之间。';
    }
    if ($form['used_count'] < 0 || $form['used_count'] > $form['total_quota']) {
        $errors[] = '已用次数不能小于 0，也不能大于总次数。';
    }
    return $errors;
}

function format_datetime_local(string $value): ?string
{
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function create_code(PDO $pdo): void
{
    $form = read_code_form();
    $errors = validate_code_form($form);

    $stmt = $pdo->prepare('SELECT id FROM redeem_codes WHERE code = ?');
    $stmt->execute([$form['code']]);
    if ($stmt->fetch()) {
        $errors[] = '兑换码已存在，请重新生成。';
    }

    if ($errors) {
        flash('error', implode('；', $errors));
        redirect('/admin/codes.php?action=new');
    }

    $expiresAt = format_datetime_local($form['expires_at']);
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO redeem_codes
(code, package_name, bound_phone, bound_email, status, activated_at, expires_at, total_quota, used_count, note, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
    $stmt->execute([
        $form['code'],
        $form['package_name'],
        $form['bound_phone'] ?: null,
        $form['bound_email'] ?: null,
        $form['status'],
        null,
        $expiresAt,
        $form['total_quota'],
        $form['used_count'],
        $form['note'],
        now(),
        now(),
    ]);
    log_admin_action($pdo, 'code_create', '新增兑换码 ' . $form['code']);
    flash('success', '兑换码已新增。');
}

function find_code(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM redeem_codes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function update_code(PDO $pdo): void
{
    $id = (int)post('id', '0');
    $code = find_code($id);
    if (!$code) {
        flash('error', '兑换码不存在。');
        redirect('/admin/codes.php');
    }

    $form = read_code_form();
    $errors = validate_code_form($form);
    $stmt = $pdo->prepare('SELECT id FROM redeem_codes WHERE code = ? AND id != ?');
    $stmt->execute([$form['code'], $id]);
    if ($stmt->fetch()) {
        $errors[] = '兑换码与其他记录重复。';
    }
    if ($errors) {
        flash('error', implode('；', $errors));
        redirect('/admin/codes.php?action=edit&id=' . $id);
    }

    // In strict mode, a code without a bound phone has not yet been claimed.
    $activatedAt = $code['activated_at'];
    $boundPhone = $form['bound_phone'] ?: null;
    if ($boundPhone === null && !config('auto_bind', true)) {
        $activatedAt = null;
    }

    $stmt = $pdo->prepare(<<<SQL
UPDATE redeem_codes
SET code = ?, package_name = ?, bound_phone = ?, bound_email = ?, status = ?, activated_at = ?,
    expires_at = ?, total_quota = ?, used_count = ?, note = ?, updated_at = ?
WHERE id = ?
SQL);
    $stmt->execute([
        $form['code'],
        $form['package_name'],
        $boundPhone,
        $form['bound_email'] ?: null,
        $form['status'],
        $activatedAt,
        format_datetime_local($form['expires_at']),
        $form['total_quota'],
        $form['used_count'],
        $form['note'],
        now(),
        $id,
    ]);
    log_admin_action($pdo, 'code_update', '编辑兑换码 ' . $form['code']);
    flash('success', '兑换码已更新。');
}

function toggle_code(PDO $pdo): void
{
    $id = (int)post('id', '0');
    $code = find_code($id);
    if (!$code) {
        flash('error', '兑换码不存在。');
        redirect('/admin/codes.php');
    }

    $next = $code['status'] === 'active' ? 'disabled' : 'active';
    $stmt = $pdo->prepare('UPDATE redeem_codes SET status = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$next, now(), $id]);
    log_admin_action($pdo, $next === 'disabled' ? 'code_disable' : 'code_enable', ($next === 'disabled' ? '停用' : '启用') . '兑换码 ' . $code['code']);
    flash('success', $next === 'disabled' ? '异常兑换码已停用。' : '兑换码已重新启用。');
}

function render_code_form(string $action, string $title, array $form, int $id = 0): void
{
    ?>
    <div class="page-head">
        <div><h1><?= e($title) ?></h1><p class="text-muted">绑定邮箱用于用户自助修改手机号时接收验证码。</p></div>
        <a class="btn ghost" href="/admin/codes.php">返回列表</a>
    </div>
    <div class="card">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="<?= e($action) ?>">
            <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <div class="form-grid">
                <label>
                    <span>兑换码</span>
                    <input name="code" value="<?= e($form['code']) ?>" required>
                    <small class="text-muted">字母数字，可保留自动生成值。</small>
                </label>
                <label>
                    <span>课程包名称</span>
                    <input name="package_name" value="<?= e($form['package_name']) ?>" placeholder="例如：Python 入门 30 讲" required>
                </label>
                <label>
                    <span>绑定手机号（可选）</span>
                    <input name="bound_phone" value="<?= e($form['bound_phone']) ?>" maxlength="11">
                </label>
                <label>
                    <span>绑定邮箱（可选，改手机号必填）</span>
                    <input type="email" name="bound_email" value="<?= e($form['bound_email']) ?>">
                </label>
                <label>
                    <span>过期时间</span>
                    <input type="datetime-local" name="expires_at" value="<?= e($form['expires_at']) ?>" required>
                </label>
                <label>
                    <span>状态</span>
                    <select name="status">
                        <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>启用</option>
                        <option value="disabled" <?= $form['status'] === 'disabled' ? 'selected' : '' ?>>停用（异常码）</option>
                    </select>
                </label>
                <label>
                    <span>总次数</span>
                    <input type="number" name="total_quota" min="1" max="99999" value="<?= (int)$form['total_quota'] ?>" required>
                </label>
                <label>
                    <span>已用次数</span>
                    <input type="number" name="used_count" min="0" max="99999" value="<?= (int)$form['used_count'] ?>" required>
                </label>
                <label class="wide">
                    <span>备注</span>
                    <textarea name="note" rows="3" placeholder="异常原因、采购渠道或内部备注"><?= e($form['note']) ?></textarea>
                </label>
            </div>
            <button class="btn primary" type="submit">保存</button>
            <a class="btn ghost" href="/admin/codes.php">取消</a>
        </form>
    </div>
    <?php
}
