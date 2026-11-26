<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Checkout\StockService;
use App\Services\Payments\StripeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
    public function __construct(private StripeSettings $stripe, private ActivityLogger $activity) {}

    public function edit(StockService $stock): Response
    {
        $mask = fn (?string $value) => $value ? substr($value, 0, 8).'…'.substr($value, -4) : null;

        return Inertia::render('Settings/Payments', [
            'mode' => $this->stripe->mode(),
            // Keys are never sent back in full, only enough to recognise which one is saved.
            'keys' => collect(StripeSettings::MODES)->mapWithKeys(fn (string $mode) => [$mode => [
                'publishable' => $mask($this->stripe->publishableKey($mode)),
                'secret' => $this->stripe->secretKey($mode) ? 'Saved' : null,
                'webhookSecret' => $this->stripe->webhookSecret($mode) ? 'Saved' : null,
            ]]),
            'webhookUrl' => route('stripe.webhook'),
            'taxEnabled' => $this->stripe->taxEnabled(),
            'allowOverselling' => $stock->oversellingAllowed(),
            'canEditKeys' => (bool) Auth::guard('admin')->user()?->isSuper(),
        ]);
    }

    /** Stripe keys and mode. Reserved for Super Admins in code, not in the permission matrix. */
    public function updateKeys(Request $request): RedirectResponse
    {
        abort_unless(Auth::guard('admin')->user()?->isSuper(), 403);

        $data = $request->validate([
            'mode' => ['required', Rule::in(StripeSettings::MODES)],
            'test.publishable' => ['nullable', 'string', 'max:255', 'starts_with:pk_test_'],
            'test.secret' => ['nullable', 'string', 'max:255', 'starts_with:sk_test_,rk_test_'],
            'test.webhook_secret' => ['nullable', 'string', 'max:255', 'starts_with:whsec_'],
            'live.publishable' => ['nullable', 'string', 'max:255', 'starts_with:pk_live_'],
            'live.secret' => ['nullable', 'string', 'max:255', 'starts_with:sk_live_,rk_live_'],
            'live.webhook_secret' => ['nullable', 'string', 'max:255', 'starts_with:whsec_'],
        ], [
            'test.publishable.starts_with' => 'A test publishable key starts with pk_test_.',
            'test.secret.starts_with' => 'A test secret key starts with sk_test_.',
            'live.publishable.starts_with' => 'A live publishable key starts with pk_live_.',
            'live.secret.starts_with' => 'A live secret key starts with sk_live_.',
            'test.webhook_secret.starts_with' => 'A webhook signing secret starts with whsec_.',
            'live.webhook_secret.starts_with' => 'A webhook signing secret starts with whsec_.',
        ]);

        foreach (StripeSettings::MODES as $mode) {
            $this->stripe->save($mode, $data[$mode] ?? []);
        }

        if ($data['mode'] === 'live' && ! ($this->stripe->publishableKey('live') && $this->stripe->secretKey('live') && $this->stripe->webhookSecret('live'))) {
            return back()->withErrors(['mode' => 'Save the live publishable key, secret key and webhook secret before switching to live.']);
        }

        $before = $this->stripe->mode();
        Setting::put('stripe.mode', $data['mode']);
        // Key values are never written to the activity log.
        $this->activity->log('edit', 'settings', null, 'Stripe keys', $before !== $data['mode'] ? ['mode' => [$before, $data['mode']]] : []);

        return back()->with('success', 'Payment keys saved.');
    }

    public function updateOptions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_enabled' => ['required', 'boolean'],
            'allow_overselling' => ['required', 'boolean'],
        ]);

        $changes = [];
        if ((bool) Setting::get('tax.stripe_tax', true) !== $data['tax_enabled']) {
            $changes['stripe_tax'] = [! $data['tax_enabled'], $data['tax_enabled']];
        }
        if ((bool) Setting::get('inventory.allow_overselling', false) !== $data['allow_overselling']) {
            $changes['allow_overselling'] = [! $data['allow_overselling'], $data['allow_overselling']];
        }
        Setting::put('tax.stripe_tax', $data['tax_enabled']);
        Setting::put('inventory.allow_overselling', $data['allow_overselling']);
        $this->activity->log('edit', 'settings', null, 'Checkout options', $changes);

        return back()->with('success', 'Checkout options saved.');
    }
}
