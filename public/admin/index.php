<?php
declare(strict_types=1);
define('ADMIN_PAGE', true);
$pageTitle = '概览';
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));
require_admin();

$pdo = db();
$stats = [
    'total' => (int)$pdo->query('SELECT COUNT(*) FROM redeem_codes')->fetchColumn(),
    'active' => (int)$pdo->query("SELECT COUNT(*) FROM redeem_codes WHERE status = 'active'")->fetchColumn(),
    'disabled' => (int)$pdo->query("SELECT COUNT(*) FROM redeem_codes WHERE status = 'disabled'")->fetchColumn(),
    'logs_today' => (int)$pdo->query("SELECT COUNT(*) FROM query_logs WHERE date(created_at) = date('now', 'localtime')")->fetchColumn(),
];

$recentLogs = $pdo->query(<<<SQL
SELECT ql.*, rc.package_name
FROM query_logs ql
LEFT JOIN redeem_codes rc ON rc.id = ql.redeem_code_id
ORDER BY ql.id DESC
LIMIT 8
SQL)->fetchAll();

$flash = pull_flash();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>数据概览</h1>
        <p class="text-muted">监控兑换码状态与今日查询情况。</p>
    </div>
    <a class="btn primary" href="/admin/codes.php?action=new">新增兑换码</a>
</div>

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php if ((int)(current_admin()['must_change_password'] ?? 0) === 1): ?>
    <div class="alert error">当前仍使用默认密码，请立即 <a href="/admin/account.php">修改密码</a>。</div>
<?php endif; ?>

<div class="stats">
    <div class="stat"><strong><?= $stats['total'] ?></strong><span>兑换码总数</span></div>
    <div class="stat"><strong class="text-success"><?= $stats['active'] ?></strong><span>启用中</span></div>
    <div class="stat"><strong class="text-danger"><?= $stats['disabled'] ?></strong><span>已停用</span></div>
    <div class="stat"><strong><?= $stats['logs_today'] ?></strong><span>今日查询</span></div>
</div>

<div class="card table-card">
    <div class="page-head" style="padding:18px 18px 0; margin-bottom:0">
        <h2 style="font-size:20px; margin:0">最近查询</h2>
        <a href="/admin/logs.php">查看全部</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>兑换码</th><th>手机号</th><th>课程包</th><th>结果</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$recentLogs): ?>
                <tr><td colspan="6" class="text-muted">暂无查询记录。</td></tr>
            <?php endif; ?>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?= e($log['created_at']) ?></td>
                    <td class="mono"><?= e(mask_code_text($log['code_text'])) ?></td>
                    <td><?= e(mask_phone($log['phone'])) ?></td>
                    <td><?= e($log['package_name'] ?: '-') ?></td>
                    <td>
                        <?php if ((int)$log['success']): ?>
                            <span class="badge ok">成功</span>
                        <?php else: ?>
                            <span class="badge danger"><?= e(log_reason_label($log['reason'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($log['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
