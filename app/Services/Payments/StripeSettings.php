<?php

namespace App\Services\Payments;

use App\Models\Setting;

/**
 * Stripe keys. Entered in Admin > Settings > Payments and stored encrypted;
 * the STRIPE_* environment values are used when nothing is saved in admin.
 */
class StripeSettings
{
    public const MODES = ['test', 'live'];

    public function mode(): string
    {
        $mode = Setting::get('stripe.mode', config('services.stripe.mode', 'test'));

        return in_array($mode, self::MODES, true) ? $mode : 'test';
    }

    public function publishableKey(?string $mode = null): ?string
    {
        return $this->value('publishable', $mode);
    }

    public function secretKey(?string $mode = null): ?string
    {
        return $this->value('secret', $mode);
    }

    public function webhookSecret(?string $mode = null): ?string
    {
        return $this->value('webhook_secret', $mode);
    }

    public function taxEnabled(): bool
    {
        return (bool) Setting::get('tax.stripe_tax', true);
    }

    public function isConfigured(): bool
    {
        return $this->publishableKey() !== null && $this->secretKey() !== null;
    }

    /** @param array{mode?:string, publishable?:?string, secret?:?string, webhook_secret?:?string} $data blank values keep what is saved */
    public function save(string $mode, array $data): void
    {
        foreach (['publishable', 'secret', 'webhook_secret'] as $name) {
            $value = trim((string) ($data[$name] ?? ''));
            if ($value !== '') {
                Setting::put("stripe.{$mode}.{$name}", $value, encrypted: true);
            }
        }
    }

    private function value(string $name, ?string $mode): ?string
    {
        $mode ??= $this->mode();
        $value = Setting::get("stripe.{$mode}.{$name}") ?: config("services.stripe.{$mode}.{$name}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
