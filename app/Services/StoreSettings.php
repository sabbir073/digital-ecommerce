<?php

namespace App\Services;

use App\Models\ContentPage;
use App\Models\Media;
use App\Models\Setting;
use App\Support\Theme;

/** The store's settings with their defaults, read in one place so admin and storefront agree. */
class StoreSettings
{
    public const CURRENCY_FORMATS = ['symbol' => '$1,234.50', 'us_symbol' => 'US$1,234.50', 'code' => '1,234.50 USD'];

    public const NUMBER_STYLES = ['date_code' => 'Date and code, like 261126-K7M2QX', 'code' => 'Code only, like K7M2QXHD'];

    public const PHONE = ['hidden' => 'Do not ask', 'optional' => 'Ask, optional', 'required' => 'Ask, required'];

    /** @return array{accent:string, heading_font:string, body_font:string, density:string, card_ratings:bool, card_compare:bool, card_badges:bool, logo_media_id:?int, favicon_media_id:?int, socials:list<array{network:string, url:string}>} */
    public function theme(): array
    {
        $saved = (array) Setting::get('theme', []);
        $accent = Theme::hex($saved['accent'] ?? '') ?? Theme::DEFAULT_ACCENT;

        return [
            'accent' => Theme::accentPasses($accent) ? $accent : Theme::DEFAULT_ACCENT,
            'heading_font' => isset(Theme::FONTS[$saved['heading_font'] ?? '']) ? $saved['heading_font'] : 'space-grotesk',
            'body_font' => isset(Theme::FONTS[$saved['body_font'] ?? '']) ? $saved['body_font'] : 'inter',
            'density' => isset(Theme::DENSITIES[$saved['density'] ?? '']) ? $saved['density'] : 'standard',
            'card_ratings' => (bool) ($saved['card_ratings'] ?? true),
            'card_compare' => (bool) ($saved['card_compare'] ?? true),
            'card_badges' => (bool) ($saved['card_badges'] ?? true),
            'logo_media_id' => isset($saved['logo_media_id']) ? (int) $saved['logo_media_id'] : null,
            'favicon_media_id' => isset($saved['favicon_media_id']) ? (int) $saved['favicon_media_id'] : null,
            'socials' => array_values(array_filter((array) ($saved['socials'] ?? []), fn ($s) => isset(Theme::SOCIALS[$s['network'] ?? '']) && ! empty($s['url']))),
        ];
    }

    /**
     * What the storefront layout needs from the theme, ready to print.
     *
     * @return array<string, mixed>
     */
    public function storefrontTheme(): array
    {
        $theme = $this->theme();
        $media = Media::query()->whereIn('id', array_filter([$theme['logo_media_id'], $theme['favicon_media_id']]))->get()->keyBy('id');
        $palette = Theme::palette($theme['accent']);
        $vars = [];
        if ($theme['accent'] !== Theme::DEFAULT_ACCENT) {
            $vars += ['--color-accent' => $palette['accent'], '--color-accent-tint' => $palette['tint'], '--color-accent-tint-text' => $palette['tintText']];
        }
        if ($theme['heading_font'] !== 'space-grotesk') {
            $vars['--font-heading'] = Theme::FONTS[$theme['heading_font']]['stack'];
        }
        if ($theme['body_font'] !== 'inter') {
            $vars['--font-body'] = Theme::FONTS[$theme['body_font']]['stack'];
        }
        $logo = $media->get($theme['logo_media_id']);
        $favicon = $media->get($theme['favicon_media_id']);

        return [
            'css' => $vars === [] ? '' : ':root{'.implode(';', array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($vars), $vars)).'}',
            'fontFiles' => array_values(array_unique(array_filter([Theme::FONTS[$theme['heading_font']]['file'], Theme::FONTS[$theme['body_font']]['file']]))),
            'logo' => $logo ? ['url' => $logo->sizeUrl(320), 'width' => $logo->width, 'height' => $logo->height] : null,
            'favicon' => $favicon?->sizeUrl(64),
            'density' => $theme['density'],
            'cards' => ['ratings' => $theme['card_ratings'], 'compare' => $theme['card_compare'], 'badges' => $theme['card_badges']],
            'socials' => array_map(fn ($s) => ['label' => Theme::SOCIALS[$s['network']], 'url' => $s['url']], $theme['socials']),
        ];
    }

    /** @return array{name:string, tagline:string, footer_text:string, contact_email:string, contact_phone:string, address:string, currency_format:string, number_prefix:string, number_style:string} */
    public function store(): array
    {
        return [
            'name' => (string) Setting::get('store.name', config('app.name')),
            'tagline' => (string) Setting::get('store.tagline', ''),
            'footer_text' => (string) Setting::get('store.footer_text', ''),
            'contact_email' => (string) Setting::get('store.contact_email', ''),
            'contact_phone' => (string) Setting::get('store.contact_phone', ''),
            'address' => (string) Setting::get('store.address', ''),
            'currency_format' => self::currencyFormat(),
            'number_prefix' => (string) Setting::get('orders.number_prefix', ''),
            'number_style' => isset(self::NUMBER_STYLES[Setting::get('orders.number_style')]) ? Setting::get('orders.number_style') : 'date_code',
        ];
    }

    public static function currencyFormat(): string
    {
        $format = Setting::get('store.currency_format');

        return isset(self::CURRENCY_FORMATS[$format]) ? $format : 'symbol';
    }

    /** @return array{guest_checkout:bool, phone:string, order_notes:bool, terms:bool, terms_page_id:?int} */
    public function checkout(): array
    {
        $saved = (array) Setting::get('checkout', []);

        return [
            'guest_checkout' => (bool) ($saved['guest_checkout'] ?? true),
            'phone' => isset(self::PHONE[$saved['phone'] ?? '']) ? $saved['phone'] : 'hidden',
            'order_notes' => (bool) ($saved['order_notes'] ?? false),
            'terms' => (bool) ($saved['terms'] ?? false),
            'terms_page_id' => isset($saved['terms_page_id']) ? (int) $saved['terms_page_id'] : null,
        ];
    }

    /**
     * The extra checkout fields as a form shows them. The terms link is left out while its page is unpublished.
     *
     * @return array{phone:string, order_notes:bool, terms:bool, terms_url:?string, guest_checkout:bool}
     */
    public function checkoutFields(): array
    {
        $checkout = $this->checkout();
        $page = $checkout['terms'] && $checkout['terms_page_id'] ? ContentPage::query()->published()->find($checkout['terms_page_id']) : null;

        return [
            'phone' => $checkout['phone'],
            'order_notes' => $checkout['order_notes'],
            'terms' => $checkout['terms'],
            'terms_url' => $page?->url(),
            'guest_checkout' => $checkout['guest_checkout'],
        ];
    }

    /**
     * Validation for the extra checkout fields, shared by the store checkout and the selling page.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function checkoutRules(): array
    {
        $checkout = $this->checkout();
        $rules = [];
        if ($checkout['phone'] !== 'hidden') {
            $rules['phone'] = [$checkout['phone'] === 'required' ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{6,40}$/'];
        }
        if ($checkout['order_notes']) {
            $rules['customer_note'] = ['nullable', 'string', 'max:1000'];
        }
        if ($checkout['terms']) {
            $rules['terms'] = ['accepted'];
        }

        return [$rules, [
            'phone.required' => t('Enter your phone number.'),
            'phone.regex' => t('That phone number does not look right.'),
            'terms.accepted' => t('Tick the box to accept the terms.'),
        ]];
    }

    /**
     * What to save on the order from the validated extra fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function checkoutExtras(array $data): array
    {
        $checkout = $this->checkout();

        return [
            'phone' => $checkout['phone'] !== 'hidden' ? (trim((string) ($data['phone'] ?? '')) ?: null) : null,
            'customer_note' => $checkout['order_notes'] ? (trim((string) ($data['customer_note'] ?? '')) ?: null) : null,
            'terms_accepted_at' => $checkout['terms'] ? now() : null,
        ];
    }

    /** @return array{enabled:bool, message:string} */
    public function maintenance(): array
    {
        $saved = (array) Setting::get('maintenance', []);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'message' => (string) ($saved['message'] ?? ''),
        ];
    }

    /** @return array{low_stock_threshold:int, allow_overselling:bool} */
    public function stock(): array
    {
        return [
            'low_stock_threshold' => (int) Setting::get('inventory.low_stock_threshold', 5),
            'allow_overselling' => (bool) Setting::get('inventory.allow_overselling', false),
        ];
    }
}
