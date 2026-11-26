<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DownloadGrant extends Model
{
    protected $fillable = ['order_item_id', 'digital_asset_id', 'token', 'downloads_used', 'max_downloads', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DownloadLog::class);
    }

    /** Why this link cannot be used, or null when it can. */
    public function blockedReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'This download link was cancelled.',
            $this->expires_at !== null && $this->expires_at->isPast() => 'This download link has expired.',
            $this->downloads_used >= $this->max_downloads => 'This download link has been used the maximum number of times.',
            default => null,
        };
    }

    public function remaining(): int
    {
        return max(0, $this->max_downloads - $this->downloads_used);
    }

    public function url(): string
    {
        return route('download', ['token' => $this->token]);
    }
}
