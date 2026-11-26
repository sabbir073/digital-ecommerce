<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ActivityLogger;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The live chat inbox, where moderators answer visitors. */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat, private readonly ChatSettings $settings, private readonly ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->settings->markStaffOnline();

        return Inertia::render('Chat/Index', [
            'conversations' => $this->conversations(),
            'open' => (int) $request->query('open') ?: null,
            'settings' => $this->settings->all(),
            'me' => Auth::guard('admin')->id(),
        ]);
    }

    /** Called every few seconds by the open inbox: the conversation list, and new messages of the one on screen. */
    public function poll(Request $request): JsonResponse
    {
        $this->settings->markStaffOnline();

        $thread = null;
        $conversation = ChatConversation::query()->find((int) $request->query('conversation'));
        if ($conversation) {
            $conversation->messages()->where('sender_type', ChatMessage::VISITOR)->whereNull('read_at')->update(['read_at' => now()]);
            $thread = [
                'id' => $conversation->id,
                'visitorActive' => $this->chat->visitorActive($conversation),
                'messages' => $conversation->messages()->with('admin:id,name')->where('id', '>', max(0, (int) $request->query('after')))->limit(500)->get()
                    ->map(fn (ChatMessage $m) => ChatService::present($m))->all(),
            ];
        }

        return response()->json(['conversations' => $this->conversations(), 'thread' => $thread]);
    }

    public function reply(Request $request, ChatConversation $conversation): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $message = $this->chat->staffSays($conversation, Auth::guard('admin')->user(), $data['message']);

        return response()->json(['message' => ChatService::present($message->load('admin:id,name'))]);
    }

    public function update(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['close', 'reopen', 'assign'])]]);
        $admin = Auth::guard('admin')->user();

        match ($data['action']) {
            'close' => $conversation->update(['status' => ChatConversation::CLOSED]),
            'reopen' => $conversation->update(['status' => ChatConversation::OPEN]),
            'assign' => $conversation->update(['assigned_admin_id' => $admin->id]),
        };
        $this->chat->note($conversation, match ($data['action']) {
            'close' => "{$admin->name} closed the conversation",
            'reopen' => "{$admin->name} reopened the conversation",
            'assign' => "{$admin->name} took the conversation",
        });

        return back();
    }

    public function destroy(ChatConversation $conversation): RedirectResponse
    {
        $this->activity->log('delete', 'chat', $conversation, 'Chat with '.$conversation->displayName());
        $conversation->delete();

        return redirect()->route('admin.chat.index')->with('success', 'Conversation deleted.');
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'on_selling_pages' => ['required', 'boolean'],
            'welcome' => ['required', 'string', 'max:200'],
            'away' => ['required', 'string', 'max:200'],
            'notify_email' => ['nullable', 'email', 'max:255'],
        ]);
        $data['notify_email'] = (string) ($data['notify_email'] ?? '');

        $this->settings->save($data);
        $this->activity->log('edit', 'chat', null, 'Live chat settings');

        return back()->with('success', 'Chat settings saved.');
    }

    /** @return list<array<string, mixed>> */
    private function conversations(): array
    {
        return ChatConversation::query()
            ->with(['assignee:id,name', 'user:id,name,email'])
            ->withCount(['messages as unread' => fn ($q) => $q->where('sender_type', ChatMessage::VISITOR)->whereNull('read_at')])
            ->addSelect(['preview' => ChatMessage::query()->select('body')->whereColumn('chat_conversation_id', 'chat_conversations.id')->where('sender_type', '!=', ChatMessage::SYSTEM)->latest('id')->limit(1)])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('last_message_at')->limit(100)->get()
            ->map(fn (ChatConversation $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'email' => $c->email,
                'isCustomer' => $c->user_id !== null,
                'status' => $c->status,
                'assignee' => $c->assignee ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
                'pageUrl' => $c->page_url,
                'preview' => Str::limit((string) $c->preview, 90),
                'unread' => (int) $c->unread,
                'lastAt' => $c->last_message_at?->toIso8601String(),
                'startedAt' => $c->created_at?->toIso8601String(),
            ])->all();
    }
}
