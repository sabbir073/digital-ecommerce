<?php

namespace Tests\Support;

use App\Exceptions\InvalidWebhookException;
use App\Services\Payments\PaymentGateway;

/** Stands in for Stripe in tests. Records what was asked of it. */
class FakeGateway implements PaymentGateway
{
    public bool $configured = true;

    /** Tax rate applied to the taxable amount, e.g. 0.1 for 10%. */
    public float $taxRate = 0.0;

    /** @var array<string, array{id:string, client_secret:string, status:string, amount:int, key:string}> */
    public array $intents = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array{calculation:string, reference:string}> */
    public array $taxTransactions = [];

    /** @var list<array<string, mixed>> */
    public array $taxRequests = [];

    public int $createCalls = 0;

    /** @var list<array{intent:string, amount:int, key:string, reason:?string}> */
    public array $refunds = [];

    /** When set, the next refund is refused with this message. */
    public ?string $refundError = null;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function publishableKey(): ?string
    {
        return $this->configured ? 'pk_test_fake' : null;
    }

    public function calculateTax(array $lines, string $country, ?string $postcode): array
    {
        $this->taxRequests[] = compact('lines', 'country', 'postcode');
        $net = array_sum(array_column($lines, 'amount'));
        $tax = (int) round($net * $this->taxRate);

        return ['id' => 'taxcalc_'.count($this->taxRequests), 'tax_cents' => $tax, 'total_cents' => $net + $tax];
    }

    public function createPaymentIntent(int $amountCents, string $idempotencyKey, array $metadata, string $email, string $description): array
    {
        $this->createCalls++;
        // Like Stripe: the same idempotency key returns the same PaymentIntent.
        foreach ($this->intents as $intent) {
            if ($intent['key'] === $idempotencyKey) {
                return $intent;
            }
        }
        $id = 'pi_fake_'.(count($this->intents) + 1);

        return $this->intents[$id] = ['id' => $id, 'client_secret' => $id.'_secret', 'status' => 'requires_payment_method', 'amount' => $amountCents, 'key' => $idempotencyKey];
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->intents[$id];
    }

    public function cancelPaymentIntent(string $id): void
    {
        $this->intents[$id]['status'] = 'canceled';
        $this->cancelled[] = $id;
    }

    public function cardDetails(string $paymentIntentId): array
    {
        return ['brand' => 'visa', 'last4' => '4242'];
    }

    public function recordTaxTransaction(string $calculationId, string $reference): string
    {
        $this->taxTransactions[] = ['calculation' => $calculationId, 'reference' => $reference];

        return 'tax_txn_'.count($this->taxTransactions);
    }

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey, ?string $reason = null): array
    {
        if ($this->refundError !== null) {
            throw new \RuntimeException($this->refundError);
        }
        $this->refunds[] = ['intent' => $paymentIntentId, 'amount' => $amountCents, 'key' => $idempotencyKey, 'reason' => $reason];

        return ['id' => 're_fake_'.md5($idempotencyKey), 'amount' => $amountCents, 'status' => 'succeeded'];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        if ($signature !== 'valid') {
            throw new InvalidWebhookException('Bad signature');
        }

        return json_decode($payload, true);
    }
}
