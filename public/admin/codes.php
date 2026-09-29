<?php
declare(strict_types=1);
define('ADMIN_PAGE', true);
$pageTitle = '兑换码管理';
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));
$admin = require_admin();
$pdo = db();
require_once __DIR__ . '/../../lib/admin-codes.php';

$action = get('action', 'list');
$flash = null;

if (is_post()) {
    if (!verify_csrf()) {
        flash('error', '表单已过期，请重试。');
        redirect('/admin/codes.php');
    }

    $formAction = post('form_action');
    if ($formAction === 'create') {
        create_code($pdo);
    } elseif ($formAction === 'toggle') {
        toggle_code($pdo);
    } elseif ($formAction === 'update') {
        update_code($pdo);
    }
    redirect('/admin/codes.php');
}

if ($action === 'new') {
    $pageTitle = '新增兑换码';
    $form = default_code_form();
    require __DIR__ . '/partials/header.php';
    render_code_form('create', '新增兑换码', $form);
    require __DIR__ . '/partials/footer.php';
    exit;
}

if ($action === 'edit') {
    $code = find_code((int)get('id'));
    if (!$code) {
        flash('error', '兑换码不存在。');
        redirect('/admin/codes.php');
    }
    $pageTitle = '编辑兑换码';
    $form = code_to_form($code);
    require __DIR__ . '/partials/header.php';
    render_code_form('update', '编辑兑换码：' . $code['code'], $form, (int)$code['id']);
    require __DIR__ . '/partials/footer.php';
    exit;
}

$page = pagination_page();
$perPage = 20;
$where = [];
$params = [];
$q = get('q');
$status = get('status');

if ($q !== '') {
    $where[] = '(code LIKE ? OR package_name LIKE ? OR bound_phone LIKE ? OR bound_email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['active', 'disabled'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM redeem_codes $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM redeem_codes $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$codes = $stmt->fetchAll();
$flash = pull_flash();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div><h1>兑换码管理</h1><p class="text-muted">新增兑换码、停用异常码，并维护课程包与绑定信息。</p></div>
    <a class="btn primary" href="/admin/codes.php?action=new">+ 新增兑换码</a>
</div>

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="card toolbar">
    <form method="get">
        <input name="q" value="<?= e($q) ?>" placeholder="搜索兑换码 / 课程包 / 手机 / 邮箱">
        <select name="status">
            <option value="">全部状态</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>启用中</option>
            <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>
        </select>
        <button class="btn" type="submit">筛选</button>
        <a class="btn ghost" href="/admin/codes.php">重置</a>
    </form>
    <span class="hint">共 <?= $total ?> 条</span>
</div>

<div class="card table-card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>兑换码 / 课程包</th>
                <th>绑定信息</th>
                <th>状态</th>
                <th>有效期</th>
                <th>次数</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$codes): ?>
                <tr><td colspan="7" class="text-muted">暂无数据。</td></tr>
            <?php endif; ?>
            <?php foreach ($codes as $code): ?>
                <?php
                $remaining = max(0, (int)$code['total_quota'] - (int)$code['used_count']);
                $runtime = status_label($code['status'], $code['expires_at'], $remaining);
                ?>
                <tr>
                    <td>
                        <strong class="mono"><?= e($code['code']) ?></strong><br>
                        <span class="text-muted"><?= e($code['package_name']) ?></span>
                    </td>
                    <td>
                        <?= e($code['bound_phone'] ?: '未绑定手机') ?><br>
                        <span class="text-muted"><?= e($code['bound_email'] ?: '未绑定邮箱') ?></span>
                    </td>
                    <td><span class="badge <?= e(status_badge_class($runtime)) ?>"><?= e($runtime) ?></span></td>
                    <td>
                        至 <?= e($code['expires_at']) ?><br>
                        <span class="text-muted"><?= $code['activated_at'] ? '激活于 ' . e($code['activated_at']) : '待激活' ?></span>
                    </td>
                    <td><strong><?= $remaining ?></strong>/<?= (int)$code['total_quota'] ?><br><span class="text-muted">已用 <?= (int)$code['used_count'] ?></span></td>
                    <td><?= e($code['created_at']) ?></td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap">
                            <a class="btn small ghost" href="/admin/codes.php?action=edit&id=<?= (int)$code['id'] ?>">编辑</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('确定<?= $code['status'] === 'active' ? '停用' : '启用' ?>该兑换码？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$code['id'] ?>">
                                <button class="btn small <?= $code['status'] === 'active' ? 'danger' : '' ?>" type="submit">
                                    <?= $code['status'] === 'active' ? '停用' : '启用' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $total, $perPage) ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
