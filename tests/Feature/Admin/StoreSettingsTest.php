<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AuthenticateAdmin;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Order;
use App\Models\Review;
use App\Models\Setting;
use App\Support\Blocks;
use App\Support\Money;
use App\Support\Theme;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Storefront\CheckoutTestCase;

class StoreSettingsTest extends CheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeAdmin(string $role): AdminUser
    {
        return AdminUser::factory()->role($role)->create();
    }

    private function signIn(AdminUser $user): static
    {
        return $this->actingAs($user, 'admin')->withSession([AuthenticateAdmin::SESSION_KEY => $user->session_version]);
    }

    public function test_store_details_change_the_storefront(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->get('/admin/settings/store')->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Store')
            ->where('store.currency_format', 'symbol')->where('checkout.guest_checkout', true)->where('maintenance.enabled', false));

        $this->signIn($admin)->put('/admin/settings/store/details', [
            'name' => 'Paper & Pixel', 'tagline' => 'Templates for makers', 'footer_text' => 'Made by a small studio.', 'contact_email' => 'hi@paper.example.test',
            'contact_phone' => '+1 555 0100', 'address' => '1 Main Street', 'currency_format' => 'code', 'number_prefix' => 'pp-', 'number_style' => 'code',
        ])->assertSessionHasNoErrors();
        auth('admin')->logout();

        $this->get('/')->assertOk()->assertSee('Paper &amp; Pixel', false)->assertSee('Made by a small studio.')->assertSee('hi@paper.example.test')->assertSee('1 Main Street');
        $this->assertSame('1,234.50 USD', Money::format(123450));
        $this->assertSame('PP-', Setting::get('orders.number_prefix'));

        $this->signIn($admin)->put('/admin/settings/store/details', ['name' => 'X', 'currency_format' => 'euro', 'number_prefix' => 'a b/', 'number_style' => 'code'])
            ->assertSessionHasErrors(['currency_format', 'number_prefix']);
        $this->signIn($this->makeAdmin('staff'))->get('/admin/settings/store')->assertForbidden();
    }

    public function test_new_orders_use_the_order_number_format(): void
    {
        Setting::put('orders.number_prefix', 'NL-');
        Setting::put('orders.number_style', 'code');
        $this->addToCart($this->product());
        $this->startCheckout()->assertOk();

        $this->assertMatchesRegularExpression('/^NL-[A-Z2-9]{8}$/', Order::query()->firstOrFail()->number);
    }

    public function test_checkout_fields_switched_on_in_admin_are_asked_and_saved(): void
    {
        $admin = $this->makeAdmin('admin');
        $terms = ContentPage::create(['title' => 'Terms', 'slug' => 'terms', 'status' => 'published', 'published_at' => now(),
            'draft_blocks' => [], 'published_blocks' => [Blocks::make('rich_text', ['html' => '<p>Rules.</p>'], [], 'content')]]);
        $this->addToCart($this->product());

        $this->withCart()->get('/checkout')->assertOk()->assertDontSee('name="phone"', false)->assertDontSee('name="terms"', false)->assertDontSee('name="customer_note"', false);

        $this->signIn($admin)->put('/admin/settings/store/checkout', ['guest_checkout' => true, 'phone' => 'required', 'order_notes' => true, 'terms' => true, 'terms_page_id' => $terms->id])
            ->assertSessionHasNoErrors();
        auth('admin')->logout();

        $this->withCart()->get('/checkout')->assertOk()->assertSee('name="phone"', false)->assertSee('name="customer_note"', false)->assertSee('name="terms"', false)->assertSee('/pages/terms');

        $this->startCheckout()->assertStatus(422)->assertJsonValidationErrors(['phone', 'terms']);
        $this->startCheckout(['phone' => '+1 (555) 010-0199', 'customer_note' => 'Please send the invoice to accounts.', 'terms' => true])->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('+1 (555) 010-0199', $order->phone);
        $this->assertSame('Please send the invoice to accounts.', $order->customer_note);
        $this->assertNotNull($order->terms_accepted_at);

        $this->signIn($admin)->get("/admin/orders/{$order->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('order.phone', '+1 (555) 010-0199')->where('order.customerNote', 'Please send the invoice to accounts.'));
    }

    public function test_with_guest_checkout_off_an_order_needs_an_account(): void
    {
        Setting::put('checkout', ['guest_checkout' => false]);
        $this->addToCart($this->product());

        $this->withCart()->get('/checkout')->assertOk()->assertSee('Orders need an account')->assertDontSee('data-account-toggle', false);
        $this->startCheckout()->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->startCheckout(['password' => 'a-long-password'])->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertNotNull($order->user_id);
        $this->assertSame($order->email, $order->user->email);
    }

    public function test_stock_rules_are_saved(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->put('/admin/settings/store/stock', ['low_stock_threshold' => 12, 'allow_overselling' => true])->assertSessionHasNoErrors();

        $this->assertSame(12, Setting::get('inventory.low_stock_threshold'));
        $this->assertTrue(Setting::get('inventory.allow_overselling'));
        $this->signIn($admin)->put('/admin/settings/store/stock', ['low_stock_threshold' => -1, 'allow_overselling' => true])->assertSessionHasErrors('low_stock_threshold');
    }

    public function test_maintenance_mode_closes_the_store_to_visitors_but_not_to_staff(): void
    {
        $admin = $this->makeAdmin('admin');
        $product = $this->product();
        $this->signIn($admin)->put('/admin/settings/store/maintenance', ['enabled' => true, 'message' => 'Back on Monday with new templates.'])->assertSessionHasNoErrors();

        // Staff still see the store and can use admin.
        $this->signIn($admin)->get('/')->assertOk();
        $this->signIn($admin)->get('/admin/settings/store')->assertOk();
        auth('admin')->logout();

        $this->get('/')->assertStatus(503)->assertSee('Back on Monday with new templates.')->assertHeader('Retry-After');
        $this->get("/product/{$product->slug}")->assertStatus(503);
        $this->getJson('/search?q=a')->assertStatus(503)->assertJsonPath('message', 'Back on Monday with new templates.');
        // The door to admin and Stripe's webhook stay open.
        $this->get('/admin/login')->assertOk();
        $this->postJson('/stripe/webhook', [])->assertStatus(400);

        $this->signIn($admin)->put('/admin/settings/store/maintenance', ['enabled' => false, 'message' => ''])->assertSessionHasNoErrors();
        auth('admin')->logout();
        $this->get('/')->assertOk();
    }

    public function test_theme_settings_change_the_storefront(): void
    {
        $admin = $this->makeAdmin('admin');
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $product = $this->product(['compare_at_cents' => 9900]);
        $product->update(['category_id' => $category->id]);

        $this->get('/products')->assertOk()->assertDontSee('--color-accent:', false)->assertSee('grid-standard')->assertSee('Sale');

        $theme = ['accent' => '#15803d', 'heading_font' => 'playfair-display', 'body_font' => 'inter', 'density' => 'compact', 'card_ratings' => false, 'card_compare' => false,
            'card_badges' => false, 'logo_media_id' => null, 'favicon_media_id' => null, 'socials' => [['network' => 'instagram', 'url' => 'https://instagram.com/northlane']]];
        $this->signIn($admin)->put('/admin/settings/theme', $theme)->assertSessionHasNoErrors();
        $this->signIn($admin)->get('/admin/settings/theme')->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Theme')->where('theme.accent', '#15803D')->has('options.accents', count(Theme::ACCENTS)));
        auth('admin')->logout();

        $page = $this->get('/products')->assertOk();
        $page->assertSee('--color-accent:#15803D', false)->assertSee("--font-heading:'Playfair Display'", false)->assertSee('grid-compact')
            ->assertSee('playfair-display', false)->assertSee('https://instagram.com/northlane')->assertSee('Instagram');
        $page->assertDontSee('<s class', false)->assertDontSee('>Sale<', false);
    }

    public function test_a_custom_accent_colour_must_pass_the_contrast_check(): void
    {
        $admin = $this->makeAdmin('admin');
        $base = ['heading_font' => 'space-grotesk', 'body_font' => 'inter', 'density' => 'standard', 'card_ratings' => true, 'card_compare' => true, 'card_badges' => true, 'socials' => []];

        $this->signIn($admin)->put('/admin/settings/theme', $base + ['accent' => '#FFD400'])->assertSessionHasErrors('accent');
        $this->signIn($admin)->put('/admin/settings/theme', $base + ['accent' => 'blue'])->assertSessionHasErrors('accent');
        $this->signIn($admin)->put('/admin/settings/theme', $base + ['accent' => '#0B5FA5'])->assertSessionHasNoErrors();
        $this->signIn($admin)->put('/admin/settings/theme', ['accent' => '#0B5FA5', 'socials' => [['network' => 'x', 'url' => 'javascript:alert(1)']]] + $base)->assertSessionHasErrors('socials.0.url');

        foreach (array_keys(Theme::ACCENTS) as $hex) {
            $palette = Theme::palette($hex);
            $this->assertGreaterThanOrEqual(4.5, Theme::contrast($hex, '#FFFFFF'), $hex);
            $this->assertGreaterThanOrEqual(4.5, Theme::contrast($palette['tintText'], $palette['tint']), $hex);
        }
        // Staff have no access to the theme module.
        $this->signIn($this->makeAdmin('staff'))->put('/admin/settings/theme', $base + ['accent' => '#0B5FA5'])->assertForbidden();
    }

    public function test_reviews_can_be_exported(): void
    {
        $product = $this->product();
        Review::create(['product_id' => $product->id, 'rating' => 5, 'title' => 'Great', 'body' => '=cmd() looks like a formula', 'display_name' => 'Rina', 'email' => 'rina@example.test',
            'status' => 'approved', 'source' => 'site', 'verified' => true]);

        $csv = $this->signIn($this->makeAdmin('moderator'))->get('/admin/reviews/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Rina', $csv);
        $this->assertStringContainsString("'=cmd()", $csv);
        $this->signIn($this->makeAdmin('staff'))->get('/admin/reviews/export')->assertForbidden();
    }
}
