<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\Setting;
use App\Services\MediaService;
use App\Services\Reviews\ReviewSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

class ReviewAdminTest extends AdminTestCase
{
    private function review(?Product $product, array $attributes = []): Review
    {
        return Review::create($attributes + ['product_id' => $product?->id, 'display_name' => 'Maya', 'rating' => 5, 'body' => 'Saved me hours of work every week.', 'status' => 'pending']);
    }

    private function paidOrder(Product $product, string $number): Order
    {
        $order = Order::create(['number' => $number, 'email' => 'buyer@example.com', 'name' => 'Sam', 'status' => Order::PAID, 'paid_at' => now(), 'subtotal_cents' => 2000, 'total_cents' => 2000, 'currency' => 'usd']);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price_cents' => 2000, 'line_total_cents' => 2000]);

        return $order;
    }

    public function test_the_queue_filters_by_status_rating_product_country_search_and_reports(): void
    {
        $pack = Product::factory()->create(['name' => 'Preset Pack']);
        $fonts = Product::factory()->create(['name' => 'Font Family']);
        $this->review($pack, ['country_code' => 'DE']);
        $this->review($pack, ['status' => 'approved', 'rating' => 2, 'display_name' => 'Lukas', 'body' => 'The licence text confused me.']);
        $reported = $this->review($fonts, ['status' => 'approved', 'display_name' => 'Omar']);
        ReviewReport::create(['review_id' => $reported->id, 'visitor_id' => 'v1', 'reason' => 'Spam or advertising']);
        $this->review(null, ['status' => 'spam', 'display_name' => 'Bot']);

        $admin = $this->makeAdmin('moderator');
        $names = fn (string $query) => collect($this->signIn($admin)->get('/admin/reviews'.$query)->assertOk()->viewData('page')['props']['reviews']['data'])->pluck('name')->sort()->values()->all();

        $this->signIn($admin)->get('/admin/reviews')->assertInertia(fn (AssertableInertia $p) => $p->component('Reviews/Index')
            ->where('counts.all', 4)->where('counts.pending', 1)->where('counts.approved', 2)->where('counts.spam', 1)->where('counts.reported', 1));

        $this->assertSame(['Maya'], $names('?status=pending'));
        $this->assertSame(['Lukas'], $names('?rating=2'));
        $this->assertSame(['Omar'], $names('?product='.$fonts->id));
        $this->assertSame(['Bot'], $names('?product=store'));
        $this->assertSame(['Maya'], $names('?country=DE'));
        $this->assertSame(['Lukas'], $names('?search=licence'));
        $this->assertSame(['Omar'], $names('?status=reported'));
        $this->assertSame([], $names('?from='.now()->addDay()->toDateString()));
    }

    public function test_moderating_a_review_changes_status_reply_pin_and_country_but_never_its_text(): void
    {
        $product = Product::factory()->create();
        $review = $this->review($product, ['country_code' => 'DE']);
        ReviewReport::create(['review_id' => $review->id, 'visitor_id' => 'v1', 'reason' => 'Offensive or abusive']);
        $admin = $this->makeAdmin('moderator');

        $this->signIn($admin)->put("/admin/reviews/{$review->id}", [
            'status' => 'approved', 'reply' => 'Thanks, Maya.', 'pinned' => true, 'country' => 'AT', 'resolve_reports' => true, 'body' => 'Rewritten by staff', 'rating' => 1,
        ])->assertSessionHas('success');

        $review->refresh();
        $this->assertSame(['approved', 'Thanks, Maya.', true, 'AT', 'Saved me hours of work every week.', 5], [$review->status, $review->store_reply, $review->pinned, $review->country_code, $review->body, $review->rating]);
        $this->assertNotNull($review->replied_at);
        $this->assertSame(0, $review->reports()->whereNull('resolved_at')->count());
        $this->assertSame(1, $product->refresh()->rating_count);
        $this->get('/product/'.$product->slug)->assertOk()->assertSee('Thanks, Maya.')->assertSee('alt="Austria"', false)->assertSee('Pinned');
        $this->assertTrue(ActivityLog::query()->where('module', 'reviews')->where('action', 'edit')->exists());

        $this->signIn($admin)->put("/admin/reviews/{$review->id}", ['status' => 'rejected', 'reply' => '', 'pinned' => false, 'country' => null])->assertSessionHas('success');
        $this->assertSame(0, $product->refresh()->rating_count);
        $this->assertNull($review->refresh()->store_reply);
    }

    public function test_bulk_approve_reject_and_delete(): void
    {
        $product = Product::factory()->create();
        $ids = collect(range(1, 3))->map(fn () => $this->review($product)->id)->all();
        $admin = $this->makeAdmin('moderator');

        $this->signIn($admin)->post('/admin/reviews/bulk', ['action' => 'approve', 'ids' => $ids])->assertSessionHas('success');
        $this->assertSame([3, 5.0], [$product->refresh()->rating_count, $product->rating_average]);

        $this->signIn($admin)->post('/admin/reviews/bulk', ['action' => 'reject', 'ids' => [$ids[0]]]);
        $this->assertSame(2, $product->refresh()->rating_count);

        $this->signIn($admin)->post('/admin/reviews/bulk', ['action' => 'delete', 'ids' => [$ids[1], $ids[2]]])->assertSessionHas('success');
        $this->assertSame([$ids[0]], Review::query()->pluck('id')->all());
        $this->assertSame(0, $product->refresh()->rating_count);

        $this->signIn($admin)->post('/admin/reviews/bulk', ['action' => 'publish', 'ids' => $ids])->assertSessionHasErrors('action');
    }

    public function test_a_hand_entered_review_carries_a_source_label_and_is_verified_only_with_a_real_order(): void
    {
        $product = Product::factory()->create(['name' => 'Preset Pack']);
        $other = Product::factory()->create();
        $this->paidOrder($product, 'ORD-1001');
        $admin = $this->makeAdmin('moderator');
        $payload = fn (array $overrides = []) => $overrides + [
            'product_id' => $product->id, 'rating' => 5, 'title' => 'By email', 'body' => 'Sent to us by email after the purchase.', 'display_name' => 'Ines',
            'country' => 'PT', 'date' => '2026-10-01', 'source_label' => 'Collected by email', 'order_number' => '', 'status' => 'approved',
        ];

        $this->signIn($admin)->post('/admin/reviews', $payload(['source_label' => '']))->assertSessionHasErrors('source_label');
        $this->signIn($admin)->post('/admin/reviews', $payload(['order_number' => 'ORD-9999']))->assertSessionHasErrors('order_number');
        $this->signIn($admin)->post('/admin/reviews', $payload(['product_id' => $other->id, 'order_number' => 'ORD-1001']))->assertSessionHasErrors('order_number');

        $this->signIn($admin)->post('/admin/reviews', $payload())->assertSessionHas('success');
        $plain = Review::query()->latest('id')->firstOrFail();
        $this->assertSame(['manual', 'Collected by email', false, '2026-10-01'], [$plain->source, $plain->source_label, $plain->verified, $plain->reviewed_at->toDateString()]);

        $this->signIn($admin)->post('/admin/reviews', $payload(['order_number' => 'ORD-1001', 'display_name' => 'Sam']))->assertSessionHas('success');
        $this->assertTrue(Review::query()->latest('id')->firstOrFail()->verified);

        $this->get('/product/'.$product->slug)->assertOk()->assertSee('Collected by email')->assertSee('October 1, 2026')->assertSee('alt="Portugal"', false);
    }

    public function test_reviews_can_be_imported_from_csv_with_a_source_label(): void
    {
        $product = Product::factory()->create(['slug' => 'preset-pack']);
        $this->paidOrder($product, 'ORD-2001');
        $csv = implode("\n", [
            "\u{FEFF}product_slug,rating,title,body,display_name,country_code,date,email,order_number",
            'preset-pack,5,Great,"Loved it, would buy again",Ana,br,2026-09-12,ana@example.com,',
            'preset-pack,4,,Solid pack for the price,Ben,US,,,ORD-2001',
            ',5,,Friendly shop and quick replies,Chen,CN,2026-08-01,,',
            'missing-product,5,,Text,Dee,US,,,',
            'preset-pack,9,,Text,Eli,US,,,',
            'preset-pack,3,,Text,Fay,XX,,,',
        ]);
        $file = UploadedFile::fake()->createWithContent('reviews.csv', $csv);
        $admin = $this->makeAdmin('moderator');

        $this->signIn($admin)->post('/admin/reviews/import', ['file' => $file, 'status' => 'approved'])->assertSessionHasErrors('source_label');
        $response = $this->signIn($admin)->post('/admin/reviews/import', ['file' => $file, 'source_label' => 'Imported from Etsy', 'status' => 'approved']);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('3 reviews imported. 3 skipped.', session('error'));

        $reviews = Review::query()->orderBy('id')->get();
        $this->assertSame(['Ana', 'Ben', 'Chen'], $reviews->pluck('display_name')->all());
        $this->assertSame(['imported', 'Imported from Etsy', 'BR', false], [$reviews[0]->source, $reviews[0]->source_label, $reviews[0]->country_code, $reviews[0]->verified]);
        $this->assertTrue($reviews[1]->verified);
        $this->assertTrue($reviews[2]->isStoreReview());
        $this->assertSame([2, 4.5], [$product->refresh()->rating_count, $product->rating_average]);

        $this->signIn($admin)->get('/admin/reviews/import-format')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_a_photo_can_be_removed_from_a_review(): void
    {
        Storage::fake('public');
        $review = $this->review(Product::factory()->create());
        $media = app(MediaService::class)->store(UploadedFile::fake()->image('p.jpg', 600, 400));
        $review->photos()->attach($media->id, ['position' => 0]);

        $this->signIn($this->makeAdmin('moderator'))->delete("/admin/reviews/{$review->id}/photos/{$media->id}")->assertSessionHas('success');
        $this->assertCount(0, $review->refresh()->photos);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertSame('Saved me hours of work every week.', $review->body);
    }

    public function test_review_settings_are_saved_and_the_captcha_needs_both_keys(): void
    {
        $admin = $this->makeAdmin('moderator');
        $payload = fn (array $overrides = []) => $overrides + [
            'who_may_review' => 'verified', 'auto_approve_verified' => false, 'hold_links' => true, 'blocked_words' => [' Casino ', 'casino', ''],
            'request_enabled' => true, 'request_delay_days' => 14, 'show_flags' => false, 'home_section' => true, 'home_min_rating' => 5,
            'captcha_enabled' => false, 'turnstile_site_key' => '', 'turnstile_secret' => '',
        ];

        $this->signIn($admin)->put('/admin/reviews/settings', $payload(['captcha_enabled' => true]))->assertSessionHasErrors('captcha_enabled');
        $this->signIn($admin)->put('/admin/reviews/settings', $payload())->assertSessionHas('success');

        $settings = app(ReviewSettings::class)->all();
        $this->assertSame(['verified', false, ['casino'], 14, false, 5], [$settings['who_may_review'], $settings['auto_approve_verified'], $settings['blocked_words'], $settings['request_delay_days'], $settings['show_flags'], $settings['home_min_rating']]);
        $this->assertTrue((bool) HomeSection::query()->where('type', 'store_reviews')->value('enabled'));

        $this->signIn($admin)->put('/admin/reviews/settings', $payload(['captcha_enabled' => true, 'turnstile_site_key' => 'site-1', 'turnstile_secret' => 'secret-1']))->assertSessionHas('success');
        $this->assertTrue(app(ReviewSettings::class)->captchaActive());
        // The secret is stored encrypted and never sent back to the browser.
        $this->assertStringNotContainsString('secret-1', (string) Setting::query()->where('key', 'reviews.turnstile_secret')->value('value'));
        $this->signIn($admin)->get('/admin/reviews/settings')->assertInertia(fn (AssertableInertia $p) => $p->component('Reviews/Settings')->where('settings.has_turnstile_secret', true)->missing('settings.turnstile_secret'));
    }

    public function test_review_admin_follows_role_permissions(): void
    {
        $review = $this->review(Product::factory()->create());
        $staff = $this->makeAdmin('staff'); // products and orders, no reviews

        $this->signIn($staff)->get('/admin/reviews')->assertForbidden();
        $this->signIn($staff)->put("/admin/reviews/{$review->id}", ['status' => 'approved', 'pinned' => false])->assertForbidden();
        $this->signIn($staff)->post('/admin/reviews/bulk', ['action' => 'delete', 'ids' => [$review->id]])->assertForbidden();
        $this->assertSame('pending', $review->refresh()->status);
    }
}
