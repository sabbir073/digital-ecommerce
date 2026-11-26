<?php

namespace App\Services\Integrations;

use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Checkout\CartSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The store's own event layer (PRD section 14). Pages and actions describe what happened in one neutral shape;
 * each service's adapter turns that into the service's own event. Nothing here knows about any service.
 */
class Tracking
{
    public const EVENTS = ['page_view', 'view_item_list', 'view_item', 'search', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'sign_up', 'submit_review'];

    private const FLASH = 'tracking.events';

    /** @return array{id:string, name:string, variant:?string, category:?string, brand:?string, price:float, quantity:int} */
    public static function item(?Product $product, ?Variant $variant, int $quantity, int $unitCents, ?string $name = null, ?string $variantLabel = null): array
    {
        return [
            // The same ID the product feeds use, so ad catalogs can match events to products.
            'id' => (string) ($product?->id ?? '0'),
            'name' => (string) ($name ?? $product?->name ?? ''),
            'variant' => $variantLabel ?? $variant?->label() ?: null,
            'category' => $product?->category?->name,
            'brand' => $product?->brand ?: null,
            'price' => round($unitCents / 100, 2),
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name:string, id:string, data:array<string, mixed>}
     */
    public static function event(string $name, array $data = [], ?string $id = null): array
    {
        return ['name' => $name, 'id' => $id ?? (string) Str::uuid(), 'data' => $data];
    }

    /** @param iterable<Product> $products */
    public static function viewItemList(iterable $products, string $listName): array
    {
        $items = [];
        foreach ($products as $product) {
            $items[] = self::item($product, null, 1, $product->fromPriceCents());
            if (count($items) === 24) {
                break;
            }
        }

        return self::event('view_item_list', ['list' => $listName, 'currency' => 'USD', 'items' => $items]);
    }

    public static function viewItem(Product $product, ?int $cents = null): array
    {
        $cents ??= $product->fromPriceCents();

        return self::event('view_item', ['currency' => 'USD', 'value' => round($cents / 100, 2), 'items' => [self::item($product, null, 1, $cents)]]);
    }

    public static function search(string $term): array
    {
        return self::event('search', ['term' => mb_substr($term, 0, 100)]);
    }

    public static function addToCart(Product $product, ?Variant $variant, int $quantity, int $unitCents): array
    {
        return self::event('add_to_cart', ['currency' => 'USD', 'value' => round($unitCents * $quantity / 100, 2), 'items' => [self::item($product, $variant, $quantity, $unitCents)]]);
    }

    public static function beginCheckout(CartSummary $summary): array
    {
        $items = array_map(fn ($line) => self::item($line->product, $line->variant, $line->quantity, $line->unitCents, $line->name, $line->variantLabel), $summary->lines);

        return self::event('begin_checkout', ['currency' => 'USD', 'value' => round($summary->totalCents() / 100, 2), 'coupon' => $summary->coupon?->code, 'items' => $items]);
    }

    /** The one Purchase event of an order. Its ID is the same in the browser and from the server, so services count it once. */
    public static function purchase(Order $order): array
    {
        $order->loadMissing('items.product.category');
        $items = $order->items->map(fn ($item) => self::item($item->product, null, (int) $item->quantity, (int) $item->unit_price_cents, $item->name, $item->variant_label)
            + ['id' => (string) $item->product_id])->all();

        return self::event('purchase', [
            'order' => $order->number,
            'currency' => strtoupper((string) ($order->currency ?: 'USD')),
            'value' => round($order->total_cents / 100, 2),
            'tax' => round($order->tax_cents / 100, 2),
            'shipping' => 0.0,
            'coupon' => $order->coupon_code ?: null,
            'items' => $items,
        ], 'purchase-'.$order->number);
    }

    /** An event that happened in this request and must be sent by the next page the visitor sees. */
    public static function flash(array $event): void
    {
        $queued = (array) session(self::FLASH, []);
        $queued[] = $event;
        session([self::FLASH => array_slice($queued, -10)]);
    }

    /** @return list<array<string, mixed>> */
    public static function pullFlashed(): array
    {
        return array_values((array) session()->pull(self::FLASH, []));
    }

    /**
     * What a server event needs to match the visitor's browser, taken while they are on the site.
     *
     * @return array<string, mixed>
     */
    public static function visitor(Request $request, Consent $consent): array
    {
        $cookie = fn (string $name) => mb_substr((string) ($request->cookies->get($name) ?? ''), 0, 200) ?: null;

        return array_filter([
            'analytics' => $consent->allows($request, 'analytics'),
            'marketing' => $consent->allows($request, 'marketing'),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            'fbp' => $cookie('_fbp'),
            'fbc' => $cookie('_fbc'),
            'ttp' => $cookie('_ttp'),
            'url' => mb_substr((string) ($request->headers->get('referer') ?: $request->fullUrl()), 0, 500),
        ], fn ($value) => $value !== null);
    }
}
