<?php
declare(strict_types=1);

/**
 * 命令行安装脚本：
 *   php install.php          初始化数据库 + 默认管理员
 *   php install.php --demo   额外写入演示兑换码
 *
 * Web 环境访问会被拒绝。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: 请在命令行运行 php install.php\n");
}

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$cfg = $GLOBALS['CFG'];

echo "== 数据库初始化完成 ==\n";
echo "数据库文件: {$cfg['db_path']}\n";

$st = $pdo->query('SELECT id, username, email FROM admins ORDER BY id LIMIT 1');
$admin = $st->fetch();
echo "管理员账号: {$admin['username']}（默认密码见 config.php / 环境变量 ADMIN_PASS）\n";

if (in_array('--demo', $argv ?? [], true)) {
    $now = now();
    $demos = [
        ['DEMO2026A', 'Python 入门 30 讲', 3, null],
        ['DEMO2026B', '英语口语季卡', 1, strtotime('+90 days')],
        ['DEMO2026C', '考研数学冲刺班', 5, strtotime('-1 day')], // 已过期
    ];
    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO codes (code, package, total_times, used_times, bound_phone, status, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, 0, NULL, "active", ?, ?, ?)'
    );
    foreach ($demos as [$code, $pkg, $times, $exp]) {
        $ins->execute([$code, $pkg, $times, $exp, $now, $now]);
    }

    // 一个已绑定的演示码
    $ins2 = $pdo->prepare(
        'INSERT OR IGNORE INTO codes (code, package, total_times, used_times, bound_phone, status, expires_at, created_at, updated_at)
         VALUES (?, ?, 2, 1, ?, "active", ?, ?, ?)'
    );
    $ins2->execute(['DEMO2026D', '数据分析实战营', '13800138000', strtotime('+180 days'), $now, $now]);

    echo "已写入 " . count($demos) . " 个未绑定演示码 + 1 个已绑定演示码\n";
    echo "演示码: DEMO2026A（3次/永久） DEMO2026B（1次/90天） DEMO2026C（已过期） DEMO2026D（已绑定13800138000）\n";
}

echo "\n启动开发服务器:\n";
echo "  php -S 127.0.0.1:8080 -t public\n";
echo "查询首页: http://127.0.0.1:8080/\n";
echo "后台地址: http://127.0.0.1:8080/admin/login.php\n";
