<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Payments\StripeSettings;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

class SalesAdminTest extends AdminTestCase
{
    private function order(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'number' => 'ND-'.fake()->unique()->numerify('######'),
            'email' => 'buyer@example.com',
            'name' => 'Sam Buyer',
            'status' => Order::PAID,
            'source' => 'cart',
            'currency' => 'USD',
            'subtotal_cents' => 2000,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 2000,
        ]);
    }

    public function test_coupons_can_be_created_edited_and_deleted(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->post('/admin/coupons', ['code' => 'save10', 'type' => 'percent', 'value' => '10', 'min_spend' => '5.00'])->assertSessionHasNoErrors();
        $coupon = Coupon::query()->where('code', 'SAVE10')->firstOrFail();
        $this->assertSame(10, $coupon->value);
        $this->assertSame(500, $coupon->min_spend_cents);
        $this->assertTrue($coupon->is_active);

        $this->signIn($admin)->put("/admin/coupons/{$coupon->id}", ['code' => 'SAVE10', 'type' => 'fixed', 'value' => '2.50', 'is_active' => false])->assertSessionHasNoErrors();
        $coupon->refresh();
        $this->assertSame('fixed', $coupon->type);
        $this->assertSame(250, $coupon->value);
        $this->assertFalse($coupon->is_active);

        $this->signIn($admin)->delete("/admin/coupons/{$coupon->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Coupon::query()->count());
        $this->assertSame(3, ActivityLog::query()->where('module', 'coupons')->count());
    }

    public function test_coupon_values_are_checked(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->post('/admin/coupons', ['code' => 'BIG', 'type' => 'percent', 'value' => '150'])->assertSessionHasErrors('value');
        $this->signIn($admin)->post('/admin/coupons', ['code' => 'bad code!', 'type' => 'fixed', 'value' => '5'])->assertSessionHasErrors('code');
        $this->signIn($admin)->post('/admin/coupons', ['code' => 'DATES', 'type' => 'fixed', 'value' => '5', 'starts_at' => '2030-02-01', 'ends_at' => '2030-01-01'])->assertSessionHasErrors('ends_at');
        $this->signIn($admin)->post('/admin/coupons', ['code' => 'ONCE', 'type' => 'fixed', 'value' => '5'])->assertSessionHasNoErrors();
        $this->signIn($admin)->post('/admin/coupons', ['code' => 'once', 'type' => 'fixed', 'value' => '5'])->assertSessionHasErrors('code');
        $this->assertSame(1, Coupon::query()->count());
    }

    public function test_roles_without_permission_cannot_reach_sales_screens(): void
    {
        $order = $this->order();

        $moderator = $this->makeAdmin('moderator');
        $this->signIn($moderator)->get('/admin/orders')->assertForbidden();
        $this->signIn($moderator)->get("/admin/orders/{$order->id}")->assertForbidden();
        $this->signIn($moderator)->get('/admin/coupons')->assertForbidden();
        $this->signIn($moderator)->get('/admin/settings/payments')->assertForbidden();

        $staff = $this->makeAdmin('staff');
        $this->signIn($staff)->get('/admin/orders')->assertOk();
        $this->signIn($staff)->post('/admin/coupons', ['code' => 'NOPE', 'type' => 'fixed', 'value' => '5'])->assertForbidden();
        $this->signIn($staff)->put('/admin/settings/payments/options', ['tax_enabled' => false, 'allow_overselling' => true])->assertForbidden();
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_the_order_list_hides_unfinished_checkouts_and_can_be_searched(): void
    {
        $paid = $this->order(['email' => 'anna@example.com']);
        $pending = $this->order(['status' => Order::PENDING, 'email' => 'ben@example.com']);
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->get('/admin/orders')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Orders/Index')->has('orders.data', 1)->where('orders.data.0.number', $paid->number));
        $this->signIn($admin)->get('/admin/orders?status='.Order::PENDING)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.number', $pending->number));
        $this->signIn($admin)->get('/admin/orders?search=anna')->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));
        $this->signIn($admin)->get("/admin/orders/{$paid->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Orders/Show')->where('order.number', $paid->number));
    }

    public function test_only_a_super_admin_can_save_payment_keys_and_they_are_stored_encrypted(): void
    {
        $keys = ['mode' => 'test', 'test' => ['publishable' => 'pk_test_abcdefghijklmnop', 'secret' => 'sk_test_supersecretvalue', 'webhook_secret' => 'whsec_signingvalue']];

        $this->signIn($this->makeAdmin('admin'))->put('/admin/settings/payments/keys', $keys)->assertForbidden();
        $this->assertNull(app(StripeSettings::class)->secretKey('test'));

        $super = $this->makeAdmin('super-admin');
        $this->signIn($super)->put('/admin/settings/payments/keys', $keys)->assertSessionHasNoErrors();

        $this->assertSame('sk_test_supersecretvalue', app(StripeSettings::class)->secretKey('test'));
        $stored = DB::table('settings')->pluck('value')->implode('|');
        $this->assertStringNotContainsString('sk_test_supersecretvalue', $stored);
        $this->assertStringNotContainsString('whsec_signingvalue', $stored);
        $this->assertStringNotContainsString('sk_test_supersecretvalue', (string) json_encode(ActivityLog::query()->get()));

        // The page shows that a key is saved, never the key itself.
        $response = $this->signIn($super)->get('/admin/settings/payments');
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Payments')->where('keys.test.secret', 'Saved')->where('keys.test.webhookSecret', 'Saved')->where('canEditKeys', true));
        $this->assertStringNotContainsString('supersecretvalue', $response->getContent());
        $this->assertStringNotContainsString('signingvalue', $response->getContent());
    }

    public function test_live_mode_needs_all_live_keys_and_wrong_key_types_are_refused(): void
    {
        $super = $this->makeAdmin('super-admin');

        $this->signIn($super)->put('/admin/settings/payments/keys', ['mode' => 'live', 'live' => ['publishable' => 'pk_live_abc']])->assertSessionHasErrors('mode');
        $this->assertSame('test', app(StripeSettings::class)->mode());

        $this->signIn($super)->put('/admin/settings/payments/keys', ['mode' => 'test', 'test' => ['secret' => 'sk_live_wrongplace']])->assertSessionHasErrors('test.secret');
    }

    public function test_checkout_options_are_saved_and_logged(): void
    {
        $this->signIn($this->makeAdmin('admin'))->put('/admin/settings/payments/options', ['tax_enabled' => false, 'allow_overselling' => true])->assertSessionHasNoErrors();

        $this->assertFalse((bool) Setting::get('tax.stripe_tax', true));
        $this->assertTrue((bool) Setting::get('inventory.allow_overselling', false));
        $this->assertTrue(ActivityLog::query()->where('module', 'settings')->where('subject_label', 'Checkout options')->exists());
    }
}
