<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\AuthenticateAdmin;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\ChatConversation;
use App\Models\ContactMessage;
use App\Models\DataDeletionRequest;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

/** PRD section 9: customers can ask for their data to be deleted. */
class DataDeletionTest extends CheckoutTestCase
{
    private function admin(string $role): AdminUser
    {
        $this->seed(RoleSeeder::class);
        $admin = AdminUser::factory()->role($role)->create();
        $this->actingAs($admin, 'admin')->withSession([AuthenticateAdmin::SESSION_KEY => $admin->session_version]);

        return $admin;
    }

    private function paidOrder(): Order
    {
        $this->addToCart($this->product(['name' => 'Studio Template', 'price_cents' => 4900]));
        $this->startCheckout()->assertOk();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->paySucceeded($order);

        return $order->fresh();
    }

    public function test_a_customer_asks_for_deletion_with_their_password_and_can_cancel(): void
    {
        $user = User::factory()->create(['email' => 'maya@example.com', 'password' => 'Str0ng-Passw0rd!x']);
        $this->actingAs($user, 'web');

        $this->get('/account')->assertOk()->assertSee('Ask to delete my data');
        $this->post('/account/delete-request', ['password' => 'wrong'])->assertSessionHasErrorsIn('deletion', ['password']);
        $this->assertSame(0, DataDeletionRequest::query()->count());

        $this->post('/account/delete-request', ['password' => 'Str0ng-Passw0rd!x'])->assertRedirect(route('account').'#your-data');
        $this->post('/account/delete-request', ['password' => 'Str0ng-Passw0rd!x']);
        $this->assertSame(1, DataDeletionRequest::query()->where('status', 'pending')->where('email', 'maya@example.com')->count());
        $this->get('/account')->assertSee('Cancel my request')->assertDontSee('Ask to delete my data');

        $this->delete('/account/delete-request')->assertRedirect();
        $this->assertSame('cancelled', DataDeletionRequest::query()->firstOrFail()->status);
        $this->assertNotNull($user->fresh());

        auth('web')->logout();
        $this->post('/account/delete-request', ['password' => 'x'])->assertRedirect('/login');
    }

    public function test_the_store_deletes_what_identifies_the_customer_and_keeps_the_order_record(): void
    {
        $order = $this->paidOrder();
        $email = $order->email;
        $user = User::factory()->create(['email' => $email]);
        $order->update(['user_id' => $user->id, 'phone' => '+1 555 0100', 'ip' => '203.0.113.9']);
        $item = $order->items()->firstOrFail();
        $this->assertGreaterThan(0, DownloadGrant::query()->where('order_item_id', $item->id)->count());
        $review = Review::create(['product_id' => $item->product_id, 'order_id' => $order->id, 'user_id' => $user->id, 'display_name' => 'Maya L.', 'email' => $email, 'rating' => 5, 'body' => 'Great template.', 'status' => 'approved', 'verified' => true, 'ip' => '203.0.113.9']);
        $chat = ChatConversation::create(['visitor_token' => str_repeat('a', 40), 'email' => $email, 'name' => 'Maya', 'status' => 'open']);
        $chat->messages()->create(['sender_type' => 'visitor', 'body' => 'Hello']);
        ContactMessage::create(['name' => 'Maya', 'email' => $email, 'message' => 'Please delete my data.']);
        ContactMessage::create(['name' => 'Someone else', 'email' => 'other@example.com', 'message' => 'Hi']);
        DataDeletionRequest::create(['user_id' => $user->id, 'email' => $email]);
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->admin('staff');
        $this->delete('/admin/customers/data', ['email' => $email])->assertForbidden();
        $this->assertNotNull($user->fresh());

        $this->admin('admin');
        $this->get('/admin/customers')->assertInertia(fn (AssertableInertia $page) => $page->has('deletionRequests', 1)->where('deletionRequests.0.email', $email));
        $this->get('/admin/customers/view?email='.urlencode($email))->assertInertia(fn (AssertableInertia $page) => $page->where('customer.deletionRequestedAt', fn ($at) => $at !== null));
        $this->delete('/admin/customers/data', ['email' => 'nobody@example.com'])->assertNotFound();

        DB::table('sessions')->insert(['id' => 'staff-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->delete('/admin/customers/data', ['email' => $email])->assertRedirect('/admin/customers')
            ->assertSessionHas('success', 'The personal data was deleted. 1 order record was kept without a name or email.');

        $this->assertNull($user->fresh());
        // The member of staff who did it is still signed in, even when their id equals the customer's.
        $this->assertSame(1, DB::table('sessions')->where('id', 'staff-session')->count());
        $kept = $order->fresh();
        $this->assertSame("deleted-{$order->id}@deleted.invalid", $kept->email);
        $this->assertNull($kept->name);
        $this->assertNull($kept->phone);
        $this->assertNull($kept->ip);
        $this->assertNull($kept->user_id);
        $this->assertNull($kept->tracking);
        $this->assertSame(4900, (int) $kept->total_cents);
        $this->assertSame($order->number, $kept->number);
        $this->assertSame(0, DownloadGrant::query()->where('order_item_id', $item->id)->whereNull('revoked_at')->count());

        $review->refresh();
        $this->assertSame(['Former customer', null, null, null], [$review->display_name, $review->email, $review->user_id, $review->ip]);
        $this->assertSame('Great template.', $review->body);
        $this->assertSame(0, ChatConversation::query()->count());
        $this->assertSame(0, DB::table('chat_messages')->count());
        $this->assertSame(['other@example.com'], ContactMessage::query()->pluck('email')->all());
        $this->assertNotNull($other->fresh());

        $request = DataDeletionRequest::query()->firstOrFail();
        $this->assertSame('done', $request->status);
        $this->assertStringNotContainsString($email, $request->email);
        // Nothing in the database still holds the address, and the customer is gone from the list.
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            $this->assertStringNotContainsStringIgnoringCase($email, DB::table($table)->get()->toJson(), $table);
        }
        $this->get('/admin/customers')->assertInertia(fn (AssertableInertia $page) => $page->has('deletionRequests', 0)->where('customers.data', fn ($rows) => collect($rows)->pluck('email')->all() === ['other@example.com']));
        $this->assertTrue(ActivityLog::query()->where('module', 'customers')->where('action', 'delete')->exists());
    }
}
