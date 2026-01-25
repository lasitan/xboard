<?php

namespace Plugin\CfAdminShield;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Plugin\CfAdminShield\models\AdminShield;
use Plugin\CfAdminShield\models\Ipdb;

class Plugin extends AbstractPlugin
{
    public static function handleWeb(Request $request, array $pluginConfigArr, string $adminPath)
    {
        $enabled = (bool) ($pluginConfigArr['enabled'] ?? false);
        if (!$enabled) {
            return null;
        }

        $allowCnIp = (bool) ($pluginConfigArr['allow_cn_ip'] ?? true);
        if (!$allowCnIp) {
            $ip = (string) $request->ip();
            $ipdbAppid = trim((string) ($pluginConfigArr['ipdb_appid'] ?? ''));
            $ipdbSecret = trim((string) ($pluginConfigArr['ipdb_secret'] ?? ''));
            $country = Ipdb::getCountryCode($ip, $ipdbAppid, $ipdbSecret);
            if ($country === 'CN') {
                $proxyUrl = trim((string) ($pluginConfigArr['proxy_url'] ?? ''));
                $proxyUrl = $proxyUrl !== '' ? $proxyUrl : 'https://hyperos.mi.com/';

                $cacheKey = 'web:cf_admin_shield:cn_proxy:' . hash('sha256', $proxyUrl);
                $html = Cache::remember($cacheKey, 300, function () use ($proxyUrl) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $proxyUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent: Mozilla/5.0',
                    ]);
                    $raw = curl_exec($ch);
                    curl_close($ch);

                    return is_string($raw) && $raw !== '' ? $raw : null;
                });

                if (!is_string($html) || $html === '') {
                    return response('Forbidden', 403);
                }

                return response($html, 200)->header('content-type', 'text/html; charset=UTF-8');
            }
        }

        return AdminShield::handle($request, $pluginConfigArr);
    }

    public function boot(): void
    {
        return;
    }

    public function schedule(Schedule $schedule): void
    {
        // no-op
    }

    private function isVerifiedToken(string $token, string $ip, string $userAgent): bool
    {
        $key = 'plugin:cf_admin_shield:verified:' . hash('sha256', $token);
        $data = Cache::get($key);
        if (!is_array($data)) {
            return false;
        }
        if (($data['ip'] ?? null) !== $ip) {
            return false;
        }
        if (($data['ua'] ?? null) !== $userAgent) {
            return false;
        }
        return true;
    }

    private function storeVerifiedToken(string $token, string $ip, string $userAgent): void
    {
        $key = 'plugin:cf_admin_shield:verified:' . hash('sha256', $token);
        Cache::put($key, ['ip' => $ip, 'ua' => $userAgent], 600);
    }

    private function verifyTurnstile(string $secretKey, string $responseToken, ?string $remoteIp = null): bool
    {
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

    private function renderChallengePage(string $siteKey, bool $failed, string $postPath)
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

        $html .= '<form method="POST" action="' . e($postPath) . '">' 
            . '<input type="hidden" name="_token" value="' . e(csrf_token()) . '" />'
            . '<div class="cf-turnstile" data-sitekey="' . e($siteKey) . '"></div>'
            . '<button type="submit">Continue</button>'
            . '</form>'
            . '</div></div></body></html>';

        return response($html)->header('content-type', 'text/html; charset=UTF-8');
    }
}
