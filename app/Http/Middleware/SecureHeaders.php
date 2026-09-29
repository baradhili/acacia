<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser-hardening headers for every response (clickjacking,
 * MIME-sniffing, referrer leakage). HSTS is sent only on secure
 * requests: behind a TLS-terminating proxy set TRUSTED_PROXIES so
 * Request::secure() reflects the forwarded client scheme — pinning
 * browsers to HTTPS over plain HTTP would be a no-op at best.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
