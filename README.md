# 在线课程兑换码查询系统（轻量 PHP）

原生 PHP + SQLite 实现，**无框架、无 Composer 依赖**，一个目录即可部署。
包含用户兑换码查询页与管理后台。

## 功能

### 用户端（`public/index.php`）
- 输入兑换码 + 手机号查询
- 展示：**课程包、激活状态、过期时间、总/已用/可用次数、绑定手机（脱敏）**
- 有效码首次查询时自动绑定手机号并激活；已绑定的码必须用绑定手机号查询
- 异常码（停用 / 过期 / 次数用完）明确提示，防止冒领
- 查询接口按 IP 限流（默认 20 次/分钟）

### 管理后台（`public/admin/`，账号密码登录）
- **概览**：兑换码总数、正常/停用/已绑定/过期统计、近 24 小时查询量、最近动态
- **兑换码管理**：新增（自定义或自动生成 10 位码）、设置课程包/总次数/过期时间；
  对异常码**一键停用 / 重新启用**；关键词与状态筛选、分页
- **查询日志**：所有用户查询与后台敏感操作（新增/停用/启用/改绑）全量留痕，
  含时间、类型、兑换码、手机号（脱敏）、结果、IP、UA、操作人，可筛选分页
- **邮箱验证码改绑手机号**：
  1. 填写接收邮箱与新手机号 → 发送 6 位验证码（10 分钟有效，60 秒发送冷却）
  2. 输入验证码确认后才更新绑定号码；全程记入日志
- **账号设置**：修改密码（登录失败 5 次锁定 15 分钟）、保存默认验证邮箱

### 邮件
- 默认 `file` 驱动：验证码邮件写入 `data/mail/`，**无 SMTP 也能开发联调**
- 配置 `MAIL_DRIVER=smtp` 后走内置轻量 SMTP 客户端（SSL 465 / STARTTLS 587），无需第三方库

## 目录结构

```
├── app/
│   ├── bootstrap.php     # 启动：配置、session、安全头、异常处理、自动建表
│   ├── config.php        # 全部配置（支持环境变量覆盖）
│   ├── functions.php     # DB、CSRF、限流、日志、校验等
│   ├── auth.php          # 管理员会话/登录限流
│   ├── mailer.php        # FileMailer + SmtpMailer
│   ├── schema.php        # SQLite 建表语句
│   └── views.php         # 后台布局/状态展示
├── public/               # Web 根目录
│   ├── index.php         # 用户查询页
│   ├── api/query.php     # 查询 JSON 接口
│   ├── assets/           # CSS / JS
│   └── admin/            # 后台页面
├── data/                 # SQLite 与邮件落盘（在 web 外，禁止访问）
├── install.php           # CLI 初始化（php install.php [--demo]）
├── start.sh              # 启动 PHP 内置服务器
└── .htaccess             # Apache：根目录部署时转发到 public/ 并保护 data/app
```

## 快速开始

要求 PHP **8.1+**（需 `pdo_sqlite`、`mbstring` 扩展）。

```bash
# 初始化数据库 + 演示数据（默认管理员 admin / admin123）
php install.php --demo

# 启动开发服务器（或 ./start.sh）
php -S 127.0.0.1:8080 -t public
```

- 用户页：<http://127.0.0.1:8080/>
- 后台：<http://127.0.0.1:8080/admin/login.php>

演示兑换码：

| 码 | 说明 |
| --- | --- |
| `DEMO2026A` | 3 次、永久有效（首次查询绑定） |
| `DEMO2026B` | 1 次、90 天有效 |
| `DEMO2026C` | 已过期（用于测试异常态） |
| `DEMO2026D` | 已绑定 `13800138000`（用于测试改绑） |

改绑手机的验证码：使用默认 file 驱动时，查看 `data/mail/outbox-*.txt`。

## 生产部署

1. Web 根目录指向 `public/`（Nginx/Apache 均可）；`app/`、`data/` 不应被直接访问。
   若只能把项目根目录作为 DocumentRoot（如虚拟主机），已附带根目录 `.htaccess` 做转发与保护。
2. 用环境变量覆盖默认配置：

   ```bash
   export APP_DEBUG=false
   export ADMIN_USER=yourname
   export ADMIN_PASS='强密码'
   export MAIL_DRIVER=smtp
   export SMTP_HOST=smtp.qq.com
   export SMTP_PORT=465            # 465 用 ssl；587 用 tls
   export SMTP_SECURITY=ssl
   export SMTP_USER=you@example.com
   export SMTP_PASS='授权码'
   export MAIL_FROM=you@example.com
   ```

   或直接修改 `app/config.php`。
3. **上线后立即在后台「账号设置」修改默认密码。**
4. 确保 `data/` 对 PHP 运行用户可写（`chmod -R 775 data`），数据库文件已被 .gitignore 排除。

## 安全说明

- 全站输出转义、POST 全部 CSRF token、密码 `password_hash`、Session HttpOnly + SameSite
- 安全响应头 `X-Content-Type-Options` / `X-Frame-Options` / `Referrer-Policy`
- 查询/发送验证码均有频率限制；登录爆破锁定
- 邮箱验证码以哈希形式入库，一次性消费；改绑在事务中完成
- 日志与页面中的手机号、邮箱均脱敏展示
