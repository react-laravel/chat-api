# DogeOW Chat API

独立的 Laravel Chat 后端，负责房间、消息、在线状态、审核、Reverb 广播和嵌入接入方校验。

## 身份与数据边界

- 仅连接独立的 `chat` PostgreSQL 数据库。
- 账号由 `next.dogeow.com` 签发的一次性 SSO 票据同步到本地 `chat_users` 快照。
- 不连接中央账号数据库，不保存密码。
- 宿主网站必须在 `chat_integrations.allowed_origins` 中按精确 Origin 注册。

## 本地运行

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve --port=8013
php artisan reverb:start --host=127.0.0.1 --port=8083
```

## 验证

```bash
vendor/bin/pint --test
php artisan test
```

生产迁移和部署脚本位于 `scripts/`，Nginx、Supervisor 与 cron 模板位于 `deploy/`。
