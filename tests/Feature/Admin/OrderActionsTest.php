<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AuthenticateAdmin;
use App\Mail\OrderConfirmation;
use App\Mail\RefundIssued;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Storefront\CheckoutTestCase;

class OrderActionsTest extends CheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(string $role = 'admin', array $attributes = []): static
    {
        $user = AdminUser::factory()->role($role)->create($attributes);

        return $this->actingAs($user, 'admin')->withSession([AuthenticateAdmin::SESSION_KEY => $user->session_version]);
    }

    private function paidOrder(array $product = [], array $customer = []): Order
    {
        $this->addToCart($this->product($product))->assertOk();
        $this->startCheckout($customer)->assertOk();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->paySucceeded($order, 'evt_'.$order->id)->assertOk();
        $this->flushHeaders()->withCookies([]);

        return $order->refresh();
    }

    public function test_a_partial_refund_reaches_stripe_and_keeps_the_downloads(): void
    {
        $order = $this->paidOrder();

        $this->admin()->get("/admin/orders/{$order->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('order.refundable', 2000)->where('order.wasPaid', true)->where('order.hasDownloads', true));

        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '5.00', 'reason' => 'One file was broken'])->assertSessionHas('success');

        $this->assertSame([['intent' => $order->payment->stripe_payment_intent_id, 'amount' => 500, 'key' => "refund-{$order->id}-0-500", 'reason' => 'One file was broken']], $this->gateway->refunds);
        $order->refresh();
        $this->assertSame([Order::PARTIALLY_REFUNDED, 500], [$order->status, (int) $order->refunded_cents]);
        $this->assertSame(0, DownloadGrant::query()->whereNotNull('revoked_at')->count());
        Mail::assertQueued(RefundIssued::class, fn (RefundIssued $mail) => $mail->hasTo($order->email) && $mail->amountCents === 500);
        $this->assertTrue(ActivityLog::query()->where('module', 'refunds')->where('action', 'refund')->exists());
        $this->assertStringContainsString('$5.00', (new RefundIssued($order, 500))->render());

        // Stripe's own webhook about the same refund changes nothing.
        $this->webhook('refund.created', ['id' => 're_fake_'.md5("refund-{$order->id}-0-500"), 'payment_intent' => $order->payment->stripe_payment_intent_id, 'amount' => 500, 'status' => 'succeeded'], 'evt_r')->assertOk();
        $this->assertSame(500, (int) $order->refresh()->refunded_cents);

        // More than what is left is refused before Stripe is asked.
        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '15.01'])->assertSessionHasErrors('amount');
        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->assertCount(1, $this->gateway->refunds);
    }

    public function test_a_full_refund_cancels_the_download_links(): void
    {
        $order = $this->paidOrder();
        $grant = DownloadGrant::query()->firstOrFail();
        $this->get($grant->url())->assertOk();

        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '20'])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame([Order::REFUNDED, 2000], [$order->status, (int) $order->refunded_cents]);
        $this->assertNotNull($grant->refresh()->revoked_at);
        $this->get($grant->url())->assertStatus(410);
        $this->assertStringContainsString('no longer work', (new RefundIssued($order, 2000))->render());

        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '1'])->assertSessionHasErrors('amount');
        $this->admin()->post("/admin/orders/{$order->id}/downloads/renew")->assertSessionHas('error');
        $this->assertNotNull($grant->refresh()->revoked_at);
    }

    public function test_a_refund_stripe_refuses_changes_nothing_and_is_noted(): void
    {
        $order = $this->paidOrder();
        $this->gateway->refundError = 'Insufficient funds in your Stripe balance.';

        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '20'])->assertSessionHas('error');

        $order->refresh();
        $this->assertSame([Order::PAID, 0], [$order->status, (int) $order->refunded_cents]);
        $this->assertTrue($order->events()->where('type', 'refund_failed')->exists());
        Mail::assertNotQueued(RefundIssued::class);
    }

    public function test_only_roles_with_the_refund_permission_can_refund(): void
    {
        $order = $this->paidOrder();

        // Staff handle orders but not refunds.
        $this->admin('staff')->get("/admin/orders/{$order->id}")->assertOk();
        $this->admin('staff')->post("/admin/orders/{$order->id}/refund", ['amount' => '5'])->assertForbidden();
        $this->admin('staff')->post("/admin/orders/{$order->id}/resend")->assertSessionHas('success');
        $this->admin('moderator')->post("/admin/orders/{$order->id}/resend")->assertForbidden();
        $this->admin('moderator')->post("/admin/orders/{$order->id}/notes", ['note' => 'x'])->assertForbidden();
        $this->assertSame([], $this->gateway->refunds);
    }

    public function test_resend_renew_note_and_cancel(): void
    {
        $order = $this->paidOrder();
        $grant = DownloadGrant::query()->firstOrFail();
        $grant->update(['downloads_used' => 5, 'expires_at' => now()->subDay()]);
        Mail::assertQueued(OrderConfirmation::class, 1);

        $this->admin()->post("/admin/orders/{$order->id}/resend")->assertSessionHas('success');
        Mail::assertQueued(OrderConfirmation::class, 2);

        $this->admin()->post("/admin/orders/{$order->id}/downloads/renew")->assertSessionHas('success');
        $grant->refresh();
        $this->assertSame(0, $grant->downloads_used);
        $this->assertTrue($grant->expires_at->isFuture());
        $this->assertNull($grant->blockedReason());

        $this->admin('admin', ['name' => 'Nadia Karim'])->post("/admin/orders/{$order->id}/notes", ['note' => 'Customer phoned about the invoice'])->assertSessionHas('success');
        $this->admin()->post("/admin/orders/{$order->id}/notes", ['note' => ''])->assertSessionHasErrors('note');
        $this->admin()->get("/admin/orders/{$order->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('order.events.0.type', 'note')->where('order.events.0.note', 'Customer phoned about the invoice')->where('order.events.0.by', 'Nadia Karim'));

        // A paid order cannot be cancelled; an unpaid one can, and its payment is cancelled in Stripe.
        $this->admin()->post("/admin/orders/{$order->id}/cancel")->assertSessionHas('error');
        $this->assertSame(Order::PAID, $order->refresh()->status);

        $this->addToCart($this->product(['track_stock' => true, 'stock' => 1]))->assertOk();
        $this->startCheckout()->assertOk();
        $unpaid = Order::query()->latest('id')->firstOrFail();
        $this->flushHeaders()->withCookies([]);
        $this->admin()->post("/admin/orders/{$unpaid->id}/cancel")->assertSessionHas('success');
        $this->assertSame(Order::CANCELLED, $unpaid->refresh()->status);
        $this->assertContains($unpaid->payment->stripe_payment_intent_id, $this->gateway->cancelled);
        $this->admin()->post("/admin/orders/{$unpaid->id}/resend")->assertSessionHas('error');
    }

    public function test_orders_can_be_filtered_and_exported(): void
    {
        $first = $this->paidOrder(['name' => '=Risky name']);
        DB::table('orders')->where('id', $first->id)->update(['created_at' => now()->subDays(10), 'name' => '=SUM(A1)']);
        $second = $this->paidOrder([], ['email' => 'second@example.com']);
        DB::table('orders')->where('id', $second->id)->update(['source' => 'selling_page']);

        $this->admin()->get('/admin/orders?from='.now()->subDays(2)->toDateString())->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.number', $second->number));
        $this->admin()->get('/admin/orders?to='.now()->subDays(2)->toDateString())->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.number', $first->number));
        $this->admin()->get('/admin/orders?source=selling_page')->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('filters.source', 'selling_page'));
        $this->admin()->get('/admin/orders?from=not-a-date&source=x')->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 2)->where('filters.from', ''));

        $csv = $this->admin()->get('/admin/orders/export?source=cart')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString($first->number, $csv);
        $this->assertStringNotContainsString($second->number, $csv);
        // A name that looks like a formula is exported as text.
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->admin('moderator')->get('/admin/orders/export')->assertForbidden();
    }

    public function test_customers_are_listed_with_their_orders_and_total_spent(): void
    {
        $this->paidOrder([], ['email' => 'rina@example.com', 'name' => 'Rina Akter']);
        $order = $this->paidOrder([], ['email' => 'rina@example.com', 'name' => 'Rina Akter']);
        $this->paidOrder([], ['email' => 'sam@example.com', 'name' => 'Sam Lee']);
        User::factory()->create(['email' => 'new@example.com', 'name' => 'New Person']);
        User::factory()->create(['email' => 'sam@example.com', 'name' => 'Sam Lee']);
        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '5']);

        $this->admin()->get('/admin/customers?sort=spent')->assertInertia(fn (AssertableInertia $p) => $p->component('Customers/Index')->has('customers.data', 3)
            ->where('customers.data.0.email', 'rina@example.com')->where('customers.data.0.orders', 2)->where('customers.data.0.spent', 3500)->where('customers.data.0.hasAccount', false)
            ->where('customers.data.1.email', 'sam@example.com')->where('customers.data.1.hasAccount', true)->where('customers.data.1.spent', 2000)
            ->where('customers.data.2.email', 'new@example.com')->where('customers.data.2.orders', 0));
        $this->admin()->get('/admin/customers?search=rina')->assertInertia(fn (AssertableInertia $p) => $p->has('customers.data', 1));

        $this->admin()->get('/admin/customers/view?email=rina@example.com')->assertInertia(fn (AssertableInertia $p) => $p->component('Customers/Show')
            ->where('customer.spent', 3500)->where('customer.refunded', 500)->has('orders', 2));
        $this->admin()->get('/admin/customers/view?email=nobody@example.com')->assertNotFound();

        $csv = $this->admin()->get('/admin/customers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('rina@example.com,"Rina Akter",no,2,35.00', $csv);
        // Staff may look at customers, moderators may not.
        $this->admin('staff')->get('/admin/customers')->assertOk();
        $this->admin('moderator')->get('/admin/customers')->assertForbidden();
    }

    public function test_the_dashboard_shows_sales_low_stock_and_top_selling_pages(): void
    {
        $order = $this->paidOrder();
        $old = $this->paidOrder();
        DB::table('orders')->where('id', $old->id)->update(['paid_at' => now()->subMonths(2)]);
        $this->admin()->post("/admin/orders/{$order->id}/refund", ['amount' => '5']);

        Product::factory()->create(['name' => 'Almost gone', 'track_stock' => true, 'stock' => 2]);
        Product::factory()->create(['name' => 'Plenty', 'track_stock' => true, 'stock' => 50]);
        Product::factory()->create(['name' => 'Not tracked', 'track_stock' => false, 'stock' => 0]);

        $page = SellingPage::create(['name' => 'Launch', 'slug' => 'launch', 'product_id' => $order->items->first()->product_id, 'status' => SellingPage::PUBLISHED, 'draft_blocks' => [], 'published_blocks' => [], 'published_at' => now()]);
        DB::table('page_views')->insert(['selling_page_id' => $page->id, 'date' => now()->toDateString(), 'views' => 40, 'unique_visitors' => 30]);
        DB::table('orders')->where('id', $order->id)->update(['selling_page_id' => $page->id, 'source' => 'selling_page']);

        $this->admin()->get('/admin')->assertInertia(fn (AssertableInertia $p) => $p->component('Dashboard')
            ->where('sales.today.orders', 1)->where('sales.today.revenue', 1500)->where('sales.month.orders', 1)
            ->has('sales.latest', 2)->where('sales.latest.0.number', $order->number)
            ->where('lowStock.threshold', 5)->has('lowStock.items', 1)->where('lowStock.items.0.name', 'Almost gone')->where('lowStock.items.0.stock', 2)
            ->has('topPages', 1)->where('topPages.0.name', 'Launch')->where('topPages.0.conversion', 2.5));

        Setting::put('inventory.low_stock_threshold', 1);
        $this->admin()->get('/admin')->assertInertia(fn (AssertableInertia $p) => $p->has('lowStock.items', 0));
        // A moderator sees none of the sales figures.
        $this->admin('moderator')->get('/admin')->assertInertia(fn (AssertableInertia $p) => $p->where('sales', null)->where('lowStock', null)->where('topPages', null));
    }
}
