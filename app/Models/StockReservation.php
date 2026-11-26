<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $fillable = ['order_id', 'product_id', 'variant_id', 'quantity', 'expires_at', 'confirmed_at', 'released_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** Holds that still take units away from what others can buy. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at')->whereNull('released_at')->where('expires_at', '>', now());
    }
}
