<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\NotFoundLog;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Review;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\Seo\Redirects;
use App\Services\Seo\SeoSettings;
use App\Services\Seo\SeoSubjects;
use App\Support\Blocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> the JSON-LD blocks of a page */
    private function jsonLd(string $html): array
    {
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);

        return array_map(fn (string $json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function ofType(string $html, string $type): ?array
    {
        return collect($this->jsonLd($html))->firstWhere('@type', $type);
    }

    public function test_a_product_page_uses_the_template_until_it_has_its_own_seo_fields(): void
    {
        Setting::put('store.name', 'Northlane Digital');
        $product = Product::factory()->create(['name' => 'Studio Template', 'slug' => 'studio-template', 'short_description' => 'A clean template for studios.', 'price_cents' => 4900]);
        $url = route('product', 'studio-template');

        $this->get('/product/studio-template')->assertOk()
            ->assertSee('<title>Studio Template · Northlane Digital</title>', false)
            ->assertSee('<meta name="description" content="A clean template for studios.">', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertSee('<meta property="og:type" content="product">', false)
            ->assertSee('<meta property="og:title" content="Studio Template · Northlane Digital">', false)
            ->assertDontSee('name="robots"', false);

        app(SeoSubjects::class)->saveMeta('product', $product, [
            'meta_title' => 'Buy the Studio Template', 'meta_description' => 'Made for small studios.', 'canonical_url' => 'https://example.com/elsewhere',
            'index' => false, 'follow' => true, 'og_title' => 'Share me', 'custom_tags' => '<meta name="author" content="Northlane"><script>alert(1)</script>',
        ]);

        $this->get('/product/studio-template')->assertOk()
            ->assertSee('<title>Buy the Studio Template</title>', false)
            ->assertSee('<meta name="description" content="Made for small studios.">', false)
            ->assertSee('<link rel="canonical" href="https://example.com/elsewhere">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<meta property="og:title" content="Share me">', false)
            ->assertSee('<meta name="author" content="Northlane">', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_product_structured_data_has_the_offer_and_ratings_only_with_approved_reviews(): void
    {
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $product = Product::factory()->create(['name' => 'Studio Template', 'slug' => 'studio-template', 'category_id' => $category->id, 'price_cents' => 4900, 'sku' => 'ST-1']);

        $html = $this->get('/product/studio-template')->assertOk()->getContent();
        $data = $this->ofType($html, 'Product');
        $this->assertSame('Studio Template', $data['name']);
        $this->assertSame('ST-1', $data['sku']);
        $this->assertSame('49.00', $data['offers']['price']);
        $this->assertSame('USD', $data['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability']);
        $this->assertArrayNotHasKey('aggregateRating', $data);
        $this->assertArrayNotHasKey('review', $data);

        $crumbs = $this->ofType($html, 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Home', 'Templates', 'Studio Template'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));

        Review::create(['product_id' => $product->id, 'display_name' => 'Maya', 'rating' => 4, 'body' => 'Saved me hours of work every week.', 'status' => 'approved']);
        Review::create(['product_id' => $product->id, 'display_name' => 'Hidden', 'rating' => 1, 'body' => 'This one is still waiting for a moderator.', 'status' => 'pending']);

        $data = $this->ofType($this->get('/product/studio-template')->getContent(), 'Product');
        $this->assertSame(1, $data['aggregateRating']['reviewCount']);
        $this->assertEquals(4, $data['aggregateRating']['ratingValue']);
        $this->assertCount(1, $data['review']);
        $this->assertSame('Maya', $data['review'][0]['author']['name']);
    }

    public function test_the_home_page_and_faq_blocks_get_their_structured_data(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertNotNull($this->ofType($home, 'Organization'));
        $this->assertStringContainsString('/search?q={search_term_string}', $this->ofType($home, 'WebSite')['potentialAction']['target']['urlTemplate']);

        $product = Product::factory()->create(['name' => 'Preset Pack', 'slug' => 'preset-pack']);
        $blocks = [
            Blocks::make('hero', ['headline' => 'Edit faster']),
            Blocks::make('faq', ['items' => [['q' => 'How do I get my files?', 'a' => 'By email, right after payment.']]]),
            Blocks::make('order'),
        ];
        SellingPage::create(['name' => 'Preset launch', 'slug' => 'preset-launch', 'product_id' => $product->id, 'status' => SellingPage::PUBLISHED, 'draft_blocks' => $blocks, 'published_blocks' => $blocks, 'published_at' => now()]);

        $faq = $this->ofType($this->get('/p/preset-launch')->assertOk()->getContent(), 'FAQPage');
        $this->assertSame('How do I get my files?', $faq['mainEntity'][0]['name']);
        $this->assertSame('By email, right after payment.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function test_lists_keep_filters_and_searches_out_of_the_index_and_pages_apart(): void
    {
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        Product::factory()->count(30)->create(['category_id' => $category->id]);
        $clean = url('/c/templates');

        $this->get('/c/templates')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$clean.'">', false)
            ->assertDontSee('name="robots"', false);

        $this->get('/c/templates?sort=price_asc')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$clean.'">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        $this->get('/c/templates?page=2')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$clean.'?page=2">', false)
            ->assertDontSee('name="robots"', false);

        $this->get('/search?q=template')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get('/cart')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false)->assertDontSee('rel="canonical"', false);
    }

    public function test_the_sitemap_lists_only_published_pages_that_may_be_indexed(): void
    {
        Product::factory()->create(['slug' => 'live-one']);
        Product::factory()->draft()->create(['slug' => 'draft-one']);
        $hidden = Product::factory()->create(['slug' => 'hidden-one']);
        app(SeoSubjects::class)->saveMeta('product', $hidden, ['index' => false, 'follow' => true]);
        Category::create(['name' => 'Templates', 'slug' => 'templates']);

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(url('/sitemap-products.xml'), false)->assertSee(url('/sitemap-posts.xml'), false);
        $this->get('/sitemap-products.xml')->assertOk()
            ->assertSee(route('product', 'live-one'), false)
            ->assertDontSee('draft-one', false)->assertDontSee('hidden-one', false);
        $this->get('/sitemap-categories.xml')->assertOk()->assertSee(url('/c/templates'), false);
        $this->get('/sitemap-pages.xml')->assertOk()->assertSee('<loc>'.url('/').'</loc>', false);

        app(SeoSettings::class)->save(['sitemap_exclude' => ['products']]);
        $this->get('/sitemap.xml')->assertDontSee('sitemap-products.xml', false)->assertSee('sitemap-categories.xml', false);
        $this->get('/sitemap-products.xml')->assertNotFound();
        $this->get('/sitemap-nothing.xml')->assertNotFound();
    }

    public function test_robots_txt_has_a_safe_default_and_always_names_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin', false)->assertSee('Disallow: /checkout', false)
            ->assertSee('Sitemap: '.url('/sitemap.xml'), false);

        app(SeoSettings::class)->save(['robots' => "User-agent: *\nDisallow: /private\nSitemap: https://evil.example/sitemap.xml"]);
        $this->get('/robots.txt')->assertSee('Disallow: /private', false)->assertDontSee('evil.example', false)->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }

    public function test_changing_the_address_of_a_published_item_redirects_the_old_one(): void
    {
        $product = Product::factory()->create(['slug' => 'old-name']);
        $draft = Product::factory()->draft()->create(['slug' => 'draft-old']);

        $product->update(['slug' => 'new-name']);
        $draft->update(['slug' => 'draft-new']);

        $this->get('/product/old-name?ref=mail')->assertStatus(301)->assertRedirect('/product/new-name?ref=mail');
        $this->get('/product/new-name')->assertOk();
        $this->assertSame(1, Redirect::query()->count());
        $this->assertSame(1, Redirect::query()->firstOrFail()->hits);
        $this->get('/product/draft-old')->assertNotFound();

        // A second move sends the first address straight to the newest one, without a chain.
        $product->update(['slug' => 'newest-name']);
        $this->get('/product/old-name')->assertRedirect('/product/newest-name');
        $this->get('/product/new-name')->assertRedirect('/product/newest-name');

        // Moving back frees the address: the page shows, and nothing redirects away from it.
        $product->update(['slug' => 'old-name']);
        $this->get('/product/old-name')->assertOk();
        $this->get('/product/newest-name')->assertRedirect('/product/old-name');
    }

    public function test_redirects_are_checked_for_loops_and_duplicates(): void
    {
        $redirects = app(Redirects::class);
        $redirects->save(null, '/a', '/b', 301);
        // A full address on this site, in capitals and with a trailing slash, is stored as the plain path.
        $redirects->save(null, strtoupper(url('/b')).'/', '/c', 302);

        $this->get('/a')->assertStatus(301)->assertRedirect('/b');
        $this->get('/b')->assertStatus(302)->assertRedirect('/c');

        foreach ([['/c', '/a', 'to_path'], ['/d', '/d', 'to_path'], ['/a', '/z', 'from_path'], ['/', '/z', 'from_path'], ['/admin/login', '/z', 'from_path']] as [$from, $to, $field]) {
            try {
                $redirects->save(null, $from, $to, 301);
                $this->fail("{$from} to {$to} should have been refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $redirects->save(null, '/out', 'https://example.com/landing', 301);
        $this->get('/out')->assertRedirect('https://example.com/landing');
        $this->assertSame(3, Redirect::query()->count());
    }

    public function test_addresses_that_do_not_exist_are_logged_with_a_count(): void
    {
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/No-Such-Page/?x=1')->assertNotFound();
        $this->get('/favicon.ico')->assertNotFound();
        $this->get('/wp-login.php')->assertNotFound();
        $this->get('/admin/no-such-thing');

        $this->assertSame(1, NotFoundLog::query()->count());
        $log = NotFoundLog::query()->firstOrFail();
        $this->assertSame('/no-such-page', $log->path);
        $this->assertSame(2, $log->hits);
    }

    public function test_site_wide_code_and_verification_tags_are_on_storefront_pages(): void
    {
        app(SeoSettings::class)->save([
            'verification' => '<meta name="google-site-verification" content="abc123">',
            'head' => '<script>window.headCode = 1;</script>',
            'body_start' => '<!-- body start code -->',
            'body_end' => '<script>window.endCode = 1;</script>',
        ]);

        $this->get('/')->assertOk()
            ->assertSee('<meta name="google-site-verification" content="abc123">', false)
            ->assertSee('window.headCode = 1;', false)
            ->assertSee('<!-- body start code -->', false)
            ->assertSee('window.endCode = 1;', false);
        $this->get('/cart')->assertSee('window.headCode = 1;', false);
    }
}
