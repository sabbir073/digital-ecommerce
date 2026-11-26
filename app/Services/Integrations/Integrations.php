<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\SellingPage;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The tracking services the store knows, what each needs from the owner, and their saved setup.
 * Adding a service means adding it here and writing its adapter; storefront code is not touched.
 */
class Integrations
{
    private const CACHE = 'integrations.all';

    /**
     * category: which cookie choice the service waits for. server: it also receives events from the server.
     * A field marked secret is never sent back to the browser, in admin or on the storefront.
     */
    public const SERVICES = [
        'gtm' => ['name' => 'Google Tag Manager', 'category' => 'marketing', 'about' => 'Loads your container on every page and pushes every store event into the data layer.', 'fields' => [
            'container_id' => ['label' => 'Container ID', 'hint' => 'Starts with GTM-', 'pattern' => '/^GTM-[A-Z0-9]{4,12}$/', 'required' => true],
        ]],
        'ga4' => ['name' => 'Google Analytics 4', 'category' => 'analytics', 'about' => 'Sends page views and ecommerce events.', 'fields' => [
            'measurement_id' => ['label' => 'Measurement ID', 'hint' => 'Starts with G-', 'pattern' => '/^G-[A-Z0-9]{6,14}$/', 'required' => true],
        ]],
        'google_ads' => ['name' => 'Google Ads', 'category' => 'marketing', 'about' => 'Sends conversions with value and currency, and builds remarketing audiences.', 'fields' => [
            'conversion_id' => ['label' => 'Conversion ID', 'hint' => 'Starts with AW-', 'pattern' => '/^AW-[0-9]{6,14}$/', 'required' => true],
            'purchase_label' => ['label' => 'Conversion label: purchase', 'pattern' => '/^[A-Za-z0-9_-]{4,40}$/'],
            'add_to_cart_label' => ['label' => 'Conversion label: add to cart', 'pattern' => '/^[A-Za-z0-9_-]{4,40}$/'],
            'begin_checkout_label' => ['label' => 'Conversion label: begin checkout', 'pattern' => '/^[A-Za-z0-9_-]{4,40}$/'],
            'sign_up_label' => ['label' => 'Conversion label: sign up', 'pattern' => '/^[A-Za-z0-9_-]{4,40}$/'],
            'enhanced_conversions' => ['label' => 'Enhanced conversions', 'type' => 'switch', 'hint' => 'Sends the buyer\'s email, hashed in the browser, with the purchase conversion. Used only when the buyer allowed marketing cookies.'],
        ]],
        'meta_pixel' => ['name' => 'Meta Pixel', 'category' => 'marketing', 'about' => 'Sends browser events to Facebook and Instagram ads.', 'fields' => [
            'pixel_id' => ['label' => 'Pixel ID', 'hint' => 'Numbers only', 'pattern' => '/^[0-9]{8,20}$/', 'required' => true],
        ]],
        'meta_capi' => ['name' => 'Meta Conversions API', 'category' => 'marketing', 'server' => true, 'needs' => 'meta_pixel', 'about' => 'Sends the same events from the server. Each one carries the same event ID as the browser event, so Meta counts it once.', 'fields' => [
            'access_token' => ['label' => 'Access token', 'secret' => true, 'required' => true, 'max' => 500],
            'test_event_code' => ['label' => 'Test event code', 'hint' => 'Optional. From Events Manager, Test events. Remove it when you go live.', 'pattern' => '/^[A-Za-z0-9]{4,20}$/'],
        ]],
        'tiktok' => ['name' => 'TikTok Pixel', 'category' => 'marketing', 'server' => true, 'about' => 'Sends browser events, and server events as well when an access token is set.', 'fields' => [
            'pixel_id' => ['label' => 'Pixel ID', 'pattern' => '/^[A-Z0-9]{10,30}$/', 'required' => true],
            'access_token' => ['label' => 'Events API access token', 'secret' => true, 'hint' => 'Optional. Turns on server events.', 'max' => 500],
            'test_event_code' => ['label' => 'Test event code', 'hint' => 'Optional. From Events Manager, Test events.', 'pattern' => '/^[A-Za-z0-9]{4,20}$/'],
        ]],
        'pinterest' => ['name' => 'Pinterest tag', 'category' => 'marketing', 'about' => 'Sends page view, add to cart and purchase.', 'fields' => [
            'tag_id' => ['label' => 'Tag ID', 'hint' => 'Numbers only', 'pattern' => '/^[0-9]{8,20}$/', 'required' => true],
        ]],
        'snapchat' => ['name' => 'Snapchat Pixel', 'category' => 'marketing', 'about' => 'Sends page view, add to cart and purchase.', 'fields' => [
            'pixel_id' => ['label' => 'Pixel ID', 'pattern' => '/^[a-f0-9-]{20,40}$/', 'required' => true],
        ]],
        'microsoft_ads' => ['name' => 'Microsoft Ads (UET tag)', 'category' => 'marketing', 'about' => 'Sends page view, add to cart and purchase.', 'fields' => [
            'tag_id' => ['label' => 'UET tag ID', 'hint' => 'Numbers only', 'pattern' => '/^[0-9]{6,12}$/', 'required' => true],
        ]],
    ];

    /** The services a selling page may add its own extra ID for, and the field that holds it. */
    public const PAGE_PIXELS = ['ga4' => 'measurement_id', 'meta_pixel' => 'pixel_id', 'tiktok' => 'pixel_id', 'pinterest' => 'tag_id', 'snapchat' => 'pixel_id'];

    /**
     * Saved setup of every service, with secrets. Cached, because every storefront page asks.
     *
     * @return array<string, array{enabled:bool, settings:array<string, mixed>, status:string, last_error:?string, last_event_at:?string}>
     */
    public function all(): array
    {
        try {
            $rows = Cache::rememberForever(self::CACHE, fn () => Integration::query()->get()->mapWithKeys(fn (Integration $i) => [$i->service => [
                'enabled' => $i->enabled, 'settings' => (array) $i->settings, 'status' => $i->status, 'last_error' => $i->last_error,
                'last_event_at' => $i->last_event_at?->toIso8601String(),
            ]])->all());
        } catch (Throwable $e) {
            $rows = []; // the table is missing while the database is being set up
        }

        $all = [];
        foreach (array_keys(self::SERVICES) as $service) {
            $all[$service] = $rows[$service] ?? ['enabled' => false, 'settings' => [], 'status' => 'off', 'last_error' => null, 'last_event_at' => null];
        }

        return $all;
    }

    /** A service counts as on only when it is switched on and what it needs is filled in. */
    public function active(string $service): bool
    {
        $all = $this->all();
        $row = $all[$service] ?? null;
        if (! $row || ! $row['enabled']) {
            return false;
        }
        foreach (self::SERVICES[$service]['fields'] as $key => $field) {
            if (($field['required'] ?? false) && trim((string) ($row['settings'][$key] ?? '')) === '') {
                return false;
            }
        }
        $needs = self::SERVICES[$service]['needs'] ?? null;

        return $needs === null || $this->active($needs);
    }

    public function setting(string $service, string $key): string
    {
        return trim((string) ($this->all()[$service]['settings'][$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $settings  already validated; an empty secret keeps the saved one
     */
    public function save(string $service, array $settings, bool $enabled): Integration
    {
        $row = Integration::query()->firstOrNew(['service' => $service]);
        $saved = (array) $row->settings;
        $clean = [];
        foreach (self::SERVICES[$service]['fields'] as $key => $field) {
            $value = $settings[$key] ?? null;
            if (($field['type'] ?? 'text') === 'switch') {
                $clean[$key] = (bool) $value;
            } elseif (($field['secret'] ?? false) && trim((string) $value) === '') {
                $clean[$key] = $saved[$key] ?? '';
            } else {
                $clean[$key] = trim((string) $value);
            }
        }
        $row->fill(['settings' => $clean, 'enabled' => $enabled, 'status' => $enabled ? 'on' : 'off', 'last_error' => null])->save();
        self::flush();

        return $row;
    }

    public function forgetSecret(string $service, string $key): void
    {
        $row = Integration::query()->where('service', $service)->first();
        if ($row) {
            $row->update(['settings' => [$key => ''] + (array) $row->settings]);
            self::flush();
        }
    }

    /** Note that an event went out, or why it did not. Shown on the service's card. */
    public function report(string $service, bool $ok, ?string $error = null): void
    {
        try {
            Integration::query()->where('service', $service)->update($ok
                ? ['status' => 'on', 'last_error' => null, 'last_event_at' => now()]
                : ['status' => 'error', 'last_error' => mb_substr((string) $error, 0, 500)]);
            self::flush();
        } catch (Throwable $e) {
            // Reporting must never break a page or a webhook.
        }
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * What the browser needs to load the tags: public IDs only, never a secret.
     *
     * @return array<string, array<string, mixed>>
     */
    public function browser(?SellingPage $page = null): array
    {
        $all = $this->all();
        $out = [];
        foreach (self::SERVICES as $service => $definition) {
            if ($service === 'meta_capi' || ! $this->active($service)) {
                continue;
            }
            $public = [];
            foreach ($definition['fields'] as $key => $field) {
                if (! ($field['secret'] ?? false) && $key !== 'test_event_code') {
                    $public[$key] = $all[$service]['settings'][$key] ?? '';
                }
            }
            $out[$service] = ['category' => $definition['category'], 'ids' => []] + $public;
        }

        // A selling page can add its own IDs on top of the site-wide ones.
        foreach (self::PAGE_PIXELS as $service => $field) {
            $extra = trim((string) ($page?->pixel_ids[$service] ?? ''));
            $pattern = self::SERVICES[$service]['fields'][$field]['pattern'];
            if ($extra === '' || ! preg_match($pattern, $extra)) {
                continue;
            }
            $out[$service] ??= ['category' => self::SERVICES[$service]['category'], 'ids' => [], $field => ''];
            if ($extra !== $out[$service][$field]) {
                $out[$service]['ids'][] = $extra;
            }
        }
        foreach ($out as $service => $config) {
            $field = self::PAGE_PIXELS[$service] ?? null;
            if ($field) {
                $out[$service]['ids'] = array_values(array_unique(array_filter(array_merge([$config[$field]], $config['ids']))));
            }
        }

        return $out;
    }

    /** True when some service wants events from the server too. */
    public function hasServerSide(): bool
    {
        return $this->active('meta_capi') || ($this->active('tiktok') && $this->setting('tiktok', 'access_token') !== '');
    }
}
