<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\AuthenticateAdmin;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\HomeSection;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellingPage;
use App\Support\Blocks;
use App\Support\HtmlSanitizer;
use App\Support\VideoEmbed;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class SellingPageTest extends CheckoutTestCase
{
    /** @param list<array<string, mixed>>|null $blocks */
    private function page(?Product $product = null, ?array $blocks = null, bool $published = true): SellingPage
    {
        $product ??= $this->product(['name' => 'Preset Pack', 'price_cents' => 3000]);
        $blocks ??= [
            Blocks::make('hero', ['headline' => 'Edit faster', 'subline' => 'One click looks.', 'button_label' => 'Get it now']),
            Blocks::make('faq', ['items' => [['q' => 'Is payment secure?', 'a' => 'Yes.']]]),
            Blocks::make('order', ['heading' => 'Complete your order']),
        ];

        $page = SellingPage::create([
            'name' => 'Preset launch',
            'slug' => 'preset-launch',
            'product_id' => $product->id,
            'status' => $published ? SellingPage::PUBLISHED : SellingPage::DRAFT,
            'draft_blocks' => $blocks,
            'published_blocks' => $published ? $blocks : null,
            'published_at' => $published ? now() : null,
        ]);
        $this->offer($page, 'One pack', 2500, [[$product, 1]], default: true);

        return $page;
    }

    /** @param list<array{0: Product, 1: int}> $items */
    private function offer(SellingPage $page, string $label, int $price, array $items, bool $default = false): Offer
    {
        $offer = $page->offers()->create(['label' => $label, 'price_cents' => $price, 'is_default' => $default, 'position' => $page->offers()->count()]);
        foreach ($items as [$product, $quantity]) {
            $offer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
        }

        return $offer;
    }

    private function start(SellingPage $page, Offer $offer, array $overrides = []): TestResponse
    {
        return $this->postJson("/p/{$page->slug}/start", $overrides + ['offer_id' => $offer->id] + $this->customer);
    }

    public function test_a_published_page_shows_its_blocks_without_store_navigation(): void
    {
        $page = $this->page();

        $this->get('/p/preset-launch')->assertOk()
            ->assertSee('Edit faster')->assertSee('Get it now')->assertSee('Is payment secure?')
            ->assertSee('One pack')->assertSee('$25.00')
            ->assertSee('id="order"', false)
            ->assertDontSee('Search products')->assertDontSee('data-cart-open', false);

        $this->assertSame(1, (int) DB::table('page_views')->where('selling_page_id', $page->id)->value('views'));
        $this->get('/p/preset-launch');
        $row = DB::table('page_views')->where('selling_page_id', $page->id)->first();
        $this->assertSame([2, 1], [(int) $row->views, (int) $row->unique_visitors]);
    }

    public function test_unpublished_and_deleted_pages_are_not_found(): void
    {
        $page = $this->page(published: false);
        $this->get('/p/preset-launch')->assertNotFound();
        $this->get('/p/preset-launch?preview=1')->assertNotFound();
        $this->start($page, $page->offers->first())->assertNotFound();

        $page->update(['status' => SellingPage::PUBLISHED, 'published_blocks' => $page->draft_blocks]);
        $this->get('/p/preset-launch')->assertOk();
        $page->delete();
        $this->get('/p/preset-launch')->assertNotFound();
        $this->get('/p/nothing-here')->assertNotFound();
    }

    public function test_staff_can_preview_a_draft(): void
    {
        $this->seed(RoleSeeder::class);
        $this->page(published: false);
        $admin = AdminUser::factory()->role('staff')->create();

        $this->actingAs($admin, 'admin')->withSession([AuthenticateAdmin::SESSION_KEY => $admin->session_version])
            ->get('/p/preset-launch?preview=1')->assertOk()->assertSee('Preview of the draft')->assertSee('Edit faster');
        $this->assertSame(0, DB::table('page_views')->count(), 'previews are not counted as views');
    }

    public function test_a_customer_pays_the_pack_price_on_the_page_and_the_order_records_its_source(): void
    {
        $bonus = $this->product(['name' => 'Bonus Brushes', 'price_cents' => 1000]);
        $page = $this->page();
        $bundle = $this->offer($page, 'Bundle', 3300, [[$page->product, 1], [$bonus, 2]]);

        $response = $this->start($page, $bundle, ['total' => 1, 'price' => 1])->assertOk()->assertJsonPath('paid', false)->assertJsonPath('total', 3300);

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame(['selling_page', $page->id, $bundle->id, Order::PENDING], [$order->source, $order->selling_page_id, $order->offer_id, $order->status]);
        // The pack price wins over the catalog prices (30.00 + 2 x 10.00) and the lines add up to it.
        $this->assertSame(3300, $order->subtotal_cents);
        $this->assertSame(3300, (int) $order->items->sum('line_total_cents'));
        $this->assertSame([1, 2], $order->items->pluck('quantity')->all());
        $this->assertSame(3300, $this->gateway->intents['pi_fake_1']['amount']);

        $this->getJson($response->json('confirm_url'))->assertOk()->assertJson(['state' => 'pending']);

        $this->paySucceeded($order)->assertOk();
        $this->getJson($response->json('confirm_url'))->assertOk()->assertJsonPath('state', 'paid')
            ->assertSee($order->number)->assertSee('Bonus Brushes')->assertSee('download', false);
        $this->assertSame(2, $order->refresh()->items->flatMap->downloads->count());

        // The confirmation needs its signed link.
        $this->getJson("/p/{$page->slug}/order/{$order->number}")->assertForbidden();
        // Returning from a bank's page with the signed link shows the page with the confirmation waiting.
        $this->get($response->json('return_url'))->assertOk()->assertSee('confirmUrl', false);
    }

    public function test_pressing_pay_twice_on_the_page_charges_once(): void
    {
        $page = $this->page();
        $offer = $page->offers->first();

        $first = $this->start($page, $offer)->assertOk()->json('client_secret');
        $second = $this->start($page, $offer)->assertOk()->json('client_secret');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $this->gateway->intents);
    }

    public function test_a_declined_card_on_the_page_leaves_the_order_unpaid(): void
    {
        $page = $this->page();
        $response = $this->start($page, $page->offers->first())->assertOk();
        $order = Order::query()->firstOrFail();

        $this->webhook('payment_intent.payment_failed', [
            'id' => $order->payment->stripe_payment_intent_id,
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ], 'evt_fail')->assertOk();

        $this->assertSame(Order::FAILED, $order->refresh()->status);
        $this->getJson($response->json('confirm_url'))->assertJsonPath('state', 'failed')->assertSee('Your card was declined.');
    }

    public function test_a_pack_is_sold_out_when_any_item_in_it_is_out_of_stock(): void
    {
        $inStock = $this->product(['name' => 'Preset Pack', 'price_cents' => 3000, 'track_stock' => true, 'stock' => 3]);
        $empty = $this->product(['name' => 'Bonus Brushes', 'price_cents' => 1000, 'track_stock' => true, 'stock' => 0]);
        $page = $this->page($inStock);
        $bundle = $this->offer($page, 'Bundle', 3300, [[$inStock, 1], [$empty, 1]]);

        $html = $this->get('/p/preset-launch')->assertOk()->assertSee('Sold out')->getContent();
        $this->assertMatchesRegularExpression('/value="'.$bundle->id.'"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$page->offers->first()->id.'"[^>]*disabled/', $html);

        $this->start($page, $bundle)->assertStatus(422)->assertJsonPath('errors.stock.0', 'Bonus Brushes: Sold out.');
        $this->assertSame(0, Order::query()->count());
        $this->start($page, $page->offers->first())->assertOk();
    }

    public function test_coupons_work_on_selling_pages_unless_switched_off_for_them(): void
    {
        $page = $this->page();
        $offer = $page->offers->first();
        Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10]);
        Coupon::create(['code' => 'CARTONLY', 'type' => 'percent', 'value' => 50, 'applies_to_selling_pages' => false]);

        $this->postJson("/p/{$page->slug}/coupon", ['offer_id' => $offer->id, 'code' => 'cartonly'])
            ->assertStatus(422)->assertJsonPath('errors.coupon.0', 'That code cannot be used on this page.');
        $this->postJson("/p/{$page->slug}/coupon", ['offer_id' => $offer->id, 'code' => 'ten'])
            ->assertOk()->assertJson(['discount' => 250, 'total' => 2250, 'coupon' => 'TEN']);
        $this->postJson("/p/{$page->slug}/quote", ['offer_id' => $offer->id, 'country' => 'DE'])->assertOk()->assertJson(['total' => 2250]);

        $this->start($page, $offer)->assertOk()->assertJsonPath('total', 2250);
        $this->assertSame('TEN', Order::query()->firstOrFail()->coupon_code);

        $this->postJson("/p/{$page->slug}/coupon", ['offer_id' => $offer->id, 'code' => ''])->assertOk()->assertJson(['discount' => 0, 'total' => 2500, 'coupon' => null]);
    }

    public function test_tax_is_added_for_the_customers_country(): void
    {
        $this->gateway->taxRate = 0.2;
        $page = $this->page();
        $offer = $page->offers->first();

        $this->postJson("/p/{$page->slug}/quote", ['offer_id' => $offer->id])->assertOk()->assertJson(['tax' => null, 'total' => 2500]);
        $this->postJson("/p/{$page->slug}/quote", ['offer_id' => $offer->id, 'country' => 'DE'])->assertOk()->assertJson(['tax' => 500, 'total' => 3000]);
        $this->start($page, $offer, ['country' => 'DE', 'postcode' => ''])->assertOk()->assertJsonPath('total', 3000);
    }

    public function test_a_pack_from_another_page_cannot_be_bought_here_and_fields_are_checked(): void
    {
        $page = $this->page();
        $other = SellingPage::create(['name' => 'Other', 'slug' => 'other', 'product_id' => $page->product_id, 'status' => SellingPage::PUBLISHED, 'published_blocks' => [Blocks::make('order')]]);
        $foreign = $this->offer($other, 'Cheap', 100, [[$page->product, 1]]);

        $this->start($page, $foreign)->assertNotFound();
        $this->start($page, $page->offers->first(), ['email' => 'nope', 'name' => '', 'country' => 'XX'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'name', 'country']);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_offer_bar_hides_after_its_end_date_and_hidden_blocks_are_not_shown(): void
    {
        $this->page(blocks: [
            Blocks::make('offer_bar', ['text' => 'Launch price ends soon', 'ends_at' => now()->addDay()->toIso8601String()]),
            Blocks::make('offer_bar', ['text' => 'Old sale', 'ends_at' => now()->subMinute()->toIso8601String()]),
            ['hidden' => true] + Blocks::make('rich_text', ['html' => '<p>Secret draft text</p>']),
            Blocks::make('rich_text', ['html' => '<h2>Why it works</h2><script>alert(1)</script><p onclick="x()">Body <a href="javascript:alert(1)">link</a></p>']),
            Blocks::make('table', ['heading' => 'Compare', 'table' => ['columns' => ['Feature', 'Ours'], 'rows' => [['Presets', '40']]]]),
            Blocks::make('video', ['url' => 'https://youtu.be/abcdefghijk', 'title' => 'Demo']),
            Blocks::make('order'),
        ]);

        $this->get('/p/preset-launch')->assertOk()
            ->assertSee('Launch price ends soon')->assertDontSee('Old sale')->assertDontSee('Secret draft text')
            ->assertSee('<h2>Why it works</h2>', false)->assertDontSee('alert(1)', false)->assertDontSee('onclick', false)
            ->assertSee('<th scope="row">Presets</th>', false)
            // The video host is contacted only after the customer presses play.
            ->assertSee('data-src="https://www.youtube-nocookie.com/embed/abcdefghijk', false)->assertDontSee('<iframe', false);
    }

    public function test_the_reviews_block_shows_only_approved_reviews_of_the_linked_product(): void
    {
        $page = $this->page(blocks: [Blocks::make('reviews', ['heading' => 'Loved by editors']), Blocks::make('order')]);
        $other = $this->product(['name' => 'Other']);
        Review::create(['product_id' => $page->product_id, 'display_name' => 'Maya', 'country_code' => 'DE', 'rating' => 5, 'body' => 'Saved me hours.', 'status' => 'approved']);
        Review::create(['product_id' => $page->product_id, 'display_name' => 'Pending Pat', 'rating' => 1, 'body' => 'Not checked yet.', 'status' => 'pending']);
        Review::create(['product_id' => $other->id, 'display_name' => 'Elsewhere', 'rating' => 5, 'body' => 'About another product.', 'status' => 'approved']);

        $this->get('/p/preset-launch')->assertOk()->assertSee('Loved by editors')->assertSee('Saved me hours.')->assertSee('Maya')->assertSee('flags/de.svg', false)->assertSee('alt="Germany"', false)
            ->assertDontSee('Not checked yet.')->assertDontSee('About another product.');
    }

    public function test_the_product_page_and_home_banner_link_to_a_published_selling_page(): void
    {
        $page = $this->page();
        $this->get('/product/'.$page->product->slug)->assertOk()->assertSee('/p/preset-launch');

        HomeSection::query()->updateOrCreate(['type' => 'featured_offer'], ['position' => 9, 'is_enabled' => true, 'settings' => ['selling_page_id' => $page->id, 'headline' => 'This week only', 'button_label' => 'See the offer']]);
        $this->get('/')->assertOk()->assertSee('This week only')->assertSee('/p/preset-launch');

        $page->update(['status' => SellingPage::DRAFT]);
        $this->get('/product/'.$page->product->slug)->assertOk()->assertDontSee('/p/preset-launch');
        $this->get('/')->assertOk()->assertDontSee('This week only');
    }

    public function test_block_data_is_cleaned(): void
    {
        $blocks = Blocks::normalize([
            ['type' => 'made_up', 'data' => []],
            ['type' => 'hero', 'style' => ['background' => 'pink', 'spacing' => 'xl'], 'data' => ['headline' => str_repeat('a', 500), 'video_url' => 'javascript:alert(1)', 'image_id' => 'x', 'unknown' => 1]],
            ['type' => 'benefits', 'data' => ['items' => [['title' => ' One ', 'text' => 'A', 'extra' => 1], 'junk', ['title' => 'Two'], ['title' => 3], ['title' => 'Four'], ['title' => 'Five']]]],
        ]);

        $this->assertCount(2, $blocks);
        $this->assertSame(120, mb_strlen($blocks[0]['data']['headline']));
        $this->assertSame(['', null], [$blocks[0]['data']['video_url'], $blocks[0]['data']['image_id']]);
        $this->assertSame(['background' => 'white', 'spacing' => 'md', 'align' => 'left'], $blocks[0]['style']);
        $this->assertArrayNotHasKey('unknown', $blocks[0]['data']);
        $this->assertSame([['title' => 'One', 'text' => 'A'], ['title' => 'Two', 'text' => ''], ['title' => '3', 'text' => ''], ['title' => 'Four', 'text' => '']], $blocks[1]['data']['items']);

        $this->assertSame('<p>Hi <a href="https://example.com" rel="noopener nofollow" target="_blank">there</a></p>', HtmlSanitizer::clean('<p style="x">Hi <a href="https://example.com" onclick="x">there</a><iframe src="//evil"></iframe></p>'));
        $this->assertSame('iframe', VideoEmbed::resolve('https://www.youtube.com/watch?v=abcdefghijk')['kind']);
        $this->assertSame('file', VideoEmbed::resolve('/storage/media/clip.mp4')['kind']);
        $this->assertNull(VideoEmbed::resolve('https://evil.example/embed/1'));
    }
}
