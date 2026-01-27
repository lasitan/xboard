<?php

namespace Plugin\CfAdminShield;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\CfAdminShield\models\AdminShield;
use Plugin\CfAdminShield\models\Ipdb;

class Plugin extends AbstractPlugin
{
    private static bool $decoyCacheWarmed = false;

    public static function handleRoot(Request $request, array $pluginConfigArr)
    {
        $enabled = (bool) ($pluginConfigArr['enabled'] ?? false);
        if (!$enabled) {
            return null;
        }

        $decoyUrl = trim((string) ($pluginConfigArr['decoy_url'] ?? ''));
        if ($decoyUrl === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $decoyUrl)) {
            return null;
        }

        return self::proxyToDecoy($request, $decoyUrl);
    }

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
                $decoyUrl = trim((string) ($pluginConfigArr['decoy_url'] ?? ''));
                if ($decoyUrl !== '' && preg_match('#^https?://#i', $decoyUrl)) {
                    return self::proxyToDecoy($request, $decoyUrl);
                }
                return response('Forbidden', 403);
            }
        }

        return AdminShield::handle($request, $pluginConfigArr);
    }

    private static function proxyToDecoy(Request $request, string $decoyUrl)
    {
        $base = rtrim($decoyUrl, '/');
        $targetUrl = $base . '/';

        $cacheKey = 'web:cf_admin_shield:decoy:' . hash('sha256', $targetUrl);
        $useCache = $request->isMethod('get') && $request->query->count() === 0 && $request->getContent() === '';
        if ($useCache) {
            if (!self::$decoyCacheWarmed) {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && isset($cached['status'], $cached['headers'], $cached['body'])) {
                    Cache::put($cacheKey, $cached, 3 * 60 * 60);
                }
                self::$decoyCacheWarmed = true;
            } else {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && isset($cached['status'], $cached['headers'], $cached['body'])) {
                    return response($cached['body'], (int) $cached['status'])->withHeaders((array) $cached['headers']);
                }
            }
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, ['host', 'content-length', 'cookie'], true)) {
                continue;
            }
            $headers[$name] = implode(',', $values);
        }

        $host = (string) parse_url($decoyUrl, PHP_URL_HOST);
        if ($host !== '') {
            $headers['Host'] = $host;
        }

        $method = strtoupper($request->method());
        $options = [
            'query' => $request->query(),
            'body' => $request->getContent(),
        ];

        $upstream = Http::withHeaders($headers)
            ->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
            ])
            ->send($method, $targetUrl, $options);

        $respHeaders = [];
        foreach ($upstream->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length'], true)) {
                continue;
            }

            if ($lower === 'set-cookie') {
                continue;
            }

            if ($lower === 'location') {
                $location = is_array($values) ? ($values[0] ?? '') : (string) $values;
                if (is_string($location) && $location !== '' && str_starts_with($location, $base)) {
                    $location = '/' . ltrim(substr($location, strlen($base)), '/');
                }
                $respHeaders[$name] = $location;
                continue;
            }

            $respHeaders[$name] = is_array($values) ? implode(',', $values) : (string) $values;
        }

        if ($useCache) {
            Cache::put($cacheKey, [
                'status' => $upstream->status(),
                'headers' => $respHeaders,
                'body' => $upstream->body(),
            ], 3 * 60 * 60);
        }

        return response($upstream->body(), $upstream->status())->withHeaders($respHeaders);
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
