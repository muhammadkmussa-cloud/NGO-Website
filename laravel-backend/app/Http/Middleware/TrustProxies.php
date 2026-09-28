<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusts upstream proxies so Request::ip() reflects the real client address
 * (not the load balancer). Required for the per-IP rate limits and the password
 * brute-force cap to work correctly when deployed behind a reverse proxy.
 *
 * Configure with ROI_TRUSTED_PROXIES:
 *   - unset / empty  -> trust nothing (direct connections; current behavior)
 *   - "*"            -> trust all (only safe when a proxy overwrites X-Forwarded-For)
 *   - "1.2.3.4,10.0.0.0/8" -> trust those CIDRs/IPs
 *
 * Without this, a deployment behind a proxy would see every client share the
 * proxy IP, causing mass lockouts under the login throttle.
 */
class TrustProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) config('roi.trusted_proxies', '');

        if ($raw === '*') {
            $proxies = ['0.0.0.0/0', '::/0'];
        } elseif ($raw !== '') {
            $proxies = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $proxies = [];
        }

        if ($proxies !== []) {
            $request->setTrustedProxies(
                $proxies,
                Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        return $next($request);
    }
}
