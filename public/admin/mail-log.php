<?php
declare(strict_types=1);
define('ADMIN_PAGE', true);
$pageTitle = '发出邮件';
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();
date_default_timezone_set((string)config('timezone'));
require_admin();
$pdo = db();

$page = pagination_page();
$perPage = 30;
$total = (int)$pdo->query('SELECT COUNT(*) FROM mail_messages')->fetchColumn();
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM mail_messages ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute();
$messages = $stmt->fetchAll();
$transport = config('mail_transport');

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>发送邮件记录</h1>
        <p class="text-muted">当前邮件模式：<strong><?= e($transport === 'mail' ? 'PHP mail()' : '本地 SQLite 记录') ?></strong></p>
    </div>
</div>
<div class="alert <?= $transport === 'mail' ? 'success' : 'error' ?>">
    <?php if ($transport !== 'mail'): ?>
        演示环境不会真实发信，验证码邮件会写入此表；生产环境请在 config.local.php 或环境变量中设置 MAIL_TRANSPORT=mail 并配置 MTA。
    <?php else: ?>
        已启用真实邮件发送；邮件由服务器 MTA 投递，不会写入本地演示邮件表。
    <?php endif; ?>
</div>
<div class="card table-card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>收件邮箱</th><th>主题</th><th>内容（含验证码）</th></tr></thead>
            <tbody>
            <?php if (!$messages): ?><tr><td colspan="4" class="text-muted">暂无邮件。</td></tr><?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <tr>
                    <td style="white-space:nowrap"><?= e($message['created_at']) ?></td>
                    <td><?= e($message['to_email']) ?></td>
                    <td><?= e($message['subject']) ?></td>
                    <td><pre class="log-body"><?= e($message['body']) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $total, $perPage) ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
