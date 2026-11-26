<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'variant_id', 'name', 'variant_label', 'sku', 'unit_price_cents', 'quantity', 'line_total_cents'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DownloadGrant::class);
    }
}
