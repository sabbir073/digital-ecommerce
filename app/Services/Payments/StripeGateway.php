<?php

namespace App\Services\Payments;

use App\Exceptions\CheckoutException;
use App\Exceptions\InvalidWebhookException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeGateway implements PaymentGateway
{
    /** Electronically supplied services: Stripe's general tax code for digital goods. */
    public const DEFAULT_TAX_CODE = 'txcd_10000000';

    private ?StripeClient $client = null;

    public function __construct(private readonly StripeSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    public function isTestMode(): bool
    {
        return $this->settings->mode() === 'test';
    }

    public function publishableKey(): ?string
    {
        return $this->settings->publishableKey();
    }

    public function calculateTax(array $lines, string $country, ?string $postcode): array
    {
        $calculation = $this->stripe()->tax->calculations->create([
            'currency' => 'usd',
            'line_items' => array_map(fn (array $line) => [
                'amount' => $line['amount'],
                'reference' => $line['reference'],
                'tax_code' => $line['tax_code'] ?: self::DEFAULT_TAX_CODE,
                'tax_behavior' => 'exclusive',
            ], $lines),
            'customer_details' => [
                'address' => array_filter(['country' => $country, 'postal_code' => $postcode]),
                'address_source' => 'billing',
            ],
        ]);

        return [
            'id' => (string) $calculation->id,
            'tax_cents' => (int) $calculation->tax_amount_exclusive,
            'total_cents' => (int) $calculation->amount_total,
        ];
    }

    public function createPaymentIntent(int $amountCents, string $idempotencyKey, array $metadata, string $email, string $description): array
    {
        $intent = $this->stripe()->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => 'usd',
            // Cards and wallets (Apple Pay, Google Pay) the account has switched on.
            'automatic_payment_methods' => ['enabled' => true],
            'receipt_email' => $email,
            'description' => $description,
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]);

        return ['id' => (string) $intent->id, 'client_secret' => (string) $intent->client_secret, 'status' => (string) $intent->status];
    }

    public function retrievePaymentIntent(string $id): array
    {
        $intent = $this->stripe()->paymentIntents->retrieve($id);

        return ['id' => (string) $intent->id, 'client_secret' => (string) $intent->client_secret, 'status' => (string) $intent->status, 'amount' => (int) $intent->amount];
    }

    public function cancelPaymentIntent(string $id): void
    {
        $this->stripe()->paymentIntents->cancel($id);
    }

    public function cardDetails(string $paymentIntentId): array
    {
        $intent = $this->stripe()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']]);
        $details = $intent->latest_charge->payment_method_details ?? null;
        $card = $details->card ?? null;

        return [
            'brand' => $card->brand ?? ($details->type ?? null),
            'last4' => $card->last4 ?? null,
        ];
    }

    public function recordTaxTransaction(string $calculationId, string $reference): string
    {
        return (string) $this->stripe()->tax->transactions->createFromCalculation([
            'calculation' => $calculationId,
            'reference' => $reference,
        ])->id;
    }

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey, ?string $reason = null): array
    {
        $refund = $this->stripe()->refunds->create(array_filter([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountCents,
            'metadata' => $reason ? ['note' => mb_substr($reason, 0, 400)] : null,
        ]), ['idempotency_key' => $idempotencyKey]);

        return ['id' => (string) $refund->id, 'amount' => (int) $refund->amount, 'status' => (string) $refund->status];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = $this->settings->webhookSecret();
        if ($secret === null) {
            throw new InvalidWebhookException('No webhook signing secret is saved.');
        }

        try {
            $event = Webhook::constructEvent($payload, (string) $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhookException($e->getMessage(), previous: $e);
        }

        return ['id' => (string) $event->id, 'type' => (string) $event->type, 'object' => $event->data->object->toArray()];
    }

    private function stripe(): StripeClient
    {
        $secret = $this->settings->secretKey();
        if ($secret === null) {
            throw new CheckoutException('Payments are not set up yet. Please try again later.');
        }

        return $this->client ??= new StripeClient(['api_key' => $secret, 'max_network_retries' => 2]);
    }
}
