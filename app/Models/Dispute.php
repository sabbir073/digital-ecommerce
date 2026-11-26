<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $fillable = ['payment_id', 'stripe_dispute_id', 'status', 'amount_cents', 'reason'];
}
