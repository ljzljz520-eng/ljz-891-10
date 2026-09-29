#!/usr/bin/env bash
# 开发服务器一键启动
set -e
cd "$(dirname "$0")"
PORT="${1:-8080}"

if ! command -v php >/dev/null 2>&1; then
  echo "未找到 php，请先安装 PHP 8.1+（需 pdo_sqlite 扩展）" >&2
  exit 1
fi

# 首次运行自动初始化
if [ ! -f data/app.sqlite ]; then
  php install.php --demo
fi

echo "查询首页: http://127.0.0.1:${PORT}/"
echo "后台地址: http://127.0.0.1:${PORT}/admin/login.php  (admin / admin123)"
exec php -S 127.0.0.1:"${PORT}" -t public
