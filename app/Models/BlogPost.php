<?php

namespace App\Models;

use App\Models\Concerns\HasSeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogPost extends Model
{
    use HasSeo;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = ['title', 'slug', 'cover_media_id', 'excerpt', 'body', 'blog_category_id', 'tags', 'author_id', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'published_at' => 'datetime'];
    }

    /** Published, and its publish date has arrived: a post dated in the future waits until then. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED && $this->published_at !== null && $this->published_at->isPast();
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_id');
    }

    public function url(): string
    {
        return route('blog.show', ['slug' => $this->slug]);
    }

    /** About 200 words a minute, never less than one minute. */
    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $this->body)) / 200));
    }
}
