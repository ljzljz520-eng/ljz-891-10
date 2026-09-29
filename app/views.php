<?php
declare(strict_types=1);

/**
 * 兑换码综合状态：admin_flag(后台停用) 优先，其次过期，最后可用次数。
 */
function code_state(array $code): string
{
    if ($code['status'] === 'disabled') {
        return 'disabled';
    }
    if ($code['expires_at'] !== null && (int)$code['expires_at'] < now()) {
        return 'expired';
    }
    if ((int)$code['used_times'] >= (int)$code['total_times']) {
        return 'used_up';
    }
    return 'active';
}

function state_label(string $state): string
{
    return match ($state) {
        'active'   => '可正常使用',
        'disabled' => '已停用',
        'expired'  => '已过期',
        'used_up'  => '次数用完',
        default    => '未知',
    };
}

function state_badge_class(string $state): string
{
    return match ($state) {
        'active'   => 'badge badge-ok',
        'disabled' => 'badge badge-danger',
        'expired'  => 'badge badge-muted',
        'used_up'  => 'badge badge-warn',
        default    => 'badge',
    };
}

/** 后台页面头部 */
function admin_header(string $title, string $active = ''): void
{
    $user = Auth::user();
    $nav = [
        'index.php'    => '概览',
        'codes.php'    => '兑换码',
        'logs.php'     => '查询日志',
    ];
    ?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · 管理后台</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin-body">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php">🎫 兑换码管理后台</a>
    <nav class="nav">
      <?php foreach ($nav as $file => $label): ?>
        <a href="<?= h($file) ?>" class="<?= $active === $file ? 'active' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
      <span class="muted"><?= h($user['username'] ?? '') ?></span>
      <a class="btn btn-sm" href="profile.php">账号</a>
      <a class="btn btn-sm btn-ghost" href="logout.php">退出</a>
    </div>
  </div>
</header>
<main class="container">
  <h1 class="page-title"><?= h($title) ?></h1>
  <?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>
<?php
}

/** 后台页面底部 */
function admin_footer(): void
{
?>
</main>
<footer class="footer muted">轻量 PHP 兑换码系统 · SQLite</footer>
</body>
</html>
<?php
}

function log_action_label(string $action): string
{
    return match ($action) {
        'query'         => '用户查询',
        'admin_create'  => '后台新增',
        'admin_disable' => '后台停用',
        'admin_enable'  => '后台启用',
        'admin_rebind'  => '改绑手机',
        default         => $action,
    };
}

function log_action_badge(string $action): string
{
    return match ($action) {
        'query'         => 'badge badge-muted',
        'admin_create'  => 'badge badge-ok',
        'admin_disable' => 'badge badge-danger',
        'admin_enable'  => 'badge badge-ok',
        'admin_rebind'  => 'badge badge-warn',
        default         => 'badge',
    };
}
