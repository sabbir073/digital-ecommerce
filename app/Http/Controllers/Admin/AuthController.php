<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAdmin;
use App\Models\AdminUser;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const PENDING_2FA = 'admin_2fa_pending';

    public function __construct(private ActivityLogger $activity) {}

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $key = 'admin-login:'.Str::lower($data['email']).'|'.$request->ip();
        $this->ensureNotLocked($key, 'email');

        $user = AdminUser::query()->where('email', $data['email'])->first();

        if (! $user || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is not correct.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is not active. Ask an administrator for access.']);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->put(self::PENDING_2FA, ['id' => $user->id, 'remember' => (bool) ($data['remember'] ?? false)]);

            return redirect()->route('admin.two-factor');
        }

        return $this->completeLogin($request, $user, (bool) ($data['remember'] ?? false));
    }

    public function showTwoFactor(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has(self::PENDING_2FA)) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Auth/TwoFactor');
    }

    public function twoFactor(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING_2FA);
        $user = $pending ? AdminUser::query()->find($pending['id']) : null;

        if (! $user || ! $user->isActive() || ! $user->hasTwoFactor()) {
            $request->session()->forget(self::PENDING_2FA);

            return redirect()->route('admin.login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $key = 'admin-2fa:'.$user->id.'|'.$request->ip();
        $this->ensureNotLocked($key, 'code');

        $code = preg_replace('/\s+/', '', $data['code']);
        $ok = preg_match('/^\d{6}$/', $code) === 1 && $google2fa->verifyKey($user->two_factor_secret, $code);

        if (! $ok) {
            // A recovery code works once, then is removed.
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search($code, $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $ok = true;
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'That code is not correct. Try again.']);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING_2FA);

        return $this->completeLogin($request, $user, (bool) $pending['remember']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::guard('admin')->user();
        if ($user) {
            $this->activity->log('sign_out', 'staff', $user, $user->name, [], $user);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function completeLogin(Request $request, AdminUser $user, bool $remember): RedirectResponse
    {
        Auth::guard('admin')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put(AuthenticateAdmin::SESSION_KEY, $user->session_version);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->activity->log('sign_in', 'staff', $user, $user->name, [], $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function ensureNotLocked(string $key, string $field): void
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                $field => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }
    }
}
