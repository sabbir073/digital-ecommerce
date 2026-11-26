<?php

namespace App\Services\Reviews;

use App\Models\Setting;

/** The review settings an admin controls (PRD section 12, Moderation). */
class ReviewSettings
{
    public const DEFAULTS = [
        'who_may_review' => 'anyone', // anyone | verified
        'auto_approve_verified' => true,
        'hold_links' => true,
        'blocked_words' => [],
        'request_enabled' => true,
        'request_delay_days' => 7,
        'show_flags' => true,
        'home_min_rating' => 4,
        'captcha_enabled' => false,
        'turnstile_site_key' => '',
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key((array) Setting::get('reviews.settings', []), self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        Setting::put('reviews.settings', array_merge($this->all(), array_intersect_key($values, self::DEFAULTS)));
    }

    public function turnstileSecret(): ?string
    {
        return Setting::get('reviews.turnstile_secret') ?: null;
    }

    public function saveTurnstileSecret(?string $secret): void
    {
        Setting::put('reviews.turnstile_secret', $secret ?: null, encrypted: true);
    }

    /** The captcha is on only when it is switched on and both keys are present. */
    public function captchaActive(): bool
    {
        $all = $this->all();

        return $all['captcha_enabled'] && $all['turnstile_site_key'] !== '' && $this->turnstileSecret() !== null;
    }
}
