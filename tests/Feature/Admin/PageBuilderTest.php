<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Role;
use App\Models\SellingPage;
use App\Support\Blocks;

class PageBuilderTest extends AdminTestCase
{
    private AdminUser $admin;

    private function page(string $start = 'blank'): SellingPage
    {
        $this->admin = $this->makeAdmin('admin');
        $product = Product::factory()->create(['name' => 'Preset Pack', 'price_cents' => 3000]);
        $this->signIn($this->admin)->post('/admin/selling-pages', ['name' => 'Preset launch', 'product_id' => $product->id, 'start' => $start])->assertRedirect();

        return SellingPage::query()->firstOrFail();
    }

    private function saveDraft(SellingPage $page, array $blocks)
    {
        return $this->signIn($this->admin)->putJson("/admin/selling-pages/{$page->id}/draft", ['blocks' => $blocks]);
    }

    public function test_the_canvas_draws_the_draft_with_editing_hooks_and_without_the_checkout_script(): void
    {
        $page = $this->page();
        $hero = Blocks::make('hero', ['headline' => 'Edit me in place', 'badge' => 'New']);
        $hidden = ['hidden' => true] + Blocks::make('rich_text', ['html' => '<p>Kept out of sight</p>']);
        $this->saveDraft($page, [$hero, $hidden, ['hidden' => true] + Blocks::make('gallery'), ...$page->draft_blocks])->assertOk();

        $canvas = $this->signIn($this->admin)->get("/admin/selling-pages/{$page->id}/canvas")->assertOk();
        $canvas->assertSee('id="builder-blocks"', false)
            ->assertSee('data-block-id="'.$hero['id'].'"', false)
            ->assertSee('data-edit="headline"', false)
            ->assertSee('Edit me in place')
            // Hidden blocks stay on the canvas so they can be selected and shown again.
            ->assertSee('Kept out of sight')
            ->assertSee('data-block-hidden', false)
            // A block with nothing in it shows a placeholder to select.
            ->assertSee('This block has no content yet')
            ->assertSee('builder-canvas', false)
            ->assertDontSee('selling-config', false);

        // The public page and the staff preview carry none of the builder markup or code.
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');
        $this->get('/p/preset-launch')->assertOk()
            ->assertSee('Edit me in place')
            ->assertDontSee('data-edit', false)
            ->assertDontSee('data-block-id', false)
            ->assertDontSee('builder-canvas', false)
            ->assertDontSee('Kept out of sight')
            ->assertDontSee('This block has no content yet');
    }

    public function test_the_canvas_is_redrawn_from_unsaved_blocks_without_changing_the_draft(): void
    {
        $page = $this->page();
        $before = $page->draft_blocks;

        $html = $this->signIn($this->admin)->postJson("/admin/selling-pages/{$page->id}/render", [
            'blocks' => [Blocks::make('rich_text', ['html' => '<p>Typed a second ago</p><script>alert(1)</script>']), ...$before],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Typed a second ago', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('data-block-type="order"', $html);
        $this->assertSame($before, $page->refresh()->draft_blocks);
    }

    public function test_autosave_stores_a_cleaned_draft_and_reports_what_blocks_publishing(): void
    {
        $page = $this->page();
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');

        $response = $this->saveDraft($page, [
            Blocks::make('rich_text', ['html' => '<h2>Why it works</h2><img src=x onerror=alert(1)>']),
            ['id' => 'unknown-type-1', 'type' => 'carousel', 'data' => []],
        ])->assertOk();

        $response->assertJsonPath('hasUnpublishedChanges', true);
        $this->assertStringContainsString('Add an Order block', $response->json('problems.0'));

        $draft = $page->refresh()->draft_blocks;
        $this->assertCount(1, $draft);
        $this->assertSame('<h2>Why it works</h2>', $draft[0]['data']['html']);
        // Customers still get the published version.
        $this->get('/p/preset-launch')->assertOk()->assertDontSee('Why it works');
        // Publishing is refused while the order block is missing.
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('error');
        $this->assertSame(['order'], array_column($page->refresh()->published_blocks, 'type'));
    }

    public function test_an_earlier_published_version_can_be_restored_as_the_draft(): void
    {
        $page = $this->page();
        $order = $page->draft_blocks;

        $this->saveDraft($page, [Blocks::make('rich_text', ['html' => '<p>First version</p>']), ...$order])->assertOk();
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');
        $this->saveDraft($page, [Blocks::make('rich_text', ['html' => '<p>Second version</p>']), ...$order])->assertOk();
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');

        $first = $page->versions()->reorder('id')->firstOrFail();
        $response = $this->signIn($this->admin)->postJson("/admin/selling-pages/{$page->id}/versions/{$first->id}/restore")->assertOk();
        $response->assertJsonPath('blocks.0.data.html', '<p>First version</p>')->assertJsonPath('hasUnpublishedChanges', true);

        // The draft is the old version; the live page changes only on the next publish.
        $this->assertSame('<p>First version</p>', $page->refresh()->draft_blocks[0]['data']['html']);
        $this->get('/p/preset-launch')->assertOk()->assertSee('Second version')->assertDontSee('First version');
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');
        $this->get('/p/preset-launch')->assertOk()->assertSee('First version');

        // A version of another page cannot be restored into this one.
        $other = SellingPage::create(['name' => 'Other', 'slug' => 'other', 'product_id' => $page->product_id, 'status' => 'draft', 'draft_blocks' => []]);
        $this->signIn($this->admin)->postJson("/admin/selling-pages/{$other->id}/versions/{$first->id}/restore")->assertNotFound();
    }

    public function test_a_page_can_be_duplicated_as_a_new_draft(): void
    {
        $page = $this->page('template');
        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/publish");

        $this->signIn($this->admin)->post("/admin/selling-pages/{$page->id}/duplicate")->assertRedirect()->assertSessionHas('success');

        $copy = SellingPage::query()->where('id', '!=', $page->id)->firstOrFail();
        $this->assertSame(['Preset launch (copy)', 'preset-launch-copy', 'draft'], [$copy->name, $copy->slug, $copy->status]);
        $this->assertSame(array_column($page->draft_blocks, 'type'), array_column($copy->draft_blocks, 'type'));
        $this->assertSame([3000], $copy->offers->pluck('price_cents')->all());
        $this->assertCount(1, $copy->offers->first()->items);
        $this->assertNull($copy->published_blocks);
    }

    public function test_builder_actions_follow_role_permissions(): void
    {
        $page = $this->page();
        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
        $role->syncPermissions(['selling_pages' => ['view']]);
        $viewer = $this->makeAdmin('viewer');

        $this->signIn($viewer)->get("/admin/selling-pages/{$page->id}/builder")->assertOk();
        $this->signIn($viewer)->get("/admin/selling-pages/{$page->id}/canvas")->assertOk();
        $this->signIn($viewer)->putJson("/admin/selling-pages/{$page->id}/draft", ['blocks' => []])->assertForbidden();
        $this->signIn($viewer)->post("/admin/selling-pages/{$page->id}/duplicate")->assertForbidden();
        $this->assertCount(1, $page->refresh()->draft_blocks);

        auth('admin')->logout();
        $this->get("/admin/selling-pages/{$page->id}/canvas")->assertRedirect();
    }
}
