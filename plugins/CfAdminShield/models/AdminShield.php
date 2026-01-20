<?php

namespace Plugin\CfAdminShield\models;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminShield
{
    public static function handle(Request $request, array $pluginConfigArr)
    {
        $enabled = (bool) ($pluginConfigArr['enabled'] ?? false);
        if (!$enabled) {
            return null;
        }
        $siteKey = trim((string) ($pluginConfigArr['turnstile_site_key'] ?? ''));
        $secretKey = trim((string) ($pluginConfigArr['turnstile_secret_key'] ?? ''));

        if ($siteKey === '' || $secretKey === '') {
            return null;
        }

        $cookieName = 'cf_admin_shield';
        $token = (string) $request->cookie($cookieName, '');
        $ip = (string) $request->ip();
        $ua = (string) $request->header('user-agent', '');

        if (self::isVerified($token, $ip, $ua)) {
            return null;
        }

        $postUrl = (string) $request->fullUrl();

        if ($request->isMethod('post')) {
            $redirectTo = (string) $request->input('redirect_to', $postUrl);
            $turnstileResponse = (string) $request->input('cf-turnstile-response', '');

            $ok = self::verifyTurnstile($secretKey, $turnstileResponse, $ip);
            if ($ok) {
                $newToken = Str::random(48);
                self::storeVerified($newToken, $ip, $ua);
                $response = redirect()->to($redirectTo);
                $response->withCookie(cookie($cookieName, $newToken, 10, '/', null, $request->isSecure(), true, false, 'Lax'));
                return $response;
            }

            return self::renderChallenge($siteKey, true, $redirectTo);
        }

        return self::renderChallenge($siteKey, false, $postUrl);
    }

    private static function isVerified(string $token, string $ip, string $ua): bool
    {
        if ($token === '') {
            return false;
        }
        $key = 'web:cf_admin_shield:verified:' . hash('sha256', $token);
        $data = Cache::get($key);
        if (!is_array($data)) {
            return false;
        }
        if (($data['ip'] ?? null) !== $ip) {
            return false;
        }
        if (($data['ua'] ?? null) !== $ua) {
            return false;
        }
        return true;
    }

    private static function storeVerified(string $token, string $ip, string $ua): void
    {
        $key = 'web:cf_admin_shield:verified:' . hash('sha256', $token);
        Cache::put($key, ['ip' => $ip, 'ua' => $ua], 600);
    }

    private static function renderChallenge(string $siteKey, bool $failed, string $postUrl)
    {
        $title = 'Admin verification';
        $message = $failed ? 'Verification failed, please try again.' : 'Please complete the verification to continue.';

        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8" />'
            . '<meta name="viewport" content="width=device-width,initial-scale=1" />'
            . '<title>' . e($title) . '</title>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
            . '<style>'
            . 'html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;}'
            . 'body{display:flex;align-items:center;justify-content:center;background:#0b1220;color:#e5e7eb;}'
            . '.wrap{width:100%;max-width:420px;padding:24px;}'
            . '.card{background:#111827;border:1px solid #1f2937;border-radius:14px;padding:18px;}'
            . 'h1{margin:0 0 10px;font-size:20px;}'
            . 'p{margin:0 0 14px;color:#9ca3af;font-size:14px;}'
            . 'button{width:100%;margin-top:12px;padding:10px 12px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer;}'
            . '.error{color:#fca5a5;margin-bottom:10px;font-size:13px;}'
            . '</style>'
            . '</head><body><div class="wrap"><div class="card">'
            . '<h1>' . e($title) . '</h1>'
            . '<p>' . e($message) . '</p>';

        if ($failed) {
            $html .= '<div class="error">' . e('Turnstile validation failed') . '</div>';
        }

        $html .= '<form method="POST" action="' . e($postUrl) . '">' 
            . '<input type="hidden" name="_token" value="' . e(csrf_token()) . '" />'
            . '<input type="hidden" name="redirect_to" value="' . e($postUrl) . '" />'
            . '<div class="cf-turnstile" data-sitekey="' . e($siteKey) . '"></div>'
            . '<button type="submit">Continue</button>'
            . '</form>'
            . '</div></div></body></html>';

        return response($html)->header('content-type', 'text/html; charset=UTF-8');
    }

    private static function verifyTurnstile(string $secretKey, string $responseToken, ?string $remoteIp = null): bool
    {
        if ($secretKey === '' || $responseToken === '') {
            return false;
        }

        $payload = [
            'secret' => $secretKey,
            'response' => $responseToken,
        ];
        if ($remoteIp) {
            $payload['remoteip'] = $remoteIp;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }
        return (bool) ($decoded['success'] ?? false);
    }
}
