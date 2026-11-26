<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Product extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected $fillable = [
        'category_id', 'name', 'slug', 'short_description', 'description', 'status', 'price_cents',
        'compare_at_cents', 'sku', 'brand', 'track_stock', 'stock', 'stripe_tax_code',
    ];

    protected function casts(): array
    {
        return ['track_stock' => 'boolean', 'rating_average' => 'float'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'product_media')->withPivot('position')->orderBy('product_media.position');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('position');
    }

    public function digitalAssets(): HasMany
    {
        return $this->hasMany(DigitalAsset::class);
    }

    public function sellingPages(): HasMany
    {
        return $this->hasMany(SellingPage::class);
    }

    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /** Products a visitor may see. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** In stock = stock is not tracked, or some unit (of the product or any variant) is left. */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('track_stock', false)
                ->orWhere(function (Builder $w) {
                    $w->whereDoesntHave('variants')->where('stock', '>', 0);
                })
                ->orWhereHas('variants', fn (Builder $v) => $v->where('stock', '>', 0));
        });
    }

    public function hasVariants(): bool
    {
        return $this->variants->isNotEmpty();
    }

    /** Lowest price a visitor can pay: the cheapest variant, or the product price. */
    public function fromPriceCents(): int
    {
        return $this->hasVariants() ? (int) $this->variants->min('price_cents') : (int) $this->price_cents;
    }

    public function isOnSale(): bool
    {
        return ! $this->hasVariants() && $this->compare_at_cents !== null && $this->compare_at_cents > $this->price_cents;
    }

    /** Units available for a variant (or the product). Null means unlimited. */
    public function availableStock(?Variant $variant = null): ?int
    {
        if (! $this->track_stock) {
            return null;
        }

        return max(0, (int) ($variant ? $variant->stock : $this->stock));
    }

    public function isInStock(): bool
    {
        if (! $this->track_stock) {
            return true;
        }

        return $this->hasVariants() ? $this->variants->contains(fn (Variant $v) => $v->stock > 0) : $this->stock > 0;
    }
}
