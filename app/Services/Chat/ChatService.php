<?php

namespace App\Services\Chat;

use App\Mail\ChatNewConversation;
use App\Mail\ChatReply;
use App\Models\AdminUser;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** The rules of live chat: starting a conversation, sending either way, and what happens when one side is away. */
class ChatService
{
    public const COOKIE = 'chat';

    /** A visitor counts as still looking at the chat for this long after their last check. */
    private const VISITOR_ACTIVE_SECONDS = 60;

    public function __construct(private readonly ChatSettings $settings) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function find(?string $token): ?ChatConversation
    {
        return is_string($token) && strlen($token) >= 32
            ? ChatConversation::query()->where('visitor_token', self::hash($token))->latest('id')->first()
            : null;
    }

    /**
     * @param  array{name?:?string, email?:?string, message:string, page_url?:?string}  $data
     * @return array{0: ChatConversation, 1: string} the conversation and the secret token for the visitor's cookie
     */
    public function start(array $data, ?User $user): array
    {
        $token = Str::random(48);

        $conversation = DB::transaction(function () use ($data, $user, $token) {
            $conversation = ChatConversation::create([
                'visitor_token' => self::hash($token),
                'user_id' => $user?->id,
                'name' => ($data['name'] ?? '') !== '' ? $data['name'] : $user?->name,
                'email' => ($data['email'] ?? '') !== '' ? Str::lower($data['email']) : $user?->email,
                'status' => ChatConversation::OPEN,
                'page_url' => isset($data['page_url']) ? Str::limit($data['page_url'], 500, '') : null,
                'last_message_at' => now(),
            ]);
            $conversation->messages()->create(['sender_type' => ChatMessage::VISITOR, 'body' => $data['message']]);

            return $conversation;
        });

        // Nobody at the inbox: tell the address the store chose, so the message is not left waiting.
        $notify = $this->settings->get('notify_email');
        if ($notify !== '' && ! $this->settings->staffOnline()) {
            try {
                Mail::to($notify)->queue(new ChatNewConversation($conversation, $data['message']));
            } catch (Throwable $e) {
                Log::warning('Chat notification could not be queued', ['message' => $e->getMessage()]);
            }
        }

        return [$conversation, $token];
    }

    public function visitorSays(ChatConversation $conversation, string $body): ChatMessage
    {
        // Writing in a closed chat opens it again.
        $conversation->update(['status' => ChatConversation::OPEN, 'last_message_at' => now()]);

        return $conversation->messages()->create(['sender_type' => ChatMessage::VISITOR, 'body' => $body]);
    }

    public function staffSays(ChatConversation $conversation, AdminUser $admin, string $body): ChatMessage
    {
        $conversation->update([
            'status' => ChatConversation::OPEN,
            'last_message_at' => now(),
            // The first person to answer takes the conversation.
            'assigned_admin_id' => $conversation->assigned_admin_id ?? $admin->id,
        ]);
        $message = $conversation->messages()->create(['sender_type' => ChatMessage::ADMIN, 'sender_id' => $admin->id, 'body' => $body]);

        // The visitor has left the page: send the reply to their email, if they gave one.
        if ($conversation->email && ! $this->visitorActive($conversation)) {
            try {
                Mail::to($conversation->email)->queue(new ChatReply($conversation, $body));
            } catch (Throwable $e) {
                Log::warning('Chat reply email could not be queued', ['message' => $e->getMessage()]);
            }
        }

        return $message;
    }

    public function note(ChatConversation $conversation, string $text): void
    {
        $conversation->messages()->create(['sender_type' => ChatMessage::SYSTEM, 'body' => $text, 'read_at' => now()]);
    }

    public function markVisitorActive(ChatConversation $conversation): void
    {
        Cache::put("chat.visitor_seen.{$conversation->id}", now()->timestamp, 600);
    }

    public function visitorActive(ChatConversation $conversation): bool
    {
        return now()->timestamp - (int) Cache::get("chat.visitor_seen.{$conversation->id}", 0) <= self::VISITOR_ACTIVE_SECONDS;
    }

    /** @return array{id:int, from:string, body:string, at:?string, by:?string} */
    public static function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'from' => $message->sender_type,
            'body' => $message->body,
            'at' => $message->created_at?->toIso8601String(),
            'by' => $message->sender_type === ChatMessage::ADMIN ? ($message->admin?->name ? Str::before($message->admin->name, ' ') : 'Support') : null,
        ];
    }
}
