<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Customer accounts: sign up, sign in, password reset and order history (PRD section 3). */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Orders placed while signed in. Guest orders with the same email are added only once the
        // address is verified, so nobody can claim another person's orders by registering their email.
        $orders = Order::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->email_verified_at !== null, fn ($w) => $w->orWhere('email', Str::lower($user->email))))
            ->whereNotIn('status', [Order::CANCELLED])
            ->with(['items.downloads.asset'])
            ->latest('id')
            ->paginate(10);

        return view('storefront.account.index', ['user' => $user, 'orders' => $orders]);
    }

    public function showLogin(): View
    {
        return view('storefront.account.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], ['email.required' => 'Enter your email.', 'password.required' => 'Enter your password.']);

        $data['email'] = Str::lower($data['email']);

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'That email and password do not match.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('account'));
    }

    public function showRegister(): View
    {
        return view('storefront.account.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)],
        ], [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email.',
            'email.unique' => 'An account with this email already exists. Sign in instead.',
            'password.required' => 'Choose a password.',
            'password.min' => 'Use at least 8 characters.',
        ]);

        $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account')->with('success', 'Your account is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    public function showForgot(): View
    {
        return view('storefront.account.forgot');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], ['email.required' => 'Enter your email.']);
        Password::sendResetLink(['email' => Str::lower((string) $request->input('email'))]);

        // Same answer whether or not the address has an account, so nobody can probe for accounts.
        return back()->with('success', 'If that email has an account, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('storefront.account.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'Use at least 8 characters.',
        ]);

        $data['email'] = Str::lower($data['email']);

        $status = Password::reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is not valid any more. Ask for a new one.']);
        }

        return redirect()->route('login')->with('success', 'Your password was changed. Sign in with the new one.');
    }
}
