<?php

namespace Plugin\CfAdminShield\moudels;

use Illuminate\Support\Facades\Cache;

class Ipdb
{
    public static function getCountryCode(string $ip, string $appid = '', string $secret = '', int $cacheSeconds = 3600, int $timeoutSeconds = 2): ?string
    {
        if ($ip === '') {
            return null;
        }

        $cacheKey = 'web:cf_admin_shield:ip_country:' . hash('sha256', $ip);
        return Cache::remember($cacheKey, $cacheSeconds, function () use ($ip, $appid, $secret, $timeoutSeconds) {
            if ($appid !== '' && $secret !== '') {
                $country = self::fetchFromIpdbPurecheat($ip, $appid, $secret, $timeoutSeconds);
                if ($country) {
                    return $country;
                }
            }

            return self::fetchFromCountryIs($ip, $timeoutSeconds);
        });
    }

    private static function fetchFromIpdbPurecheat(string $ip, string $appid, string $secret, int $timeoutSeconds): ?string
    {
        $timestamp = (string) ((int) round(microtime(true) * 1000));
        $datasign = md5($appid . $ip . $timestamp . $secret);

        $payload = json_encode([
            'appid' => $appid,
            'address' => $ip,
            'timestamp' => $timestamp,
            'datasign' => $datasign,
        ]);

        if (!is_string($payload)) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://ipdb.purecheat.com/api/v1/paas/ip/fetch');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['status'] ?? -1) !== 0) {
            return null;
        }

        $dataStr = $decoded['data'] ?? null;
        if (!is_string($dataStr) || $dataStr === '') {
            return null;
        }

        $dataJson = json_decode($dataStr, true);
        if (!is_array($dataJson)) {
            return null;
        }

        $countryCode = $dataJson['countryCode'] ?? null;
        if (!is_string($countryCode) || $countryCode === '') {
            return null;
        }

        return strtoupper($countryCode);
    }

    private static function fetchFromCountryIs(string $ip, int $timeoutSeconds): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.country.is/' . rawurlencode($ip));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $country = $decoded['country'] ?? null;
        if (!is_string($country) || $country === '') {
            return null;
        }

        return strtoupper($country);
    }
}
