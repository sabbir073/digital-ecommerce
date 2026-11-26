<?php

namespace App\View\Composers;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Setting;
use App\Services\Chat\ChatSettings;
use App\Services\Checkout\CartService;
use App\Services\Content\Navigation;
use App\Services\Integrations\CustomScripts;
use App\Services\Seo\SeoBuilder;
use App\Services\StoreSettings;
use App\Services\UiText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Data every storefront page needs for the shared header and footer. */
class StorefrontComposer
{
    /** @var array<string, mixed>|null */
    private ?array $shared = null;

    private ?object $sharedFor = null;

    public function __construct(private readonly Navigation $navigation, private readonly StoreSettings $settings) {}

    /**
     * The header menu built in admin. Until one is built, the top-level categories are the menu.
     *
     * @return list<array{label:string, url:string, children:list<array{label:string, url:string}>}>
     */
    private function headerLinks(): array
    {
        $links = $this->navigation->items('header');
        if ($links !== []) {
            return $links;
        }

        return Category::query()->whereNull('parent_id')->orderBy('position')->get(['id', 'name', 'slug'])
            ->map(fn (Category $c) => ['label' => $c->name, 'url' => url('/c/'.$c->slug), 'children' => []])->all();
    }

    /**
     * The composer runs for every storefront view, partials included, so the
     * data is built once per request and reused.
     */
    public function compose(View $view): void
    {
        $request = request();
        if ($this->shared === null || $this->sharedFor !== $request) {
            $this->shared = $this->shared();
            $this->sharedFor = $request;
        }

        $view->with($this->shared);
    }

    /**
     * Site-wide verification tags and code from SEO settings, plus the custom scripts for this address.
     *
     * @return array{verification:string, head:string, body_start:string, body_end:string}
     */
    private function siteCode(): array
    {
        $site = app(SeoBuilder::class)->site();
        foreach (app(CustomScripts::class)->for(request()) as $placement => $code) {
            if ($code !== '') {
                $site[$placement] = trim($site[$placement]."\n".$code);
            }
        }

        return $site;
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'storeName' => Setting::get('store.name', config('app.name')),
            'footerText' => Setting::get('store.footer_text'),
            'announcement' => $this->announcement(),
            'navLinks' => $this->headerLinks(),
            'footerMenus' => collect(['footer_1', 'footer_2', 'footer_3'])
                ->map(fn (string $location) => ['name' => $this->navigation->name($location), 'items' => $this->navigation->items($location)])
                ->filter(fn (array $menu) => $menu['items'] !== [])->values(),
            'cartCount' => $this->cartCount(),
            'theme' => $this->settings->storefrontTheme(),
            'seoSite' => $this->siteCode(),
            'currencyFormat' => StoreSettings::currencyFormat(),
            'uiText' => UiText::forScripts(),
            'storeContact' => array_filter([
                'email' => (string) Setting::get('store.contact_email', ''),
                'phone' => (string) Setting::get('store.contact_phone', ''),
                'address' => (string) Setting::get('store.address', ''),
            ]),
            'checkoutFields' => $this->settings->checkoutFields(),
            'chat' => $this->chat(),
        ];
    }

    /**
     * What the chat widget needs, or null when chat is switched off.
     *
     * @return array{welcome:string, away:string, onSellingPages:bool, name:?string, email:?string}|null
     */
    private function chat(): ?array
    {
        $settings = app(ChatSettings::class)->all();
        if (! $settings['enabled']) {
            return null;
        }
        $user = Auth::guard('web')->user();

        return ['welcome' => $settings['welcome'], 'away' => $settings['away'], 'onSellingPages' => (bool) $settings['on_selling_pages'], 'name' => $user?->name, 'email' => $user?->email];
    }

    private function cartCount(): int
    {
        $token = request()->cookie(CartService::COOKIE);

        return is_string($token) && $token !== ''
            ? (int) CartItem::query()->whereHas('cart', fn ($q) => $q->where('token', $token))->sum('quantity')
            : 0;
    }

    /** @return array{text:string, link:?string}|null */
    private function announcement(): ?array
    {
        $bar = Setting::get('announcement');
        if (! is_array($bar) || empty($bar['enabled']) || empty($bar['text'])) {
            return null;
        }

        $now = Carbon::now();
        if (! empty($bar['starts_at']) && $now->lt(Carbon::parse($bar['starts_at']))) {
            return null;
        }
        if (! empty($bar['ends_at']) && $now->gt(Carbon::parse($bar['ends_at']))) {
            return null;
        }

        return ['text' => $bar['text'], 'link' => $bar['link'] ?? null];
    }
}
