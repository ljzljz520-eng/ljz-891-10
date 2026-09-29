<?php
declare(strict_types=1);

/**
 * 全局配置。可通过环境变量覆盖（部署到生产时建议用环境变量）。
 */
return [
    'app_name'     => getenv('APP_NAME') ?: '云课堂 · 兑换码查询',
    'debug'        => (bool) (getenv('APP_DEBUG') ?: false),

    // SQLite 数据库文件放在 public 之外，避免被下载
    'db_path'      => dirname(__DIR__) . '/data/app.sqlite',

    // 管理员初始账号（仅在首次安装时写入数据库，之后请在后台修改）
    'admin' => [
        'username' => getenv('ADMIN_USER') ?: 'admin',
        'password' => getenv('ADMIN_PASS') ?: 'admin123',
    ],

    // 邮件发送：file = 写入本地文件（开发默认，无需 SMTP）；smtp = 真实发送
    'mail' => [
        'driver'   => getenv('MAIL_DRIVER') ?: 'file',
        'from'     => getenv('MAIL_FROM') ?: 'no-reply@example.com',
        'from_name'=> '云课堂兑换系统',
        'spool_dir'=> dirname(__DIR__) . '/data/mail',

        'host'        => getenv('SMTP_HOST') ?: 'smtp.example.com',
        'port'        => (int) (getenv('SMTP_PORT') ?: 465),
        'security'    => getenv('SMTP_SECURITY') ?: 'ssl', // ssl | tls | none
        'username'    => getenv('SMTP_USER') ?: '',
        'password'    => getenv('SMTP_PASS') ?: '',
        'verify_peer' => false,
    ],

    // 兑换码规则
    'code_min_len' => 6,
    'code_max_len' => 32,

    // 邮箱验证码有效期（秒）与冷却时间（秒）
    'email_code_ttl'       => 600,
    'email_code_cooldown'  => 60,

    // 查询接口限流：每 IP 每分钟最多次数
    'query_rate_per_minute' => 20,
    // 登录限流：连续失败 5 次锁定 15 分钟
    'login_max_fail' => 5,
    'login_lock_seconds' => 900,
];
