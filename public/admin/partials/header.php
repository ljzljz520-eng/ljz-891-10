<?php
if (!defined('ADMIN_PAGE')) {
    http_response_code(403);
    exit;
}
$admin = $admin ?? current_admin();
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$nav = [
    'index.php' => ['概览', '📊'],
    'codes.php' => ['兑换码', '🎟️'],
    'logs.php' => ['查询日志', '📝'],
    'mail-log.php' => ['发送邮件', '✉️'],
    'account.php' => ['账号设置', '⚙️'],
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? '管理后台') ?> · 课程兑换</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar">
        <div class="brand">课程兑换后台</div>
        <nav>
            <?php foreach ($nav as $file => [$label, $icon]): ?>
                <a class="<?= $currentPage === $file ? 'active' : '' ?>" href="/admin/<?= e($file) ?>"><?= $icon ?> <?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="spacer"></div>
        <p class="hint" style="color:#94a3b8">当前：<?= e($admin['username'] ?? '') ?></p>
        <form method="post" action="/admin/logout.php">
            <?= csrf_field() ?>
            <button class="btn ghost" style="width:100%" type="submit">退出登录</button>
        </form>
    </aside>
    <main class="content">
