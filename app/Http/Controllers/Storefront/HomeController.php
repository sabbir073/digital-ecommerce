<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\Media;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\Reviews\RatingCalculator;
use App\Services\Reviews\ReviewSettings;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $sections = HomeSection::forHome()->map(function (array $section) {
            $section['data'] = match ($section['type']) {
                'hero' => ['tiles' => $this->heroTiles($section['settings']['media_ids'] ?? [])],
                'categories' => ['categories' => Category::query()->whereNull('parent_id')->with('image')->orderBy('position')->orderBy('name')->get()],
                'best_sellers' => ['products' => $this->bestSellers($section['settings']['product_ids'] ?? [])],
                'featured_offer' => ['page' => ($section['settings']['selling_page_id'] ?? null)
                    ? SellingPage::query()->published()->with('product.images')->find($section['settings']['selling_page_id'])
                    : null],
                'store_reviews' => ['reviews' => Review::query()->approved()->ofStore()->with('photos')
                    ->where('rating', '>=', (int) app(ReviewSettings::class)->get('home_min_rating'))
                    ->orderByDesc('pinned')->orderByRaw('COALESCE(reviewed_at, created_at) DESC')->limit(3)->get(),
                    'summary' => app(RatingCalculator::class)->summary(null)],
                default => [],
            };

            return $section;
        });

        return view('storefront.home', [
            'tagline' => Setting::get('store.tagline', 'Digital products, delivered instantly.'),
            'sections' => $sections,
            'seo' => app(SeoBuilder::class)->for('home', null, ['json_ld' => app(StructuredData::class)->home()]),
        ]);
    }

    /**
     * Three image tiles: the ones the admin picked, otherwise the newest product images.
     *
     * @param  list<int>  $mediaIds
     * @return Collection<int, Media>
     */
    private function heroTiles(array $mediaIds): Collection
    {
        if ($mediaIds !== []) {
            $media = Media::query()->whereIn('id', $mediaIds)->where('type', 'image')->get()->keyBy('id');

            return collect($mediaIds)->map(fn ($id) => $media->get($id))->filter()->take(3)->values();
        }

        return Product::query()->visible()->with('images')->latest('id')->limit(12)->get()
            ->map(fn (Product $p) => $p->images->first())->filter()->take(3)->values();
    }

    /**
     * Eight products: the admin's picks first, topped up with the best-selling ones.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, Product>
     */
    private function bestSellers(array $productIds): Collection
    {
        $with = ['category:id,name,slug', 'images', 'variants'];
        $picked = collect();
        if ($productIds !== []) {
            $found = Product::query()->visible()->with($with)->whereIn('id', $productIds)->get()->keyBy('id');
            $picked = collect($productIds)->map(fn ($id) => $found->get($id))->filter()->values();
        }

        if ($picked->count() < 8) {
            $picked = $picked->concat(
                Product::query()->visible()->with($with)
                    ->whereNotIn('id', $picked->pluck('id'))
                    ->orderByDesc('sales_count')->orderByDesc('id')
                    ->limit(8 - $picked->count())->get()
            );
        }

        return $picked->take(8)->values();
    }
}
