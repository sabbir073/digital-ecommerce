<?php

namespace App\Services\Content;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\SellingPage;
use Illuminate\Support\Collection;

/**
 * The store's menus. A link points at a category, a content page, a selling page, the blog or any
 * address; links to things that were deleted or unpublished are left out instead of breaking.
 */
class Navigation
{
    public const LOCATIONS = [
        'header' => 'Header menu',
        'footer_1' => 'Footer column 1',
        'footer_2' => 'Footer column 2',
        'footer_3' => 'Footer column 3',
    ];

    /** @var array<string, array{name:string, items:list<array{label:string, url:string, children:list<array{label:string, url:string}>}>}>|null */
    private ?array $resolved = null;

    /** @return list<array{label:string, url:string, children:list<array{label:string, url:string}>}> */
    public function items(string $location): array
    {
        return $this->all()[$location]['items'] ?? [];
    }

    public function name(string $location): string
    {
        return $this->all()[$location]['name'] ?? self::LOCATIONS[$location];
    }

    /** @return array<string, array{name:string, items:list<array<string, mixed>>}> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $menus = Menu::query()->with('items.children')->get()->keyBy('location');
        $all = $menus->flatMap(fn (Menu $m) => $m->items->concat($m->items->flatMap->children));
        $ids = fn (string $type) => $all->where('link_type', $type)->pluck('link_id')->filter()->unique()->all();

        $targets = [
            'category' => ($c = $ids('category')) ? Category::query()->whereIn('id', $c)->pluck('slug', 'id')->map(fn ($slug) => url('/c/'.$slug)) : collect(),
            'page' => ($p = $ids('page')) ? ContentPage::query()->published()->whereIn('id', $p)->pluck('slug', 'id')->map(fn ($slug) => route('page.show', ['slug' => $slug])) : collect(),
            'selling_page' => ($s = $ids('selling_page')) ? SellingPage::query()->published()->whereIn('id', $s)->pluck('slug', 'id')->map(fn ($slug) => url('/p/'.$slug)) : collect(),
        ];

        $this->resolved = [];
        foreach ($menus as $location => $menu) {
            $this->resolved[$location] = ['name' => $menu->name, 'items' => $this->resolve($menu->items, $targets, true)];
        }

        return $this->resolved;
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @param  array<string, Collection<int, string>>  $targets
     * @return list<array<string, mixed>>
     */
    private function resolve(Collection $items, array $targets, bool $withChildren): array
    {
        $out = [];
        foreach ($items as $item) {
            $url = match ($item->link_type) {
                'url' => $item->url,
                'blog' => route('blog.index'),
                default => $targets[$item->link_type][$item->link_id] ?? null,
            };
            $children = $withChildren ? $this->resolve($item->children, $targets, false) : [];
            // A parent whose own target is gone still shows if it has working sub-links.
            if (! $url && $children === []) {
                continue;
            }
            $out[] = ['label' => $item->label, 'url' => $url ?: $children[0]['url']] + ($withChildren ? ['children' => $children] : []);
        }

        return $out;
    }
}
