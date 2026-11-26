<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/** The customer's password reset link, sent in place of the framework's stock notification. */
class PasswordReset extends Mailable
{
    use Queueable, UsesEmailTemplate;

    public function __construct(public string $name, public string $resetUrl, public int $expiresMinutes) {}

    protected function templateKey(): string
    {
        return 'password_reset';
    }

    protected function templateVariables(): array
    {
        return ['customer_name' => $this->name ?: 'there', 'expires_minutes' => $this->expiresMinutes];
    }

    protected function templateBlocks(): array
    {
        return ['button' => ['type' => 'button', 'url' => $this->resetUrl, 'label' => 'Choose a new password']];
    }
}
