<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalAsset extends Model
{
    protected $fillable = ['product_id', 'variant_id', 'disk', 'path', 'filename', 'size_bytes', 'version'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
