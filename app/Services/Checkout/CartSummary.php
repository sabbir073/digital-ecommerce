<?php

namespace App\Services\Checkout;

use App\Models\Coupon;

final class CartSummary
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly ?Coupon $coupon,
        public readonly ?string $couponProblem,
    ) {}

    public function count(): int
    {
        return array_sum(array_map(fn (CartLine $line) => $line->quantity, $this->lines));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Total before tax. */
    public function totalCents(): int
    {
        return max(0, $this->subtotalCents - $this->discountCents);
    }

    public function hasIssues(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->issue !== null) {
                return true;
            }
        }

        return false;
    }
}
