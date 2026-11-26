<?php

namespace App\Providers;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeGateway;
use App\View\Composers\StorefrontComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorefrontComposer::class);
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
    }

    public function boot(): void
    {
        View::composer(['storefront.*', 'selling.*'], StorefrontComposer::class);

        // Each limit has its own counter. (Plain "throttle:10,1" shares one counter
        // per visitor across every route, so adding to the cart would eat into coupon attempts.)
        $limits = ['cart' => 60, 'coupon' => 10, 'quote' => 30, 'checkout' => 12, 'download' => 30, 'sign-in' => 10, 'password' => 5];
        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }
    }
}
