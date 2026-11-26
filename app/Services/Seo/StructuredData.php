<?php

namespace App\Services\Seo;

use App\Models\BlogPost;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\StoreSettings;
use Illuminate\Support\Str;

/** JSON-LD for search engines, built from the same data the page shows (PRD section 13). */
class StructuredData
{
    /**
     * @param  iterable<Review>|null  $shown  the reviews the page is showing, so the data matches what a visitor sees
     * @return array<string, mixed>
     */
    public function product(Product $product, ?iterable $shown = null): array
    {
        $product->loadMissing(['images', 'variants']);
        $url = route('product', $product->slug);
        $prices = $product->hasVariants() ? $product->variants->pluck('price_cents')->map(fn ($c) => (int) $c) : collect([(int) $product->price_cents]);
        $availability = 'https://schema.org/'.($product->isInStock() ? 'InStock' : 'OutOfStock');
        $amount = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'url' => $url,
            'image' => $product->images->map(fn ($image) => $image->sizeUrl(1200))->values()->all(),
            'description' => Str::limit(trim(strip_tags((string) ($product->short_description ?: $product->description))), 500),
            'sku' => $product->sku ?: null,
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
            'offers' => $prices->min() === $prices->max()
                ? ['@type' => 'Offer', 'url' => $url, 'price' => $amount($prices->min()), 'priceCurrency' => 'USD', 'availability' => $availability]
                : ['@type' => 'AggregateOffer', 'url' => $url, 'lowPrice' => $amount($prices->min()), 'highPrice' => $amount($prices->max()), 'priceCurrency' => 'USD',
                    'offerCount' => $prices->count(), 'availability' => $availability],
        ];

        // Ratings only when real, approved reviews exist.
        if ((int) $product->rating_count > 0) {
            $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float) $product->rating_average, 1), 'reviewCount' => (int) $product->rating_count];
            $data['review'] = collect($shown ?? [])->take(5)->values()->map(fn (Review $review) => array_filter([
                '@type' => 'Review',
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $review->rating, 'bestRating' => 5],
                'author' => ['@type' => 'Person', 'name' => $review->display_name],
                'name' => $review->title ?: null,
                'reviewBody' => Str::limit((string) $review->body, 1000),
                'datePublished' => $review->created_at?->toDateString(),
            ]))->all();
        }

        return array_filter($data, fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  list<array{name:string, url:string}>  $trail
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $trail): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']], $trail, array_keys($trail)),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function home(): array
    {
        $name = (string) Setting::get('store.name', config('app.name'));
        $theme = app(StoreSettings::class)->storefrontTheme();
        $contact = array_filter(['email' => (string) Setting::get('store.contact_email', ''), 'telephone' => (string) Setting::get('store.contact_phone', '')]);

        return [
            array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $name,
                'url' => url('/'),
                'logo' => $theme['logo']['url'] ?? null,
                'sameAs' => array_column($theme['socials'], 'url') ?: null,
                'contactPoint' => $contact ? ['@type' => 'ContactPoint', 'contactType' => 'customer support'] + $contact : null,
            ]),
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $name,
                'url' => url('/'),
                'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('/search').'?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
            ],
        ];
    }

    /**
     * FAQ data from the FAQ blocks of a page; null when it has none.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    public function faq(array $blocks): ?array
    {
        $questions = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'faq' || ($block['hidden'] ?? false)) {
                continue;
            }
            foreach ($block['data']['items'] ?? [] as $item) {
                $q = trim((string) ($item['q'] ?? ''));
                $a = trim(strip_tags((string) ($item['a'] ?? '')));
                if ($q !== '' && $a !== '') {
                    $questions[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
                }
            }
        }

        return $questions === [] ? null : ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
    }

    /** @return array<string, mixed> */
    public function article(BlogPost $post): array
    {
        $post->loadMissing(['cover', 'author']);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => Str::limit($post->title, 110, ''),
            'description' => $post->excerpt ?: null,
            'image' => $post->cover ? [$post->cover->sizeUrl(1200)] : null,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => $post->author ? ['@type' => 'Person', 'name' => $post->author->name] : null,
            'publisher' => ['@type' => 'Organization', 'name' => (string) Setting::get('store.name', config('app.name'))],
            'mainEntityOfPage' => $post->url(),
        ]);
    }
}
