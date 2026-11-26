<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Coupon;
use App\Support\Money;

class CouponService
{
    /**
     * Find a coupon by code and check every rule against this purchase.
     *
     * @param  'cart'|'selling_page'  $source
     *
     * @throws CheckoutException with a plain-language reason
     */
    public function resolve(string $code, int $subtotalCents, string $source = 'cart'): Coupon
    {
        $code = mb_strtoupper(trim($code));
        $coupon = $code === '' ? null : Coupon::query()->whereRaw('UPPER(code) = ?', [$code])->first();

        if (! $coupon || ! $coupon->is_active) {
            throw new CheckoutException('That code is not valid.', 'coupon');
        }

        $this->check($coupon, $subtotalCents, $source);

        return $coupon;
    }

    /** @throws CheckoutException */
    public function check(Coupon $coupon, int $subtotalCents, string $source = 'cart'): void
    {
        $fail = fn (string $message) => throw new CheckoutException($message, 'coupon');

        if (! $coupon->is_active) {
            $fail('That code is not valid.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $fail('That code is not active yet.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            $fail('That code has expired.');
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            $fail('That code has been used the maximum number of times.');
        }
        if ($source === 'selling_page' && ! $coupon->applies_to_selling_pages) {
            $fail('That code cannot be used on this page.');
        }
        if ($subtotalCents < $coupon->min_spend_cents) {
            $fail('That code needs a minimum spend of '.Money::format($coupon->min_spend_cents).'.');
        }
    }
}
