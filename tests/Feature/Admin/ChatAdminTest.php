<?php

namespace Tests\Feature\Admin;

use App\Mail\ChatReply;
use App\Models\ActivityLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatSettings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

class ChatAdminTest extends AdminTestCase
{
    /** @return array{0: ChatConversation, 1: string} */
    private function conversation(array $data = []): array
    {
        return app(ChatService::class)->start($data + ['name' => 'Rina', 'email' => 'rina@example.test', 'message' => 'Do the presets work on mobile?'], null);
    }

    public function test_the_inbox_lists_conversations_and_reading_one_marks_it_read(): void
    {
        [$conversation] = $this->conversation();
        $moderator = $this->makeAdmin('moderator');

        $this->assertFalse(app(ChatSettings::class)->staffOnline());
        $this->signIn($moderator)->get('/admin/chat')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Chat/Index')->has('conversations', 1)->where('conversations.0.name', 'Rina')->where('conversations.0.unread', 1)
            ->where('conversations.0.preview', 'Do the presets work on mobile?')->where('settings.enabled', true)->where('me', $moderator->id));
        // Opening the inbox is what makes the team show as online to visitors.
        $this->assertTrue(app(ChatSettings::class)->staffOnline());

        $this->signIn($moderator)->getJson('/admin/chat/poll')->assertOk()->assertJsonPath('thread', null)->assertJsonPath('conversations.0.unread', 1);
        $this->signIn($moderator)->getJson("/admin/chat/poll?conversation={$conversation->id}&after=0")->assertOk()
            ->assertJsonPath('thread.id', $conversation->id)->assertJsonPath('thread.messages.0.body', 'Do the presets work on mobile?')
            ->assertJsonPath('conversations.0.unread', 0);
        $this->assertNotNull($conversation->messages()->first()->read_at);

        $last = $conversation->messages()->max('id');
        $this->signIn($moderator)->getJson("/admin/chat/poll?conversation={$conversation->id}&after={$last}")->assertOk()->assertJsonCount(0, 'thread.messages');
    }

    public function test_a_reply_reaches_the_visitor_live_and_the_first_replier_takes_the_conversation(): void
    {
        Mail::fake();
        [$conversation, $token] = $this->conversation();
        $moderator = $this->makeAdmin('moderator', ['name' => 'Nadia Karim']);

        $first = $conversation->messages()->max('id');

        // The visitor's widget is polling, so they are on the site.
        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->getJson('/chat/state?after=0')->assertOk()->assertJsonPath('conversation.unread', 0);

        $this->signIn($moderator)->postJson("/admin/chat/{$conversation->id}/messages", ['message' => 'Yes, on both.'])
            ->assertOk()->assertJsonPath('message.from', 'admin')->assertJsonPath('message.by', 'Nadia');
        $this->signIn($moderator)->postJson("/admin/chat/{$conversation->id}/messages", ['message' => ''])->assertStatus(422);
        Mail::assertNothingQueued();
        $this->assertSame($moderator->id, $conversation->refresh()->assigned_admin_id);

        // A second moderator answering does not take it away.
        $this->signIn($this->makeAdmin('moderator'))->postJson("/admin/chat/{$conversation->id}/messages", ['message' => 'Anything else?'])->assertOk();
        $this->assertSame($moderator->id, $conversation->refresh()->assigned_admin_id);

        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->getJson("/chat/state?after={$first}")->assertOk()
            ->assertJsonPath('conversation.unread', 2)->assertJsonPath('messages.0.body', 'Yes, on both.')->assertJsonPath('messages.0.by', 'Nadia');
        // With the panel open the replies count as read.
        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->getJson("/chat/state?after={$first}&open=1")->assertOk();
        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->getJson('/chat/state?after='.ChatMessage::query()->max('id'))->assertOk()->assertJsonPath('conversation.unread', 0);
    }

    public function test_a_reply_is_emailed_when_the_visitor_has_left(): void
    {
        Mail::fake();
        [$conversation] = $this->conversation();
        [$anonymous] = $this->conversation(['email' => null]);
        Mail::assertNothingQueued(); // no store notification address is set
        $moderator = $this->makeAdmin('moderator');

        $this->signIn($moderator)->postJson("/admin/chat/{$conversation->id}/messages", ['message' => 'Yes, on both.'])->assertOk();
        Mail::assertQueued(ChatReply::class, fn (ChatReply $mail) => $mail->hasTo('rina@example.test') && $mail->text === 'Yes, on both.');

        $this->signIn($moderator)->postJson("/admin/chat/{$anonymous->id}/messages", ['message' => 'Hello'])->assertOk();
        Mail::assertQueued(ChatReply::class, 1);

        $html = (new ChatReply($conversation, "Yes <b>both</b>.\nThanks"))->render();
        $this->assertStringContainsString('Yes &lt;b&gt;both&lt;/b&gt;.', $html);
    }

    public function test_close_reopen_take_over_and_delete(): void
    {
        [$conversation, $token] = $this->conversation();
        $moderator = $this->makeAdmin('moderator', ['name' => 'Nadia Karim']);

        $this->signIn($moderator)->put("/admin/chat/{$conversation->id}", ['action' => 'close'])->assertRedirect();
        $this->assertSame('closed', $conversation->refresh()->status);
        $this->assertSame('Nadia Karim closed the conversation', $conversation->messages()->reorder()->latest('id')->first()->body);

        // The visitor writing again opens it.
        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->postJson('/chat/messages', ['message' => 'One more thing'])->assertOk()->assertJsonPath('conversation.status', 'open');

        $this->signIn($moderator)->put("/admin/chat/{$conversation->id}", ['action' => 'assign'])->assertRedirect();
        $this->assertSame($moderator->id, $conversation->refresh()->assigned_admin_id);
        $this->signIn($moderator)->put("/admin/chat/{$conversation->id}", ['action' => 'explode'])->assertSessionHasErrors('action');

        $this->signIn($this->makeAdmin('staff'))->delete("/admin/chat/{$conversation->id}")->assertForbidden();
        $this->signIn($moderator)->delete("/admin/chat/{$conversation->id}")->assertRedirect('/admin/chat');
        $this->assertSame(0, ChatConversation::query()->count() + ChatMessage::query()->count());
        $this->assertTrue(ActivityLog::query()->where('module', 'chat')->where('action', 'delete')->exists());
    }

    public function test_settings_and_permissions(): void
    {
        [$conversation] = $this->conversation();

        $staff = $this->makeAdmin('staff');
        $this->signIn($staff)->get('/admin/chat')->assertForbidden();
        $this->signIn($staff)->getJson('/admin/chat/poll')->assertForbidden();
        $this->signIn($staff)->postJson("/admin/chat/{$conversation->id}/messages", ['message' => 'Hi'])->assertForbidden();
        $this->signIn($staff)->put('/admin/chat/settings', [])->assertForbidden();

        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->put('/admin/chat/settings', ['enabled' => true, 'on_selling_pages' => true, 'welcome' => '', 'away' => 'Back soon.', 'notify_email' => 'nope'])
            ->assertSessionHasErrors(['welcome', 'notify_email']);
        $this->signIn($admin)->put('/admin/chat/settings', ['enabled' => false, 'on_selling_pages' => true, 'welcome' => 'Hello there', 'away' => 'Back soon.', 'notify_email' => ''])
            ->assertSessionHasNoErrors();

        $settings = app(ChatSettings::class)->all();
        $this->assertSame([false, true, 'Hello there', 'Back soon.', ''], [$settings['enabled'], $settings['on_selling_pages'], $settings['welcome'], $settings['away'], $settings['notify_email']]);
    }
}
