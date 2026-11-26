<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['download_grant_id', 'ip'];
}
