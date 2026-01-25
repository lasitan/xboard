<?php

use App\Exceptions\ApiException;
use App\Models\Plugin;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$callbackHandler = function (Request $request) {
    $state = (string) $request->input('state', '');
    $code = (string) $request->input('code', '');
    $error = (string) $request->input('error', '');

    if ($error !== '') {
        throw new ApiException('Google OAuth error: ' . $error, 400);
    }
    if ($state === '' || $code === '') {
        throw new ApiException('Invalid callback request', 400);
    }

    $stateKey = 'oauth:google:state:' . hash('sha256', $state);
    $stateData = Cache::pull($stateKey);
    if (!is_array($stateData)) {
        throw new ApiException('State expired', 400);
    }

    $pluginConfig = Cache::remember('oauth:google_login:config', 60, function () {
        $plugin = Plugin::query()->where('code', 'google_login')->first();
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

    $cfg = is_array($pluginConfig) ? $pluginConfig : [];
    $enabled = (bool) ($cfg['enabled'] ?? true);
    $clientId = trim((string) ($cfg['client_id'] ?? ''));
    $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
    $callbackUrl = trim((string) ($cfg['callback_url'] ?? ''));
    $callbackDomain = trim((string) ($cfg['callback_domain'] ?? ''));

    if (!$enabled || $clientId === '' || $clientSecret === '') {
        throw new ApiException('Google login is not configured', 400);
    }

    $callbackPath = '/api/v1/passport/auth/google/callback';
    $redirectUri = $callbackUrl !== ''
        ? $callbackUrl
        : ($callbackDomain !== '' ? rtrim($callbackDomain, '/') . $callbackPath : url($callbackPath));

    $tokenPayload = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenPayload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw = curl_exec($ch);
    curl_close($ch);

    $tokenResp = json_decode((string) $raw, true);
    if (!is_array($tokenResp) || empty($tokenResp['access_token'])) {
        throw new ApiException('Failed to exchange token', 400);
    }

    $accessToken = (string) $tokenResp['access_token'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw = curl_exec($ch);
    curl_close($ch);

    $profile = json_decode((string) $raw, true);
    if (!is_array($profile) || empty($profile['email'])) {
        throw new ApiException('Failed to fetch userinfo', 400);
    }

    $email = (string) $profile['email'];

    $user = User::where('email', $email)->first();
    if (!$user) {
        $userService = app(UserService::class);
        $user = $userService->createUser([
            'email' => $email,
            'password' => Str::random(32)
        ]);
        if (!$user->save()) {
            throw new ApiException('Failed to create user', 500);
        }
    }

    if ($user->banned) {
        throw new ApiException('Your account has been suspended', 400);
    }

    $user->last_login_at = time();
    $user->save();

    $authService = new AuthService($user);
    $authData = $authService->generateAuthData();

    $redirect = (string) ($stateData['redirect'] ?? 'dashboard');
    $redirect = ltrim($redirect, '/');

    $appUrl = admin_setting('app_url');
    $base = $appUrl ? rtrim($appUrl, '/') : rtrim(url(''), '/');

    $frontPath = '/#/' . $redirect;
    $glue = str_contains($frontPath, '?') ? '&' : '?';
    $frontUrl = $base . $frontPath . $glue . http_build_query($authData);

    return redirect()->away($frontUrl);
};

Route::group([
    'prefix' => 'api/v1/passport/auth/google'
], function () {
    Route::get('/login', function (Request $request) {
        $pluginConfig = Cache::remember('oauth:google_login:config', 60, function () {
            $plugin = Plugin::query()->where('code', 'google_login')->first();
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

        $cfg = is_array($pluginConfig) ? $pluginConfig : [];
        $enabled = (bool) ($cfg['enabled'] ?? true);
        $clientId = trim((string) ($cfg['client_id'] ?? ''));
        $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
        $callbackUrl = trim((string) ($cfg['callback_url'] ?? ''));
        $callbackDomain = trim((string) ($cfg['callback_domain'] ?? ''));

        if (!$enabled || $clientId === '' || $clientSecret === '') {
            throw new ApiException('Google login is not configured', 400);
        }

        $redirect = (string) $request->input('redirect', ($cfg['redirect'] ?? 'dashboard'));
        $state = Str::random(32);
        Cache::put('oauth:google:state:' . hash('sha256', $state), ['redirect' => $redirect], 600);

        $callbackPath = '/api/v1/passport/auth/google/callback';
        $redirectUri = $callbackUrl !== ''
            ? $callbackUrl
            : ($callbackDomain !== '' ? rtrim($callbackDomain, '/') . $callbackPath : url($callbackPath));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account'
        ];

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    });

    Route::get('/callback', $callbackHandler);
});

try {
    $pluginConfigForRoutes = Cache::remember('oauth:google_login:config', 60, function () {
        $plugin = Plugin::query()->where('code', 'google_login')->first();
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

    $cfg = is_array($pluginConfigForRoutes) ? $pluginConfigForRoutes : [];
    $callbackUrl = trim((string) ($cfg['callback_url'] ?? ''));

    if ($callbackUrl !== '') {
        $defaultCallbackPath = '/api/v1/passport/auth/google/callback';
        $parsed = parse_url($callbackUrl);
        $customPath = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';
        $customPath = $customPath !== '' ? '/' . ltrim($customPath, '/') : '';

        if ($customPath !== '' && $customPath !== $defaultCallbackPath) {
            $customUri = ltrim($customPath, '/');

            $routesByMethod = Route::getRoutes()->getRoutesByMethod();
            $existingGetRoutes = $routesByMethod['GET'] ?? [];
            $existingHeadRoutes = $routesByMethod['HEAD'] ?? [];

            $conflict = false;
            foreach (array_merge($existingGetRoutes, $existingHeadRoutes) as $r) {
                if (method_exists($r, 'uri') && $r->uri() === $customUri) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                $err = 'GoogleLogin callback_url path conflicts with an existing route: ' . $customPath;
                Log::error($err);
                Cache::put('oauth:google_login:callback_route_error', $err, 3600);
            } else {
                Route::get($customUri, function (Request $request) {
                    return redirect()->to('/api/v1/passport/auth/google/callback?' . http_build_query($request->query()));
                });
            }
        }
    }
} catch (\Throwable $e) {
    Log::error('GoogleLogin failed to register custom callback route: ' . $e->getMessage());
    Cache::put('oauth:google_login:callback_route_error', $e->getMessage(), 3600);
}
