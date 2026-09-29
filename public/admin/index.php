<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/views.php';

Auth::requireLogin();
$pdo = db();

$total   = (int) $pdo->query('SELECT COUNT(*) FROM codes')->fetchColumn();
$active  = (int) $pdo->query("SELECT COUNT(*) FROM codes WHERE status = 'active'")->fetchColumn();
$disabled= (int) $pdo->query("SELECT COUNT(*) FROM codes WHERE status = 'disabled'")->fetchColumn();
$bound   = (int) $pdo->query('SELECT COUNT(*) FROM codes WHERE bound_phone IS NOT NULL')->fetchColumn();

// 过期数（基于当前时间，直接在 PHP 侧粗算：所有有 expires_at 且小于当前）
$now = now();
$st = $pdo->prepare('SELECT COUNT(*) FROM codes WHERE expires_at IS NOT NULL AND expires_at < ? AND status = "active"');
$st->execute([$now]);
$expired = (int) $st->fetchColumn();

$logs24 = (int) $pdo->query('SELECT COUNT(*) FROM query_logs WHERE created_at >= ' . ($now - 86400))->fetchColumn();

$recent = $pdo->query(
    'SELECT l.*, a.username FROM query_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.id DESC LIMIT 10'
)->fetchAll();

admin_header('概览', 'index.php');
?>
<div class="stats">
  <div class="stat"><div class="num"><?= $total ?></div><div class="label">兑换码总数</div></div>
  <div class="stat"><div class="num text-ok"><?= $active ?></div><div class="label">正常码</div></div>
  <div class="stat"><div class="num text-danger"><?= $disabled ?></div><div class="label">已停用</div></div>
  <div class="stat"><div class="num"><?= $bound ?></div><div class="label">已绑定手机</div></div>
  <div class="stat"><div class="num"><?= $expired ?></div><div class="label">已过期（含未停用）</div></div>
  <div class="stat"><div class="num"><?= $logs24 ?></div><div class="label">近 24 小时查询</div></div>
</div>

<div class="panel">
  <h2>快捷操作</h2>
  <a class="btn btn-primary" href="codes.php?action=new">新增兑换码</a>
  <a class="btn" href="codes.php">管理兑换码</a>
  <a class="btn" href="logs.php">查看查询日志</a>
</div>

<div class="panel">
  <h2>最近动态</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>时间</th><th>类型</th><th>兑换码</th><th>手机号</th><th>结果</th><th>IP</th><th>操作人</th></tr></thead>
    <tbody>
    <?php if (!$recent): ?>
      <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">暂无记录</td></tr>
    <?php endif; ?>
    <?php foreach ($recent as $log): ?>
      <tr>
        <td><?= h(date('Y-m-d H:i:s', (int)$log['created_at'])) ?></td>
        <td><?= h(log_action_label($log['action'])) ?></td>
        <td><code><?= h($log['code']) ?></code></td>
        <td><?= h(mask_phone($log['phone']) ?: '<span class="muted">-</span>') ?></td>
        <td><?= h($log['result']) ?></td>
        <td class="muted"><?= h($log['ip']) ?></td>
        <td><?= h($log['username'] ?? '<span class="muted">用户</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php admin_footer(); ?>
