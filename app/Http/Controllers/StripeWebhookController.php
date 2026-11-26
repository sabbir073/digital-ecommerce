<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWebhookException;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Services\Checkout\OrderFulfillment;
use App\Services\Payments\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stripe's signed webhook. An order becomes Paid only here (PRD section 6).
 * The same event can arrive more than once and in any order, so every handler is repeatable.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, OrderFulfillment $fulfillment): Response
    {
        try {
            $event = $gateway->parseWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhookException) {
            return response('Invalid signature', 400);
        }

        try {
            $record = StripeEvent::query()->firstOrCreate(['stripe_event_id' => $event['id']], ['type' => $event['type']]);
        } catch (UniqueConstraintViolationException) {
            $record = StripeEvent::query()->where('stripe_event_id', $event['id'])->firstOrFail(); // two deliveries at once
        }
        if ($record->processed_at !== null) {
            return response('Already handled', 200);
        }

        $object = $event['object'];

        match ($event['type']) {
            'payment_intent.succeeded' => $this->succeeded($fulfillment, $object),
            'payment_intent.payment_failed' => $this->failed($fulfillment, $object),
            'refund.created', 'refund.updated' => $this->refund($fulfillment, $object),
            'charge.refunded' => $this->chargeRefunded($fulfillment, $object),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => $this->dispute($fulfillment, $object),
            default => null,
        };

        // Only now: if a handler threw, Stripe gets a 500 and sends the event again.
        $record->update(['processed_at' => now()]);

        return response('OK', 200);
    }

    /** @param array<string, mixed> $intent */
    private function succeeded(OrderFulfillment $fulfillment, array $intent): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $intent['id'] ?? '')->with('order')->first();
        if ($payment?->order && ($intent['currency'] ?? '') === 'usd') {
            $fulfillment->markPaid($payment->order, $payment->stripe_payment_intent_id, (int) ($intent['amount_received'] ?? 0));
        }
    }

    /** @param array<string, mixed> $intent */
    private function failed(OrderFulfillment $fulfillment, array $intent): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $intent['id'] ?? '')->with('order')->first();
        if ($payment?->order) {
            $fulfillment->markFailed($payment->order, $payment->stripe_payment_intent_id, $intent['last_payment_error']['message'] ?? null);
        }
    }

    /** @param array<string, mixed> $refund */
    private function refund(OrderFulfillment $fulfillment, array $refund): void
    {
        if (! empty($refund['payment_intent']) && in_array($refund['status'] ?? '', ['succeeded', 'pending'], true)) {
            $fulfillment->recordRefund((string) $refund['payment_intent'], (string) $refund['id'], (int) $refund['amount'], $refund['reason'] ?? null);
        }
    }

    /** @param array<string, mixed> $charge */
    private function chargeRefunded(OrderFulfillment $fulfillment, array $charge): void
    {
        if (! empty($charge['payment_intent'])) {
            $fulfillment->syncRefundedTotal((string) $charge['payment_intent'], (int) ($charge['amount_refunded'] ?? 0));
        }
    }

    /** @param array<string, mixed> $dispute */
    private function dispute(OrderFulfillment $fulfillment, array $dispute): void
    {
        if (! empty($dispute['payment_intent'])) {
            $fulfillment->recordDispute((string) $dispute['payment_intent'], (string) $dispute['id'], (string) ($dispute['status'] ?? 'open'), (int) ($dispute['amount'] ?? 0), $dispute['reason'] ?? null);
        }
    }
}
