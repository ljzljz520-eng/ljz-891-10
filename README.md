# 在线课程兑换码查询（轻量 PHP）

一个无框架、无 Composer 依赖的课程兑换码查询与后台管理示例。数据库使用 SQLite，首次访问自动建表。

## 功能

### 用户端

- 输入兑换码与手机号查询：
  - 课程包名称
  - 激活状态（待激活 / 正常 / 已过期 / 已停用 / 次数已用尽）
  - 激活时间、过期时间
  - 总次数、已用次数、可用次数
- 默认仅允许已绑定手机号的兑换码查询；如业务需要，可设置 `CODE_AUTO_BIND=1` 允许首次查询自动领取未分配码。
- 已设置绑定邮箱的兑换码，可通过邮箱验证码自助修改绑定手机号：
  - 原手机号校验
  - 6 位数字验证码
  - 10 分钟有效、错误 5 次失效
  - 发送频率限制（60 秒 1 次，每小时 5 次）

### 管理后台

- 数据概览与最近查询
- 新增、编辑、搜索兑换码
- 一键停用异常兑换码，也可重新启用
- 查询日志：成功/失败、原因、手机号脱敏、IP、User-Agent、时间筛选
- 邮件发送记录：默认演示模式把验证码写入 SQLite，方便本地查看
- 管理员登录失败限流、CSRF 防护、首次默认密码修改提醒

## 目录结构

```text
├── data/               # SQLite 与保护文件（运行时生成 app.sqlite）
├── lib/                # 数据库、认证、邮件和业务辅助函数
├── public/             # Web 文档根目录
│   ├── admin/          # 管理后台页面
│   ├── assets/style.css
│   ├── index.php       # 兑换码查询页
│   ├── change-phone.php
│   ├── verify-phone.php
│   └── router.php       # PHP 内置服务器开发入口
└── config.php
```

## 环境要求

- PHP 8.1+
- PDO SQLite 扩展
- 生产发送邮件需配置服务器 MTA（或自行替换 `lib/mailer.php` 为 SMTP 服务）

## 本地运行

```bash
cd /path/to/project
php -S 127.0.0.1:8000 -t public public/router.php
```

访问：

- 用户端：<http://127.0.0.1:8000/>
- 后台：<http://127.0.0.1:8000/admin/login.php>

默认后台账号：

```text
用户名：admin
密码：ChangeMe!2026
```

首次登录后请立即在“账号设置”修改密码。

## 生产部署

1. 将 Web Server 的文档根目录指向 `public/`，不要指向项目根目录。
2. 确保 `data/` 不可通过公网访问，并赋予 Web 用户写入权限：

   ```bash
   chmod -R 750 data
   chown -R www-data:www-data data
   ```

3. 复制配置并按需修改：

   ```bash
   cp config.php config.local.php
   ```

4. 如需要真实发信，设置：

   ```bash
   export MAIL_TRANSPORT=mail
   export MAIL_FROM_EMAIL=no-reply@your-domain.com
   ```

   未配置 MTA 时保持默认 `sqlite`，验证码会出现在后台“发送邮件”页，适合演示和测试。

## 安全说明

- 全站表单使用 CSRF token。
- SQL 全部使用 PDO 预处理。
- 页面输出统一 HTML 转义。
- 查询和后台登录均有 IP 维度失败频率限制。
- 日志中的手机号、兑换码做脱敏展示。
- 默认密码仅用于首次运行，生产环境必须修改。
- 默认不会让用户仅凭兑换码领取未分配码；保持 `CODE_AUTO_BIND=0` 即可。

## 常用配置

| 配置 | 环境变量 | 默认值 | 说明 |
|---|---|---:|---|
| 自动绑定首次查询手机号 | `CODE_AUTO_BIND` | `0` | 设为 `1` 后首次查询可领取未分配码 |
| 邮件模式 | `MAIL_TRANSPORT` | `sqlite` | `sqlite` 记录到数据库；`mail` 调用 PHP mail() |
| 发件邮箱 | `MAIL_FROM_EMAIL` | `no-reply@example.test` | 真实发信时修改 |
| 初始管理员用户名 | `ADMIN_USER` | `admin` | 首次建库时使用；默认密码见登录说明，登录后请修改 |
