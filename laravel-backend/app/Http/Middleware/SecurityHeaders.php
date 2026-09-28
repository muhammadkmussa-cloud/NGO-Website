<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-06 / HDR-01: baseline security headers on every response.
 * - X-Frame-Options + frame-ancestors: blocks clickjacking of the admin dashboard.
 * - nosniff: prevents MIME-sniffing into executable content.
 * - Referrer-Policy: keeps URLs (tokens, references) out of third-party referrers.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Camera is needed by the open gate station (/checking) for QR scanning.
        // Allow it there only; microphone and geolocation stay blocked everywhere.
        $cameraPolicy = str_starts_with($request->path(), 'checking')
            ? 'camera=(self), microphone=(), geolocation=()'
            : 'camera=(), microphone=(), geolocation=()';
        $response->headers->set('Permissions-Policy', $cameraPolicy);
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (strtolower((string) config('roi.environment')) === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Merge with any existing CSP rather than clobbering it.
        $existing = $response->headers->get('Content-Security-Policy');
        $frameAncestors = "frame-ancestors 'none'";
        $response->headers->set(
            'Content-Security-Policy',
            $existing ? $existing . '; ' . $frameAncestors : $frameAncestors
        );

        return $response;
    }
}
