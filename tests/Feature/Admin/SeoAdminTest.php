<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\NotFoundLog;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\Seo\Redirects;
use App\Services\Seo\SeoSettings;
use App\Services\Seo\SeoSubjects;
use App\Services\StoreSettings;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

class SeoAdminTest extends AdminTestCase
{
    public function test_the_seo_panel_saves_the_fields_and_they_show_on_the_page(): void
    {
        $product = Product::factory()->create(['name' => 'Studio Template', 'slug' => 'studio-template', 'short_description' => 'A clean template.']);
        $this->signIn($this->makeAdmin('admin'));

        $this->get("/admin/seo/product/{$product->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Seo/Edit')
            ->where('item.slug', 'studio-template')
            ->where('item.public', true)
            ->where('meta.meta_title', '')
            ->where('defaults.canonical', route('product', 'studio-template'))
            ->has('checks'));

        $this->put("/admin/seo/product/{$product->id}", [
            'meta_title' => 'Buy the Studio Template', 'meta_description' => 'Made for small studios.', 'canonical_url' => '', 'index' => true, 'follow' => false,
            'og_title' => '', 'og_description' => '', 'og_media_id' => null, 'focus_keyword' => 'studio template',
            'custom_tags' => "<meta name=\"author\" content=\"Northlane\">\n<script>alert(1)</script>", 'slug' => 'Studio Template Pro',
        ])->assertRedirect("/admin/seo/product/{$product->id}")->assertSessionHas('success', 'Saved. The old address now redirects to the new one.');

        $this->assertSame('studio-template-pro', $product->fresh()->slug);
        $meta = app(SeoSubjects::class)->meta('product', $product->fresh());
        $this->assertSame('<meta name="author" content="Northlane">', $meta['custom_tags']);
        $this->assertFalse($meta['follow']);
        $this->assertNotNull(ActivityLog::query()->where('module', 'seo')->first());

        auth('admin')->logout();
        $this->get('/product/studio-template')->assertStatus(301)->assertRedirect('/product/studio-template-pro');
        $this->get('/product/studio-template-pro')->assertOk()
            ->assertSee('<title>Buy the Studio Template</title>', false)
            ->assertSee('<meta name="robots" content="nofollow">', false);
    }

    public function test_the_panel_checks_the_address_and_works_for_the_home_page(): void
    {
        $product = Product::factory()->create(['slug' => 'first']);
        Product::factory()->create(['slug' => 'taken']);
        $this->signIn($this->makeAdmin('admin'));
        $fields = ['meta_title' => '', 'meta_description' => '', 'canonical_url' => 'not a link', 'index' => true, 'follow' => true, 'slug' => 'taken'];

        $this->put("/admin/seo/product/{$product->id}", $fields)->assertSessionHasErrors(['slug', 'canonical_url']);
        $this->put("/admin/seo/product/{$product->id}", ['slug' => '!!!'] + $fields)->assertSessionHasErrors(['slug']);
        $this->assertSame('first', $product->fresh()->slug);
        $this->get('/admin/seo/product/999999')->assertNotFound();
        $this->get('/admin/seo/nonsense/1')->assertNotFound();

        $this->get('/admin/seo/home')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('item.type', 'home')->where('item.slug', null));
        $this->put('/admin/seo/home', ['meta_title' => 'Digital goods that work', 'meta_description' => '', 'canonical_url' => '', 'index' => true, 'follow' => true])->assertRedirect('/admin/seo/home');
        $this->postJson('/admin/seo-check/home', ['meta_title' => str_repeat('long ', 20)])->assertOk()->assertJsonPath('checks.0.ok', false);

        auth('admin')->logout();
        $this->get('/')->assertSee('<title>Digital goods that work</title>', false);
    }

    public function test_the_dashboard_lists_items_with_a_failing_check(): void
    {
        Category::create(['name' => 'Templates', 'slug' => 'templates']);
        Product::factory()->create(['name' => 'No Description Yet', 'slug' => 'bare', 'short_description' => null, 'description' => null]);
        $this->signIn($this->makeAdmin('admin'));

        $this->get('/admin/seo')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Seo/Dashboard')
            ->where('counts.1.type', 'product')->where('counts.1.total', 1)->where('counts.1.failing', 1)
            ->where('items', fn ($items) => collect($items)->contains(fn ($item) => $item['type'] === 'product' && $item['name'] === 'No Description Yet' && $item['problems'] !== [])));

        $this->get('/admin/seo?type=category')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', 'category')
            ->where('items', fn ($items) => collect($items)->every(fn ($item) => $item['type'] === 'category')));
    }

    public function test_templates_verification_robots_and_sitemap_are_saved_in_seo_settings(): void
    {
        Setting::put('store.name', 'Northlane Digital');
        Product::factory()->create(['name' => 'Studio Template', 'slug' => 'studio-template', 'price_cents' => 4900]);
        $this->signIn($this->makeAdmin('admin'));
        $this->get('/admin/seo/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Seo/Settings')->where('canEditCode', false)->where('robotsIsDefault', true));

        $templates = app(SeoSettings::class)->all()['templates'];
        $templates['product'] = ['title' => 'Get {{product_name}} for {{price}} | {{store_name}}', 'description' => ''];
        $this->put('/admin/seo/settings/templates', ['templates' => $templates, 'share_media_id' => null])->assertSessionHasNoErrors();
        $templates['product']['title'] = '';
        $this->put('/admin/seo/settings/templates', ['templates' => $templates, 'share_media_id' => null])->assertSessionHasErrors(['templates.product.title']);

        $this->put('/admin/seo/settings/verification', ['verification' => '<meta name="google-site-verification" content="abc"><script>bad()</script>'])->assertSessionHasNoErrors();
        $this->assertSame('<meta name="google-site-verification" content="abc">', app(SeoSettings::class)->all()['verification']);
        $this->put('/admin/seo/settings/verification', ['verification' => 'abc123'])->assertSessionHasErrors(['verification']);

        $this->put('/admin/seo/settings/robots', ['robots' => 'Disallow: /'])->assertSessionHasErrors(['robots']);
        $this->put('/admin/seo/settings/robots', ['robots' => "User-agent: *\nDisallow: /private"])->assertSessionHasNoErrors();
        $this->put('/admin/seo/settings/sitemap', ['sitemap_exclude' => ['posts', 'nonsense']])->assertSessionHasErrors(['sitemap_exclude.1']);
        $this->put('/admin/seo/settings/sitemap', ['sitemap_exclude' => ['posts']])->assertSessionHasNoErrors();

        auth('admin')->logout();
        $this->get('/product/studio-template')->assertSee('<title>Get Studio Template for $49.00 | Northlane Digital</title>', false);
        $this->get('/robots.txt')->assertSee('Disallow: /private', false);
        $this->get('/sitemap.xml')->assertDontSee('sitemap-posts.xml', false);

        $this->signIn($this->makeAdmin('admin'))->delete('/admin/seo/settings/robots')->assertSessionHas('success');
        $this->assertSame(SeoSettings::DEFAULT_ROBOTS, app(SeoSettings::class)->all()['robots']);
    }

    public function test_only_a_super_admin_can_change_site_wide_code(): void
    {
        $code = ['head' => '<script>window.tag = 1;</script>', 'body_start' => '', 'body_end' => ''];

        $this->signIn($this->makeAdmin('admin'))->put('/admin/seo/settings/code', $code)->assertForbidden();
        $this->assertSame('', app(SeoSettings::class)->all()['head']);

        $this->signIn($this->makeAdmin('super-admin'))->put('/admin/seo/settings/code', $code)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('<script>window.tag = 1;</script>', app(SeoSettings::class)->all()['head']);
    }

    public function test_redirects_are_created_changed_deleted_and_checked_for_loops(): void
    {
        $this->signIn($this->makeAdmin('admin'));

        $this->post('/admin/seo/redirects', ['from_path' => '/Old-Page/', 'to_path' => 'new-page', 'type' => 301])->assertSessionHasNoErrors();
        $redirect = Redirect::query()->firstOrFail();
        $this->assertSame(['/old-page', '/new-page', 301], [$redirect->from_path, $redirect->to_path, $redirect->type]);

        $this->post('/admin/seo/redirects', ['from_path' => '/new-page', 'to_path' => '/old-page', 'type' => 301])->assertSessionHasErrors(['to_path']);
        $this->post('/admin/seo/redirects', ['from_path' => '/old-page', 'to_path' => '/other', 'type' => 301])->assertSessionHasErrors(['from_path']);
        $this->post('/admin/seo/redirects', ['from_path' => '/x', 'to_path' => '/y', 'type' => 307])->assertSessionHasErrors(['type']);
        $this->assertSame(1, Redirect::query()->count());

        $this->put("/admin/seo/redirects/{$redirect->id}", ['from_path' => '/old-page', 'to_path' => '/newer-page', 'type' => 302])->assertSessionHasNoErrors();
        $this->get('/admin/seo/redirects?q=newer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Seo/Redirects')->where('redirects.total', 1)->where('redirects.data.0.type', 302));
        $this->get('/admin/seo/redirects?q=nothing')->assertInertia(fn (AssertableInertia $page) => $page->where('redirects.total', 0));

        auth('admin')->logout();
        $this->get('/old-page')->assertStatus(302)->assertRedirect('/newer-page');

        $this->signIn($this->makeAdmin('admin'))->delete("/admin/seo/redirects/{$redirect->id}")->assertSessionHas('success');
        auth('admin')->logout();
        $this->get('/old-page')->assertNotFound();
    }

    public function test_a_csv_of_redirects_is_imported_and_bad_lines_are_reported(): void
    {
        $csv = "old,new,type\n/summer-sale,/products,301\n/tmp-offer,/c/offers,302\n/loop-a,/loop-b\n/loop-b,/loop-a\n/broken,,301\n/odd,/even,999\n\n/summer-sale,/again,301\n";
        $this->signIn($this->makeAdmin('admin'));

        $this->post('/admin/seo/redirects/import', ['file' => UploadedFile::fake()->createWithContent('redirects.csv', $csv)])
            ->assertSessionHas('success', '3 redirects imported.')
            ->assertSessionHas('importProblems', fn (array $problems) => count($problems) === 4
                && str_starts_with($problems[0], 'Line 5:') && str_starts_with($problems[1], 'Line 6:') && str_starts_with($problems[2], 'Line 7:') && str_starts_with($problems[3], 'Line 9:'));

        $this->assertSame(['/loop-a', '/summer-sale', '/tmp-offer'], Redirect::query()->orderBy('from_path')->pluck('from_path')->all());
        $this->assertSame(302, Redirect::query()->where('from_path', '/tmp-offer')->value('type'));
        $this->post('/admin/seo/redirects/import', ['file' => UploadedFile::fake()->create('redirects.pdf', 10)])->assertSessionHasErrors(['file']);
    }

    public function test_the_not_found_log_turns_into_redirects_and_can_be_cleared(): void
    {
        $this->get('/lost-page')->assertNotFound();
        $this->get('/lost-page')->assertNotFound();
        $this->get('/another-lost-page')->assertNotFound();
        $this->signIn($this->makeAdmin('admin'));

        $this->get('/admin/seo/redirects?tab=404')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', '404')->where('notFound.total', 2)->where('notFound.data.0.path', '/lost-page')->where('notFound.data.0.hits', 2));

        $this->post('/admin/seo/redirects', ['from_path' => '/lost-page', 'to_path' => '/products', 'type' => 301])->assertSessionHasNoErrors();
        $this->assertSame(['/another-lost-page'], NotFoundLog::query()->pluck('path')->all());

        $this->delete('/admin/seo/not-found')->assertSessionHas('success');
        $this->assertSame(0, NotFoundLog::query()->count());
        $this->assertSame(1, Redirect::query()->count());
        $this->assertNotNull(app(Redirects::class)->match('/lost-page'));
    }

    public function test_roles_without_the_seo_module_cannot_open_or_change_seo(): void
    {
        $product = Product::factory()->create();

        foreach (['moderator', 'staff'] as $role) {
            $this->signIn($this->makeAdmin($role));
            $this->get('/admin/seo')->assertForbidden();
            $this->get('/admin/seo/settings')->assertForbidden();
            $this->get('/admin/seo/redirects')->assertForbidden();
            $this->get("/admin/seo/product/{$product->id}")->assertForbidden();
            $this->put("/admin/seo/product/{$product->id}", ['slug' => 'changed', 'index' => true, 'follow' => true])->assertForbidden();
            $this->post('/admin/seo/redirects', ['from_path' => '/a', 'to_path' => '/b', 'type' => 301])->assertForbidden();
            $this->put('/admin/seo/settings/robots', ['robots' => 'User-agent: *'])->assertForbidden();
            $this->delete('/admin/seo/not-found')->assertForbidden();
        }
        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_delivery_and_returns_text_are_set_in_store_settings(): void
    {
        $product = Product::factory()->create(['slug' => 'studio-template']);
        $this->get('/product/studio-template')->assertSee('Your download link is shown right after payment');

        $details = ['name' => 'Northlane Digital', 'currency_format' => 'symbol', 'number_style' => 'date_code', 'delivery_text' => 'Files arrive by email within a minute.', 'returns_text' => 'Refunds within 14 days.'];
        $current = app(StoreSettings::class)->store();
        $this->signIn($this->makeAdmin('admin'))->put('/admin/settings/store/details', $details + $current)->assertSessionHasNoErrors();

        auth('admin')->logout();
        $this->get('/product/'.$product->slug)->assertSee('Files arrive by email within a minute.')->assertSee('Refunds within 14 days.')->assertDontSee('Your download link is shown right after payment');
    }
}
