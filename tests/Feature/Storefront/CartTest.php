<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Checkout\CartService;
use App\Services\ProductWriter;
use Illuminate\Routing\Middleware\ThrottleRequests;

class CartTest extends CheckoutTestCase
{
    public function test_cart_survives_a_return_visit_and_uses_database_prices(): void
    {
        $product = $this->product(['name' => 'Loop Pack', 'price_cents' => 1600]);

        // A price sent by the browser is ignored.
        $this->postJson('/cart/items', ['product_id' => $product->id, 'quantity' => 2, 'price' => 1, 'unit_price_cents' => 1])
            ->assertOk()->assertJsonPath('count', 2)->assertCookie('cart');

        $this->withCart()->get('/cart')->assertOk()->assertSee('Loop Pack')->assertSee('$32.00');
        $this->withCart()->get('/')->assertSee('data-cart-count', false);
        $this->withCookie('cart', 'someone-elses-token')->get('/cart')->assertSee('Your cart is empty'); // a different browser has its own cart
    }

    public function test_quantity_changes_and_removal(): void
    {
        $product = $this->product();
        $this->addToCart($product, 1);
        $item = Cart::first()->items()->first();

        $this->withCart()->patchJson("/cart/items/{$item->id}", ['quantity' => 3])->assertJsonPath('count', 3);
        $this->withCart()->patchJson("/cart/items/{$item->id}", ['quantity' => 0])->assertJsonPath('count', 0);
        $this->addToCart($product, 2);
        $this->withCart()->deleteJson('/cart/items/'.Cart::first()->items()->first()->id)->assertJsonPath('count', 0);
    }

    public function test_quantity_is_limited_to_available_stock(): void
    {
        $product = $this->product(['track_stock' => true, 'stock' => 3]);

        $this->addToCart($product, 10)->assertOk()->assertJsonPath('count', 3);
        $this->addToCart($product, 1)->assertJsonPath('count', 3);

        $soldOut = $this->product(['track_stock' => true, 'stock' => 0]);
        $this->addToCart($soldOut)->assertStatus(422)->assertJsonPath('error', 'This item is out of stock.');
    }

    public function test_overselling_switch_lifts_the_stock_limit(): void
    {
        Setting::put('inventory.allow_overselling', true);
        $product = $this->product(['track_stock' => true, 'stock' => 0]);

        $this->addToCart($product, 4)->assertOk()->assertJsonPath('count', 4);
    }

    public function test_a_variant_must_be_chosen_and_must_belong_to_the_product(): void
    {
        $product = app(ProductWriter::class)->save(new Product, [
            'name' => 'Presets', 'slug' => 'presets', 'status' => 'active', 'price' => '22',
            'options' => [['name' => 'Tone', 'values' => [['value' => 'Warm'], ['value' => 'Cool']]]],
            'variants' => [['values' => ['Warm'], 'price' => '22'], ['values' => ['Cool'], 'price' => '30']],
        ]);
        $other = $this->product();

        $this->addToCart($product)->assertStatus(422)->assertJsonPath('error', 'Choose an option first.');
        $this->addToCart($other, 1, $product->variants[1]->id)->assertOk(); // variant of another product is ignored
        $this->addToCart($product, 1, $product->variants[1]->id)->assertOk();

        $summary = app(CartService::class)->summary(Cart::first());
        $this->assertSame(2000 + 3000, $summary->subtotalCents);
        $this->assertSame('Cool', $summary->lines[1]->variantLabel);
    }

    public function test_draft_products_cannot_be_added(): void
    {
        $draft = Product::factory()->draft()->create();
        $this->addToCart($draft)->assertStatus(422);
    }

    public function test_coupon_rules(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // this test tries more codes than a visitor may per minute
        $this->addToCart($this->product(['price_cents' => 5000]), 2); // subtotal $100

        $apply = fn (string $code) => $this->withCart()->postJson('/cart/coupon', ['code' => $code]);
        $total = fn () => app(CartService::class)->summary(Cart::first()->refresh())->totalCents();

        Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10]);
        Coupon::create(['code' => 'FIVER', 'type' => 'fixed', 'value' => 500]);
        Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 99900]);
        Coupon::create(['code' => 'MIN200', 'type' => 'percent', 'value' => 50, 'min_spend_cents' => 20000]);
        Coupon::create(['code' => 'OLD', 'type' => 'percent', 'value' => 50, 'ends_at' => now()->subDay()]);
        Coupon::create(['code' => 'SOON', 'type' => 'percent', 'value' => 50, 'starts_at' => now()->addDay()]);
        Coupon::create(['code' => 'OFF', 'type' => 'percent', 'value' => 50, 'is_active' => false]);
        $usedUp = Coupon::create(['code' => 'USED', 'type' => 'percent', 'value' => 50, 'usage_limit' => 1]);
        $usedUp->forceFill(['used_count' => 1])->save();

        $apply('ten')->assertOk(); // codes are not case sensitive
        $this->assertSame(9000, $total());

        $apply('FIVER')->assertOk();
        $this->assertSame(9500, $total());

        $apply('BIG')->assertOk(); // a discount never goes below zero
        $this->assertSame(0, $total());

        $apply('MIN200')->assertStatus(422)->assertJsonPath('error', 'That code needs a minimum spend of $200.00.');
        $apply('OLD')->assertStatus(422)->assertJsonPath('error', 'That code has expired.');
        $apply('SOON')->assertStatus(422)->assertJsonPath('error', 'That code is not active yet.');
        $apply('OFF')->assertStatus(422)->assertJsonPath('error', 'That code is not valid.');
        $apply('USED')->assertStatus(422)->assertJsonPath('error', 'That code has been used the maximum number of times.');
        $apply('NOPE')->assertStatus(422)->assertJsonPath('error', 'That code is not valid.');
        $this->assertSame(0, $total(), 'a refused code leaves the applied one in place');

        $this->withCart()->deleteJson('/cart/coupon')->assertOk();
        $this->assertSame(10000, $total());
    }

    public function test_a_coupon_stops_applying_when_the_cart_no_longer_qualifies(): void
    {
        $product = $this->product(['price_cents' => 5000]);
        $this->addToCart($product, 2);
        Coupon::create(['code' => 'MIN80', 'type' => 'fixed', 'value' => 1000, 'min_spend_cents' => 8000]);
        $this->withCart()->postJson('/cart/coupon', ['code' => 'MIN80'])->assertOk();

        $item = Cart::first()->items()->first();
        $this->withCart()->patchJson("/cart/items/{$item->id}", ['quantity' => 1]);

        $summary = app(CartService::class)->summary(Cart::first());
        $this->assertSame(0, $summary->discountCents);
        $this->assertNotNull($summary->couponProblem);
    }

    public function test_coupon_attempts_are_rate_limited(): void
    {
        $this->addToCart($this->product());
        for ($i = 0; $i < 10; $i++) {
            $this->withCart()->postJson('/cart/coupon', ['code' => "GUESS{$i}"])->assertStatus(422);
        }
        $this->withCart()->postJson('/cart/coupon', ['code' => 'GUESS11'])->assertStatus(429);
    }

    public function test_buy_now_goes_to_checkout_with_only_that_item(): void
    {
        $inCart = $this->product(['name' => 'Already In Cart']);
        $bought = $this->product(['name' => 'Bought Now']);
        $this->addToCart($inCart);

        $this->withCart(Cart::first())->post('/cart/items', ['product_id' => $bought->id, 'intent' => 'buy'])
            ->assertRedirect('/checkout?buy=1');

        $this->withCart(Cart::first())->get('/checkout?buy=1')->assertOk()->assertSee('Bought Now')->assertDontSee('Already In Cart');
        $this->withCart(Cart::first())->get('/checkout')->assertOk()->assertSee('Already In Cart')->assertDontSee('Bought Now');
    }

    public function test_product_page_shows_buy_buttons_and_empty_checkout_redirects(): void
    {
        $product = $this->product(['slug' => 'thing']);
        $this->get('/product/thing')->assertOk()->assertSee('Add to cart')->assertSee('Buy now');
        $this->get('/checkout')->assertRedirect('/cart');
    }
}
