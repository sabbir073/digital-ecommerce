<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Variant extends Model
{
    protected $fillable = ['product_id', 'sku', 'price_cents', 'compare_at_cents', 'stock', 'media_id', 'position'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'variant_option_value');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function label(): string
    {
        return $this->optionValues->sortBy('product_option_id')->pluck('value')->implode(' / ');
    }
}
