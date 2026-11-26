<?php

namespace Tests\Feature\Storefront;

use App\Mail\ChatNewConversation;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SellingPage;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Chat\ChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function token(TestResponse $response): string
    {
        return $response->getCookie(ChatService::COOKIE, true)->getValue();
    }

    public function test_a_visitor_starts_a_chat_and_only_their_browser_can_read_it(): void
    {
        app(ChatSettings::class)->markStaffOnline();

        $this->getJson('/chat/state')->assertOk()->assertJson(['online' => true, 'conversation' => null, 'messages' => []]);

        $response = $this->postJson('/chat/start', ['name' => 'Rina', 'message' => 'Do the presets work on mobile?', 'page_url' => 'http://localhost/products/x'])
            ->assertOk()->assertJsonPath('messages.0.body', 'Do the presets work on mobile?')->assertJsonPath('messages.0.from', 'visitor')
            ->assertJsonPath('conversation.hasEmail', false);
        $token = $this->token($response);

        $conversation = ChatConversation::query()->firstOrFail();
        $this->assertSame(['Rina', 'open', 'http://localhost/products/x'], [$conversation->name, $conversation->status, $conversation->page_url]);
        // Only a hash of the secret is stored.
        $this->assertNotSame($token, $conversation->visitor_token);
        $this->assertSame(ChatService::hash($token), $conversation->visitor_token);

        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->postJson('/chat/messages', ['message' => 'And on desktop?', 'after' => 1])
            ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'And on desktop?');
        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->getJson('/chat/state?after=0')->assertOk()->assertJsonCount(2, 'messages');

        // Another browser sees nothing and cannot write into it.
        $this->flushHeaders()->withCookies([])->withCredentials()->withCookie(ChatService::COOKIE, str_repeat('x', 48))->getJson('/chat/state')->assertOk()->assertJson(['conversation' => null, 'messages' => []]);
        $this->withCredentials()->withCookie(ChatService::COOKIE, str_repeat('x', 48))->postJson('/chat/messages', ['message' => 'hi'])->assertNotFound();
        $this->assertSame(2, ChatMessage::query()->count());
    }

    public function test_an_email_is_required_when_nobody_is_at_the_inbox_and_the_store_is_told(): void
    {
        Mail::fake();
        app(ChatSettings::class)->save(['notify_email' => 'team@example.test']);

        $this->getJson('/chat/state')->assertOk()->assertJson(['online' => false]);
        $this->postJson('/chat/start', ['message' => 'Hello'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/chat/start', ['message' => ''])->assertStatus(422)->assertJsonValidationErrors('message');

        $this->postJson('/chat/start', ['message' => 'Hello', 'email' => 'Rina@Example.test'])->assertOk()->assertJsonPath('conversation.hasEmail', true);
        $this->assertSame('rina@example.test', ChatConversation::query()->firstOrFail()->email);
        Mail::assertQueued(ChatNewConversation::class, fn ($mail) => $mail->hasTo('team@example.test'));
    }

    public function test_no_email_goes_out_while_staff_are_online_and_a_signed_in_customer_needs_no_email(): void
    {
        Mail::fake();
        app(ChatSettings::class)->save(['notify_email' => 'team@example.test']);
        app(ChatSettings::class)->markStaffOnline();
        $this->postJson('/chat/start', ['message' => 'Hello'])->assertOk();
        Mail::assertNothingQueued();

        $this->travel(2)->minutes();
        $user = User::factory()->create(['name' => 'Sam Lee', 'email' => 'sam@example.test']);
        $this->flushHeaders()->withCookies([])->actingAs($user, 'web')->postJson('/chat/start', ['message' => 'Where is my download?'])->assertOk();

        $conversation = ChatConversation::query()->latest('id')->firstOrFail();
        $this->assertSame([$user->id, 'Sam Lee', 'sam@example.test'], [$conversation->user_id, $conversation->name, $conversation->email]);
    }

    public function test_a_bot_that_fills_the_hidden_field_leaves_nothing_behind(): void
    {
        app(ChatSettings::class)->markStaffOnline();
        $this->postJson('/chat/start', ['message' => 'Buy followers', 'website' => 'http://spam.test'])->assertOk()->assertJson(['conversation' => null]);
        $this->assertSame(0, ChatConversation::query()->count());
    }

    public function test_starting_again_in_the_same_browser_continues_the_same_chat_and_reopens_a_closed_one(): void
    {
        app(ChatSettings::class)->markStaffOnline();
        $token = $this->token($this->postJson('/chat/start', ['message' => 'First']));
        ChatConversation::query()->update(['status' => 'closed']);

        $this->withCredentials()->withCookie(ChatService::COOKIE, $token)->postJson('/chat/start', ['message' => 'Second', 'email' => 'rina@example.test'])->assertOk();

        $this->assertSame(1, ChatConversation::query()->count());
        $conversation = ChatConversation::query()->firstOrFail();
        $this->assertSame(['open', 'rina@example.test', 2], [$conversation->status, $conversation->email, $conversation->messages()->count()]);
    }

    public function test_the_widget_follows_the_settings(): void
    {
        $this->artisan('demo:seed', ['--no-images' => true])->assertSuccessful();
        $page = SellingPage::query()->where('slug', 'demo-pitch-deck')->firstOrFail();

        $this->get('/')->assertOk()->assertSee('data-chat', false);
        $this->get("/p/{$page->slug}")->assertOk()->assertDontSee('data-chat', false);

        app(ChatSettings::class)->save(['on_selling_pages' => true]);
        $this->get("/p/{$page->slug}")->assertOk()->assertSee('data-chat', false);

        app(ChatSettings::class)->save(['enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-chat', false);
        $this->get("/p/{$page->slug}")->assertOk()->assertDontSee('data-chat', false);
        $this->getJson('/chat/state')->assertNotFound();
        $this->postJson('/chat/start', ['message' => 'Hello', 'email' => 'a@example.test'])->assertNotFound();
    }
}
