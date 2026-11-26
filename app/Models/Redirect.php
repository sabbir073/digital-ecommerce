<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A 301 or 302 from an old address to a new one (PRD section 13). */
class Redirect extends Model
{
    protected $fillable = ['from_path', 'from_hash', 'to_path', 'type', 'hits'];

    protected function casts(): array
    {
        return ['type' => 'integer', 'hits' => 'integer'];
    }
}
