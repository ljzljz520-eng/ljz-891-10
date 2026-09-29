<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function send_mail(string $toEmail, string $subject, string $body): void
{
    $transport = config('mail_transport', 'sqlite');

    if ($transport === 'mail') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: =?UTF-8?B?' . base64_encode((string)config('mail_from_name')) . '?= <' . config('mail_from_email') . '>',
        ];
        $sent = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        if (!$sent) {
            throw new RuntimeException('邮件发送失败，请检查服务器邮件服务。');
        }
        return;
    }

    // Default: persist to SQLite so local/demo deployments can inspect outbound codes.
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO mail_messages (to_email, subject, body, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$toEmail, $subject, $body, now()]);
}

function send_verification_code(array $code, string $email, string $newPhone, string $plainCode): void
{
    $appName = (string)config('app_name');
    $minutes = (int)config('code_ttl_seconds', 600) / 60;
    $subject = '修改绑定手机号验证码';
    $body = <<<TEXT
您正在为兑换码 {$code['code']} 修改绑定手机号。
新手机号：{$newPhone}
验证码：{$plainCode}
验证码 {$minutes} 分钟内有效。请勿将验证码提供给他人。
若非本人操作，请忽略本邮件并联系管理员。
—— {$appName}
TEXT;
    send_mail($email, $subject, $body);
}
