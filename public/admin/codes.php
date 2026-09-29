<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/views.php';

Auth::requireLogin();
$pdo  = db();
$cfg  = $GLOBALS['CFG'];
$adminId = Auth::id();

/* ---------------- POST 动作 ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'create') {
        $package    = trim((string)($_POST['package'] ?? ''));
        $code       = normalize_code((string)($_POST['code'] ?? ''));
        $totalTimes = (int)($_POST['total_times'] ?? 1);
        $expireRaw  = trim((string)($_POST['expires_at'] ?? ''));
        $autoGen    = !empty($_POST['auto_gen']);

        $errors = [];
        if ($package === '') {
            $errors[] = '请填写课程包名称';
        }
        if (!$autoGen) {
            if (strlen($code) < $cfg['code_min_len'] || strlen($code) > $cfg['code_max_len'] || !ctype_alnum($code)) {
                $errors[] = '兑换码需为 6-32 位字母或数字';
            }
        }
        if ($totalTimes < 1 || $totalTimes > 9999) {
            $errors[] = '总次数需在 1-9999 之间';
        }

        if ($autoGen) {
            $code = gen_code(10);
            // 极小概率撞码时重试
            for ($i = 0; $i < 5; $i++) {
                $q = $pdo->prepare('SELECT 1 FROM codes WHERE code = ?');
                $q->execute([$code]);
                if (!$q->fetchColumn()) {
                    break;
                }
                $code = gen_code(10);
            }
        } else {
            $q = $pdo->prepare('SELECT 1 FROM codes WHERE code = ?');
            $q->execute([$code]);
            if ($q->fetchColumn()) {
                $errors[] = '兑换码已存在';
            }
        }

        $expiresAt = null;
        if ($expireRaw !== '') {
            $ts = strtotime($expireRaw);
            if ($ts === false) {
                $errors[] = '过期时间格式不正确';
            } else {
                $expiresAt = $ts;
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                flash('error', $e);
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO codes (code, package, total_times, used_times, status, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, 0, "active", ?, ?, ?)'
            );
            $stmt->execute([$code, $package, $totalTimes, $expiresAt, now(), now()]);
            add_log($code, "新增兑换码 / 课程包：{$package} / 总次数：{$totalTimes}", 'admin_create', $adminId);
            flash('success', "兑换码 {$code} 创建成功");
        }
        redirect('codes.php');
    }

    if ($do === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, ['active', 'disabled'], true)) {
            flash('error', '非法状态');
            redirect('codes.php');
        }
        $q = $pdo->prepare('SELECT * FROM codes WHERE id = ?');
        $q->execute([$id]);
        $code = $q->fetch();
        if (!$code) {
            flash('error', '兑换码不存在');
            redirect('codes.php');
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        $u = $pdo->prepare('UPDATE codes SET status = ?, updated_at = ? WHERE id = ?');
        $u->execute([$status, now(), $id]);
        if ($status === 'disabled') {
            $msg = '停用兑换码' . ($reason !== '' ? "，原因：{$reason}" : '');
            add_log($code['code'], $msg, 'admin_disable', $adminId, (string)($code['bound_phone'] ?? ''));
            flash('success', "兑换码 {$code['code']} 已停用");
        } else {
            add_log($code['code'], '重新启用兑换码', 'admin_enable', $adminId, (string)($code['bound_phone'] ?? ''));
            flash('success', "兑换码 {$code['code']} 已重新启用");
        }
        $back = http_build_query(array_filter([
            'page'   => max(1, (int)($_POST['page'] ?? 1)),
            'q'      => trim((string)($_POST['q'] ?? '')),
            'status' => (string)($_POST['status_filter'] ?? ''),
        ]));
        redirect('codes.php?' . $back);
    }
}

/* ---------------- 列表查询 ---------------- */
$q       = trim((string)($_GET['q'] ?? ''));
$filter  = (string)($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(code LIKE ? OR package LIKE ? OR bound_phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($filter, ['active', 'disabled'], true)) {
    $where[] = 'status = ?';
    $params[] = $filter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM codes {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$listStmt = $pdo->prepare(
    "SELECT * FROM codes {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$codes = $listStmt->fetchAll();

$queryString = function (int $p) use ($q, $filter) {
    return 'codes.php?' . http_build_query(array_filter(['q' => $q, 'status' => $filter, 'page' => $p]));
};

admin_header('兑换码管理', 'codes.php');
?>
<div class="panel">
  <h2>新增兑换码</h2>
  <form method="post" action="codes.php">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-grid">
      <label class="field">
        <span>课程包名称 *</span>
        <input type="text" name="package" required maxlength="100" placeholder="如：Python 入门 30 讲">
      </label>
      <label class="field">
        <span>兑换码（留空自动生成）</span>
        <input type="text" name="code" maxlength="32" placeholder="自动生成 10 位码">
      </label>
      <label class="field">
        <span>总可用次数 *</span>
        <input type="number" name="total_times" value="1" min="1" max="9999" required>
      </label>
      <label class="field">
        <span>过期时间（留空 = 永久）</span>
        <input type="datetime-local" name="expires_at">
      </label>
    </div>
    <div style="margin-top:14px">
      <button class="btn btn-primary" type="submit">创建兑换码</button>
      <label class="muted" style="margin-left:8px">
        <input type="checkbox" name="auto_gen" value="1" checked> 自动生成兑换码
      </label>
    </div>
  </form>
</div>

<div class="panel">
  <h2>兑换码列表（共 <?= $total ?> 条）</h2>
  <form class="toolbar" method="get">
    <label class="field">
      <span class="muted">关键词</span>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="兑换码 / 课程包 / 手机号">
    </label>
    <label class="field">
      <span class="muted">状态</span>
      <select name="status">
        <option value="">全部</option>
        <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>正常</option>
        <option value="disabled" <?= $filter === 'disabled' ? 'selected' : '' ?>>已停用</option>
      </select>
    </label>
    <button class="btn btn-primary" type="submit">筛选</button>
    <a class="btn" href="codes.php">重置</a>
  </form>

  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>兑换码</th><th>课程包</th><th>状态</th><th>绑定手机</th>
        <th>次数</th><th>过期时间</th><th style="min-width:230px">操作</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$codes): ?>
      <tr><td colspan="7" class="muted" style="text-align:center;padding:28px">没有符合条件的兑换码</td></tr>
    <?php endif; ?>
    <?php foreach ($codes as $c):
      $state = code_state($c);
      $available = (int)$c['total_times'] - (int)$c['used_times'];
    ?>
      <tr>
        <td><code><?= h($c['code']) ?></code></td>
        <td><?= h($c['package']) ?></td>
        <td><span class="<?= state_badge_class($state) ?>"><?= h(state_label($state)) ?></span></td>
        <td><?= $c['bound_phone'] ? h(mask_phone($c['bound_phone'])) : '<span class="muted">未绑定</span>' ?></td>
        <td>
          <?= (int)$c['used_times'] ?> / <?= (int)$c['total_times'] ?>
          <span class="muted">（余 <?= max(0, $available) ?>）</span>
        </td>
        <td class="muted"><?= h(fmt_time($c['expires_at'] !== null ? (int)$c['expires_at'] : null)) ?></td>
        <td>
          <div class="actions">
            <?php if ($c['bound_phone']): ?>
              <a class="btn btn-sm" href="rebind.php?id=<?= (int)$c['id'] ?>">改绑手机</a>
            <?php else: ?>
              <span class="badge badge-muted" title="用户首次查询时自动绑定">未激活</span>
            <?php endif; ?>

            <?php if ($c['status'] === 'active'): ?>
              <form class="inline-form" method="post" action="codes.php"
                    onsubmit="return confirm('确认停用该兑换码？停用后用户将无法查询使用。');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="set_status">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="status" value="disabled">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="status_filter" value="<?= h($filter) ?>">
                <button class="btn btn-sm btn-danger" type="submit">停用</button>
              </form>
            <?php else: ?>
              <form class="inline-form" method="post" action="codes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="set_status">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="status" value="active">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="status_filter" value="<?= h($filter) ?>">
                <button class="btn btn-sm btn-ok" type="submit">启用</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <span class="muted">第 <?= $page ?> / <?= $pages ?> 页</span>
    <span>
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h($queryString($page - 1)) ?>">上一页</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h($queryString($page + 1)) ?>">下一页</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
