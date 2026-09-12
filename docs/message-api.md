# 社区消息 API

所有接口位于 `/api/chat`，沿用 Chat 会话或 Bearer 身份校验。以下是新增接口和契约；服务端始终检查房间成员资格及操作权限。

## 消息

- `POST /rooms/{roomId}/messages`：`message`（最多1000字符），可选 `reply_to_id`、`attachment_ids`（最多5个）。正文和附件至少有一项；用户发送类型仅允许 `text`，系统消息只由服务端创建。
- `PATCH /rooms/{roomId}/messages/{messageId}`：`message`。仅作者可编辑文本消息；禁言/封禁状态仍生效。
- `DELETE /rooms/{roomId}/messages/{messageId}`：作者、房间创建者或管理员可以删除。消息软删除，引用只返回已删除占位。
- `PUT /rooms/{roomId}/messages/{messageId}/reactions`：`emoji`。幂等添加当前用户的回应。
- `DELETE /rooms/{roomId}/messages/{messageId}/reactions`：`emoji`。只取消当前用户的回应。
- `GET /rooms/{roomId}/messages/search?q=关键词&page=1`：搜索所有历史消息、发送者名称和附件文件名，每页20条，返回 Laravel 分页结构。`q` 最多200字符。

支持的回应：👍、❤️、😂、🎉、👀、🙏。

HTTP 消息资源和 `.message.sent` / `.message.updated` 广播包含相同字段：`id`、`room_id`、`user_id`、`message`、`message_type`、`created_at`、`updated_at`、`edited_at`、`revision`、`user`、`reply_to_id`、`reply_to`、`reactions`、`attachments`。

`reply_to` 是单层引用 `{id, deleted, message, user, attachment_count}`，只允许同一房间内的消息。原消息删除后保留 ID，`deleted=true`，正文和作者为空。回应格式为 `{emoji, count, user_ids}`，前端按当前账号判断自己是否已回应。`revision` 用于避免迟到的 HTTP 结果覆盖更新的实时消息。

## 邀请

- `GET /rooms/{roomId}/invites`：管理员查看有效邀请。
- `POST /rooms/{roomId}/invites`：可选 `expires_in_hours`（1–168，默认168）和 `max_uses`（1–1000，默认不限）。响应返回邀请元数据及一次性可见的 `token`。
- `DELETE /rooms/{roomId}/invites/{inviteId}`：撤销邀请。
- `POST /invites/accept`：`token`。校验有效期、名额和封禁状态后添加成员资格；同一成员重复接受不会重复占用名额。

数据库仅保存邀请 token 的 SHA-256 摘要，列表不会返回摘要或原 token。前端入口为 `/chat?invite=...`，完成处理后从地址栏清除 token。

## 实时访问

消息频道 `chat.room.{id}`、房间状态频道 `chat-room-{id}` 和目录频道 `chat-rooms-list` 均改为私有认证订阅。前端使用 Echo `private()`。房间频道校验有效房间、成员资格及封禁状态；私有房间的成员动态不会广播到公共房间目录。

私有房间的消息、编辑和删除通过当前有访问资格成员的 `user.{id}.notifications` 私有频道投递，避免已封禁用户保留旧订阅后继续获得私有内容。在线名单使用 `chat.room.{id}.presence` presence 频道（Echo `join()`）。

## 已读与成员管理

- `PUT /rooms/{roomId}/read`：`message_id`，只能提交同房间消息，阅读游标只前进；返回 `last_read_message_id`、`unread_count`。自己的消息和系统消息不计入未读。
- 房间列表增加 `last_read_message_id` 和 `unread_count`。个人私有频道的 `.room.read` 同步其他设备已读状态；`.room.activity` 提醒刷新列表。
- `GET /rooms/{roomId}/members?q=名称&page=1`：房间创建者或管理员查看完整成员列表，包含离线、禁言和封禁状态；对应操作沿用 `/moderation/rooms/{roomId}/users/{userId}/{mute|unmute|ban|unban}`。
- 举报提交和审核沿用 `/reports` 路由；审核删除同时更新历史缓存、实时消息和附件访问权限。

## 附件

- `POST /rooms/{roomId}/attachments`：multipart `file`，可选 UUID `upload_id`。相同用户和上传 ID 的重试返回同一未发送附件。
- `GET /rooms/{roomId}/attachments/drafts`：恢复当前账号在该房间的24小时内待发送附件。
- `DELETE /rooms/{roomId}/attachments/{id}`：移除自己的未发送附件。
- `GET /rooms/{roomId}/attachments/{id}`：按房间成员身份下载。未发送附件仅上传者可读，消息删除后返回404。

每个附件最多10 MB；每条消息最多5个；每个用户最多20个待发送附件。允许 jpg/jpeg/png/webp/gif/pdf/txt/doc/docx/xls/xlsx/zip，服务端检查实际 MIME。消息附件结构为 `{id,name,mime_type,size,url}`，URL 是需要身份校验的接口，不是公开存储地址。

文件存放在私有 local disk，默认 `storage/app/private`，可用 `CHAT_PRIVATE_STORAGE_PATH` 指定持久卷。不得把这个目录公开为静态站点。已发送附件随数据库一同备份；`chat:cleanup-attachments` 每日清理超过24小时的未发送附件，不自动删除已发送记录。

## 自部署发布说明

1. 备份数据库和私有附件目录，执行本轮五个迁移；已有房间成员的历史消息初始化为已读。
2. PHP 设置 `upload_max_filesize=10M`、`post_max_size=12M`，反向代理请求体限制至少12 MB；前端代理限制已配置为12 MB。
3. 前后端使用匹配的公开 Reverb key 和地址，secret 仅保留在服务端。运行队列 worker、Reverb 和 Laravel scheduler；更新后重启长驻进程。
4. 按实际域名配置接入 Origin、身份服务回调和 Cookie；先完成真实账号登录、跨站嵌入、附件及权限验收，再开放访问。生产关闭 `APP_DEBUG`。

广播故障不撤销已经持久化的消息或管理操作，服务端记录 `Chat realtime publish failed`，客户端重连后从历史恢复。当前没有持久化广播补偿队列；部署者应监控广播、队列、磁盘和数据库，并在实际 PostgreSQL/Redis 环境执行容量及备份恢复验证。

**兼容性**：需要先执行本轮数据库迁移，再配套发布前后端并重启广播/队列进程。旧前端的公开频道订阅将不再收到新消息。迁移与实时订阅协议变化必须一起纳入发布计划。
