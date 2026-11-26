<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Services\Checkout\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Integrations\Tracking;
use App\Services\Payments\PaymentGateway;
use App\Services\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly PaymentGateway $gateway,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $buyNow = $request->boolean('buy');
        $cart = $this->cart($buyNow);
        $summary = $this->carts->summary($cart);

        if ($summary->isEmpty()) {
            return redirect()->route('cart');
        }

        $user = Auth::guard('web')->user();

        return view('storefront.checkout', [
            'summary' => $summary,
            'trackEvents' => [Tracking::beginCheckout($summary)],
            'buyNow' => $buyNow,
            'countries' => config('countries'),
            'customer' => [
                'email' => $user?->email,
                'name' => $user?->name,
                'country' => $user?->country_code,
            ],
            'ready' => $ready = $this->gateway->isConfigured() || $summary->totalCents() === 0,
            'config' => [
                'key' => $this->gateway->publishableKey(),
                'ready' => $ready,
                'total' => $summary->totalCents(),
                'buy' => $buyNow,
                'quoteUrl' => route('checkout.quote'),
                'startUrl' => route('checkout.start'),
            ],
            'testMode' => $this->gateway->isConfigured() && $this->gateway->isTestMode(),
        ]);
    }

    /** Totals with tax for the chosen country, shown before the customer pays. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', Rule::in(array_keys(config('countries')))],
            'postcode' => ['nullable', 'string', 'max:20'],
        ]);

        $summary = $this->carts->summary($this->cart($request->boolean('buy')));

        try {
            $quote = $this->checkout->quote($summary, $data['country'], $data['postcode'] ?? null);
        } catch (CheckoutException $e) {
            return response()->json(['errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        return response()->json(['subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'tax' => $quote['tax'], 'total' => $quote['total']]);
    }

    /** Create (or continue) the unpaid order and hand the browser what it needs to confirm the payment. */
    public function start(Request $request): JsonResponse
    {
        $settings = app(StoreSettings::class);
        [$extraRules, $extraMessages] = $settings->checkoutRules();
        // With guest checkout switched off, a customer signs in or makes an account while paying.
        $needsAccount = ! $settings->checkout()['guest_checkout'] && ! Auth::guard('web')->check();
        if ($needsAccount) {
            $request->merge(['create_account' => true]);
        }
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', Rule::in(array_keys(config('countries')))],
            'postcode' => [Rule::requiredIf(fn () => in_array($request->input('country'), ['US', 'CA'], true)), 'nullable', 'string', 'max:20'],
            'create_account' => ['boolean'],
            'password' => [Rule::requiredIf(fn () => $request->boolean('create_account') && ! Auth::guard('web')->check()), 'nullable', Password::min(8)],
        ] + $extraRules, $extraMessages + [
            'email.required' => t('Enter your email so we can send your download links.'),
            'email.email' => t('That email address does not look right.'),
            'name.required' => t('Enter your name.'),
            'country.required' => t('Choose your country.'),
            'postcode.required' => t('Enter your ZIP or postal code.'),
            'password.required' => t('Choose a password for your account.'),
            'password.min' => t('Use at least 8 characters for your password.'),
        ]);

        $data['email'] = Str::lower($data['email']);

        $cart = $this->cart($request->boolean('buy'));
        if (! $cart) {
            return response()->json(['errors' => ['checkout' => [t('Your cart is empty.')]]], 422);
        }

        $user = Auth::guard('web')->user();
        if (! $user && $request->boolean('create_account')) {
            if (User::query()->where('email', $data['email'])->exists()) {
                return response()->json(['errors' => ['email' => [t('An account with this email already exists. Sign in, or untick "Create an account".')]]], 422);
            }
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            Auth::login($user);
        }
        if ($user && ! $user->country_code) {
            $user->forceFill(['country_code' => $data['country']])->save();
        }

        try {
            $result = $this->checkout->start($cart, [
                'email' => $data['email'],
                'name' => $data['name'],
                'country' => $data['country'],
                'postcode' => $data['postcode'] ?? null,
            ], ['source' => 'cart', 'user_id' => $user?->id, 'ip' => $request->ip()]);
        } catch (CheckoutException $e) {
            return response()->json(['errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        $order = $result['order'];
        $order->forceFill($settings->checkoutExtras($data))->save();

        return response()->json([
            'client_secret' => $result['client_secret'],
            'paid' => $result['paid'],
            'total' => (int) $order->total_cents,
            'tax' => (int) $order->tax_cents,
            'order_url' => $order->url(),
        ]);
    }

    private function cart(bool $buyNow): ?Cart
    {
        return $buyNow ? $this->carts->buyNowCart() : $this->carts->current();
    }
}
