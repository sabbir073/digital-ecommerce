<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOption extends Model
{
    protected $fillable = ['product_id', 'name', 'display', 'position'];

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('position');
    }
}
