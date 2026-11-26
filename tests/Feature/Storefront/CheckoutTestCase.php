<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\DigitalAsset;
use App\Models\Order;
use App\Models\Product;
use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGateway;
use Tests\TestCase;

abstract class CheckoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeGateway $gateway;

    protected array $customer = ['email' => 'Buyer@Example.com', 'name' => 'Sam Buyer', 'country' => 'US', 'postcode' => '10001'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        Mail::fake();
        Storage::fake('downloads');
    }

    protected function product(array $attributes = [], bool $withFile = true): Product
    {
        $product = Product::factory()->create($attributes + ['price_cents' => 2000]);
        if ($withFile) {
            Storage::disk('downloads')->put("files/{$product->id}/pack.zip", 'zip-bytes');
            DigitalAsset::create(['product_id' => $product->id, 'disk' => 'downloads', 'path' => "files/{$product->id}/pack.zip", 'filename' => 'pack.zip', 'size_bytes' => 9]);
        }

        return $product;
    }

    /** Send the cart cookie the way a returning browser would. */
    protected function withCart(?Cart $cart = null): static
    {
        $cart ??= Cart::query()->latest('id')->first();

        // JSON test requests leave cookies out unless credentials are switched on.
        return $cart ? $this->withCredentials()->withCookie('cart', $cart->token) : $this;
    }

    protected function addToCart(Product $product, int $quantity = 1, ?int $variantId = null): TestResponse
    {
        return $this->withCart()->postJson('/cart/items', ['product_id' => $product->id, 'variant_id' => $variantId, 'quantity' => $quantity]);
    }

    protected function startCheckout(array $overrides = []): TestResponse
    {
        return $this->withCart()->postJson('/checkout/start', $overrides + $this->customer);
    }

    protected function webhook(string $type, array $object, string $id = 'evt_1', string $signature = 'valid'): TestResponse
    {
        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $id, 'type' => $type, 'object' => $object]));
    }

    protected function paySucceeded(Order $order, string $eventId = 'evt_paid', ?int $amount = null): TestResponse
    {
        return $this->webhook('payment_intent.succeeded', [
            'id' => $order->payment->stripe_payment_intent_id,
            'amount_received' => $amount ?? (int) $order->total_cents,
            'currency' => 'usd',
        ], $eventId);
    }
}
