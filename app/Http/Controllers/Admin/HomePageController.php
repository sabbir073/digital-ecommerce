<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomeSection;
use App\Models\Media;
use App\Models\Product;
use App\Models\SellingPage;
use App\Services\ActivityLogger;
use App\Support\Blocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The home page: which sections show, in what order, and what each one says. */
class HomePageController extends Controller
{
    public const LABELS = [
        'hero' => 'Hero',
        'categories' => 'Shop by category',
        'best_sellers' => 'Best sellers',
        'featured_offer' => 'Featured offer',
        'store_reviews' => 'Store reviews',
        'trust' => 'Trust row',
    ];

    public const HINTS = [
        'categories' => 'Shows one tile for each top-level category. Change the tiles under Categories.',
        'best_sellers' => 'Shows eight products. The ones you pick come first; the rest are filled with the best-selling products.',
        'featured_offer' => 'A dark banner that links to one selling page. It stays hidden until you choose a published page.',
        'store_reviews' => 'Shows the three latest approved reviews of the store. Its rules are under Reviews, Settings.',
    ];

    public function edit(): Response
    {
        $saved = HomeSection::query()->get()->keyBy('type');
        $defaults = HomeSection::defaults();
        $fields = $this->fields();

        $sections = collect(HomeSection::TYPES)->map(function (string $type, int $index) use ($saved, $defaults, $fields) {
            $row = $saved->get($type);

            return [
                'type' => $type,
                'label' => self::LABELS[$type],
                'hint' => self::HINTS[$type] ?? null,
                'enabled' => $row ? $row->enabled : ! in_array($type, HomeSection::OFF_BY_DEFAULT, true),
                'position' => $row ? $row->position : $index,
                'settings' => Blocks::clean($fields[$type], array_merge($defaults[$type], $row?->settings ?? [])),
                'fields' => $fields[$type],
            ];
        })->sortBy('position')->values();

        $mediaIds = $sections->flatMap(fn ($s) => $s['settings']['media_ids'] ?? [])->unique();

        return Inertia::render('Content/Home', [
            'sections' => $sections,
            'media' => Media::query()->whereIn('id', $mediaIds)->get()->map->toAdminArray()->keyBy('id'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'size:'.count(HomeSection::TYPES)],
            'sections.*.type' => ['required', 'distinct', Rule::in(HomeSection::TYPES)],
            'sections.*.enabled' => ['required', 'boolean'],
            'sections.*.settings' => ['present', 'array'],
        ]);
        $fields = $this->fields();

        DB::transaction(function () use ($data, $fields) {
            foreach (array_values($data['sections']) as $position => $section) {
                HomeSection::query()->updateOrCreate(['type' => $section['type']], [
                    'enabled' => (bool) $section['enabled'],
                    'position' => $position,
                    'settings' => Blocks::clean($fields[$section['type']], $section['settings']),
                ]);
            }
        });

        app(ActivityLogger::class)->log('edit', 'content', null, 'Home page');

        return back()->with('success', 'Home page saved.');
    }

    /**
     * What can be edited in each section, in the same form the block editor uses.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function fields(): array
    {
        $products = Product::query()->where('status', 'active')->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->all();
        $pages = SellingPage::query()->where('status', SellingPage::PUBLISHED)->orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->all();
        $icons = ['download' => 'Download', 'lock' => 'Lock', 'refresh' => 'Returns', 'chat' => 'Chat'];

        return [
            'hero' => [
                ['key' => 'eyebrow', 'label' => 'Small text above the headline', 'type' => 'text', 'max' => 60],
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'text', 'max' => 120],
                ['key' => 'text', 'label' => 'One line of text', 'type' => 'textarea', 'max' => 240],
                ['key' => 'primary_label', 'label' => 'First button label', 'type' => 'text', 'max' => 40],
                ['key' => 'primary_url', 'label' => 'First button link', 'type' => 'url'],
                ['key' => 'secondary_label', 'label' => 'Second button label', 'type' => 'text', 'max' => 40],
                ['key' => 'secondary_url', 'label' => 'Second button link', 'type' => 'url'],
                ['key' => 'media_ids', 'label' => 'Three image tiles (empty uses the newest product images)', 'type' => 'images', 'min' => 0, 'max' => 3],
            ],
            'categories' => [['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 80]],
            'best_sellers' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 80],
                ['key' => 'product_ids', 'label' => 'Products to show first', 'type' => 'picks', 'max' => 8, 'choices' => $products],
            ],
            'featured_offer' => [
                ['key' => 'selling_page_id', 'label' => 'Selling page', 'type' => 'pick', 'choices' => $pages],
                ['key' => 'eyebrow', 'label' => 'Small text above the headline', 'type' => 'text', 'max' => 60],
                ['key' => 'headline', 'label' => 'Headline (empty uses the page name)', 'type' => 'text', 'max' => 120],
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'max' => 240],
                ['key' => 'button_label', 'label' => 'Button label', 'type' => 'text', 'max' => 40],
            ],
            'store_reviews' => [['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 80]],
            'trust' => [
                ['key' => 'items', 'label' => 'Items', 'type' => 'list', 'min' => 1, 'max' => 4, 'itemLabel' => 'Item', 'fields' => [
                    ['key' => 'icon', 'label' => 'Icon', 'type' => 'select', 'options' => $icons, 'default' => 'download'],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'max' => 60],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'max' => 160],
                ]],
            ],
        ];
    }
}
