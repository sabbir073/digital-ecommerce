<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\Order;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeSettings;
use App\Services\StoreSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Turns a cart into an unpaid order with a Stripe PaymentIntent. Both checkouts
 * (cart flow and selling page) go through here, so the amount is always worked
 * out on the server from database prices (PRD section 6).
 */
class CheckoutService
{
    /** PaymentIntent states in which the customer can still (re)enter payment details. */
    private const OPEN_INTENT = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    public function __construct(
        private readonly CartService $carts,
        private readonly StockService $stock,
        private readonly PaymentGateway $gateway,
        private readonly StripeSettings $settings,
        private readonly OrderFulfillment $fulfillment,
    ) {}

    /**
     * Totals for the order summary, with tax once the country is known.
     *
     * @return array{subtotal:int, discount:int, tax:?int, total:int, calculation:?string}
     */
    public function quote(CartSummary $summary, ?string $country, ?string $postcode): array
    {
        $net = $summary->totalCents();
        $tax = null;
        $calculation = null;

        if ($net > 0 && $country && $this->settings->taxEnabled() && $this->gateway->isConfigured()) {
            try {
                $result = $this->gateway->calculateTax($this->taxLines($summary), $country, $postcode);
            } catch (ApiErrorException $e) {
                Log::warning('Stripe Tax calculation failed', ['message' => $e->getMessage()]);
                throw new CheckoutException('We could not work out the tax for that address. Check the country and postcode.', 'postcode');
            }
            $tax = $result['tax_cents'];
            $calculation = $result['id'];
        }

        return [
            'subtotal' => $summary->subtotalCents,
            'discount' => $summary->discountCents,
            'tax' => $tax,
            'total' => $net + (int) $tax,
            'calculation' => $calculation,
        ];
    }

    /**
     * @param  array{email:string, name:?string, country:string, postcode:?string}  $customer
     * @param  array{source?:string, selling_page_id?:?int, offer_id?:?int, user_id?:?int, ip?:?string}  $context
     * @return array{order: Order, client_secret: ?string, paid: bool}
     *
     * @throws CheckoutException
     */
    public function start(Cart $cart, array $customer, array $context = []): array
    {
        return $this->place($this->carts->summary($cart), $customer, ['cart_id' => $cart->id] + $context);
    }

    /**
     * Create (or continue) the unpaid order for a priced set of lines. The cart flow and the
     * selling page both end up here.
     *
     * @param  array{email:string, name:?string, country:string, postcode:?string}  $customer
     * @param  array{source?:string, cart_id?:?int, checkout_key?:?string, selling_page_id?:?int, offer_id?:?int, user_id?:?int, ip?:?string}  $context
     * @return array{order: Order, client_secret: ?string, paid: bool}
     *
     * @throws CheckoutException
     */
    public function place(CartSummary $summary, array $customer, array $context = []): array
    {
        $fromCart = ($context['source'] ?? 'cart') === 'cart';
        if ($summary->isEmpty()) {
            throw new CheckoutException($fromCart ? 'Your cart is empty.' : 'Choose a pack to continue.');
        }
        foreach ($summary->lines as $line) {
            if ($line->issue !== null) {
                throw new CheckoutException("{$line->name}: {$line->issue}.".($fromCart ? ' Update your cart to continue.' : ''), 'stock');
            }
        }
        if ($summary->totalCents() > 0 && ! $this->gateway->isConfigured()) {
            throw new CheckoutException('Payments are not set up yet. Please try again later.');
        }

        $quote = $this->quote($summary, $customer['country'], $customer['postcode']);
        $fingerprint = $this->fingerprint($summary, $customer, $quote['total']);
        $cartId = $context['cart_id'] ?? null;
        $checkoutKey = $context['checkout_key'] ?? null;

        // Pressing Pay again (double click, retry after a declined card) continues the same order.
        $previous = ($cartId || $checkoutKey)
            ? Order::query()
                ->when($cartId, fn ($q) => $q->where('cart_id', $cartId), fn ($q) => $q->where('checkout_key', $checkoutKey))
                ->whereIn('status', [Order::PENDING, Order::FAILED])->latest('id')->first()
            : null;
        if ($previous) {
            $resumed = $previous->fingerprint === $fingerprint ? $this->resume($previous) : null;
            if ($resumed) {
                return $resumed;
            }
            $this->fulfillment->cancel($previous, 'Replaced by a new checkout attempt');
        }

        $order = DB::transaction(function () use ($cartId, $checkoutKey, $summary, $customer, $context, $quote, $fingerprint) {
            $order = Order::create([
                'number' => $this->newNumber(),
                'user_id' => $context['user_id'] ?? null,
                'cart_id' => $cartId,
                'checkout_key' => $checkoutKey,
                'fingerprint' => $fingerprint,
                'email' => Str::lower($customer['email']),
                'name' => $customer['name'] ?: null,
                'status' => Order::PENDING,
                'source' => $context['source'] ?? 'cart',
                'selling_page_id' => $context['selling_page_id'] ?? null,
                'offer_id' => $context['offer_id'] ?? null,
                'coupon_id' => $summary->coupon?->id,
                'coupon_code' => $summary->coupon?->code,
                'subtotal_cents' => $quote['subtotal'],
                'discount_cents' => $quote['discount'],
                'tax_cents' => (int) $quote['tax'],
                'total_cents' => $quote['total'],
                'currency' => 'usd',
                'billing_country' => $customer['country'],
                'billing_postcode' => $customer['postcode'] ?: null,
                'stripe_tax_calculation_id' => $quote['calculation'],
                'ip' => $context['ip'] ?? null,
            ]);

            foreach ($summary->lines as $line) {
                $order->items()->create([
                    'product_id' => $line->product?->id,
                    'variant_id' => $line->variant?->id,
                    'name' => $line->name,
                    'variant_label' => $line->variantLabel,
                    'sku' => $line->variant?->sku ?: $line->product?->sku,
                    'unit_price_cents' => $line->unitCents,
                    'quantity' => $line->quantity,
                    'line_total_cents' => $line->lineTotalCents,
                ]);
            }

            $order->load('items');
            $this->stock->reserve($order);
            $order->record('created', 'Checkout started');

            return $order;
        });

        if ($order->total_cents === 0) {
            // Nothing to charge (a coupon covered everything), so there is no payment to wait for.
            $this->fulfillment->markPaid($order, null, 0);

            return ['order' => $order->refresh(), 'client_secret' => null, 'paid' => true];
        }

        try {
            $intent = $this->gateway->createPaymentIntent(
                $order->total_cents,
                // The same order and amount always sends the same key, so a retried request never makes a second charge.
                "order-{$order->id}-{$order->total_cents}",
                ['order_number' => $order->number, 'order_id' => (string) $order->id, 'source' => $order->source],
                $order->email,
                "Order {$order->number}",
            );
        } catch (Throwable $e) {
            Log::error('Stripe PaymentIntent could not be created', ['order' => $order->number, 'message' => $e->getMessage()]);
            $this->fulfillment->cancel($order, 'Payment could not be started');
            throw $e instanceof CheckoutException ? $e : new CheckoutException('We could not start the payment. Please try again in a moment.');
        }

        $order->payments()->create([
            'stripe_payment_intent_id' => $intent['id'],
            'idempotency_key' => "order-{$order->id}-{$order->total_cents}",
            'status' => 'pending',
            'amount_cents' => $order->total_cents,
        ]);

        return ['order' => $order, 'client_secret' => $intent['client_secret'], 'paid' => false];
    }

    /** @return array{order: Order, client_secret: ?string, paid: bool}|null null when a fresh order is needed */
    private function resume(Order $order): ?array
    {
        $payment = $order->payment;
        if (! $payment) {
            return null;
        }

        try {
            $intent = $this->gateway->retrievePaymentIntent($payment->stripe_payment_intent_id);
        } catch (Throwable) {
            return null;
        }

        if (in_array($intent['status'], ['succeeded', 'processing'], true)) {
            // Already charged; the webhook marks the order paid. Never start a second payment.
            return ['order' => $order, 'client_secret' => null, 'paid' => true];
        }
        if (! in_array($intent['status'], self::OPEN_INTENT, true) || $intent['amount'] !== $order->total_cents) {
            return null;
        }

        try {
            DB::transaction(function () use ($order) {
                $order->load('items');
                $this->stock->reserve($order);
                $order->update(['status' => Order::PENDING]);
            });
        } catch (CheckoutException $e) {
            $this->fulfillment->cancel($order, 'Stock no longer available');
            throw $e;
        }

        return ['order' => $order, 'client_secret' => $intent['client_secret'], 'paid' => false];
    }

    /**
     * Line amounts after the discount, which is spread across lines in proportion to their value.
     *
     * @return list<array{reference:string, amount:int, tax_code:?string}>
     */
    private function taxLines(CartSummary $summary): array
    {
        $lines = [];
        $left = $summary->discountCents;
        $count = count($summary->lines);

        foreach ($summary->lines as $index => $line) {
            $share = $index === $count - 1
                ? $left
                : ($summary->subtotalCents > 0 ? (int) floor($summary->discountCents * $line->lineTotalCents / $summary->subtotalCents) : 0);
            $share = min($share, $line->lineTotalCents, $left);
            $left -= $share;

            $lines[] = [
                'reference' => "line-{$line->id}",
                'amount' => $line->lineTotalCents - $share,
                'tax_code' => $line->product?->stripe_tax_code,
            ];
        }

        return $lines;
    }

    /** @param array{email:string, name:?string, country:string, postcode:?string} $customer */
    private function fingerprint(CartSummary $summary, array $customer, int $total): string
    {
        return hash('sha256', json_encode([
            array_map(fn (CartLine $l) => [$l->product?->id, $l->variant?->id, $l->quantity, $l->unitCents], $summary->lines),
            $summary->coupon?->id,
            Str::lower($customer['email']),
            $customer['country'],
            $customer['postcode'],
            $total,
        ]));
    }

    private function newNumber(): string
    {
        // No look-alike characters, so a number read over the phone is unambiguous.
        $code = fn (int $length) => collect(range(1, $length))->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])->implode('');
        $store = app(StoreSettings::class)->store();
        do {
            $number = $store['number_prefix'].($store['number_style'] === 'code' ? $code(8) : now()->format('ymd').'-'.$code(6));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
