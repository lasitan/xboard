<?php

namespace App\Http\Middleware;

use App\Models\Payment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RestrictNotifyDomain
{
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();
        $port = $request->getPort();
        $hostKey = $port ? ($host . ':' . $port) : $host;

        $notifyHosts = Cache::remember('notify_domain_hosts', 60, function () {
            return Payment::query()
                ->whereNotNull('notify_domain')
                ->where('notify_domain', '!=', '')
                ->get(['notify_domain'])
                ->map(function ($payment) {
                    $parts = parse_url($payment->notify_domain);
                    if (!$parts || !isset($parts['host'])) {
                        return null;
                    }
                    $host = $parts['host'];
                    if (isset($parts['port'])) {
                        $host .= ':' . $parts['port'];
                    }
                    return $host;
                })
                ->filter()
                ->values()
                ->all();
        });

        $debug = (string) $request->query('__debug_notify_domain', '');
        $debugEnabled = $debug === '1';

        if (in_array($hostKey, $notifyHosts, true)) {
            if ($request->isMethod('options')) {
                return $next($request);
            }

            $path = ltrim($request->path(), '/');
            $subscribePath = trim((string) admin_setting('subscribe_path', 's'), '/');
            $isSubscribePath = $subscribePath !== ''
                && $request->isMethod('get')
                && (
                    str_starts_with($path, $subscribePath . '/')
                );
            if (
                !$isSubscribePath
                && !str_starts_with($path, 'api/v1/guest/payment/notify/')
                || !in_array(strtolower($request->method()), ['get', 'post'], true)
            ) {
                $resp = response('Not Found', 404);
                if ($debugEnabled) {
                    $resp->headers->set('x-debug-notify-domain-hostkey', (string) $hostKey);
                    $resp->headers->set('x-debug-notify-domain-in-hosts', '1');
                    $resp->headers->set('x-debug-notify-domain-path', (string) $path);
                    $resp->headers->set('x-debug-notify-domain-method', strtolower((string) $request->method()));
                    $resp->headers->set('x-debug-notify-domain-subscribe-path', (string) $subscribePath);
                    $resp->headers->set('x-debug-notify-domain-is-subscribe', $isSubscribePath ? '1' : '0');
                    $resp->headers->set('x-debug-notify-domain-is-notify', str_starts_with($path, 'api/v1/guest/payment/notify/') ? '1' : '0');
                }
                return $resp;
            }
        }

        return $next($request);
    }
}
