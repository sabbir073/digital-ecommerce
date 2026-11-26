<?php

namespace App\Models;

use App\Models\Concerns\HasSeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A page of the store's own content (About, Contact, policies), built with the block editor. */
class ContentPage extends Model
{
    use HasSeo;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = ['title', 'slug', 'draft_blocks', 'published_blocks', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['draft_blocks' => 'array', 'published_blocks' => 'array', 'published_at' => 'datetime'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function url(): string
    {
        return route('page.show', ['slug' => $this->slug]);
    }
}
