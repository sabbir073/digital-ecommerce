<?php

namespace App\Providers;

use App\View\Composers\StorefrontComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('storefront.*', StorefrontComposer::class);
    }
}
