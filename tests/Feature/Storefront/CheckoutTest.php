<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\InvalidWebhookException;
use App\Mail\OrderConfirmation;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Setting;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CheckoutTest extends CheckoutTestCase
{
    public function test_starting_checkout_creates_an_unpaid_order_priced_by_the_server(): void
    {
        $this->gateway->taxRate = 0.1;
        $product = $this->product(['name' => 'Loop Pack', 'price_cents' => 5000, 'track_stock' => true, 'stock' => 5]);
        $this->addToCart($product, 2);
        Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10]);
        $this->withCart()->postJson('/cart/coupon', ['code' => 'TEN']);

        $response = $this->startCheckout(['total' => 1, 'amount' => 1])->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('client_secret', 'pi_fake_1_secret')
            ->assertJsonPath('total', 9900);

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::PENDING, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertSame([10000, 1000, 900, 9900], [$order->subtotal_cents, $order->discount_cents, $order->tax_cents, $order->total_cents]);
        $this->assertSame('buyer@example.com', $order->email);
        $this->assertSame(9900, $this->gateway->intents['pi_fake_1']['amount']);
        $this->assertSame(9000, $this->gateway->taxRequests[array_key_last($this->gateway->taxRequests)]['lines'][0]['amount'], 'tax is worked out on the discounted amount');
        $this->assertStringContainsString('/order/'.$order->number, $response->json('order_url'));

        // Stock is only held, not reduced, until payment succeeds.
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(2, (int) StockReservation::query()->holding()->sum('quantity'));
        Mail::assertNothingQueued();
    }

    public function test_order_is_paid_only_when_the_signed_webhook_arrives(): void
    {
        $product = $this->product(['price_cents' => 2000, 'track_stock' => true, 'stock' => 5]);
        Coupon::create(['code' => 'TWO', 'type' => 'fixed', 'value' => 200]);
        $this->addToCart($product, 2);
        $this->withCart()->postJson('/cart/coupon', ['code' => 'TWO']);
        $this->startCheckout()->assertOk();
        $order = Order::query()->firstOrFail();

        // The customer landing on the confirmation page does not make it paid.
        $this->get($order->url())->assertOk()->assertSee('Confirming your payment')->assertDontSee('Your downloads');
        $this->assertSame(Order::PENDING, $order->refresh()->status);

        $this->assertSame(0, DownloadGrant::query()->count());
    }

    public function test_successful_payment_fulfils_the_order_once(): void
    {
        $product = $this->product(['price_cents' => 2000, 'track_stock' => true, 'stock' => 5]);
        $coupon = Coupon::create(['code' => 'TWO', 'type' => 'fixed', 'value' => 200]);
        $this->addToCart($product, 2);
        $this->withCart()->postJson('/cart/coupon', ['code' => 'TWO']);
        $this->startCheckout()->assertOk();
        $order = Order::query()->firstOrFail();

        $this->webhook('payment_intent.succeeded', ['id' => $order->payment->stripe_payment_intent_id, 'amount_received' => 3800, 'currency' => 'usd'], 'evt_x', 'forged')->assertStatus(400);
        $this->assertSame(Order::PENDING, $order->refresh()->status);
        $this->assertSame(5, $product->refresh()->stock);

        $this->paySucceeded($order)->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(2, $product->sales_count);
        $this->assertSame(1, $coupon->refresh()->used_count);
        $this->assertSame('succeeded', $order->payment->status);
        $this->assertSame(['visa', '4242'], [$order->payment->card_brand, $order->payment->card_last4]);
        $this->assertSame(1, DownloadGrant::query()->count());
        $this->assertSame(0, Cart::first()->items()->count(), 'the cart is emptied');
        $this->assertSame([['calculation' => $order->stripe_tax_calculation_id, 'reference' => $order->number]], $this->gateway->taxTransactions);
        Mail::assertQueued(OrderConfirmation::class, fn (OrderConfirmation $mail) => $mail->hasTo('buyer@example.com'));

        // Stripe sends the same event again, and a second event for the same payment.
        $this->paySucceeded($order)->assertOk();
        $this->paySucceeded($order, 'evt_other')->assertOk();

        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(1, $coupon->refresh()->used_count);
        $this->assertSame(1, DownloadGrant::query()->count());
        Mail::assertQueuedCount(1);

        $this->get($order->url())->assertOk()->assertSee('Thank you, Sam')->assertSee('Your downloads')->assertSee('pack.zip');
    }

    public function test_a_declined_card_creates_no_paid_order(): void
    {
        $product = $this->product(['track_stock' => true, 'stock' => 5]);
        $this->addToCart($product);
        $this->startCheckout()->assertOk();
        $order = Order::query()->firstOrFail();

        $this->webhook('payment_intent.payment_failed', [
            'id' => $order->payment->stripe_payment_intent_id,
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ])->assertOk();

        $order->refresh();
        $this->assertSame(Order::FAILED, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertSame('Your card was declined.', $order->payment->failure_message);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(0, DownloadGrant::query()->count());
        $this->assertSame(0, Order::query()->where('status', Order::PAID)->count());
        Mail::assertNothingQueued();

        $this->getJson(URL::signedRoute('order.status', ['number' => $order->number]))
            ->assertJsonPath('failed', true)->assertJsonPath('message', 'Your card was declined.');

        // Trying again with another card continues the same order and the same PaymentIntent.
        $this->startCheckout()->assertOk()->assertJsonPath('client_secret', 'pi_fake_1_secret');
        $this->assertSame(1, Order::query()->count());
        $this->paySucceeded($order->refresh())->assertOk();
        $this->assertSame(Order::PAID, $order->refresh()->status);
    }

    public function test_pressing_pay_twice_charges_once(): void
    {
        $this->addToCart($this->product());

        $first = $this->startCheckout()->assertOk()->json('client_secret');
        $second = $this->startCheckout()->assertOk()->json('client_secret');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $this->gateway->intents);
        $this->assertSame(1, $this->gateway->createCalls);

        // Once Stripe has the money, another press never starts a second payment.
        $this->gateway->intents['pi_fake_1']['status'] = 'succeeded';
        $this->startCheckout()->assertOk()->assertJsonPath('paid', true)->assertJsonPath('client_secret', null);
        $this->assertCount(1, $this->gateway->intents);
    }

    public function test_changing_the_cart_replaces_the_unpaid_order(): void
    {
        $product = $this->product(['track_stock' => true, 'stock' => 5]);
        $this->addToCart($product, 1);
        $this->startCheckout()->assertOk();
        $this->addToCart($product, 1);
        $this->startCheckout()->assertOk()->assertJsonPath('client_secret', 'pi_fake_2_secret');

        $this->assertSame([Order::CANCELLED, Order::PENDING], Order::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(['pi_fake_1'], $this->gateway->cancelled);
        $this->assertSame(2, (int) StockReservation::query()->holding()->sum('quantity'), 'only the live order holds stock');
    }

    public function test_stock_is_held_for_the_first_customer_when_overselling_is_off(): void
    {
        $product = $this->product(['name' => 'Last One', 'track_stock' => true, 'stock' => 1]);

        $this->addToCart($product);
        $firstCart = Cart::first();
        $this->startCheckout()->assertOk();

        // A second customer already had it in their cart.
        $secondCart = Cart::create(['token' => str_repeat('b', 48)]);
        $secondCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->withCart($secondCart)->postJson('/checkout/start', $this->customer)
            ->assertStatus(422)->assertJsonPath('errors.stock.0', 'Last One: Out of stock. Update your cart to continue.');
        $this->assertSame(1, Order::query()->count());

        // The hold runs out, the unpaid order is cancelled and the unit is free again.
        $this->travel(41)->minutes();
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(Order::CANCELLED, Order::first()->status);
        $this->withCart($secondCart)->postJson('/checkout/start', $this->customer)->assertOk();
        $this->assertNotNull($firstCart);
    }

    public function test_overselling_on_accepts_the_order_and_stock_goes_negative(): void
    {
        Setting::put('inventory.allow_overselling', true);
        $product = $this->product(['track_stock' => true, 'stock' => 1]);
        $this->addToCart($product, 3);
        $this->startCheckout()->assertOk();
        $this->assertSame(0, StockReservation::query()->count());

        $this->paySucceeded(Order::first())->assertOk();
        $this->assertSame(-2, $product->refresh()->stock);
    }

    public function test_a_payment_of_the_wrong_amount_is_not_fulfilled(): void
    {
        $this->addToCart($this->product(['price_cents' => 2000]));
        $this->startCheckout()->assertOk();
        $order = Order::first();

        $this->paySucceeded($order, 'evt_low', 100)->assertOk();

        $this->assertSame(Order::PENDING, $order->refresh()->status);
        $this->assertTrue($order->events()->where('type', 'amount_mismatch')->exists());
        $this->assertSame(0, DownloadGrant::query()->count());
    }

    public function test_refund_and_dispute_events_update_the_order(): void
    {
        $this->addToCart($this->product(['price_cents' => 2000]));
        $this->startCheckout();
        $order = Order::first();
        $this->paySucceeded($order);
        $intent = $order->payment->stripe_payment_intent_id;

        $refund = ['id' => 're_1', 'payment_intent' => $intent, 'amount' => 500, 'status' => 'succeeded', 'reason' => 'requested_by_customer'];
        $this->webhook('refund.created', $refund, 'evt_r1')->assertOk();
        $this->webhook('refund.updated', $refund, 'evt_r1b')->assertOk(); // same refund again
        $order->refresh();
        $this->assertSame([Order::PARTIALLY_REFUNDED, 500], [$order->status, $order->refunded_cents]);
        $this->assertSame(0, DownloadGrant::query()->whereNotNull('revoked_at')->count());

        $this->webhook('charge.refunded', ['id' => 'ch_1', 'payment_intent' => $intent, 'amount_refunded' => 2000], 'evt_r2')->assertOk();
        $order->refresh();
        $this->assertSame([Order::REFUNDED, 2000], [$order->status, $order->refunded_cents]);
        $this->assertSame(1, DownloadGrant::query()->whereNotNull('revoked_at')->count(), 'a full refund cancels the download links');

        $this->webhook('charge.dispute.created', ['id' => 'dp_1', 'payment_intent' => $intent, 'amount' => 2000, 'status' => 'needs_response', 'reason' => 'fraudulent'], 'evt_d1')->assertOk();
        $this->assertSame('needs_response', $order->payment->disputes()->first()->status);
        $this->assertTrue($order->events()->where('type', 'dispute_opened')->exists());

        $this->webhook('customer.created', ['id' => 'cus_1'], 'evt_other_type')->assertOk(); // unrelated events are accepted and ignored
    }

    public function test_a_coupon_that_covers_everything_needs_no_payment(): void
    {
        Coupon::create(['code' => 'FREE', 'type' => 'percent', 'value' => 100]);
        $this->gateway->configured = false; // even with no Stripe keys saved
        $this->addToCart($this->product());
        $this->withCart()->postJson('/cart/coupon', ['code' => 'FREE']);

        $this->startCheckout()->assertOk()->assertJsonPath('paid', true)->assertJsonPath('client_secret', null);

        $order = Order::first();
        $this->assertSame([Order::PAID, 0], [$order->status, $order->total_cents]);
        $this->assertCount(0, $this->gateway->intents);
        $this->assertSame(1, DownloadGrant::query()->count());
    }

    public function test_checkout_explains_what_is_wrong(): void
    {
        $this->addToCart($this->product());

        $this->withCart()->postJson('/checkout/start', ['email' => 'nope', 'country' => 'US'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'That email address does not look right.')
            ->assertJsonPath('errors.name.0', 'Enter your name.')
            ->assertJsonPath('errors.postcode.0', 'Enter your ZIP or postal code.');

        $this->gateway->configured = false;
        $this->startCheckout()->assertStatus(422)->assertJsonPath('errors.checkout.0', 'Payments are not set up yet. Please try again later.');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_tax_can_be_switched_off_and_a_quote_shows_it_before_paying(): void
    {
        $this->gateway->taxRate = 0.2;
        $this->addToCart($this->product(['price_cents' => 1000]));

        $this->withCart()->postJson('/checkout/quote', ['country' => 'DE'])->assertOk()->assertJson(['tax' => 200, 'total' => 1200]);

        Setting::put('tax.stripe_tax', false);
        $this->withCart()->postJson('/checkout/quote', ['country' => 'DE'])->assertOk()->assertJson(['tax' => null, 'total' => 1000]);
        $this->startCheckout(['country' => 'DE', 'postcode' => ''])->assertOk()->assertJsonPath('total', 1000);
    }

    public function test_checkout_can_create_an_account(): void
    {
        $this->addToCart($this->product());

        $this->startCheckout(['create_account' => true, 'password' => 'short'])->assertStatus(422)->assertJsonPath('errors.password.0', 'Use at least 8 characters for your password.');
        $this->startCheckout(['create_account' => true, 'password' => 'long-enough-1'])->assertOk();

        $user = User::query()->where('email', 'buyer@example.com')->firstOrFail();
        $this->assertSame($user->id, Order::first()->user_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_download_links_are_limited_logged_and_served_from_the_private_disk(): void
    {
        config(['store.download_limit' => 2]);
        $this->addToCart($this->product());
        $this->startCheckout();
        $this->paySucceeded(Order::first());
        $grant = DownloadGrant::query()->firstOrFail();

        $this->assertFalse(Storage::disk('public')->exists($grant->asset->path));
        $this->get('/download/not-a-real-token')->assertNotFound()->assertSee('This download link is not valid.');

        $response = $this->get($grant->url())->assertOk();
        $this->assertStringContainsString('pack.zip', (string) $response->headers->get('content-disposition'));
        $this->get($grant->url())->assertOk();
        $this->assertSame(2, $grant->refresh()->downloads_used);
        $this->assertSame(2, $grant->logs()->count());

        $this->get($grant->url())->assertStatus(410)->assertSee('used the maximum number of times');

        $grant->update(['downloads_used' => 0, 'expires_at' => now()->subDay()]);
        $this->get($grant->url())->assertSee('has expired');
        $grant->update(['expires_at' => null, 'revoked_at' => now()]);
        $this->get($grant->url())->assertSee('was cancelled');
    }

    public function test_an_order_page_needs_its_signed_link(): void
    {
        $this->addToCart($this->product());
        $this->startCheckout();
        $order = Order::first();

        $this->get('/order/'.$order->number)->assertForbidden();
        $this->get($order->url().'x')->assertForbidden();
        $this->get($order->url())->assertOk();
    }

    public function test_the_real_gateway_verifies_stripe_signatures(): void
    {
        Setting::put('stripe.test.webhook_secret', 'whsec_test_secret', true);
        $gateway = new StripeGateway(new StripeSettings);
        $payload = json_encode(['id' => 'evt_real', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1', 'object' => 'payment_intent', 'amount_received' => 500]]]);
        $time = time();
        $signature = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test_secret');

        $event = $gateway->parseWebhook($payload, $signature);
        $this->assertSame(['evt_real', 'payment_intent.succeeded', 500], [$event['id'], $event['type'], $event['object']['amount_received']]);

        foreach ([null, 'garbage', "t={$time},v1=".str_repeat('0', 64), 't='.($time - 3600).',v1='.hash_hmac('sha256', ($time - 3600).".{$payload}", 'whsec_test_secret')] as $bad) {
            try {
                $gateway->parseWebhook($payload, $bad);
                $this->fail('A bad signature was accepted.');
            } catch (InvalidWebhookException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_webhook_is_refused_when_no_signing_secret_is_saved(): void
    {
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=abc'], '{"id":"evt_1"}')->assertStatus(400);
    }
}
