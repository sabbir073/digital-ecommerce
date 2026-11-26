<?php

namespace App\Models;

use App\Services\Reviews\RatingCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A product review, or a review of the store when it has no product (PRD section 12). */
class Review extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SPAM = 'spam';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SPAM];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'pinned' => 'boolean', 'reviewed_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // The average counts approved reviews only, so it is recalculated whenever one of them changes.
        $refresh = function (Review $review) {
            $calculator = app(RatingCalculator::class);
            $calculator->refresh($review->product_id);
            if ($review->wasChanged('product_id')) {
                $calculator->refresh($review->getOriginal('product_id'));
            }
        };
        static::saved(function (Review $review) use ($refresh) {
            if ($review->wasRecentlyCreated || $review->wasChanged(['status', 'rating', 'product_id'])) {
                $refresh($review);
            }
        });
        static::deleted($refresh);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'review_photos')->withPivot('position')->orderBy('review_photos.position');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /** Reviews of the store as a whole. */
    public function scopeOfStore(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }

    public function isStoreReview(): bool
    {
        return $this->product_id === null;
    }

    public function countryName(): ?string
    {
        return config('countries')[strtoupper((string) $this->country_code)] ?? null;
    }

    /** When the reviewer wrote it: the original date for imported reviews, else when it arrived here. */
    public function writtenAt(): Carbon
    {
        return $this->reviewed_at ?? $this->created_at;
    }
}
