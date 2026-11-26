<?php

namespace App\Services\Payments;

use App\Exceptions\InvalidWebhookException;

/**
 * Everything the store asks of the payment provider. Stripe is the only real
 * implementation; tests swap in a fake so no test ever talks to Stripe.
 */
interface PaymentGateway
{
    public function isConfigured(): bool;

    public function isTestMode(): bool;

    public function publishableKey(): ?string;

    /**
     * Ask Stripe Tax what to add to these lines for this customer.
     *
     * @param  list<array{reference:string, amount:int, tax_code:?string}>  $lines  amounts in cents, after discount
     * @return array{id:string, tax_cents:int, total_cents:int}
     */
    public function calculateTax(array $lines, string $country, ?string $postcode): array;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id:string, client_secret:string, status:string}
     */
    public function createPaymentIntent(int $amountCents, string $idempotencyKey, array $metadata, string $email, string $description): array;

    /** @return array{id:string, client_secret:string, status:string, amount:int} */
    public function retrievePaymentIntent(string $id): array;

    public function cancelPaymentIntent(string $id): void;

    /** @return array{brand:?string, last4:?string} */
    public function cardDetails(string $paymentIntentId): array;

    /** Record the tax of a paid order with Stripe Tax. Returns the tax transaction id. */
    public function recordTaxTransaction(string $calculationId, string $reference): string;

    /** @return array{id:string, amount:int, status:string} */
    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey, ?string $reason = null): array;

    /**
     * Verify a webhook's signature and return the event.
     *
     * @return array{id:string, type:string, object:array<string, mixed>}
     *
     * @throws InvalidWebhookException
     */
    public function parseWebhook(string $payload, ?string $signature): array;
}
