<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    public const TYPES = ['percent', 'fixed'];

    protected $fillable = ['code', 'type', 'value', 'min_spend_cents', 'starts_at', 'ends_at', 'usage_limit', 'applies_to_selling_pages', 'is_active'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'applies_to_selling_pages' => 'boolean', 'is_active' => 'boolean'];
    }

    /** Discount in cents for a subtotal. Percent values are whole percents; fixed values are cents. */
    public function discountFor(int $subtotalCents): int
    {
        $discount = $this->type === 'percent'
            ? (int) round($subtotalCents * min(100, (int) $this->value) / 100)
            : (int) $this->value;

        return max(0, min($discount, $subtotalCents));
    }
}
