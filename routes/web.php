<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Plugin\CfAdminShield\Plugin as CfAdminShieldPlugin;

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

$proxyGet = function (Request $request) {
    $proxyUrl = Cache::remember('web:cf_admin_shield:proxy_url', 60, function () {
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
        $proxyUrl = trim((string) ($config['proxy_url'] ?? ''));
        return $proxyUrl !== '' ? $proxyUrl : null;
    });
    $proxyUrl = is_string($proxyUrl) && $proxyUrl !== '' ? $proxyUrl : 'https://hyperos.mi.com/';

    $base = rtrim($proxyUrl, '/');
    $path = '/' . ltrim($request->path(), '/');
    $qs = $request->getQueryString();
    $target = $base . $path . ($qs ? ('?' . $qs) : '');

    $forwardHeaders = [];
    foreach (['accept', 'accept-language', 'user-agent', 'referer', 'range'] as $h) {
        $v = $request->header($h);
        if (is_string($v) && $v !== '') {
            $forwardHeaders[] = $h . ': ' . $v;
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    if (!empty($forwardHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return response('Bad Gateway', 502);
    }
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeader = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $resp = response($body, $statusCode);

    $lines = preg_split("/\r\n|\n|\r/", (string) $rawHeader);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '' || stripos($line, 'HTTP/') === 0) {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }

            $lower = strtolower($name);
            if (in_array($lower, ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade'], true)) {
                continue;
            }
            $resp->headers->set($name, $value, false);
        }
    }

    return $resp;
};

Route::get('/', function (Request $request) use ($proxyGet) {
    return $proxyGet($request);
});

//TODO:: 兼容
$adminPath = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
$adminPath = '/' . trim($adminPath, '/');
$adminPathTrim = ltrim($adminPath, '/');

Route::match(['GET', 'POST'], '/' . $adminPathTrim . '/{any?}', function (Request $request) use ($adminPath, $proxyGet) {

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

    $shieldResponse = CfAdminShieldPlugin::handleWeb($request, $pluginConfigArr, $adminPath);
    if ($shieldResponse) {
        return $shieldResponse;
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

Route::get('/{any}', function () use ($proxyGet) {
    return $proxyGet(request());
})->where('any', '.*');