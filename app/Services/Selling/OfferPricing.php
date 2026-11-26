<?php

namespace App\Services\Selling;

use App\Exceptions\CheckoutException;
use App\Models\Coupon;
use App\Models\Offer;
use App\Services\Checkout\CartLine;
use App\Services\Checkout\CartSummary;
use App\Services\Checkout\CouponService;
use App\Services\Checkout\StockService;

/**
 * Prices a pack. The pack's fixed price always wins over the catalog prices of what is
 * inside it, and a pack can only be bought when every item in it is available.
 */
class OfferPricing
{
    public function __construct(private readonly StockService $stock, private readonly CouponService $coupons) {}

    /** Why this pack cannot be bought right now, or null when it can. */
    public function problem(Offer $offer): ?string
    {
        foreach ($this->lines($offer) as $line) {
            if ($line->issue !== null) {
                return $line->issue;
            }
        }

        return $offer->items->isEmpty() ? 'Not available' : null;
    }

    public function summary(Offer $offer, ?Coupon $coupon = null): CartSummary
    {
        $lines = $this->lines($offer);
        $subtotal = (int) $offer->price_cents;
        $discount = 0;
        $problem = null;

        if ($coupon) {
            try {
                $this->coupons->check($coupon, $subtotal, 'selling_page');
                $discount = $coupon->discountFor($subtotal);
            } catch (CheckoutException $e) {
                $problem = $e->getMessage();
                $coupon = null;
            }
        }

        return new CartSummary($lines, $lines === [] ? 0 : $subtotal, $discount, $coupon, $problem);
    }

    /**
     * One line per item in the pack. The pack price is shared out between the lines in
     * proportion to their catalog value, so the lines always add up to the pack price.
     *
     * @return list<CartLine>
     */
    private function lines(Offer $offer): array
    {
        $offer->loadMissing(['items.product.images', 'items.product.variants', 'items.variant.optionValues', 'items.variant.image']);
        $items = $offer->items->values();

        $weights = $items->map(fn ($item) => (int) ($item->variant?->price_cents ?? $item->product?->price_cents ?? 0) * $item->quantity);
        $totalWeight = (int) $weights->sum();
        $left = (int) $offer->price_cents;
        $last = $items->count() - 1;

        $lines = [];
        foreach ($items as $index => $item) {
            $product = $item->product;
            $variant = $item->variant;

            $share = $index === $last
                ? $left
                : ($totalWeight > 0 ? (int) floor($offer->price_cents * $weights[$index] / $totalWeight) : (int) floor($offer->price_cents / $items->count()));
            $left -= $share;

            $issue = null;
            $available = null;
            if (! $product || $product->status !== 'active' || ($product->hasVariants() && ! $variant) || ($variant && $variant->product_id !== $product->id)) {
                $issue = 'No longer available';
            } else {
                $available = $this->stock->available($product, $variant);
                if ($available !== null && $available < $item->quantity) {
                    $issue = 'Sold out';
                }
            }

            $lines[] = new CartLine(
                id: $item->id,
                product: $product,
                variant: $variant,
                name: $product->name ?? 'Unavailable product',
                variantLabel: $variant?->label() ?: null,
                unitCents: intdiv($share, max(1, $item->quantity)),
                quantity: $item->quantity,
                lineTotalCents: $share,
                available: $available,
                issue: $issue,
                image: $variant?->image ?? $product?->images->first(),
            );
        }

        return $lines;
    }
}
