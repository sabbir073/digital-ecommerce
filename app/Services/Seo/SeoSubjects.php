<?php

namespace App\Services\Seo;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Media;
use App\Models\Product;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything that has an SEO panel: products, categories, selling pages, content pages, blog posts
 * and the home page. This class knows, for each, its address, its own SEO fields and the facts
 * (name, summary, image, text) that the defaults and the checklist are built from.
 */
class SeoSubjects
{
    public const MODELS = [
        'product' => Product::class,
        'category' => Category::class,
        'selling_page' => SellingPage::class,
        'page' => ContentPage::class,
        'post' => BlogPost::class,
    ];

    public const FIELDS = ['meta_title', 'meta_description', 'canonical_url', 'index', 'follow', 'og_title', 'og_description', 'og_media_id', 'focus_keyword', 'custom_tags'];

    public function find(string $type, int $id): ?Model
    {
        if ($type === 'home' || ! isset(self::MODELS[$type])) {
            return null;
        }

        return self::MODELS[$type]::query()->find($id);
    }

    public static function typeOf(Model $model): string
    {
        return (string) array_search($model::class, self::MODELS, true);
    }

    /** The path of the public page, like /product/desk-mat. */
    public static function path(string $type, ?string $slug): string
    {
        return match ($type) {
            'product' => '/product/'.$slug,
            'category' => '/c/'.$slug,
            'selling_page' => '/p/'.$slug,
            'page' => '/pages/'.$slug,
            'post' => '/blog/'.$slug,
            default => '/',
        };
    }

    /** Whether visitors can open it right now. */
    public static function isPublic(string $type, ?Model $model): bool
    {
        return match ($type) {
            'home', 'category' => true,
            'product' => $model->status === 'active',
            'selling_page' => $model->isPublished(),
            'page' => $model->isPublished(),
            'post' => $model->isLive(),
            default => false,
        };
    }

    /**
     * The SEO fields the owner filled in, with empty values for the rest.
     *
     * @return array<string, mixed>
     */
    public function meta(string $type, ?Model $model): array
    {
        $saved = $type === 'home'
            ? (array) Setting::get('seo.home', [])
            : (($model->relationLoaded('seo') ? $model->seo : $model->seo()->first())?->only(self::FIELDS) ?? []);

        return [
            'meta_title' => (string) ($saved['meta_title'] ?? ''),
            'meta_description' => (string) ($saved['meta_description'] ?? ''),
            'canonical_url' => (string) ($saved['canonical_url'] ?? ''),
            'index' => (bool) ($saved['index'] ?? true),
            'follow' => (bool) ($saved['follow'] ?? true),
            'og_title' => (string) ($saved['og_title'] ?? ''),
            'og_description' => (string) ($saved['og_description'] ?? ''),
            'og_media_id' => isset($saved['og_media_id']) ? (int) $saved['og_media_id'] : null,
            'focus_keyword' => (string) ($saved['focus_keyword'] ?? ''),
            'custom_tags' => (string) ($saved['custom_tags'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $values */
    public function saveMeta(string $type, ?Model $model, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        foreach (['meta_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'focus_keyword'] as $key) {
            $values[$key] = trim((string) ($values[$key] ?? '')) ?: null;
        }
        $values['custom_tags'] = SeoSettings::cleanTags($values['custom_tags'] ?? '') ?: null;
        $values['index'] = (bool) ($values['index'] ?? true);
        $values['follow'] = (bool) ($values['follow'] ?? true);
        $values['og_media_id'] = ($values['og_media_id'] ?? null) ?: null;

        if ($type === 'home') {
            Setting::put('seo.home', $values);

            return;
        }
        $model->seo()->updateOrCreate([], $values);
        $model->unsetRelation('seo');
    }

    /**
     * What the templates, defaults and checklist need to know about one item.
     *
     * @return array{name:string, url:string, variables:array<string,string>, image:?Media, text:string, has_h1:bool, missing_alt:int, og_type:string}
     */
    public function facts(string $type, ?Model $model): array
    {
        $store = (string) Setting::get('store.name', config('app.name'));
        $plain = fn (?string $html) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], (string) $html)))));

        switch ($type) {
            case 'product':
                /** @var Product $model */
                $model->loadMissing(['category', 'images']);
                $summary = $plain($model->short_description) ?: Str::limit($plain($model->description), 155);

                return [
                    'name' => $model->name,
                    'url' => route('product', $model->slug),
                    'variables' => ['product_name' => $model->name, 'category_name' => (string) $model->category?->name, 'price' => Money::format($model->fromPriceCents()), 'summary' => $summary, 'store_name' => $store],
                    'image' => $model->images->first(),
                    'text' => $plain($model->short_description.' '.$model->description),
                    'has_h1' => true,
                    'missing_alt' => 0, // a product image without alt text uses the product name
                    'og_type' => 'product',
                ];
            case 'category':
                /** @var Category $model */
                $model->loadMissing('image');

                return [
                    'name' => $model->name,
                    'url' => url('/c/'.$model->slug),
                    'variables' => ['category_name' => $model->name, 'store_name' => $store],
                    'image' => $model->image,
                    'text' => '',
                    'has_h1' => true,
                    'missing_alt' => 0,
                    'og_type' => 'website',
                ];
            case 'selling_page':
                /** @var SellingPage $model */
                $model->loadMissing(['product.images', 'offers']);
                $blocks = $this->visible($model->published_blocks ?? $model->draft_blocks ?? []);
                $price = $model->offers->min('price_cents');

                return [
                    'name' => $model->name,
                    'url' => $model->url(),
                    'variables' => ['page_name' => $model->name, 'product_name' => (string) $model->product?->name, 'price' => $price !== null ? Money::format($price) : '',
                        'summary' => $plain($model->product?->short_description), 'store_name' => $store],
                    'image' => $model->product?->images->first(),
                    'text' => $plain($this->blockText($blocks)),
                    'has_h1' => collect($blocks)->contains(fn ($b) => $b['type'] === 'hero'),
                    'missing_alt' => $this->missingAlt($blocks),
                    'og_type' => 'product',
                ];
            case 'page':
                /** @var ContentPage $model */
                $blocks = $this->visible($model->published_blocks ?? $model->draft_blocks ?? []);
                $text = $plain($this->blockText($blocks));

                return [
                    'name' => $model->title,
                    'url' => $model->url(),
                    'variables' => ['page_title' => $model->title, 'summary' => Str::limit($text, 155), 'store_name' => $store],
                    'image' => null,
                    'text' => $text,
                    'has_h1' => true,
                    'missing_alt' => $this->missingAlt($blocks),
                    'og_type' => 'website',
                ];
            case 'post':
                /** @var BlogPost $model */
                $model->loadMissing(['cover', 'category']);
                $text = $plain($model->body);

                return [
                    'name' => $model->title,
                    'url' => $model->url(),
                    'variables' => ['post_title' => $model->title, 'category_name' => (string) $model->category?->name, 'summary' => $plain($model->excerpt) ?: Str::limit($text, 155), 'store_name' => $store],
                    'image' => $model->cover,
                    'text' => $text,
                    'has_h1' => true,
                    'missing_alt' => $model->cover && trim((string) $model->cover->alt) === '' ? 1 : 0,
                    'og_type' => 'article',
                ];
            default:
                return [
                    'name' => 'Home page',
                    'url' => url('/'),
                    'variables' => ['store_name' => $store, 'tagline' => (string) Setting::get('store.tagline', 'Digital products, delivered instantly.')],
                    'image' => null,
                    'text' => '',
                    'has_h1' => true,
                    'missing_alt' => 0,
                    'og_type' => 'website',
                ];
        }
    }

    /**
     * Every item that has an SEO panel, as [type, model] pairs, the home page first.
     *
     * @return Collection<int, array{0:string, 1:?Model}>
     */
    public function all(): Collection
    {
        $items = collect([['home', null]]);
        $with = ['product' => ['seo', 'category', 'images'], 'category' => ['seo', 'image'], 'selling_page' => ['seo', 'product.images', 'offers'], 'page' => ['seo'], 'post' => ['seo', 'cover', 'category']];
        foreach (self::MODELS as $type => $class) {
            foreach ($class::query()->with($with[$type])->orderBy('id')->limit(2000)->get() as $model) {
                $items->push([$type, $model]);
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function visible(array $blocks): array
    {
        return array_values(array_filter($blocks, fn ($b) => is_array($b) && ! ($b['hidden'] ?? false)));
    }

    /** @param list<array<string, mixed>> $blocks */
    private function blockText(array $blocks): string
    {
        $text = [];
        array_walk_recursive($blocks, function ($value, $key) use (&$text) {
            if (is_string($value) && ! in_array($key, ['type', 'id', 'url', 'button_url', 'video_url', 'icon', 'background', 'align'], true) && ! is_numeric($value)) {
                $text[] = $value;
            }
        });

        return implode(' ', $text);
    }

    /** Images used by the blocks that have no alt text in the media library. */
    private function missingAlt(array $blocks): int
    {
        $ids = [];
        foreach ($blocks as $block) {
            foreach (['image_id', 'poster_id'] as $key) {
                if (! empty($block['data'][$key])) {
                    $ids[] = (int) $block['data'][$key];
                }
            }
            array_push($ids, ...array_map('intval', $block['data']['image_ids'] ?? []));
        }
        if ($ids === []) {
            return 0;
        }

        return Media::query()->whereIn('id', array_unique($ids))->where(fn ($q) => $q->whereNull('alt')->orWhere('alt', ''))->count();
    }
}
