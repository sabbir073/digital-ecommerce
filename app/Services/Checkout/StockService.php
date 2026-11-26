<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Stock rules (PRD section 6 and the owner's decision on overselling):
 *  - Stock is reduced when payment succeeds, not when the customer starts paying.
 *  - With overselling off, units are held for a short time while a customer pays,
 *    so two people cannot pay for the last unit at once.
 *  - With overselling on, nothing is held or refused; stock may go below zero.
 */
class StockService
{
    public function oversellingAllowed(): bool
    {
        return (bool) Setting::get('inventory.allow_overselling', false);
    }

    /** Units a customer can buy right now. Null means no limit. */
    public function available(Product $product, ?Variant $variant = null, ?int $exceptOrderId = null): ?int
    {
        if (! $product->track_stock || $this->oversellingAllowed()) {
            return null;
        }

        $held = (int) StockReservation::query()->holding()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->when($exceptOrderId, fn ($q) => $q->where('order_id', '!=', $exceptOrderId))
            ->sum('quantity');

        return max(0, (int) ($variant ? $variant->stock : $product->stock) - $held);
    }

    /**
     * Hold the order's units while the customer pays. Must run inside a transaction.
     *
     * @throws CheckoutException when something is no longer available
     */
    public function reserve(Order $order): void
    {
        $order->reservations()->whereNull('confirmed_at')->whereNull('released_at')->update(['released_at' => now()]);

        if ($this->oversellingAllowed()) {
            return;
        }

        foreach ($order->items as $item) {
            $product = Product::query()->lockForUpdate()->find($item->product_id);
            if (! $product || ! $product->track_stock) {
                continue;
            }
            $variant = $item->variant_id ? Variant::query()->lockForUpdate()->find($item->variant_id) : null;

            $available = $this->available($product, $variant, $order->id);
            if ($available !== null && $available < $item->quantity) {
                throw new CheckoutException(
                    $available === 0
                        ? "{$item->name} has just sold out. Remove it from your cart to continue."
                        : "Only {$available} of {$item->name} left. Lower the quantity to continue.",
                    'stock',
                );
            }

            $order->reservations()->create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'quantity' => $item->quantity,
                'expires_at' => now()->addMinutes((int) config('store.reservation_minutes')),
            ]);
        }
    }

    /** Payment succeeded: take the units out of stock for good. */
    public function confirm(Order $order): void
    {
        foreach ($order->items as $item) {
            $product = $item->product_id ? Product::query()->lockForUpdate()->find($item->product_id) : null;
            if (! $product) {
                continue;
            }
            if ($product->track_stock) {
                if ($item->variant_id) {
                    DB::table('variants')->where('id', $item->variant_id)->decrement('stock', $item->quantity);
                } else {
                    DB::table('products')->where('id', $product->id)->decrement('stock', $item->quantity);
                }
            }
            DB::table('products')->where('id', $product->id)->increment('sales_count', $item->quantity);
        }

        $order->reservations()->whereNull('confirmed_at')->update(['confirmed_at' => now(), 'released_at' => null]);
    }

    public function release(Order $order): void
    {
        $order->reservations()->whereNull('confirmed_at')->whereNull('released_at')->update(['released_at' => now()]);
    }
}
