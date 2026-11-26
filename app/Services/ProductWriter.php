<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Tag;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Saves a product with its tags, images, options, variants and SEO fields in one transaction. */
class ProductWriter
{
    /** @param array<string, mixed> $data validated admin form data */
    public function save(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'category_id' => $data['category_id'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'price_cents' => Money::toCents($data['price']) ?? 0,
                'compare_at_cents' => Money::toCents($data['compare_at'] ?? null),
                'sku' => $data['sku'] ?? null,
                'brand' => $data['brand'] ?? null,
                'track_stock' => (bool) ($data['track_stock'] ?? false),
                'stock' => (int) ($data['stock'] ?? 0),
            ])->save();

            $this->syncTags($product, $data['tags'] ?? []);
            $this->syncImages($product, $data['image_ids'] ?? []);
            $this->syncVariants($product, $data['options'] ?? [], $data['variants'] ?? []);

            $seo = array_filter([
                'meta_title' => $data['seo']['meta_title'] ?? null,
                'meta_description' => $data['seo']['meta_description'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($seo !== [] || $product->seo) {
                $product->seo()->updateOrCreate([], [
                    'meta_title' => $seo['meta_title'] ?? null,
                    'meta_description' => $seo['meta_description'] ?? null,
                ]);
            }

            return $product->refresh();
        });
    }

    /** @param list<string> $names */
    public function syncTags(Product $product, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $slug = Str::slug($name);
            if ($slug === '') {
                continue;
            }
            $ids[] = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }
        $product->tags()->sync(array_unique($ids));
    }

    /** @param list<int> $mediaIds in display order */
    private function syncImages(Product $product, array $mediaIds): void
    {
        $sync = [];
        foreach (array_values(array_unique($mediaIds)) as $position => $id) {
            $sync[$id] = ['position' => $position];
        }
        $product->images()->sync($sync);
    }

    /**
     * Options and their values are rebuilt on every save. Variants are matched
     * by their combination of values, so an unchanged variant keeps its id
     * (carts and past orders point at variant ids).
     *
     * @param  list<array{name:string, display?:string, values:list<array{value:string, swatch_hex?:?string}>}>  $options
     * @param  list<array{values:list<string>, price:mixed, compare_at?:mixed, sku?:?string, stock?:mixed, media_id?:?int}>  $variants
     */
    private function syncVariants(Product $product, array $options, array $variants): void
    {
        $existing = $product->variants()->with('optionValues')->get()
            ->keyBy(fn (Variant $v) => $this->key($v->optionValues->sortBy('product_option_id')->pluck('value')->all()));

        $product->options()->each(fn ($option) => $option->delete()); // cascades to values and pivot rows

        $valueIds = []; // [optionIndex][value] => id
        foreach (array_values($options) as $index => $option) {
            $model = $product->options()->create([
                'name' => $option['name'],
                'display' => $option['display'] ?? 'pill',
                'position' => $index,
            ]);
            foreach (array_values($option['values']) as $position => $value) {
                $valueIds[$index][$value['value']] = $model->values()->create([
                    'value' => $value['value'],
                    'swatch_hex' => $value['swatch_hex'] ?? null,
                    'position' => $position,
                ])->id;
            }
        }

        $kept = [];
        if ($options !== []) {
            foreach (array_values($variants) as $position => $row) {
                $ids = [];
                foreach (array_values($row['values']) as $index => $value) {
                    if (! isset($valueIds[$index][$value])) {
                        continue 2; // a combination that no longer exists
                    }
                    $ids[] = $valueIds[$index][$value];
                }
                if (count($ids) !== count($options)) {
                    continue;
                }

                $attributes = [
                    'sku' => $row['sku'] ?? null,
                    'price_cents' => Money::toCents($row['price']) ?? $product->price_cents,
                    'compare_at_cents' => Money::toCents($row['compare_at'] ?? null),
                    'stock' => (int) ($row['stock'] ?? 0),
                    'media_id' => $row['media_id'] ?? null,
                    'position' => $position,
                ];

                $variant = $existing->get($this->key($row['values']));
                if ($variant) {
                    $variant->update($attributes);
                } else {
                    $variant = $product->variants()->create($attributes);
                }
                $variant->optionValues()->sync($ids);
                $kept[] = $variant->id;
            }
        }

        $product->variants()->whereNotIn('id', $kept)->get()->each->delete();
        $product->unsetRelation('variants')->unsetRelation('options');

        // Listings filter and sort on the product price, so with variants it holds the lowest one.
        $lowest = $product->variants()->min('price_cents');
        if ($lowest !== null && (int) $lowest !== (int) $product->price_cents) {
            $product->update(['price_cents' => (int) $lowest]);
        }
    }

    /** @param list<string> $values */
    private function key(array $values): string
    {
        return implode('|', array_map(fn ($v) => Str::lower(trim((string) $v)), array_values($values)));
    }

    public function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = Str::slug($base) ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
