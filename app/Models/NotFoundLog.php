<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An address visitors asked for that does not exist, with how often. */
class NotFoundLog extends Model
{
    protected $fillable = ['path', 'path_hash', 'hits', 'last_seen_at'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'last_seen_at' => 'datetime'];
    }
}
