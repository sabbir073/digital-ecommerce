<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A pack on a selling page: a bundle of products and variants sold at one fixed price. */
class Offer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'price_cents' => 'integer', 'position' => 'integer'];
    }

    public function sellingPage(): BelongsTo
    {
        return $this->belongsTo(SellingPage::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position')->orderBy('id');
    }
}
