<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Daily view counts for a selling page, used for the conversion rate in admin. */
class PageView extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
