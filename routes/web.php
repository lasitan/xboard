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

Route::get('/', function (Request $request) {
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

    $cacheKey = 'web:get_proxy:' . hash('sha256', $proxyUrl);
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
        return response('Bad Gateway', 502);
    }

    return response($html, 200)->header('content-type', 'text/html; charset=UTF-8');
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

Route::get('/{any}', function () {
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

    $cacheKey = 'web:get_proxy:' . hash('sha256', $proxyUrl);
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
        return response('Bad Gateway', 502);
    }

    return response($html, 200)->header('content-type', 'text/html; charset=UTF-8');
})->where('any', '.*');