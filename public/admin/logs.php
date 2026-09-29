<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/views.php';

Auth::requireLogin();
$pdo = db();

$action = (string)($_GET['action'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];
$validActions = ['query', 'admin_create', 'admin_disable', 'admin_enable', 'admin_rebind'];
if (in_array($action, $validActions, true)) {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where[] = '(l.code LIKE ? OR l.phone LIKE ? OR l.ip LIKE ? OR l.result LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int) (function () use ($pdo, $whereSql, $params) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM query_logs l {$whereSql}");
    $st->execute($params);
    return $st->fetchColumn();
})();
$pages = max(1, (int) ceil($total / $perPage));

$st = $pdo->prepare(
    "SELECT l.*, a.username FROM query_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     {$whereSql}
     ORDER BY l.id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$st->execute($params);
$logs = $st->fetchAll();

$qs = function (int $p) use ($q, $action) {
    return 'logs.php?' . http_build_query(array_filter(['q' => $q, 'action' => $action, 'page' => $p]));
};

admin_header('查询日志', 'logs.php');
?>
<div class="panel">
  <h2>日志筛选（共 <?= $total ?> 条）</h2>
  <form class="toolbar" method="get">
    <label class="field">
      <span class="muted">关键词</span>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="兑换码 / 手机号 / IP / 结果">
    </label>
    <label class="field">
      <span class="muted">类型</span>
      <select name="action">
        <option value="">全部</option>
        <?php foreach (['query' => '用户查询', 'admin_create' => '后台新增', 'admin_disable' => '后台停用', 'admin_enable' => '后台启用', 'admin_rebind' => '改绑手机'] as $v => $l): ?>
          <option value="<?= h($v) ?>" <?= $action === $v ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn btn-primary" type="submit">查询</button>
    <a class="btn" href="logs.php">重置</a>
  </form>

  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>时间</th><th>类型</th><th>兑换码</th><th>手机号</th><th>结果说明</th><th>IP</th><th>UA</th><th>操作人</th></tr>
    </thead>
    <tbody>
    <?php if (!$logs): ?>
      <tr><td colspan="8" class="muted" style="text-align:center;padding:28px">暂无日志</td></tr>
    <?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap"><?= h(date('Y-m-d H:i:s', (int)$l['created_at'])) ?></td>
        <td><span class="<?= log_action_badge($l['action']) ?>"><?= h(log_action_label($l['action'])) ?></span></td>
        <td><code><?= h($l['code']) ?></code></td>
        <td><?= $l['phone'] ? h(mask_phone($l['phone'])) : '<span class="muted">-</span>' ?></td>
        <td><?= h($l['result']) ?></td>
        <td class="muted"><?= h($l['ip']) ?></td>
        <td class="muted" title="<?= h($l['user_agent']) ?>"><?= h(mb_substr($l['user_agent'], 0, 40)) ?></td>
        <td><?= $l['username'] ? h($l['username']) : '<span class="muted">用户</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <span class="muted">第 <?= $page ?> / <?= $pages ?> 页</span>
    <span>
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h($qs($page - 1)) ?>">上一页</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h($qs($page + 1)) ?>">下一页</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
