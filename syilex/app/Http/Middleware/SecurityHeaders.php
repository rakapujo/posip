<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk attach HTTP security headers.
 *
 * Melindungi dari:
 * - XSS (X-XSS-Protection, X-Content-Type-Options)
 * - Clickjacking (X-Frame-Options)
 * - MIME sniffing attack (X-Content-Type-Options)
 * - Referrer leakage (Referrer-Policy)
 *
 * Note: HSTS dan CSP tidak di-enable default karena butuh konfigurasi
 * per-environment. Enable manual di production HTTPS env.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Cegah browser "sniff" MIME type (penting untuk upload file)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Cegah page di-embed di iframe situs lain (anti clickjacking)
        $response->headers->set('X-Frame-Options', 'DENY');

        // Legacy XSS filter browser — enable
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Minimal referrer info (privacy + hindari leak internal URL)
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Batasi permissions API yang sensitif (geolocation, camera, dll)
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=()'
        );

        return $response;
    }
}
