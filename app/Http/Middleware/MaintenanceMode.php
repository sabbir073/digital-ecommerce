<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\StoreSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode from Settings, Store: visitors see a short message, signed-in staff see the store.
 * Admin, Stripe's webhook and links customers already hold (orders, downloads) keep working.
 */
class MaintenanceMode
{
    private const OPEN = ['admin', 'admin/*', 'stripe/webhook', 'up', 'order/*', 'download/*', 'downloads/*'];

    public function __construct(private readonly StoreSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->settings->maintenance();
        if (! $maintenance['enabled'] || $request->is(...self::OPEN) || Auth::guard('admin')->check()) {
            return $next($request);
        }

        $message = $maintenance['message'] !== '' ? $maintenance['message'] : 'We are making a few changes and will be back shortly.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503, ['Retry-After' => '3600']);
        }

        return response()->view('storefront.maintenance', [
            'storeName' => Setting::get('store.name', config('app.name')),
            'message' => $message,
            'theme' => $this->settings->storefrontTheme(),
        ], 503, ['Retry-After' => '3600', 'Cache-Control' => 'no-store']);
    }
}
