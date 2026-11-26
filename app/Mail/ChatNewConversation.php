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

class ChatNewConversation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ChatConversation $conversation, public string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New chat message from '.$this->conversation->displayName());
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.chat-new-conversation', with: [
            'conversation' => $this->conversation,
            'text' => $this->text,
            'storeName' => Setting::get('store.name', config('app.name')),
        ]);
    }
}
