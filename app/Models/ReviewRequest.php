<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The invitation to review one bought item. Its token is the link in the review-request email. */
class ReviewRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['send_at' => 'datetime', 'sent_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function url(): string
    {
        return route('review.invited', $this->token);
    }
}
