<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\ChatConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ChatReply extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function __construct(public ChatConversation $conversation, public string $text) {}

    protected function templateKey(): string
    {
        return 'chat_reply';
    }

    protected function templateVariables(): array
    {
        return ['customer_name' => $this->conversation->name ?: 'there'];
    }

    protected function templateBlocks(): array
    {
        return [
            'message' => ['type' => 'panel', 'text' => $this->text],
            'button' => ['type' => 'button', 'url' => url('/'), 'label' => 'Back to the store'],
        ];
    }
}
