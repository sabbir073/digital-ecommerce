<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Data every storefront page needs for the shared header and footer. */
class StorefrontComposer
{
    public function compose(View $view): void
    {
        $view->with([
            'storeName' => Setting::get('store.name', config('app.name')),
            'footerText' => Setting::get('store.footer_text'),
            'announcement' => $this->announcement(),
            'navCategories' => Category::query()->whereNull('parent_id')->orderBy('position')->get(['id', 'name', 'slug']),
            'footerMenus' => Menu::query()->where('location', 'like', 'footer%')->with('items')->orderBy('location')->get(),
        ]);
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
