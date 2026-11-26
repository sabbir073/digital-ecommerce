<?php

namespace App\Mail;

use App\Models\AdminUser;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AdminUser $user, public string $url, public string $inviterName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to '.Setting::get('store.name', config('app.name')).' admin');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-invitation', with: [
            'storeName' => Setting::get('store.name', config('app.name')),
            'roleName' => $this->user->role?->name,
        ]);
    }
}
