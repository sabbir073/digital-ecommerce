<?php

namespace Tests\Feature\Admin;

use App\Mail\ContactMessageReceived;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\ContentPage;
use App\Models\HomeSection;
use App\Models\Setting;
use App\Support\Blocks;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

class ContentAdminTest extends AdminTestCase
{
    private function page(string $title = 'About us'): ContentPage
    {
        $this->signIn($this->makeAdmin('admin'))->post('/admin/pages', ['title' => $title])->assertRedirect();

        return ContentPage::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    public function test_a_content_page_is_built_published_and_shown_on_the_storefront(): void
    {
        $admin = $this->makeAdmin('admin');
        $page = $this->page();
        $this->assertSame('about-us', $page->slug);
        $this->assertSame('draft', $page->status);

        $this->get('/pages/about-us')->assertNotFound();

        $this->signIn($admin)->get("/admin/pages/{$page->id}/builder")->assertInertia(fn (AssertableInertia $p) => $p
            ->component('SellingPages/Builder')->where('page.module', 'content')->where('page.base', "/admin/pages/{$page->id}")
            ->where('versions', null)->has('library.contact_form')->missing('library.order')->missing('library.offer_bar'));

        $blocks = [
            Blocks::make('rich_text', ['html' => '<h2>Who we are</h2><p>A small studio.</p><script>alert(1)</script>'], [], 'content'),
            Blocks::make('order', [], [], 'selling'), // a selling block has no place on a content page
        ];
        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => $blocks])->assertOk()->assertJsonPath('problems', []);
        $page->refresh();
        $this->assertCount(1, $page->draft_blocks);
        $this->assertStringNotContainsString('<script', $page->draft_blocks[0]['data']['html']);

        $this->signIn($admin)->postJson("/admin/pages/{$page->id}/render", ['blocks' => $page->draft_blocks])->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Who we are'));
        $this->signIn($admin)->get("/admin/pages/{$page->id}/canvas")->assertOk()->assertSee('Who we are');

        // Still a draft: staff can preview it, visitors cannot.
        $this->signIn($admin)->get('/pages/about-us?preview=1')->assertOk()->assertSee('Who we are');

        $this->signIn($admin)->post("/admin/pages/{$page->id}/publish")->assertSessionHas('success');
        $this->assertSame('published', $page->fresh()->status);
    }

    public function test_a_published_page_is_public_and_keeps_showing_the_published_version(): void
    {
        $admin = $this->makeAdmin('admin');
        $page = $this->page('Refund policy');
        $first = [Blocks::make('rich_text', ['html' => '<p>Refunds within 14 days.</p>'], [], 'content')];
        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => $first])->assertOk();
        $this->signIn($admin)->post("/admin/pages/{$page->id}/publish");
        auth('admin')->logout();

        $this->get('/pages/refund-policy')->assertOk()->assertSee('Refunds within 14 days.')->assertSee('<h1', false);

        $second = [Blocks::make('rich_text', ['html' => '<p>Refunds within 30 days.</p>'], [], 'content')];
        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => $second])->assertOk()->assertJsonPath('hasUnpublishedChanges', true);
        auth('admin')->logout();
        $this->get('/pages/refund-policy')->assertOk()->assertSee('Refunds within 14 days.')->assertDontSee('30 days');
        // The preview flag does nothing for a visitor.
        $this->get('/pages/refund-policy?preview=1')->assertOk()->assertDontSee('30 days');

        $this->signIn($admin)->post("/admin/pages/{$page->id}/unpublish");
        auth('admin')->logout();
        $this->get('/pages/refund-policy')->assertNotFound();
    }

    public function test_a_page_with_placeholder_text_or_no_blocks_cannot_be_published(): void
    {
        $admin = $this->makeAdmin('admin');
        $page = $this->page('Terms');

        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => []])->assertOk();
        $this->signIn($admin)->post("/admin/pages/{$page->id}/publish")->assertSessionHas('error');

        $blocks = [Blocks::make('rich_text', ['html' => '<p>[YOUR TERMS]</p>'], [], 'content')];
        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => $blocks])->assertOk()->assertJsonCount(1, 'problems');
        $this->signIn($admin)->post("/admin/pages/{$page->id}/publish")->assertSessionHas('error');
        $this->assertSame('draft', $page->fresh()->status);
    }

    public function test_page_addresses_are_unique_and_staff_without_the_content_module_are_refused(): void
    {
        $admin = $this->makeAdmin('admin');
        $first = $this->page('About us');
        $second = $this->page('About us');
        $this->assertSame('about-us-2', $second->slug);

        $this->signIn($admin)->put("/admin/pages/{$second->id}", ['title' => 'About', 'slug' => 'About Us'])->assertSessionHasErrors('slug');
        $this->signIn($admin)->put("/admin/pages/{$second->id}", ['title' => 'Our story', 'slug' => 'Our Story!'])->assertSessionHasNoErrors();
        $this->assertSame('our-story', $second->fresh()->slug);

        $staff = $this->makeAdmin('staff');
        $this->signIn($staff)->get('/admin/pages')->assertForbidden();
        $this->signIn($staff)->delete("/admin/pages/{$first->id}")->assertForbidden();

        $this->signIn($this->makeAdmin('moderator'))->get('/admin/pages')->assertInertia(fn (AssertableInertia $p) => $p->component('Content/Pages')->has('pages', 2));
        $this->signIn($admin)->delete("/admin/pages/{$first->id}")->assertRedirect('/admin/pages');
        $this->assertNull(ContentPage::find($first->id));
    }

    public function test_menus_are_saved_and_show_in_the_header_and_footer(): void
    {
        $admin = $this->makeAdmin('admin');
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $live = $this->page('About us');
        $this->signIn($admin)->post("/admin/pages/{$live->id}/publish");
        $draft = $this->page('Careers');

        $this->signIn($admin)->put('/admin/navigation/header', ['name' => '', 'items' => [
            ['label' => 'Shop', 'link_type' => 'category', 'link_id' => $category->id, 'children' => [
                ['label' => 'Journal', 'link_type' => 'blog'],
            ]],
            ['label' => 'Our story', 'link_type' => 'page', 'link_id' => $live->id],
            ['label' => 'Work with us', 'link_type' => 'page', 'link_id' => $draft->id],
            ['label' => 'Help centre', 'link_type' => 'url', 'url' => 'https://help.example.test/'],
        ]])->assertSessionHasNoErrors();
        $this->signIn($admin)->put('/admin/navigation/footer_1', ['name' => 'Company', 'items' => [
            ['label' => 'About the studio', 'link_type' => 'page', 'link_id' => $live->id],
        ]])->assertSessionHasNoErrors();

        $this->signIn($admin)->get('/admin/navigation')->assertInertia(fn (AssertableInertia $p) => $p->component('Content/Navigation')
            ->has('menus', 4)->where('menus.0.items.0.label', 'Shop')->where('menus.0.items.0.children.0.label', 'Journal')
            ->where('menus.1.name', 'Company'));
        auth('admin')->logout();

        $home = $this->get('/')->assertOk();
        $home->assertSee('Our story')->assertSee('/pages/about-us')->assertSee('Journal')->assertSee('https://help.example.test/')
            ->assertSee('Company')->assertSee('About the studio');
        // A link to a page that is not published is left out instead of leading to a 404.
        $home->assertDontSee('Work with us');
    }

    public function test_menu_links_are_checked(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->put('/admin/navigation/header', ['items' => [['label' => 'Bad', 'link_type' => 'url', 'url' => 'javascript:alert(1)']]])
            ->assertSessionHasErrors('items.0.url');
        $this->signIn($admin)->put('/admin/navigation/header', ['items' => [['label' => 'Empty', 'link_type' => 'url']]])->assertSessionHasErrors('items.0.url');
        $this->signIn($admin)->put('/admin/navigation/header', ['items' => [['label' => 'Gone', 'link_type' => 'page', 'link_id' => 999999]]])
            ->assertSessionHasErrors('items.0.link_id');
        $this->signIn($admin)->put('/admin/navigation/footer_2', ['name' => '', 'items' => []])->assertSessionHasErrors('name');
        $this->signIn($admin)->put('/admin/navigation/sidebar', ['items' => []])->assertNotFound();
        $this->signIn($this->makeAdmin('staff'))->put('/admin/navigation/header', ['items' => []])->assertForbidden();
    }

    public function test_the_announcement_bar_is_controlled_from_admin(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->put('/admin/navigation/announcement', ['enabled' => true, 'text' => ''])->assertSessionHasErrors('text');
        $this->signIn($admin)->put('/admin/navigation/announcement', ['enabled' => true, 'text' => 'Winter sale is on', 'link' => '/c/templates'])->assertSessionHasNoErrors();
        auth('admin')->logout();
        $this->get('/')->assertSee('Winter sale is on');

        $this->signIn($admin)->put('/admin/navigation/announcement', ['enabled' => true, 'text' => 'Winter sale is on', 'starts_at' => now()->addDay()->toIso8601String()]);
        auth('admin')->logout();
        $this->get('/')->assertDontSee('Winter sale is on');

        $this->signIn($admin)->put('/admin/navigation/announcement', ['enabled' => false, 'text' => 'Winter sale is on']);
        $this->assertFalse(Setting::get('announcement')['enabled']);
    }

    public function test_home_sections_are_reordered_switched_off_and_edited(): void
    {
        $admin = $this->makeAdmin('admin');
        Category::create(['name' => 'Templates', 'slug' => 'templates']);

        $this->get('/')->assertOk()->assertSee('Shop by category');

        $sections = [];
        $this->signIn($admin)->get('/admin/home')->assertInertia(function (AssertableInertia $p) use (&$sections) {
            $p->component('Content/Home')->has('sections', count(HomeSection::TYPES));
            $sections = $p->toArray()['props']['sections'];
        });

        $payload = collect($sections)->map(fn ($s) => ['type' => $s['type'], 'enabled' => $s['enabled'], 'settings' => $s['settings']])->keyBy('type');
        $hero = $payload['hero'];
        $hero['settings']['headline'] = 'Tools for people who make things';
        $hero['settings']['primary_url'] = 'javascript:alert(1)';
        $categories = $payload['categories'];
        $categories['enabled'] = false;
        $trust = $payload['trust'];
        $ordered = collect([$trust, $hero, $categories])->concat($payload->except(['trust', 'hero', 'categories'])->values())->values()->all();

        $this->signIn($admin)->put('/admin/home', ['sections' => $ordered])->assertSessionHasNoErrors();
        $this->assertSame(0, HomeSection::query()->where('type', 'trust')->value('position'));
        $this->assertSame(1, HomeSection::query()->where('type', 'hero')->value('position'));
        $this->assertNotSame('javascript:alert(1)', HomeSection::query()->where('type', 'hero')->first()->settings['primary_url'] ?? '');
        auth('admin')->logout();

        $this->get('/')->assertOk()->assertSee('Tools for people who make things')->assertDontSee('Shop by category')->assertDontSee('javascript:alert');

        $this->signIn($admin)->put('/admin/home', ['sections' => array_slice($ordered, 1)])->assertSessionHasErrors('sections');
        $this->signIn($this->makeAdmin('staff'))->get('/admin/home')->assertForbidden();
    }

    public function test_contact_messages_reach_the_inbox_and_the_store_email(): void
    {
        Mail::fake();
        Setting::put('store.contact_email', 'hello@store.example.test');

        $this->post('/contact', ['name' => 'Rina', 'email' => 'Rina@Example.test', 'message' => 'Do you offer team licences?'])->assertSessionHas('success');
        Mail::assertQueued(ContactMessageReceived::class, fn ($mail) => $mail->hasTo('hello@store.example.test'));
        $message = ContactMessage::query()->firstOrFail();
        $this->assertSame('rina@example.test', $message->email);

        // A bot that fills the hidden field is told it worked, and nothing is kept.
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'Buy cheap things', 'website' => 'http://spam.test'])->assertSessionHas('success');
        $this->assertSame(1, ContactMessage::query()->count());
        $this->post('/contact', ['name' => '', 'email' => 'nope', 'message' => 'Hi'])->assertSessionHasErrors(['name', 'email', 'message']);

        $moderator = $this->makeAdmin('moderator');
        $this->signIn($moderator)->get('/admin/messages')->assertInertia(fn (AssertableInertia $p) => $p->component('Content/Messages')
            ->has('messages.data', 1)->where('messages.data.0.name', 'Rina')->where('counts.new', 1));
        $this->signIn($moderator)->put("/admin/messages/{$message->id}", ['status' => 'done'])->assertSessionHasNoErrors();
        $this->signIn($moderator)->get('/admin/messages')->assertInertia(fn (AssertableInertia $p) => $p->has('messages.data', 0)->where('counts.new', 0));
        $this->signIn($moderator)->get('/admin/messages?status=done')->assertInertia(fn (AssertableInertia $p) => $p->has('messages.data', 1));

        $this->signIn($this->makeAdmin('staff'))->get('/admin/messages')->assertForbidden();
        $this->signIn($moderator)->delete("/admin/messages/{$message->id}")->assertSessionHas('success');
        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_a_contact_form_block_renders_a_working_form(): void
    {
        $admin = $this->makeAdmin('admin');
        $page = $this->page('Contact');
        $blocks = [Blocks::make('contact_form', ['heading' => 'Talk to us'], [], 'content')];
        $this->signIn($admin)->putJson("/admin/pages/{$page->id}/draft", ['blocks' => $blocks])->assertOk();
        $this->signIn($admin)->post("/admin/pages/{$page->id}/publish");
        auth('admin')->logout();

        $this->get('/pages/contact')->assertOk()->assertSee('Talk to us')->assertSee('action="'.route('contact.send').'"', false)->assertSee('name="website"', false);
    }

    public function test_blog_posts_are_written_scheduled_and_published(): void
    {
        $admin = $this->makeAdmin('admin');
        $post = fn (array $data) => $data + ['title' => 'How to price a template', 'slug' => '', 'excerpt' => 'A short guide.', 'body' => '<p>Start with value.</p><script>x</script>',
            'category' => 'Guides', 'tags' => ['pricing', ' pricing ', 'templates'], 'status' => 'draft'];

        $this->signIn($admin)->post('/admin/blog', $post([]))->assertRedirect();
        $draft = BlogPost::query()->firstOrFail();
        $this->assertSame('how-to-price-a-template', $draft->slug);
        $this->assertSame(['pricing', 'templates'], $draft->tags);
        $this->assertStringNotContainsString('<script', $draft->body);
        $this->assertSame($admin->id, $draft->author_id);
        auth('admin')->logout();

        $this->get('/blog')->assertOk()->assertDontSee('How to price a template');
        $this->get('/blog/how-to-price-a-template')->assertNotFound();
        // Staff who may see the blog can read the draft.
        $this->signIn($admin)->get('/blog/how-to-price-a-template')->assertOk()->assertSee('Start with value.');

        // Scheduled: published, but dated tomorrow.
        $this->signIn($admin)->put("/admin/blog/{$draft->id}", $post(['status' => 'published', 'published_at' => now()->addDay()->toIso8601String()]))->assertSessionHasNoErrors();
        $this->signIn($admin)->get('/admin/blog')->assertInertia(fn (AssertableInertia $p) => $p->component('Blog/Index')->where('posts.data.0.scheduled', true));
        auth('admin')->logout();
        $this->get('/blog')->assertDontSee('How to price a template');

        $this->travel(2)->days();
        $this->get('/blog')->assertOk()->assertSee('How to price a template')->assertSee('Guides');
        $this->get('/blog/category/guides')->assertOk()->assertSee('How to price a template');
        $this->get('/blog?tag=pricing')->assertOk()->assertSee('How to price a template');
        $this->get('/blog?tag=nothing')->assertOk()->assertDontSee('How to price a template');
        $this->get('/blog/how-to-price-a-template')->assertOk()->assertSee('Start with value.')->assertSee('A short guide.');
        $this->get('/blog/category/unknown')->assertNotFound();

        $this->signIn($admin)->post('/admin/blog', $post(['slug' => 'how-to-price-a-template']))->assertSessionHasErrors('slug');
        $this->signIn($admin)->delete("/admin/blog/{$draft->id}")->assertRedirect('/admin/blog');
        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_only_roles_with_the_blog_module_can_write_posts(): void
    {
        $data = ['title' => 'News', 'body' => '<p>Hello</p>', 'status' => 'published', 'tags' => []];

        $this->signIn($this->makeAdmin('staff'))->post('/admin/blog', $data)->assertForbidden();
        $this->signIn($this->makeAdmin('moderator'))->post('/admin/blog', $data)->assertRedirect();
        $this->assertTrue(BlogPost::query()->firstOrFail()->isLive());
    }
}
