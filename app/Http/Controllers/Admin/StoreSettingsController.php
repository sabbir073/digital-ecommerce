<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\StoreSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Store settings: details, order numbers, checkout fields, stock rules, maintenance mode and data exports. */
class StoreSettingsController extends Controller
{
    public function __construct(private readonly StoreSettings $settings, private readonly ActivityLogger $activity) {}

    public function edit(): Response
    {
        return Inertia::render('Settings/Store', [
            'store' => $this->settings->store(),
            'checkout' => $this->settings->checkout(),
            'stock' => $this->settings->stock(),
            'maintenance' => $this->settings->maintenance(),
            'options' => [
                'currencyFormats' => StoreSettings::CURRENCY_FORMATS,
                'numberStyles' => StoreSettings::NUMBER_STYLES,
                'phone' => StoreSettings::PHONE,
                'pages' => ContentPage::query()->orderBy('title')->get(['id', 'title', 'status'])
                    ->map(fn (ContentPage $p) => ['id' => $p->id, 'label' => $p->title.($p->isPublished() ? '' : ' (draft)')]),
            ],
            'storeUrl' => url('/'),
        ]);
    }

    public function updateDetails(Request $request): RedirectResponse
    {
        $request->merge(['number_prefix' => strtoupper(trim((string) $request->input('number_prefix')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'footer_text' => ['nullable', 'string', 'max:300'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:300'],
            'delivery_text' => ['nullable', 'string', 'max:1000'],
            'returns_text' => ['nullable', 'string', 'max:1000'],
            'currency_format' => ['required', Rule::in(array_keys(StoreSettings::CURRENCY_FORMATS))],
            'number_prefix' => ['nullable', 'string', 'max:8', 'regex:/^[A-Z0-9]+-?$/'],
            'number_style' => ['required', Rule::in(array_keys(StoreSettings::NUMBER_STYLES))],
        ], [
            'name.required' => 'Enter the store name.',
            'number_prefix.regex' => 'Use letters and numbers, with an optional dash at the end, like NL-.',
        ]);

        Setting::put('store.name', $data['name']);
        Setting::put('store.tagline', $data['tagline'] ?? '');
        Setting::put('store.footer_text', $data['footer_text'] ?? '');
        Setting::put('store.contact_email', $data['contact_email'] ?? '');
        Setting::put('store.contact_phone', $data['contact_phone'] ?? '');
        Setting::put('store.address', $data['address'] ?? '');
        Setting::put('product.delivery_text', $data['delivery_text'] ?? '');
        Setting::put('product.returns_text', $data['returns_text'] ?? '');
        Setting::put('store.currency_format', $data['currency_format']);
        Setting::put('orders.number_prefix', $data['number_prefix'] ?? '');
        Setting::put('orders.number_style', $data['number_style']);
        $this->activity->log('edit', 'settings', null, 'Store details');

        return back()->with('success', 'Store details saved.');
    }

    public function updateCheckout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'guest_checkout' => ['required', 'boolean'],
            'phone' => ['required', Rule::in(array_keys(StoreSettings::PHONE))],
            'order_notes' => ['required', 'boolean'],
            'terms' => ['required', 'boolean'],
            'terms_page_id' => ['nullable', Rule::exists('content_pages', 'id')],
        ]);

        $before = $this->settings->checkout();
        Setting::put('checkout', $data);
        $this->activity->log('edit', 'settings', null, 'Checkout fields', ['from' => $before, 'to' => $data]);

        return back()->with('success', 'Checkout settings saved.');
    }

    public function updateStock(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:10000'],
            'allow_overselling' => ['required', 'boolean'],
        ]);

        $before = $this->settings->stock();
        Setting::put('inventory.low_stock_threshold', (int) $data['low_stock_threshold']);
        Setting::put('inventory.allow_overselling', (bool) $data['allow_overselling']);
        $this->activity->log('edit', 'settings', null, 'Stock rules', ['from' => $before, 'to' => $data]);

        return back()->with('success', 'Stock rules saved.');
    }

    public function updateMaintenance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::put('maintenance', ['enabled' => (bool) $data['enabled'], 'message' => trim((string) ($data['message'] ?? ''))]);
        $this->activity->log('edit', 'settings', null, 'Maintenance mode', ['enabled' => [! $data['enabled'], (bool) $data['enabled']]]);

        return back()->with('success', $data['enabled']
            ? 'Maintenance mode is on. Visitors see your message; signed-in staff still see the store.'
            : 'Maintenance mode is off. The store is open.');
    }
}
