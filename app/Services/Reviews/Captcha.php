<?php

namespace App\Services\Reviews;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Optional Cloudflare Turnstile check on the review form. Off until an admin adds keys and switches it on. */
class Captcha
{
    public function __construct(private readonly ReviewSettings $settings) {}

    public function passes(?string $token, ?string $ip): bool
    {
        if (! $this->settings->captchaActive()) {
            return true;
        }
        if (! $token) {
            return false;
        }

        try {
            return (bool) Http::asForm()->timeout(8)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $this->settings->turnstileSecret(), 'response' => $token, 'remoteip' => $ip])
                ->json('success', false);
        } catch (Throwable $e) {
            // If Cloudflare cannot be reached the review is refused, with a message to try again.
            Log::warning('Turnstile check failed', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
