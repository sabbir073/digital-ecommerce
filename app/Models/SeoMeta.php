<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'meta_title', 'meta_description', 'canonical_url', 'index', 'follow',
        'og_title', 'og_description', 'og_media_id', 'focus_keyword', 'custom_tags',
    ];

    protected function casts(): array
    {
        return ['index' => 'boolean', 'follow' => 'boolean'];
    }

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
