<?php
declare(strict_types=1);

// Copy this file to config.local.php and adjust values if needed.
// Environment variables always take precedence.
return [
    'app_name' => getenv('APP_NAME') ?: '课程兑换查询',
    'base_url' => getenv('BASE_URL') ?: '',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Shanghai',

    // Default administrator username, used only when the admins table is empty.
    // The first-run password is ChangeMe!2026 and must be changed after login.
    'admin_user' => getenv('ADMIN_USER') ?: 'admin',

    // sqlite | mail
    // sqlite: store every message in mail_messages (best for local/demo)
    // mail: call PHP mail() (requires a configured local MTA)
    'mail_transport' => getenv('MAIL_TRANSPORT') ?: 'sqlite',
    'mail_from_email' => getenv('MAIL_FROM_EMAIL') ?: 'no-reply@example.test',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: '课程兑换查询',
    'code_ttl_seconds' => 600,

    // Require an administrator to pre-bind each code before public queries.
    // Set CODE_AUTO_BIND=1 only if unassigned codes may be claimed on first query.
    'auto_bind' => (getenv('CODE_AUTO_BIND') ?: '0') === '1',
];
