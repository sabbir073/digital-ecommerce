<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The shopping cart. It lives in the database and is found again through a
 * long-lived cookie, so it survives a reload and a return visit on the same device.
 */
class CartService
{
    public const COOKIE = 'cart';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    public const MAX_LINE_QUANTITY = 99;

    public function __construct(
        private readonly StockService $stock,
        private readonly CouponService $coupons,
    ) {}

    /** Always the request being handled now (never one captured when this service was built). */
    private function request(): Request
    {
        return request();
    }

    public function current(bool $create = false): ?Cart
    {
        $token = $this->request()->cookie(self::COOKIE);
        $cart = is_string($token) && $token !== '' ? Cart::query()->where('token', $token)->first() : null;

        if (! $cart && $create) {
            $cart = Cart::create(['token' => Str::random(48), 'user_id' => Auth::id()]);
            Cookie::queue(self::COOKIE, $cart->token, self::COOKIE_MINUTES, null, null, null, true, false, 'lax');
            // Later calls in this same request must find the cart too.
            $this->request()->cookies->set(self::COOKIE, $cart->token);
        }

        return $cart;
    }

    /** A one-item cart for "Buy now", kept apart from the shopper's real cart. */
    public function buyNow(Product $product, ?Variant $variant, int $quantity): Cart
    {
        $cart = Cart::create(['token' => Str::random(48), 'user_id' => Auth::id()]);
        $this->add($cart, $product, $variant, $quantity);
        $this->request()->session()->put('buy_now_cart', $cart->id);

        return $cart;
    }

    public function buyNowCart(): ?Cart
    {
        $id = $this->request()->session()->get('buy_now_cart');

        return $id ? Cart::query()->find($id) : null;
    }

    /** @throws CheckoutException */
    public function add(Cart $cart, Product $product, ?Variant $variant, int $quantity): CartItem
    {
        if ($product->status !== 'active') {
            throw new CheckoutException('This product is not available.', 'stock');
        }
        $product->loadMissing('variants');
        if ($product->hasVariants() && (! $variant || $variant->product_id !== $product->id)) {
            throw new CheckoutException('Choose an option first.', 'variant');
        }
        if (! $product->hasVariants()) {
            $variant = null;
        }

        $item = $cart->items()->where('product_id', $product->id)->where('variant_id', $variant?->id)->first();
        $wanted = min(self::MAX_LINE_QUANTITY, max(1, $quantity) + ($item?->quantity ?? 0));

        $available = $this->stock->available($product, $variant);
        if ($available !== null) {
            if ($available === 0) {
                throw new CheckoutException('This item is out of stock.', 'stock');
            }
            $wanted = min($wanted, $available);
        }

        if ($item) {
            $item->update(['quantity' => $wanted]);
        } else {
            $item = $cart->items()->create(['product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $wanted]);
        }
        $cart->touch();

        return $item;
    }

    public function setQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        $item = $cart->items()->with('product', 'variant')->find($itemId);
        if (! $item) {
            return;
        }
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $quantity = min(self::MAX_LINE_QUANTITY, $quantity);
        $available = $item->product ? $this->stock->available($item->product, $item->variant) : 0;
        if ($available !== null) {
            $quantity = min($quantity, max(1, $available));
        }
        $item->update(['quantity' => $quantity]);
    }

    public function remove(Cart $cart, int $itemId): void
    {
        $cart->items()->whereKey($itemId)->delete();
    }

    /** @throws CheckoutException */
    public function applyCoupon(Cart $cart, string $code): void
    {
        $summary = $this->summary($cart);
        $coupon = $this->coupons->resolve($code, $summary->subtotalCents);
        $cart->update(['coupon_id' => $coupon->id]);
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->update(['coupon_id' => null]);
    }

    /** Prices and stock always come from the database, never from the browser. */
    public function summary(?Cart $cart): CartSummary
    {
        if (! $cart) {
            return new CartSummary([], 0, 0, null, null);
        }

        $cart->load(['items.product.images', 'items.product.variants', 'items.variant.optionValues', 'items.variant.image', 'coupon']);

        $lines = [];
        $subtotal = 0;
        foreach ($cart->items as $item) {
            $product = $item->product;
            $variant = $item->variant;
            $issue = null;
            $available = null;

            if (! $product || $product->status !== 'active' || ($product->hasVariants() && ! $variant)) {
                $issue = 'No longer available';
            } else {
                $available = $this->stock->available($product, $variant);
                if ($available !== null && $available < $item->quantity) {
                    $issue = $available === 0 ? 'Out of stock' : "Only {$available} left";
                }
            }

            $unit = (int) ($variant ? $variant->price_cents : ($product->price_cents ?? 0));
            $lineTotal = $unit * $item->quantity;
            if ($issue !== 'No longer available') {
                $subtotal += $lineTotal;
            }

            $lines[] = new CartLine(
                id: $item->id,
                product: $product,
                variant: $variant,
                name: $product->name ?? 'Unavailable product',
                variantLabel: $variant?->label() ?: null,
                unitCents: $unit,
                quantity: $item->quantity,
                lineTotalCents: $lineTotal,
                available: $available,
                issue: $issue,
                image: $variant?->image ?? $product?->images->first(),
            );
        }

        $coupon = $cart->coupon;
        $couponProblem = null;
        $discount = 0;
        if ($coupon) {
            try {
                $this->coupons->check($coupon, $subtotal);
                $discount = $coupon->discountFor($subtotal);
            } catch (CheckoutException $e) {
                $couponProblem = $e->getMessage();
                $coupon = null;
            }
        }

        return new CartSummary($lines, $subtotal, $discount, $coupon, $couponProblem);
    }
}
