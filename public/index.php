<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$cfg = $GLOBALS['CFG'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($cfg['app_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="page">
  <div class="hero">
    <div class="logo">🎫</div>
    <h1><?= h($cfg['app_name']) ?></h1>
    <p class="subtitle">输入兑换码与绑定的手机号，查询课程包信息</p>
  </div>

  <form id="query-form" class="card query-form" autocomplete="off">
    <label class="field">
      <span>兑换码</span>
      <input type="text" name="code" id="code" maxlength="32"
             placeholder="请输入兑换码，如 K7X9P2QM4A" required
             inputmode="latin" autocapitalize="characters">
    </label>
    <label class="field">
      <span>手机号</span>
      <input type="tel" name="phone" id="phone" maxlength="11"
             placeholder="请输入兑换时绑定的手机号" required inputmode="numeric">
    </label>
    <button type="submit" class="btn btn-primary btn-block" id="submit-btn">立即查询</button>
    <p class="form-tip muted">首次用有效兑换码查询时将自动绑定该手机号；兑换码不区分大小写。</p>
  </form>

  <div id="result" class="result" hidden></div>

  <p class="bottom-link muted">管理员请走 <a href="admin/login.php">后台入口</a></p>
</div>
<script src="assets/app.js"></script>
</body>
</html>
