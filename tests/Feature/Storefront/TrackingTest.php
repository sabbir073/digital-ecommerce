<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\ConsentRecord;
use App\Models\EventLog;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\Integrations\CustomScripts;
use App\Services\Integrations\Integrations;
use App\Support\Blocks;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

class TrackingTest extends CheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1]), 'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'])]);
    }

    private function on(string $service, array $settings): void
    {
        app(Integrations::class)->save($service, $settings, true);
    }

    /** @return array<string, mixed>|null the tracking config printed in a page */
    private function config(string $html): ?array
    {
        return preg_match('~<script type="application/json" id="tracking-config">(.*?)</script>~s', $html, $m) ? json_decode($m[1], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function metaOn(): void
    {
        $this->on('meta_pixel', ['pixel_id' => '123456789012']);
        $this->on('meta_capi', ['access_token' => 'SECRET-TOKEN-VALUE', 'test_event_code' => '']);
    }

    public function test_nothing_is_loaded_until_a_service_is_switched_on(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertNull($this->config($html));
        $this->assertStringNotContainsString('cookie-banner', $html);
        $this->assertStringNotContainsString('tracking-config', $html);

        // Saved but switched off, or switched on without its ID: still nothing.
        app(Integrations::class)->save('ga4', ['measurement_id' => 'G-ABC1234567'], false);
        app(Integrations::class)->save('meta_pixel', ['pixel_id' => ''], true);
        $this->assertNull($this->config($this->get('/')->getContent()));
    }

    public function test_a_page_carries_public_ids_the_banner_and_never_a_secret(): void
    {
        $this->on('ga4', ['measurement_id' => 'G-ABC1234567']);
        $this->metaOn();

        $html = $this->get('/')->assertOk()->assertSee('id="cookie-banner"', false)->assertSee('Cookies on this store')->assertSee('Only necessary')->getContent();
        $config = $this->config($html);
        $this->assertSame(['G-ABC1234567'], $config['services']['ga4']['ids']);
        $this->assertSame('analytics', $config['services']['ga4']['category']);
        $this->assertSame(['123456789012'], $config['services']['meta_pixel']['ids']);
        $this->assertArrayNotHasKey('meta_capi', $config['services']);
        $this->assertTrue($config['consent']['required']);
        $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', $html);
        // No tag is written into the page: the script loads them after the visitor's choice.
        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('connect.facebook.net', $html);

        Setting::put('consent', ['enabled' => false]);
        $html = $this->get('/')->getContent();
        $this->assertFalse($this->config($html)['consent']['required']);
        $this->assertStringNotContainsString('cookie-banner', $html);
    }

    public function test_europe_only_mode_asks_by_country_and_leaves_the_rest_to_the_browser(): void
    {
        $this->on('ga4', ['measurement_id' => 'G-ABC1234567']);
        Setting::put('consent', ['enabled' => true, 'region' => 'europe']);

        $this->assertTrue($this->config($this->withHeader('CF-IPCountry', 'DE')->get('/')->getContent())['consent']['required']);
        $this->assertFalse($this->config($this->withHeader('CF-IPCountry', 'US')->get('/')->getContent())['consent']['required']);
        $this->flushHeaders();
        $html = $this->get('/')->getContent();
        $this->assertNull($this->config($html)['consent']['required']);
        $this->assertStringContainsString('cookie-banner', $html);
    }

    public function test_pages_report_their_events(): void
    {
        $this->on('ga4', ['measurement_id' => 'G-ABC1234567']);
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $product = $this->product(['name' => 'Studio Template', 'slug' => 'studio-template', 'category_id' => $category->id, 'price_cents' => 4900, 'brand' => 'Northlane']);

        $event = $this->config($this->get('/product/studio-template')->getContent())['events'][0];
        $this->assertSame('view_item', $event['name']);
        $this->assertSame(49, $event['data']['value']);
        $this->assertSame(['id' => (string) $product->id, 'name' => 'Studio Template', 'variant' => null, 'category' => 'Templates', 'brand' => 'Northlane', 'price' => 49, 'quantity' => 1], $event['data']['items'][0]);

        $events = $this->config($this->get('/search?q=studio')->getContent())['events'];
        $this->assertSame(['search', 'view_item_list'], array_column($events, 'name'));
        $this->assertSame('studio', $events[0]['data']['term']);
        $this->assertSame((string) $product->id, $events[1]['data']['items'][0]['id']);

        // Adding to the cart answers with the event for the script to pass on.
        $this->postJson('/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertOk()
            ->assertJsonPath('track.name', 'add_to_cart')->assertJsonPath('track.data.value', 98)->assertJsonPath('track.data.items.0.quantity', 2);

        $checkout = $this->config($this->withCart()->get('/checkout')->getContent())['events'][0];
        $this->assertSame('begin_checkout', $checkout['name']);
        $this->assertSame(98, $checkout['data']['value']);
    }

    public function test_sign_up_is_reported_on_the_next_page_only(): void
    {
        $this->on('ga4', ['measurement_id' => 'G-ABC1234567']);
        $this->post('/register', ['name' => 'Maya Lin', 'email' => 'maya@example.com', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])->assertRedirect();

        $this->assertSame(['sign_up'], array_column($this->config($this->get('/account')->getContent())['events'], 'name'));
        $this->assertSame([], $this->config($this->get('/account')->getContent())['events']);
    }

    public function test_the_purchase_event_reaches_the_browser_once_and_the_server_services_once(): void
    {
        $this->metaOn();
        $this->on('tiktok', ['pixel_id' => 'CABCDEFGHIJ1234567890', 'access_token' => 'TT-SECRET', 'test_event_code' => '']);
        $product = $this->product(['name' => 'Studio Template', 'price_cents' => 4900]);

        $this->withCredentials()->withUnencryptedCookies(['cc' => 'a1m1.visitor12345', '_fbp' => 'fb.1.1700000000.111'])->addToCart($product);
        $this->startCheckout()->assertOk();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertTrue($order->tracking['marketing']);
        $this->assertSame('fb.1.1700000000.111', $order->tracking['fbp']);

        // Before payment is confirmed nothing is sent anywhere.
        $this->assertSame([], $this->config($this->get($order->url())->getContent())['events']);
        Http::assertNothingSent();

        $this->paySucceeded($order)->assertOk();
        $this->paySucceeded($order, 'evt_again')->assertOk();
        $this->paySucceeded($order)->assertOk();

        $sent = Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertCount(1, $sent);
        $body = $sent->first()[0]->data();
        $this->assertStringContainsString('/v26.0/123456789012/events', $sent->first()[0]->url());
        $this->assertSame('Purchase', $body['data'][0]['event_name']);
        $this->assertSame('purchase-'.$order->number, $body['data'][0]['event_id']);
        $this->assertSame(hash('sha256', $order->email), $body['data'][0]['user_data']['em']);
        $this->assertSame('fb.1.1700000000.111', $body['data'][0]['user_data']['fbp']);
        $this->assertEquals(49, $body['data'][0]['custom_data']['value']);
        $this->assertSame($order->number, $body['data'][0]['custom_data']['order_id']);
        $this->assertSame([(string) $product->id], $body['data'][0]['custom_data']['content_ids']);

        $tiktok = Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), 'business-api.tiktok.com'));
        $this->assertCount(1, $tiktok);
        $this->assertSame('TT-SECRET', $tiktok->first()[0]->header('Access-Token')[0]);
        $this->assertSame('Purchase', $tiktok->first()[0]->data()['data'][0]['event']);
        $this->assertSame('purchase-'.$order->number, $tiktok->first()[0]->data()['data'][0]['event_id']);
        $this->assertSame(2, EventLog::query()->where('event', 'purchase')->where('ok', true)->count());

        // The browser gets the same event, with the same ID, the first time the paid order is shown. Never again.
        $first = $this->config($this->get($order->url())->getContent())['events'];
        $this->assertSame(['purchase'], array_column($first, 'name'));
        $this->assertSame('purchase-'.$order->number, $first[0]['id']);
        $this->assertSame($order->number, $first[0]['data']['order']);
        $this->assertSame([], $this->config($this->get($order->url())->getContent())['events']);
        $this->assertSame([], $this->config($this->get($order->url())->getContent())['events']);
    }

    public function test_a_buyer_who_did_not_allow_marketing_is_not_reported_from_the_server(): void
    {
        $this->metaOn();
        $product = $this->product(['price_cents' => 4900]);

        $this->withCredentials()->withUnencryptedCookies(['cc' => 'a1m0.visitor12345'])->addToCart($product);
        $this->startCheckout()->assertOk();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertFalse($order->tracking['marketing']);

        $this->paySucceeded($order)->assertOk();
        Http::assertNothingSent();
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_browser_events_are_logged_and_passed_on_with_the_same_id_when_allowed(): void
    {
        $this->metaOn();
        $event = ['name' => 'add_to_cart', 'id' => 'evt-abc-123', 'url' => 'https://store.test/product/x', 'sent' => ['meta_pixel', 'snapchat'],
            'data' => ['currency' => 'USD', 'value' => 49, 'email' => 'nobody@example.com', 'items' => [['id' => '7', 'name' => 'Studio Template', 'price' => 49, 'quantity' => 1]]]];

        // No recorded choice: logged as a browser event, but nothing leaves the server.
        $this->postJson('/events', $event)->assertOk();
        Http::assertNothingSent();
        $log = EventLog::query()->where('service', 'browser')->firstOrFail();
        $this->assertSame('add_to_cart', $log->event);
        $this->assertStringContainsString('Meta Pixel', $log->response);
        $this->assertStringNotContainsString('Snapchat', $log->response);
        $this->assertArrayNotHasKey('email', $log->payload['data']);

        $this->withCredentials()->withUnencryptedCookies(['cc' => 'a1m1.visitor12345'])->postJson('/events', $event)->assertOk();
        $sent = Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertCount(1, $sent);
        $this->assertSame('AddToCart', $sent->first()[0]->data()['data'][0]['event_name']);
        $this->assertSame('evt-abc-123', $sent->first()[0]->data()['data'][0]['event_id']);
        $this->assertSame('https://store.test/product/x', $sent->first()[0]->data()['data'][0]['event_source_url']);

        // A purchase can never be reported by a browser, and unknown events are refused.
        $this->postJson('/events', ['name' => 'purchase'] + $event)->assertStatus(422);
        $this->postJson('/events', ['name' => 'made_up'] + $event)->assertStatus(422);
    }

    public function test_a_failed_server_event_marks_the_service_with_the_reason(): void
    {
        $this->metaOn();
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        $this->withCredentials()->withUnencryptedCookies(['cc' => 'a1m1.visitor12345'])
            ->postJson('/events', ['name' => 'search', 'id' => 'evt-1', 'sent' => ['meta_pixel'], 'data' => ['term' => 'presets']])->assertOk();

        $row = app(Integrations::class)->all()['meta_capi'];
        $this->assertSame('error', $row['status']);
        $this->assertSame('Invalid OAuth access token.', $row['last_error']);
        $this->assertFalse(EventLog::query()->where('service', 'meta_capi')->firstOrFail()->ok);
    }

    public function test_a_cookie_choice_is_recorded(): void
    {
        $this->postJson('/consent', ['visitor' => 'visitor12345', 'analytics' => true, 'marketing' => false])->assertOk();
        $this->postJson('/consent', ['visitor' => 'visitor12345', 'analytics' => false, 'marketing' => false])->assertOk();
        $this->postJson('/consent', ['visitor' => 'BAD ID', 'analytics' => true, 'marketing' => true])->assertStatus(422);

        $this->assertSame(1, ConsentRecord::query()->count());
        $this->assertEquals(['necessary' => true, 'analytics' => false, 'marketing' => false], ConsentRecord::query()->firstOrFail()->categories);
    }

    public function test_product_feeds_list_products_on_sale_that_have_an_image(): void
    {
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $with = $this->product(['name' => 'Studio & Co Template', 'slug' => 'studio', 'category_id' => $category->id, 'price_cents' => 4900, 'description' => '<p>Clean, "fast" template.</p>']);
        $with->images()->attach(Media::create(['disk' => 'public', 'path' => 'media/x/a.jpg', 'filename' => 'a.jpg', 'type' => 'image', 'mime' => 'image/jpeg'])->id, ['position' => 0]);
        $this->product(['name' => 'No Picture', 'slug' => 'no-picture']);
        $draft = Product::factory()->draft()->create(['name' => 'Draft One', 'slug' => 'draft-one']);
        $draft->images()->attach(Media::query()->firstOrFail()->id, ['position' => 0]);

        $xml = $this->get('/feeds/google.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $feed = simplexml_load_string($xml);
        $this->assertNotFalse($feed);
        $items = $feed->channel->item;
        $this->assertCount(1, $items);
        $g = $items[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame((string) $with->id, (string) $g->id);
        $this->assertSame('Studio & Co Template', (string) $g->title);
        $this->assertSame('Clean, "fast" template.', (string) $g->description);
        $this->assertSame('49.00 USD', (string) $g->price);
        $this->assertSame('in stock', (string) $g->availability);
        $this->assertSame('Templates', (string) $g->product_type);
        $this->assertSame(route('product', 'studio'), (string) $g->link);
        $this->assertNotSame('', (string) $g->image_link);

        $csv = $this->get('/feeds/meta.csv')->assertOk()->streamedContent();
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        $this->assertSame(['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'brand', 'product_type'], $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertSame([(string) $with->id, 'Studio & Co Template'], array_slice($lines[1], 0, 2));
    }

    public function test_custom_scripts_show_site_wide_or_only_on_the_chosen_addresses(): void
    {
        app(CustomScripts::class)->save([
            ['id' => 'a', 'name' => 'Everywhere', 'placement' => 'head', 'scope' => 'all', 'paths' => [], 'code' => '<script>window.everywhere = 1;</script>', 'enabled' => true],
            ['id' => 'b', 'name' => 'Cart only', 'placement' => 'body_end', 'scope' => 'paths', 'paths' => ['/cart', '/blog/*'], 'code' => '<script>window.cartOnly = 1;</script>', 'enabled' => true],
            ['id' => 'c', 'name' => 'Off', 'placement' => 'body_start', 'scope' => 'all', 'paths' => [], 'code' => '<script>window.switchedOff = 1;</script>', 'enabled' => false],
        ]);

        $this->get('/')->assertSee('window.everywhere = 1;', false)->assertDontSee('window.cartOnly', false)->assertDontSee('window.switchedOff', false);
        $this->get('/cart')->assertSee('window.everywhere = 1;', false)->assertSee('window.cartOnly = 1;', false);
        $this->get('/blog')->assertSee('window.cartOnly = 1;', false);
        $this->get('/products')->assertDontSee('window.cartOnly', false);
    }

    public function test_a_selling_page_adds_its_own_ids_to_the_site_wide_ones(): void
    {
        $this->on('meta_pixel', ['pixel_id' => '123456789012']);
        $product = $this->product(['name' => 'Preset Pack', 'price_cents' => 3000]);
        $blocks = [Blocks::make('hero', ['headline' => 'Edit faster']), Blocks::make('order')];
        $page = SellingPage::create(['name' => 'Preset launch', 'slug' => 'preset-launch', 'product_id' => $product->id, 'status' => SellingPage::PUBLISHED, 'draft_blocks' => $blocks, 'published_blocks' => $blocks, 'published_at' => now(),
            'pixel_ids' => ['meta_pixel' => '999888777666', 'tiktok' => 'CPAGEONLY1234567890', 'ga4' => 'not valid']]);
        $page->offers()->create(['label' => 'One pack', 'price_cents' => 2500, 'is_default' => true, 'position' => 0])->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $config = $this->config($this->get('/p/preset-launch')->assertOk()->getContent());
        $this->assertSame(['123456789012', '999888777666'], $config['services']['meta_pixel']['ids']);
        $this->assertSame(['CPAGEONLY1234567890'], $config['services']['tiktok']['ids']);
        $this->assertArrayNotHasKey('ga4', $config['services']);
        $this->assertSame('view_item', $config['events'][0]['name']);
        $this->assertSame(25, $config['events'][0]['data']['value']);

        // Other pages keep only the site-wide ID, and a preview reports nothing.
        $this->assertSame(['123456789012'], $this->config($this->get('/')->getContent())['services']['meta_pixel']['ids']);
    }
}
