<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Behind Cloudflare or another proxy, the visitor's real address and "https" arrive in headers.
 * They are believed only from the proxies named in TRUSTED_PROXIES, because anyone can send those headers.
 */
class TrustProxies extends Middleware
{
    protected function proxies(): array|string|null
    {
        $proxies = trim((string) config('app.trusted_proxies', ''));

        return match (true) {
            $proxies === '' => null,
            $proxies === '*' => '*',
            default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
        };
    }
}
