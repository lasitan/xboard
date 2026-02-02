<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramCaptchaController extends Controller
{
    public function show(Request $request)
    {
        $siteKey = (string) $this->getTelegramPluginConfigValue('hcaptcha_site_key', '');
        if ($siteKey === '') {
            return response('hCaptcha is not configured', 500);
        }

        $chatId = (string) $request->query('chat_id', '');
        $userId = (string) $request->query('user_id', '');
        $ts = (string) $request->query('ts', '');
        $sig = (string) $request->query('sig', '');

        if (!$this->isValidSignature($chatId, $userId, $ts, $sig)) {
            return response('Invalid signature', 403);
        }

        $actionUrl = url('/api/v1/guest/telegram/hcaptcha');

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>hCaptcha</title>'
            . '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:520px;margin:32px auto;padding:0 16px}button{width:100%;padding:12px 16px;border:0;border-radius:10px;background:#111;color:#fff;font-size:16px} .card{border:1px solid #e5e5e5;border-radius:12px;padding:18px}</style>'
            . '</head><body><div class="card">'
            . '<h2 style="margin:0 0 10px 0">人机验证</h2>'
            . '<p style="margin:0 0 16px 0;color:#555">完成验证后返回 Telegram。</p>'
            . '<form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '">'
            . '<input type="hidden" name="chat_id" value="' . htmlspecialchars($chatId, ENT_QUOTES) . '">' 
            . '<input type="hidden" name="user_id" value="' . htmlspecialchars($userId, ENT_QUOTES) . '">' 
            . '<input type="hidden" name="ts" value="' . htmlspecialchars($ts, ENT_QUOTES) . '">' 
            . '<input type="hidden" name="sig" value="' . htmlspecialchars($sig, ENT_QUOTES) . '">' 
            . '<div class="h-captcha" data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES) . '"></div>'
            . '<div style="height:12px"></div>'
            . '<button type="submit">验证并继续</button>'
            . '</form></div></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function verify(Request $request)
    {
        $secretKey = (string) $this->getTelegramPluginConfigValue('hcaptcha_secret_key', '');
        if ($secretKey === '') {
            return response('hCaptcha is not configured', 500);
        }

        $chatId = (string) $request->input('chat_id', '');
        $userId = (string) $request->input('user_id', '');
        $ts = (string) $request->input('ts', '');
        $sig = (string) $request->input('sig', '');

        if (!$this->isValidSignature($chatId, $userId, $ts, $sig)) {
            return response('Invalid signature', 403);
        }

        $token = (string) $request->input('h-captcha-response', '');
        if ($token === '') {
            return response('Missing hCaptcha token', 400);
        }

        $resp = Http::asForm()->post('https://hcaptcha.com/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $request->ip()
        ]);

        if (!$resp->successful()) {
            return response('Verify request failed', 502);
        }

        $json = $resp->json();
        if (!is_array($json) || !($json['success'] ?? false)) {
            return response('Verification failed', 400);
        }

        Cache::put($this->cacheKey($chatId, $userId), true, 3600);

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>验证成功</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:520px;margin:32px auto;padding:0 16px} .card{border:1px solid #e5e5e5;border-radius:12px;padding:18px} a{display:inline-block;margin-top:12px}</style>'
            . '</head><body><div class="card">'
            . '<h2 style="margin:0 0 10px 0">验证成功</h2>'
            . '<p style="margin:0;color:#555">你可以返回 Telegram 继续操作。</p>'
            . '</div></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function getTelegramPluginConfigValue(string $key, mixed $default = null): mixed
    {
        $plugin = Plugin::query()->where('code', 'telegram')->first();
        if (!$plugin || empty($plugin->config)) {
            return $default;
        }
        $config = json_decode($plugin->config, true);
        if (!is_array($config)) {
            return $default;
        }
        return $config[$key] ?? $default;
    }

    protected function isValidSignature(string $chatId, string $userId, string $ts, string $sig): bool
    {
        if ($chatId === '' || $userId === '' || $ts === '' || $sig === '') {
            return false;
        }

        if (!ctype_digit($ts)) {
            return false;
        }

        $tsInt = (int) $ts;
        if ($tsInt <= 0) {
            return false;
        }

        if (abs(time() - $tsInt) > 900) {
            return false;
        }

        $expected = hash_hmac('sha256', $chatId . '|' . $userId . '|' . $ts, $this->signingKey());
        return hash_equals($expected, $sig);
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

    protected function cacheKey(string $chatId, string $userId): string
    {
        return 'telegram_hcaptcha_verified:' . $chatId . ':' . $userId;
    }
}
