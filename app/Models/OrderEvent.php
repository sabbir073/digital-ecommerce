<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'type', 'note', 'admin_user_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
