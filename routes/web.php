<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Plugin\CfAdminShield\models\Ipdb;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    $html = '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8" />'
        . '<meta name="viewport" content="width=device-width,initial-scale=1" />'
        . '<title>' . e('Coming Soon') . '</title>'
        . '<style>'
        . 'html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;}'
        . 'body{display:flex;align-items:center;justify-content:center;background:#0b1220;color:#e5e7eb;}'
        . '.wrap{text-align:center;max-width:720px;padding:24px;}'
        . 'h1{margin:0 0 12px;font-size:42px;letter-spacing:.02em;}'
        . 'p{margin:0;color:#9ca3af;font-size:16px;}'
        . '</style>'
        . '</head><body><div class="wrap">'
        . '<h1>Coming Soon</h1>'
        . '<p>' . e('Coming Soon') . '</p>'
        . '</div></body></html>';

    return response($html)
        ->header('content-type', 'text/html; charset=UTF-8');
});

//TODO:: 兼容
$adminPath = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
$adminPath = '/' . trim($adminPath, '/');
$adminPathTrim = ltrim($adminPath, '/');

Route::match(['GET', 'POST'], '/' . $adminPathTrim . '/{any?}', function (Request $request) use ($adminPath) {
    $pluginConfig = Cache::remember('web:cf_admin_shield:plugin_config', 60, function () {
        $plugin = Plugin::query()->where('code', 'cf_admin_shield')->first();
        if (!$plugin || !$plugin->is_enabled) {
            return null;
        }
        $config = [];
        if (is_string($plugin->config) && $plugin->config !== '') {
            $decoded = json_decode($plugin->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        return $config;
    });

    $pluginConfigArr = is_array($pluginConfig) ? $pluginConfig : [];
    $enabled = (bool) ($pluginConfigArr['enabled'] ?? false);
    $allowCnIp = (bool) ($pluginConfigArr['allow_cn_ip'] ?? true);
    $ipdbAppid = trim((string) ($pluginConfigArr['ipdb_appid'] ?? ''));
    $ipdbSecret = trim((string) ($pluginConfigArr['ipdb_secret'] ?? ''));
    $siteKey = trim((string) ($pluginConfigArr['turnstile_site_key'] ?? ''));
    $secretKey = trim((string) ($pluginConfigArr['turnstile_secret_key'] ?? ''));

    $cookieName = 'cf_admin_shield';
    $token = (string) $request->cookie($cookieName, '');
    $ip = (string) $request->ip();
    $ua = (string) $request->header('user-agent', '');

    $getIpCountryCode = function (string $ip) use ($ipdbAppid, $ipdbSecret): ?string {
        return Ipdb::getCountryCode($ip, $ipdbAppid, $ipdbSecret);
    };

    if (!$allowCnIp) {
        $country = $getIpCountryCode($ip);
        if ($country === 'CN') {
            return response('Forbidden', 403);
        }
    }

    $isVerified = function (string $token) use ($ip, $ua): bool {
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
    };

    $storeVerified = function (string $token) use ($ip, $ua): void {
        $key = 'web:cf_admin_shield:verified:' . hash('sha256', $token);
        Cache::put($key, ['ip' => $ip, 'ua' => $ua], 600);
    };

    $renderChallenge = function (string $siteKey, bool $failed, string $postUrl) {
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
            . '<input type="hidden" name="redirect_to" value="' . e($postUrl) . '" />'
            . '<div class="cf-turnstile" data-sitekey="' . e($siteKey) . '"></div>'
            . '<button type="submit">Continue</button>'
            . '</form>'
            . '</div></div></body></html>';

        return response($html)->header('content-type', 'text/html; charset=UTF-8');
    };

    $verifyTurnstile = function (string $secretKey, string $responseToken, ?string $remoteIp = null): bool {
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
    };

    if ($enabled && $siteKey !== '' && $secretKey !== '') {
        if (!$isVerified($token)) {
            $postUrl = (string) $request->fullUrl();

            if ($request->isMethod('post')) {
                $redirectTo = (string) $request->input('redirect_to', $postUrl);
                $turnstileResponse = (string) $request->input('cf-turnstile-response', '');
                $ok = $verifyTurnstile($secretKey, $turnstileResponse, $ip);
                if ($ok) {
                    $newToken = Str::random(48);
                    $storeVerified($newToken);
                    $response = redirect()->to($redirectTo);
                    $response->withCookie(cookie($cookieName, $newToken, 10, '/', null, $request->isSecure(), true, false, 'Lax'));
                    return $response;
                }
                return $renderChallenge($siteKey, true, $redirectTo);
            }

            return $renderChallenge($siteKey, false, $postUrl);
        }
    }

    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
})->where('any', '.*');

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');