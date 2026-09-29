<?php
declare(strict_types=1);
define('ADMIN_PAGE', true);
$pageTitle = '查询日志';
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));
require_admin();
$pdo = db();

$page = pagination_page();
$perPage = 30;
$where = [];
$params = [];
$q = get('q');
$result = get('result');
$reason = get('reason');
$date = get('date');

if ($q !== '') {
    $where[] = '(ql.code_text LIKE ? OR ql.phone LIKE ? OR ql.ip LIKE ? OR rc.package_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($result === '1' || $result === '0') {
    $where[] = 'ql.success = ?';
    $params[] = (int)$result;
}
if ($reason !== '') {
    $where[] = 'ql.reason = ?';
    $params[] = $reason;
}
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'date(ql.created_at) = ?';
    $params[] = $date;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM query_logs ql LEFT JOIN redeem_codes rc ON rc.id = ql.redeem_code_id $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(<<<SQL
SELECT ql.*, rc.package_name
FROM query_logs ql
LEFT JOIN redeem_codes rc ON rc.id = ql.redeem_code_id
$whereSql
ORDER BY ql.id DESC
LIMIT $perPage OFFSET $offset
SQL);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$reasonStmt = $pdo->query('SELECT DISTINCT reason FROM query_logs ORDER BY reason');
$reasons = $reasonStmt->fetchAll(PDO::FETCH_COLUMN);
$flash = pull_flash();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div><h1>查询日志</h1><p class="text-muted">记录每次查询、失败原因、IP 与 UA，便于排查异常码和滥用。</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="card toolbar">
    <form method="get">
        <input name="q" value="<?= e($q) ?>" placeholder="兑换码 / 手机号 / IP / 课程包">
        <select name="result">
            <option value="">全部结果</option>
            <option value="1" <?= $result === '1' ? 'selected' : '' ?>>成功</option>
            <option value="0" <?= $result === '0' ? 'selected' : '' ?>>失败</option>
        </select>
        <select name="reason">
            <option value="">全部原因</option>
            <?php foreach ($reasons as $item): ?>
                <option value="<?= e($item) ?>" <?= $reason === $item ? 'selected' : '' ?>><?= e(log_reason_label($item)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= e($date) ?>">
        <button class="btn" type="submit">筛选</button>
        <a class="btn ghost" href="/admin/logs.php">重置</a>
    </form>
    <span class="hint">共 <?= $total ?> 条</span>
</div>

<div class="card table-card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr><th>时间</th><th>兑换码 / 课程包</th><th>手机号</th><th>结果</th><th>IP</th><th>User-Agent</th></tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="6" class="text-muted">暂无日志。</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space:nowrap"><?= e($log['created_at']) ?></td>
                    <td>
                        <strong class="mono"><?= e(mask_code_text($log['code_text'])) ?></strong><br>
                        <span class="text-muted"><?= e($log['package_name'] ?: '未匹配') ?></span>
                    </td>
                    <td><?= e(mask_phone($log['phone'])) ?></td>
                    <td>
                        <?php if ((int)$log['success']): ?>
                            <span class="badge ok">成功</span>
                        <?php else: ?>
                            <span class="badge danger"><?= e(log_reason_label($log['reason'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($log['ip']) ?></td>
                    <td><span class="text-muted" title="<?= e($log['user_agent']) ?>"><?= e(truncate_text($log['user_agent'], 58)) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $total, $perPage) ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
