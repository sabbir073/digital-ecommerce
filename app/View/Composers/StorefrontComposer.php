<?php

namespace App\View\Composers;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Setting;
use App\Services\Chat\ChatSettings;
use App\Services\Checkout\CartService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Data every storefront page needs for the shared header and footer. */
class StorefrontComposer
{
    /** @var array<string, mixed>|null */
    private ?array $shared = null;

    private ?object $sharedFor = null;

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

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'storeName' => Setting::get('store.name', config('app.name')),
            'footerText' => Setting::get('store.footer_text'),
            'announcement' => $this->announcement(),
            'navCategories' => Category::query()->whereNull('parent_id')->orderBy('position')->get(['id', 'name', 'slug']),
            'footerMenus' => Menu::query()->where('location', 'like', 'footer%')->with('items')->orderBy('location')->get(),
            'cartCount' => $this->cartCount(),
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
