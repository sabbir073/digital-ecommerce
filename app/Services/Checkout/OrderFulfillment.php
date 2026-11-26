<?php

namespace App\Services\Checkout;

use App\Mail\OrderConfirmation;
use App\Models\Coupon;
use App\Models\DigitalAsset;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGateway;
use App\Services\Reviews\ReviewRequests;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** What happens to an order when Stripe tells us about its payment. Every method is safe to run twice. */
class OrderFulfillment
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PaymentGateway $gateway,
        private readonly ReviewRequests $reviewRequests,
    ) {}

    /**
     * Mark an order paid and hand over the goods. Called from the verified
     * webhook (or directly for an order with nothing to charge).
     */
    public function markPaid(Order $order, ?string $paymentIntentId, int $amountReceivedCents): bool
    {
        $done = DB::transaction(function () use ($order, $paymentIntentId, $amountReceivedCents) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->wasPaid()) {
                return false; // the same event arrived twice
            }

            if ($amountReceivedCents !== (int) $order->total_cents) {
                $order->record('amount_mismatch', "Stripe reported {$amountReceivedCents} cents for an order of {$order->total_cents} cents. Not fulfilled; check it in Stripe.");
                Log::error('Paid amount does not match the order', ['order' => $order->number]);

                return false;
            }

            $order->load('items');
            $this->stock->confirm($order);

            if ($order->coupon_id) {
                Coupon::query()->whereKey($order->coupon_id)->increment('used_count');
                DB::table('coupon_redemptions')->insertOrIgnore([
                    'coupon_id' => $order->coupon_id, 'order_id' => $order->id, 'email' => $order->email,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->grantDownloads($order);

            $order->update(['status' => Order::PAID, 'paid_at' => now()]);
            if ($paymentIntentId) {
                Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->update(['status' => 'succeeded', 'failure_message' => null]);
            }
            $order->record('paid', $paymentIntentId ? 'Payment confirmed by Stripe' : 'Nothing to charge');

            // The cart did its job. A "Buy now" cart is single-use; the shopper's own cart is emptied.
            if ($order->cart_id) {
                DB::table('cart_items')->where('cart_id', $order->cart_id)->delete();
                DB::table('carts')->where('id', $order->cart_id)->update(['coupon_id' => null]);
            }

            return true;
        });

        if (! $done) {
            return false;
        }

        $order->refresh();
        $this->afterPaid($order, $paymentIntentId);

        return true;
    }

    /** Things that talk to other systems. A failure here must never undo the paid order. */
    private function afterPaid(Order $order, ?string $paymentIntentId): void
    {
        if ($paymentIntentId) {
            try {
                $card = $this->gateway->cardDetails($paymentIntentId);
                Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)
                    ->update(['card_brand' => $card['brand'], 'card_last4' => $card['last4']]);
            } catch (Throwable $e) {
                Log::warning('Could not read card details', ['order' => $order->number, 'message' => $e->getMessage()]);
            }
        }

        if ($order->stripe_tax_calculation_id && ! $order->stripe_tax_transaction_id) {
            try {
                $order->update(['stripe_tax_transaction_id' => $this->gateway->recordTaxTransaction($order->stripe_tax_calculation_id, $order->number)]);
            } catch (Throwable $e) {
                $order->record('tax_not_recorded', 'Stripe Tax transaction could not be recorded: '.$e->getMessage());
            }
        }

        $this->reviewRequests->schedule($order);

        try {
            Mail::to($order->email)->queue(new OrderConfirmation($order));
            $order->record('email_queued', 'Confirmation email queued');
        } catch (Throwable $e) {
            $order->record('email_failed', 'Confirmation email could not be queued: '.$e->getMessage());
        }
    }

    private function grantDownloads(Order $order): void
    {
        $days = (int) config('store.download_days');

        foreach ($order->items as $item) {
            $assets = DigitalAsset::query()->where('product_id', $item->product_id)
                ->where(fn ($q) => $q->whereNull('variant_id')->when($item->variant_id, fn ($w) => $w->orWhere('variant_id', $item->variant_id)))
                ->get();

            foreach ($assets as $asset) {
                $item->downloads()->firstOrCreate(
                    ['digital_asset_id' => $asset->id],
                    [
                        'token' => Str::random(48),
                        'max_downloads' => max(1, (int) config('store.download_limit')),
                        'expires_at' => $days > 0 ? now()->addDays($days) : null,
                    ],
                );
            }
        }
    }

    public function markFailed(Order $order, string $paymentIntentId, ?string $message): void
    {
        DB::transaction(function () use ($order, $paymentIntentId, $message) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->where('status', '!=', 'succeeded')
                ->update(['status' => 'failed', 'failure_message' => $message ? mb_substr($message, 0, 250) : null]);

            if ($order->status === Order::PENDING) {
                // The hold on stock stays until it expires, so the customer can try another card.
                $order->update(['status' => Order::FAILED]);
                $order->record('payment_failed', $message ?: 'The payment was declined');
            }
        });
    }

    /** Give up on an unpaid order: free its stock and cancel the PaymentIntent. */
    public function cancel(Order $order, string $reason): void
    {
        if ($order->wasPaid() || $order->status === Order::CANCELLED) {
            return;
        }

        $order->loadMissing('payments');
        foreach ($order->payments as $payment) {
            try {
                $intent = $this->gateway->retrievePaymentIntent($payment->stripe_payment_intent_id);
                if (in_array($intent['status'], ['succeeded', 'processing'], true)) {
                    return; // money is on its way; the webhook will mark it paid
                }
                if ($intent['status'] !== 'canceled') {
                    $this->gateway->cancelPaymentIntent($payment->stripe_payment_intent_id);
                }
                $payment->update(['status' => 'cancelled']);
            } catch (Throwable $e) {
                Log::warning('Could not cancel PaymentIntent', ['order' => $order->number, 'message' => $e->getMessage()]);
            }
        }

        DB::transaction(function () use ($order, $reason) {
            $this->stock->release($order);
            $order->update(['status' => Order::CANCELLED]);
            $order->record('cancelled', $reason);
        });
    }

    /** A refund was created in Stripe (from admin or the Stripe dashboard). */
    public function recordRefund(string $paymentIntentId, string $refundId, int $amountCents, ?string $reason, ?int $adminUserId = null): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $payment) {
            return;
        }

        DB::transaction(function () use ($payment, $refundId, $amountCents, $reason, $adminUserId) {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $refund = $payment->refunds()->firstOrCreate(
                ['stripe_refund_id' => $refundId],
                ['amount_cents' => $amountCents, 'reason' => $reason ? mb_substr($reason, 0, 250) : null, 'admin_user_id' => $adminUserId],
            );
            if (! $refund->wasRecentlyCreated) {
                return;
            }

            // Never lower a total Stripe already reported through charge.refunded.
            $this->applyRefundTotal($order, max((int) $order->refunded_cents, (int) $payment->refunds()->sum('amount_cents')));
            $order->record('refunded', Money::format($amountCents).' refunded', $adminUserId);
        });
    }

    /** Stripe's running total for a charge; keeps the order right even if a refund event was missed. */
    public function syncRefundedTotal(string $paymentIntentId, int $amountRefundedCents): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $payment) {
            return;
        }

        DB::transaction(function () use ($payment, $amountRefundedCents) {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            if ($amountRefundedCents > (int) $order->refunded_cents) {
                $this->applyRefundTotal($order, $amountRefundedCents);
            }
        });
    }

    private function applyRefundTotal(Order $order, int $refundedCents): void
    {
        $full = $refundedCents >= (int) $order->total_cents;
        $order->update([
            'refunded_cents' => min($refundedCents, (int) $order->total_cents),
            'status' => $full ? Order::REFUNDED : Order::PARTIALLY_REFUNDED,
        ]);

        if ($full) {
            // A fully refunded order loses its downloads.
            DB::table('download_grants')
                ->whereIn('order_item_id', $order->items()->pluck('id'))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
    }

    public function recordDispute(string $paymentIntentId, string $disputeId, string $status, int $amountCents, ?string $reason): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $payment) {
            return;
        }

        $dispute = $payment->disputes()->updateOrCreate(
            ['stripe_dispute_id' => $disputeId],
            ['status' => $status, 'amount_cents' => $amountCents, 'reason' => $reason],
        );
        if ($dispute->wasRecentlyCreated) {
            $payment->order->record('dispute_opened', 'The customer\'s bank opened a dispute'.($reason ? " ({$reason})" : '').'. Respond in Stripe.');
        }
    }
}
