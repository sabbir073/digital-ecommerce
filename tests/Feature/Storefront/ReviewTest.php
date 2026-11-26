<?php

namespace Tests\Feature\Storefront;

use App\Mail\ReviewRequestMail;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\Reviews\ReviewSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class ReviewTest extends CheckoutTestCase
{
    private function review(?Product $product, array $attributes = []): Review
    {
        return Review::create($attributes + ['product_id' => $product?->id, 'display_name' => 'Maya', 'rating' => 5, 'body' => 'Saved me hours of work every week.', 'status' => 'approved']);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['rating' => 4, 'title' => 'Does the job', 'body' => 'Clear files and easy to change. Would buy again.', 'display_name' => 'Rafi', 'email' => 'Rafi@Example.com', 'country_code' => 'BD'];
    }

    private function paidOrder(Product $product): Order
    {
        $this->addToCart($product);
        $this->startCheckout()->assertOk();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->paySucceeded($order, 'evt_'.$order->id)->assertOk();

        return $order->refresh();
    }

    public function test_the_average_counts_approved_reviews_only_and_follows_status_changes(): void
    {
        $product = $this->product(['name' => 'Preset Pack']);
        $five = $this->review($product);
        $two = $this->review($product, ['rating' => 2, 'status' => 'pending', 'display_name' => 'Pending Pat', 'body' => 'Waiting for a moderator to look at it.']);

        $this->assertSame([1, 5.0], [$product->refresh()->rating_count, $product->rating_average]);
        $this->get('/product/'.$product->slug)->assertOk()->assertSee('Based on 1 review')->assertSee('Saved me hours')->assertDontSee('Waiting for a moderator');

        $two->update(['status' => 'approved']);
        $this->assertSame([2, 3.5], [$product->refresh()->rating_count, $product->rating_average]);

        $five->update(['status' => 'spam']);
        $this->assertSame([1, 2.0], [$product->refresh()->rating_count, $product->rating_average]);

        $two->delete();
        $this->assertSame([0, 0.0], [$product->refresh()->rating_count, $product->rating_average]);
        $this->get('/product/'.$product->slug)->assertOk()->assertSee('No reviews yet');
    }

    public function test_the_list_can_be_filtered_sorted_and_is_paginated_ten_per_page(): void
    {
        $product = $this->product();
        foreach (range(1, 11) as $i) {
            $this->review($product, ['body' => "Five star review number {$i} with enough text.", 'reviewed_at' => now()->subDays($i)]);
        }
        $this->review($product, ['rating' => 1, 'body' => 'The only one star review in the list.', 'country_code' => 'DE', 'helpful_count' => 9, 'reviewed_at' => now()->subDays(30)]);
        $this->review($product, ['rating' => 3, 'body' => 'Pinned three star review stays on top.', 'pinned' => true, 'reviewed_at' => now()->subDays(40), 'country_code' => 'FR']);

        $url = '/product/'.$product->slug;
        $this->get($url)->assertOk()->assertSeeInOrder(['Pinned three star', 'review number 1 '])->assertDontSee('review number 10')->assertDontSee('only one star');
        $this->get($url.'?rpage=2')->assertOk()->assertSee('review number 10')->assertSee('only one star');
        $this->get($url.'?stars=1')->assertOk()->assertSee('only one star')->assertDontSee('review number 1 ')->assertSee('flags/de.svg', false)->assertSee('alt="Germany"', false);
        $this->get($url.'?sort=lowest')->assertOk()->assertSeeInOrder(['Pinned three star', 'only one star', 'Five star']);
        $this->get($url.'?sort=helpful')->assertOk()->assertSeeInOrder(['Pinned three star', 'only one star', 'Five star']);
        $this->get($url.'?country=DE')->assertOk()->assertSee('only one star')->assertDontSee('Pinned three star');
        $this->get($url.'?photos=1')->assertOk()->assertSee('No reviews match these filters');
    }

    public function test_a_visitor_review_waits_for_approval_and_stores_the_country(): void
    {
        $product = $this->product();

        $this->post("/product/{$product->slug}/reviews", $this->form(['body' => 'Too short']))->assertSessionHasErrors('body');
        $this->post("/product/{$product->slug}/reviews", $this->form(['rating' => 6]))->assertSessionHasErrors('rating');
        $this->post("/product/{$product->slug}/reviews", $this->form(['email' => '']))->assertSessionHasErrors('email');
        $this->assertSame(0, Review::query()->count());

        $this->post("/product/{$product->slug}/reviews", $this->form())->assertRedirect()->assertSessionHas('success');

        $review = Review::query()->firstOrFail();
        $this->assertSame(['pending', false, 'BD', 'rafi@example.com', 'customer'], [$review->status, $review->verified, $review->country_code, $review->email, $review->source]);
        $this->assertSame(0, $product->refresh()->rating_count);
        $this->get('/product/'.$product->slug)->assertOk()->assertDontSee('Would buy again');
    }

    public function test_photos_are_limited_to_five_and_stored_through_the_media_library(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $photo = fn () => UploadedFile::fake()->image('photo.jpg', 900, 600);

        $this->post("/product/{$product->slug}/reviews", $this->form(['photos' => array_map($photo, range(1, 6))]))->assertSessionHasErrors('photos');
        $this->post("/product/{$product->slug}/reviews", $this->form(['photos' => [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]]))->assertSessionHasErrors('photos.0');

        $this->post("/product/{$product->slug}/reviews", $this->form(['photos' => [$photo(), $photo()]]))->assertSessionHas('success');
        $review = Review::query()->firstOrFail();
        $this->assertCount(2, $review->photos);

        $review->update(['status' => 'approved']);
        $this->get('/product/'.$product->slug.'?photos=1')->assertOk()->assertSee('Would buy again')->assertSee('Photo 1 from Rafi');
    }

    public function test_spam_protection_honeypot_links_blocked_words_and_rate_limit(): void
    {
        $product = $this->product();
        app(ReviewSettings::class)->save(['blocked_words' => ['casino']]);
        $buyer = User::factory()->create();

        // A filled hidden field: accepted quietly, stored as spam, never public.
        $this->post("/product/{$product->slug}/reviews", $this->form(['website' => 'http://spam.example']))->assertSessionHas('success');
        $this->assertSame('spam', Review::query()->latest('id')->value('status'));

        // Verified buyers are auto-approved, unless the text has a link or a blocked word.
        $order = $this->paidOrder($product);
        $order->update(['user_id' => $buyer->id]);
        RateLimiter::clear('review-ip|127.0.0.1');
        $this->actingAs($buyer)->post("/product/{$product->slug}/reviews", $this->form(['body' => 'Great pack, more at www.my-shop.com for everyone.']))->assertSessionHas('success');
        $held = Review::query()->latest('id')->firstOrFail();
        $this->assertSame(['pending', true], [$held->status, $held->verified]);

        $second = $this->paidOrder($product);
        $second->update(['user_id' => $buyer->id]);
        $this->actingAs($buyer)->post("/product/{$product->slug}/reviews", $this->form(['body' => 'Better than a night at the CASINO, honestly.']))->assertSessionHas('success');
        $this->assertSame('pending', Review::query()->latest('id')->value('status'));

        // Five reviews an hour from one address, then the form refuses.
        RateLimiter::clear('review-ip|127.0.0.1');
        foreach (range(1, 5) as $i) {
            $this->post("/product/{$product->slug}/reviews", $this->form(['email' => "person{$i}@example.com"]))->assertSessionHas('success');
        }
        $count = Review::query()->count();
        $this->post("/product/{$product->slug}/reviews", $this->form(['email' => 'person6@example.com']))->assertSessionHas('error');
        $this->assertSame($count, Review::query()->count());
    }

    public function test_a_signed_in_buyer_is_verified_once_per_order_and_takes_the_order_country(): void
    {
        $product = $this->product();
        $buyer = User::factory()->create(['name' => 'Nadia Buyer']);
        $order = $this->paidOrder($product);
        $order->update(['user_id' => $buyer->id, 'billing_country' => 'CA']);

        $this->actingAs($buyer)->get('/product/'.$product->slug)->assertOk()->assertSee('Verified buyer')->assertSee('Taken from your order')->assertSee('value="Nadia Buyer"', false);
        $this->actingAs($buyer)->post("/product/{$product->slug}/reviews", $this->form(['country_code' => 'BD', 'email' => null]))->assertSessionHas('success');

        $review = Review::query()->firstOrFail();
        $this->assertSame(['approved', true, 'CA', $order->id, $order->items->first()->id], [$review->status, $review->verified, $review->country_code, $review->order_id, $review->order_item_id]);
        $this->assertSame(1, $product->refresh()->rating_count);
        $this->get('/product/'.$product->slug)->assertOk()->assertSee('Verified buyer')->assertSee('flags/ca.svg', false);

        // The same order cannot be reviewed twice: the next review is an ordinary, unverified one.
        $this->actingAs($buyer)->post("/product/{$product->slug}/reviews", $this->form(['email' => null]))->assertSessionHas('success');
        $this->assertFalse(Review::query()->latest('id')->firstOrFail()->verified);
    }

    public function test_only_verified_buyers_may_review_when_the_setting_says_so(): void
    {
        $product = $this->product();
        app(ReviewSettings::class)->save(['who_may_review' => 'verified']);

        $this->get('/product/'.$product->slug)->assertOk()->assertSee('written by customers who bought from us')->assertDontSee('Send review');
        $this->post("/product/{$product->slug}/reviews", $this->form())->assertSessionHas('error');
        $this->assertSame(0, Review::query()->count());
    }

    public function test_the_review_request_email_goes_out_after_the_delay_and_its_link_gives_a_verified_review(): void
    {
        Mail::fake();
        $product = $this->product(['name' => 'Preset Pack']);
        $order = $this->paidOrder($product);
        $order->update(['billing_country' => 'GB']);

        $invitation = ReviewRequest::query()->firstOrFail();
        $this->assertSame($order->items->first()->id, $invitation->order_item_id);
        $this->assertTrue($invitation->send_at->isSameDay(now()->addDays(7)));

        $this->artisan('reviews:send-requests');
        Mail::assertNotQueued(ReviewRequestMail::class);

        $this->travel(8)->days();
        $this->artisan('reviews:send-requests');
        Mail::assertQueued(ReviewRequestMail::class, fn (ReviewRequestMail $mail) => $mail->hasTo($order->email) && str_contains($mail->render(), $invitation->url()));
        $this->artisan('reviews:send-requests');
        Mail::assertQueued(ReviewRequestMail::class, 1);

        // The link works without signing in.
        $this->get('/review/not-a-real-token')->assertNotFound();
        $this->get($invitation->url())->assertOk()->assertSee('How was Preset Pack?')->assertSee('Verified buyer')->assertDontSee('id="rv-email"', false);
        $this->post($invitation->url(), $this->form(['email' => null, 'country_code' => 'BD']))->assertSessionHas('success');

        $review = Review::query()->firstOrFail();
        $this->assertSame(['approved', true, 'GB', $order->email], [$review->status, $review->verified, $review->country_code, $review->email]);
        $this->assertNotNull($invitation->refresh()->used_at);

        // One review per item per order.
        $this->get($invitation->url())->assertOk()->assertSee('You have reviewed this item');
        $this->post($invitation->url(), $this->form(['email' => null]))->assertSessionHas('error');
        $this->assertSame(1, Review::query()->count());
    }

    public function test_no_request_is_sent_when_switched_off_or_for_a_refunded_order(): void
    {
        Mail::fake();
        $order = $this->paidOrder($this->product());
        $this->travel(8)->days();

        app(ReviewSettings::class)->save(['request_enabled' => false]);
        $this->artisan('reviews:send-requests');
        Mail::assertNotQueued(ReviewRequestMail::class);

        app(ReviewSettings::class)->save(['request_enabled' => true]);
        $order->update(['status' => Order::REFUNDED]);
        $this->artisan('reviews:send-requests');
        Mail::assertNotQueued(ReviewRequestMail::class);
    }

    public function test_a_visitor_can_mark_helpful_once_and_report_a_review(): void
    {
        $product = $this->product();
        $review = $this->review($product);
        $hidden = $this->review($product, ['status' => 'pending']);

        $this->withCookie('visitor', str_repeat('a', 40))->post("/reviews/{$review->id}/helpful")->assertRedirect();
        $this->withCookie('visitor', str_repeat('a', 40))->post("/reviews/{$review->id}/helpful")->assertRedirect();
        $this->assertSame(1, $review->refresh()->helpful_count);
        $this->withCookie('visitor', str_repeat('b', 40))->post("/reviews/{$review->id}/helpful");
        $this->assertSame(2, $review->refresh()->helpful_count);
        $this->post("/reviews/{$hidden->id}/helpful")->assertNotFound();

        $this->withCookie('visitor', str_repeat('a', 40))->post("/reviews/{$review->id}/report", ['reason' => 'nonsense'])->assertSessionHasErrors('reason');
        $this->withCookie('visitor', str_repeat('a', 40))->post("/reviews/{$review->id}/report", ['reason' => 'fake', 'details' => 'Same text as on another site'])->assertSessionHas('success');
        $this->withCookie('visitor', str_repeat('a', 40))->post("/reviews/{$review->id}/report", ['reason' => 'spam']);
        $this->assertSame(['Not a genuine review: Same text as on another site'], $review->reports()->pluck('reason')->all());
    }

    public function test_store_reviews_have_their_own_page_and_an_optional_home_section(): void
    {
        $product = $this->product(['name' => 'Preset Pack']);
        $this->review(null, ['body' => 'Fast answers from support, smooth payment.', 'country_code' => 'BD', 'store_reply' => 'Thank you for the kind words.']);
        $this->review(null, ['rating' => 2, 'body' => 'Took a while to find my download link.', 'display_name' => 'Lukas']);
        $this->review($product, ['body' => 'A review about one product only.']);

        $this->get('/reviews')->assertOk()->assertSee('Based on 2 reviews')->assertSee('Fast answers from support')->assertSee('Thank you for the kind words.')
            ->assertSee('alt="Bangladesh"', false)->assertDontSee('about one product only');
        $this->get('/product/'.$product->slug)->assertOk()->assertDontSee('Fast answers from support');

        $this->post('/reviews', $this->form())->assertSessionHas('success');
        $this->assertTrue(Review::query()->latest('id')->firstOrFail()->isStoreReview());

        // Off until an admin switches it on; then only reviews at or above the chosen rating.
        $this->get('/')->assertOk()->assertDontSee('Fast answers from support');
        HomeSection::query()->updateOrCreate(['type' => 'store_reviews'], ['enabled' => true]);
        $this->get('/')->assertOk()->assertSee('Fast answers from support')->assertDontSee('Took a while to find');

        app(ReviewSettings::class)->save(['show_flags' => false]);
        $this->get('/reviews')->assertOk()->assertDontSee('flags/bd.svg', false)->assertSee('Bangladesh');
    }

    public function test_the_optional_captcha_is_checked_with_cloudflare(): void
    {
        $product = $this->product();
        $settings = app(ReviewSettings::class);
        $settings->save(['captcha_enabled' => true, 'turnstile_site_key' => 'site-key-1']);
        $settings->saveTurnstileSecret('secret-1');
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get('/product/'.$product->slug)->assertOk()->assertSee('data-sitekey="site-key-1"', false);
        $this->post("/product/{$product->slug}/reviews", $this->form())->assertSessionHas('error');
        $this->post("/product/{$product->slug}/reviews", $this->form(['cf-turnstile-response' => 'bad']))->assertSessionHas('error');
        $this->assertSame(0, Review::query()->count());
        $this->post("/product/{$product->slug}/reviews", $this->form(['cf-turnstile-response' => 'good']))->assertSessionHas('success');
        $this->assertSame(1, Review::query()->count());
        Http::assertSent(fn ($request) => $request['secret'] === 'secret-1' && $request['response'] === 'good');
    }
}
