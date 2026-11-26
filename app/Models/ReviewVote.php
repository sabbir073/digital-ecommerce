<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewVote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
