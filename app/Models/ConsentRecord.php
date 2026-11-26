<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A visitor's cookie choice, kept as proof of consent. */
class ConsentRecord extends Model
{
    protected $fillable = ['visitor_id', 'categories', 'region'];

    protected function casts(): array
    {
        return ['categories' => 'array'];
    }
}
