<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    public const APPROVED = 'approved';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'pinned' => 'boolean', 'reviewed_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /** The flag for the reviewer's country, built from its two-letter code. */
    public function flag(): ?string
    {
        $code = strtoupper((string) $this->country_code);
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }
}
