<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataDeletionRequest extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['user_id', 'email', 'status', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
