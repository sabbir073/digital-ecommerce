<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One marketing or tracking service and its saved setup (PRD section 14). Settings are encrypted at rest. */
class Integration extends Model
{
    protected $fillable = ['service', 'settings', 'enabled', 'status', 'last_error', 'last_event_at'];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'enabled' => 'boolean', 'last_event_at' => 'datetime'];
    }
}
