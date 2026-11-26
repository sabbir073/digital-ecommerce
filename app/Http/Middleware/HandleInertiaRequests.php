<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\Payments\StripeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'admin';

    public function share(Request $request): array
    {
        $user = Auth::guard('admin')->user();

        return [
            ...parent::share($request),
            'store' => ['name' => Setting::get('store.name', config('app.name'))],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->name,
                    'isSuper' => $user->isSuper(),
                    'hasTwoFactor' => $user->hasTwoFactor(),
                ] : null,
                // Used only to hide menu items and buttons. Every request is
                // checked again on the server by the `permission` middleware.
                'permissions' => $user && $user->role ? $user->role->permissionMap() : (object) [],
            ],
            // Drives the "Test mode" banner shown on every admin page (PRD section 6).
            'payments' => fn () => $user ? ['testMode' => app(StripeSettings::class)->isConfigured() && app(StripeSettings::class)->mode() === 'test'] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
