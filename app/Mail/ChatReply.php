<?php

namespace App\Mail;

use App\Models\ChatConversation;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatReply extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ChatConversation $conversation, public string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reply to your message to '.Setting::get('store.name', config('app.name')));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.chat-reply', with: [
            'conversation' => $this->conversation,
            'text' => $this->text,
            'storeName' => Setting::get('store.name', config('app.name')),
        ]);
    }
}
