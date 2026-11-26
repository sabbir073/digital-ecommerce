<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Checkout\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(): View
    {
        return view('storefront.cart', ['summary' => $this->carts->summary($this->carts->current())]);
    }

    /** The slide-in panel's content. */
    public function panel(): JsonResponse
    {
        return $this->respond();
    }

    public function add(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.CartService::MAX_LINE_QUANTITY],
            'intent' => ['nullable', 'in:cart,buy'],
        ]);

        $product = Product::query()->visible()->find($data['product_id']);
        $variant = ! empty($data['variant_id']) ? Variant::query()->find($data['variant_id']) : null;
        $quantity = (int) ($data['quantity'] ?? 1);

        try {
            if (! $product) {
                throw new CheckoutException(t('This product is not available.'), 'stock');
            }

            if (($data['intent'] ?? 'cart') === 'buy') {
                // Buy now: straight to checkout with only this item; the shopper's cart is left alone.
                $this->carts->buyNow($product, $variant, $quantity);

                return $request->expectsJson()
                    ? response()->json(['redirect' => route('checkout', ['buy' => 1])])
                    : redirect()->route('checkout', ['buy' => 1]);
            }

            $this->carts->add($this->carts->current(create: true), $product, $variant, $quantity);
        } catch (CheckoutException $e) {
            return $this->respond(error: $e->getMessage());
        }

        return $this->respond(message: "{$product->name} added to your cart.");
    }

    public function update(Request $request, int $item): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:999']]);
        if ($cart = $this->carts->current()) {
            $this->carts->setQuantity($cart, $item, (int) $data['quantity']);
        }

        return $this->respond();
    }

    public function remove(int $item): JsonResponse|RedirectResponse
    {
        if ($cart = $this->carts->current()) {
            $this->carts->remove($cart, $item);
        }

        return $this->respond();
    }

    public function applyCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:60']], ['code.required' => t('Enter a code.')]);
        $cart = $request->boolean('buy') ? $this->carts->buyNowCart() : $this->carts->current();

        try {
            if (! $cart) {
                throw new CheckoutException(t('Your cart is empty.'), 'coupon');
            }
            $this->carts->applyCoupon($cart, $data['code']);
        } catch (CheckoutException $e) {
            return $this->respond(error: $e->getMessage());
        }

        return $this->respond(message: 'Code applied.');
    }

    public function removeCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $cart = $request->boolean('buy') ? $this->carts->buyNowCart() : $this->carts->current();
        if ($cart) {
            $this->carts->removeCoupon($cart);
        }

        return $this->respond();
    }

    private function respond(?string $message = null, ?string $error = null): JsonResponse|RedirectResponse
    {
        if (! request()->expectsJson()) {
            return back()->with($error ? 'error' : 'success', $error ?? $message);
        }

        $summary = $this->carts->summary($this->carts->current());

        return response()->json([
            'html' => view('storefront.cart.panel', ['summary' => $summary, 'message' => $message, 'error' => $error])->render(),
            'count' => $summary->count(),
            'message' => $message,
            'error' => $error,
        ], $error ? 422 : 200);
    }
}
