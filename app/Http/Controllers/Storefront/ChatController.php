<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/** The visitor's side of live chat. The widget asks this controller for new messages every few seconds. */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat, private readonly ChatSettings $settings) {}

    public function state(Request $request): JsonResponse
    {
        abort_unless($this->settings->get('enabled'), 404);
        $conversation = $this->chat->find($request->cookie(ChatService::COOKIE));

        return $this->respond($request, $conversation);
    }

    public function start(Request $request): JsonResponse
    {
        abort_unless($this->settings->get('enabled'), 404);
        $online = $this->settings->staffOnline();
        $user = Auth::guard('web')->user();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            // With nobody at the inbox the reply can only go by email.
            'email' => [$online || $user ? 'nullable' : 'required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ], [
            'email.required' => 'Enter your email so we can reply.',
            'message.required' => 'Write your message.',
        ]);

        // A filled hidden field means a bot: answer as if it worked and keep nothing.
        if (trim((string) $request->input('website')) !== '') {
            return response()->json(['conversation' => null, 'messages' => [], 'online' => $online]);
        }

        // One chat per browser: a second "start" continues the one that is open.
        $existing = $this->chat->find($request->cookie(ChatService::COOKIE));
        if ($existing) {
            $existing->fill(array_filter(['name' => $data['name'] ?? null, 'email' => isset($data['email']) ? strtolower($data['email']) : null]))->save();
            $this->chat->visitorSays($existing, $data['message']);

            return $this->respond($request, $existing);
        }

        [$conversation, $token] = $this->chat->start($data, $user);
        Cookie::queue(ChatService::COOKIE, $token, 60 * 24 * 90, null, null, null, true, false, 'lax');

        return $this->respond($request, $conversation);
    }

    public function send(Request $request): JsonResponse
    {
        abort_unless($this->settings->get('enabled'), 404);
        $conversation = $this->chat->find($request->cookie(ChatService::COOKIE));
        abort_unless($conversation, 404);

        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $this->chat->visitorSays($conversation, $data['message']);

        return $this->respond($request, $conversation);
    }

    private function respond(Request $request, ?ChatConversation $conversation): JsonResponse
    {
        $messages = [];
        if ($conversation) {
            $this->chat->markVisitorActive($conversation);
            $after = max(0, (int) $request->input('after'));
            $new = $conversation->messages()->with('admin:id,name')->where('id', '>', $after)->limit(200)->get();
            // What staff wrote counts as read once the widget has fetched it with the panel open.
            if ($request->boolean('open')) {
                $conversation->messages()->where('sender_type', ChatMessage::ADMIN)->whereNull('read_at')->update(['read_at' => now()]);
            }
            $messages = $new->map(fn (ChatMessage $m) => ChatService::present($m))->all();
        }

        return response()->json([
            'online' => $this->settings->staffOnline(),
            'conversation' => $conversation ? [
                'status' => $conversation->status,
                'hasEmail' => (bool) $conversation->email,
                'unread' => $conversation->messages()->where('sender_type', ChatMessage::ADMIN)->whereNull('read_at')->count(),
            ] : null,
            'messages' => $messages,
        ])->header('Cache-Control', 'no-store, private');
    }
}
