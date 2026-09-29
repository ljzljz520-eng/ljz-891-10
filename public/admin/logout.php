<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
start_session_securely();

$admin = current_admin();
if ($admin) {
    if (!is_post() || !verify_csrf()) {
        redirect('/admin/index.php');
    }
    log_admin_action(db(), 'logout', '退出后台');
}
logout_admin();
redirect('/admin/login.php');
