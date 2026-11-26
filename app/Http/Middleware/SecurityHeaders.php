<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HTTPS everywhere in production, and the headers that stop framing and content sniffing (PRD section 9). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $https = $request->isSecure() || self::proxySaysHttps($request);

        // A live store whose address is https never answers on plain http.
        if (! $https && app()->isProduction() && str_starts_with((string) config('app.url'), 'https://') && $request->isMethodSafe()) {
            return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), usb=()');
        if (! $response->headers->has('X-Frame-Options')) {
            // The page builder shows the store in a frame on the same site; no other site may frame it.
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if ($https && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * Only used to avoid a redirect loop when a proxy ends HTTPS and is not listed in TRUSTED_PROXIES.
     * A visitor who fakes the header merely skips their own redirect.
     */
    private static function proxySaysHttps(Request $request): bool
    {
        return strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https'
            || str_contains((string) $request->headers->get('CF-Visitor'), '"https"');
    }
}
