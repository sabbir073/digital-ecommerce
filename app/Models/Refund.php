<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = ['payment_id', 'stripe_refund_id', 'amount_cents', 'reason', 'admin_user_id'];
}
