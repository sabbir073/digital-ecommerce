<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The product grid behind the category, search and all-products pages:
 * filters (category, price range, in stock), sort and pagination (PRD section 3).
 */
class CatalogQuery
{
    public const PER_PAGE = 12;

    public const SORTS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'best' => 'Best selling',
    ];

    /**
     * @param  array<string, mixed>  $input  raw query string
     * @return array{products: LengthAwarePaginator, filters: array<string, mixed>}
     */
    public function run(array $input, ?Category $fixedCategory = null, ?string $search = null): array
    {
        $filters = $this->filters($input);
        $query = Product::query()->visible()->with(['category:id,name,slug', 'images', 'variants']);

        $category = $fixedCategory;
        if (! $category && $filters['category']) {
            $category = Category::query()->where('slug', $filters['category'])->first();
        }
        if ($category) {
            $query->whereIn('category_id', $category->selfAndChildIds());
        }

        if ($search !== null && $search !== '') {
            $this->applySearch($query, $search);
        }

        if ($filters['min'] !== null) {
            $query->where('price_cents', '>=', $filters['min']);
        }
        if ($filters['max'] !== null) {
            $query->where('price_cents', '<=', $filters['max']);
        }
        if ($filters['in_stock']) {
            $query->inStock();
        }

        match ($filters['sort']) {
            'price_asc' => $query->orderBy('price_cents')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price_cents')->orderByDesc('id'),
            'best' => $query->orderByDesc('sales_count')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return [
            'products' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
        ];
    }

    /** Search matches product name, category and tags. */
    private function applySearch(Builder $query, string $search): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $like))
                ->orWhereHas('tags', fn (Builder $t) => $t->where('name', 'like', $like));
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{category:?string, min:?int, max:?int, min_input:string, max_input:string, in_stock:bool, sort:string, active:int}
     */
    private function filters(array $input): array
    {
        $text = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $min = $text('min') !== '' && is_numeric($text('min')) ? Money::toCents($text('min')) : null;
        $max = $text('max') !== '' && is_numeric($text('max')) ? Money::toCents($text('max')) : null;
        $sort = array_key_exists($text('sort'), self::SORTS) ? $text('sort') : 'newest';
        $category = $text('category') !== '' ? $text('category') : null;
        $inStock = $text('in_stock') === '1';

        return [
            'category' => $category,
            'min' => $min,
            'max' => $max,
            'min_input' => $min !== null ? $text('min') : '',
            'max_input' => $max !== null ? $text('max') : '',
            'in_stock' => $inStock,
            'sort' => $sort,
            'active' => (int) ($category !== null) + (int) ($min !== null) + (int) ($max !== null) + (int) $inStock,
        ];
    }
}
