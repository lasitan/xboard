# Telegram 插件

本插件用于在用户加入指定 Telegram 群组时发送欢迎语，并推荐关注指定频道。

## 配置

- group_id: 需要发送欢迎语的群组 ID（例如 -100xxxxxxxxxx）
- channel_id: 推荐关注的频道 ID（例如 -100xxxxxxxxxx）或 @频道用户名

## 行为

当 Telegram 触发 chat_join_request 并被系统批准加入后，若 chat_id 与 group_id 匹配：

- 在群内发送欢迎语
- 若配置了 channel_id，则在欢迎语中附带“推荐关注频道”
