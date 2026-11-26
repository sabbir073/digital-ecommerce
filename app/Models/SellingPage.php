<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A one-page selling page: one product, one URL, pay without leaving (PRD section 4). */
class SellingPage extends Model
{
    use HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const KEEP_VERSIONS = 10;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'draft_blocks' => 'array',
            'published_blocks' => 'array',
            'pixel_ids' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('position')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SellingPageVersion::class)->orderByDesc('id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(PageView::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)->whereNotNull('published_blocks');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED && $this->published_blocks !== null;
    }

    public function url(): string
    {
        return route('selling-page', ['slug' => $this->slug]);
    }
}
