<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    public const TYPES = ['category', 'page', 'selling_page', 'blog', 'url'];

    protected $fillable = ['menu_id', 'parent_id', 'label', 'link_type', 'link_id', 'url', 'position'];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }
}
