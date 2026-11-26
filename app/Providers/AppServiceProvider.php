<?php

namespace App\Providers;

use App\Models\Order;
use App\Services\Integrations\Consent;
use App\Services\Integrations\MailSettings;
use App\Services\Integrations\Tracking;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeGateway;
use App\View\Composers\StorefrontComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
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
        // In the page builder, text and images carry the name of the field they edit. Customers never get these attributes.
        Blade::directive('edit', fn (string $path) => "<?php if (\$builder ?? false) { echo ' data-edit=\"'.e($path).'\"'; } ?>");
        Blade::directive('editimage', fn (string $path) => "<?php if (\$builder ?? false) { echo ' data-edit-image=\"'.e($path).'\"'; } ?>");

        View::composer(['storefront.*', 'selling.*'], StorefrontComposer::class);

        // Email goes out through the account saved in Admin > Integrations > Email, when there is one.
        app(MailSettings::class)->apply();

        // An order remembers what the buyer's browser allowed, so the Purchase event sent after payment respects it.
        Order::creating(function (Order $order) {
            if ($order->tracking === null && (! app()->runningInConsole() || app()->runningUnitTests())) {
                $order->tracking = Tracking::visitor(request(), app(Consent::class));
            }
        });

        // Each limit has its own counter. (Plain "throttle:10,1" shares one counter
        // per visitor across every route, so adding to the cart would eat into coupon attempts.)
        $limits = ['cart' => 60, 'coupon' => 10, 'quote' => 30, 'checkout' => 12, 'download' => 30, 'sign-in' => 10, 'password' => 5, 'review' => 10, 'review-vote' => 30, 'chat-poll' => 60, 'chat-start' => 5, 'chat-send' => 20, 'contact' => 5, 'events' => 120, 'consent' => 10];
        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }
    }
}
