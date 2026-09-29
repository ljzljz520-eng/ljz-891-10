<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

if (Auth::check()) {
    redirect('index.php');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = '请输入账号和密码';
    } else {
        $error = Auth::attempt($username, $password) ?? '';
        if ($error === '') {
            redirect('index.php');
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理后台登录</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="login-wrap">
  <form class="card login-card" method="post" autocomplete="off">
    <h1>🎫 管理后台</h1>
    <p class="sub muted">兑换码查询管理系统</p>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>
    <?= csrf_field() ?>
    <label class="field">
      <span>管理员账号</span>
      <input type="text" name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
    </label>
    <label class="field">
      <span>密码</span>
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="btn btn-primary btn-block">登 录</button>
    <p class="form-tip muted"><a href="../index.php">← 返回查询页</a></p>
  </form>
</body>
</html>
