<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAdmin;
use App\Services\ActivityLogger;
use App\Services\PasswordPolicy;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/** A person's own account: password and two-factor sign-in. */
class AccountController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function edit(Request $request, PasswordPolicy $policy, Google2FA $google2fa): Response
    {
        $user = $request->user('admin');
        $setup = null;

        // A secret exists but is not confirmed yet: show the QR code to finish setup.
        if ($user->two_factor_secret !== null && ! $user->hasTwoFactor()) {
            $uri = $google2fa->getQRCodeUrl(config('app.name'), $user->email, $user->two_factor_secret);
            $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));
            $setup = ['secret' => $user->two_factor_secret, 'qrSvg' => $writer->writeString($uri)];
        }

        return Inertia::render('Account/Edit', [
            'passwordHint' => $policy->describe(),
            'twoFactor' => ['enabled' => $user->hasTwoFactor(), 'setup' => $setup],
            'recoveryCodes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function updatePassword(Request $request, PasswordPolicy $policy): RedirectResponse
    {
        $user = $request->user('admin');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', $policy->rule()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not correct.']);
        }

        $user->forceFill(['password' => $data['password']])->save();
        // Sign out every other session, keep this one.
        $user->invalidateSessions();
        $request->session()->put(AuthenticateAdmin::SESSION_KEY, $user->session_version);

        $this->activity->log('change_password', 'staff', $user, $user->name);

        return back()->with('success', 'Password changed. Other sessions were signed out.');
    }

    public function startTwoFactor(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $user = $request->user('admin');
        if (! $user->hasTwoFactor()) {
            $user->forceFill(['two_factor_secret' => $google2fa->generateSecretKey(32)])->save();
        }

        return back();
    }

    public function confirmTwoFactor(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $user = $request->user('admin');
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $code = preg_replace('/\s+/', '', $data['code']);

        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1
            || ! $google2fa->verifyKey($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not correct. Check the app and try again.']);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $this->activity->log('enable_two_factor', 'staff', $user, $user->name);

        return back()->with('success', 'Two-factor sign-in is on.')->with('recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user('admin');
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is not correct.']);
        }

        $wasOn = $user->hasTwoFactor();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($wasOn) {
            $this->activity->log('disable_two_factor', 'staff', $user, $user->name);
        }

        return back()->with('success', 'Two-factor sign-in is off.');
    }
}
