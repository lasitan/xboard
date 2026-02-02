<?php

namespace Plugin\Telegram;

use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;

class Plugin extends AbstractPlugin
{
    protected TelegramService $telegramService;

    public function boot(): void
    {
        $this->telegramService = new TelegramService();

        $this->listen('telegram.chat_join_request.approved', [$this, 'handleChatJoinApproved'], 10);
    }

    public function handleChatJoinApproved(array $data): void
    {
        [$payload] = $data;

        $groupId = trim((string) $this->getConfig('group_id', ''));
        if ($groupId === '') {
            return;
        }

        $chatId = $payload['chat_id'] ?? null;
        if ($chatId === null) {
            return;
        }

        if ((string) $chatId !== $groupId) {
            return;
        }

        $channelId = trim((string) $this->getConfig('channel_id', ''));
        $enableHcaptcha = (bool) $this->getConfig('enable_hcaptcha', false);
        $hcaptchaSiteKey = trim((string) $this->getConfig('hcaptcha_site_key', ''));

        $joinRequest = $payload['join_request'] ?? [];
        $from = $joinRequest['from'] ?? [];
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);

        $userId = $payload['user_id'] ?? null;

        $replyMarkup = null;
        $buttons = [];
        if ($enableHcaptcha && $hcaptchaSiteKey !== '' && $userId !== null) {
            $buttons[] = [
                [
                    'text' => '人机验证',
                    'url' => $this->buildHcaptchaUrl((string) $chatId, (string) $userId)
                ]
            ];
        }

        $channelUrl = $this->buildChannelUrl($channelId);
        if ($channelUrl !== null) {
            $buttons[] = [
                [
                    'text' => '关注频道',
                    'url' => $channelUrl
                ]
            ];
        }

        if (!empty($buttons)) {
            $replyMarkup = [
                'inline_keyboard' => $buttons
            ];
        }

        $text = $fullName !== '' ? "欢迎 {$fullName} 加入本群！" : '欢迎加入本群！';

        if ($enableHcaptcha && $hcaptchaSiteKey !== '' && $userId !== null) {
            $text .= "\n\n请先完成人机验证后再继续。";
        }

        if ($channelId !== '' && $channelUrl === null) {
            $text .= "\n\n推荐关注频道：{$channelId}";
        }

        $this->telegramService->sendMessage((int) $chatId, $text, 'markdown', $replyMarkup);
    }

    protected function buildHcaptchaUrl(string $chatId, string $userId): string
    {
        $ts = (string) time();
        $sig = hash_hmac('sha256', $chatId . '|' . $userId . '|' . $ts, $this->signingKey());

        $base = rtrim((string) admin_setting('app_url', ''), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url', ''), '/');
        }

        $query = http_build_query([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'ts' => $ts,
            'sig' => $sig
        ]);

        return $base . '/api/v1/guest/telegram/hcaptcha?' . $query;
    }

    protected function buildChannelUrl(string $channelId): ?string
    {
        $channelId = trim($channelId);
        if ($channelId === '') {
            return null;
        }

        if (str_starts_with($channelId, 'http://') || str_starts_with($channelId, 'https://')) {
            return $channelId;
        }

        if (str_starts_with($channelId, '@')) {
            return 'https://t.me/' . ltrim($channelId, '@');
        }

        if (preg_match('/^[a-zA-Z0-9_]{5,}$/', $channelId)) {
            return 'https://t.me/' . $channelId;
        }

        return null;
    }

    protected function signingKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $key;
    }
}
