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

        if (in_array($hostKey, $notifyHosts, true)) {
            if ($request->isMethod('options')) {
                return $next($request);
            }

            $path = ltrim($request->path(), '/');
            if (
                !str_starts_with($path, 'api/v1/guest/payment/notify/')
                || !in_array(strtolower($request->method()), ['get', 'post'], true)
            ) {
                return response('Not Found', 404);
            }
        }

        return $next($request);
    }
}
