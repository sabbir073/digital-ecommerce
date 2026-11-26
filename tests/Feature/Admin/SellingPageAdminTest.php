<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\SellingPage;
use App\Models\Variant;
use App\Support\Blocks;
use Inertia\Testing\AssertableInertia;

class SellingPageAdminTest extends AdminTestCase
{
    private function payload(SellingPage $page, array $overrides = []): array
    {
        return $overrides + [
            'name' => $page->name,
            'slug' => $page->slug,
            'product_id' => $page->product_id,
            'blocks' => $page->draft_blocks,
            'offers' => [['label' => 'One pack', 'price' => '19.00', 'note' => 'Most popular', 'is_default' => true, 'items' => [['product_id' => $page->product_id, 'variant_id' => null, 'quantity' => 1]]]],
        ];
    }

    private function create(string $start = 'template'): SellingPage
    {
        $product = Product::factory()->create(['name' => 'Preset Pack', 'price_cents' => 3000, 'short_description' => 'One click looks.']);
        $this->signIn($this->makeAdmin('admin'))->post('/admin/selling-pages', ['name' => 'Preset launch', 'product_id' => $product->id, 'start' => $start])->assertRedirect();

        return SellingPage::query()->firstOrFail();
    }

    public function test_a_page_created_from_the_starter_template_is_a_draft_with_the_reference_blocks(): void
    {
        $page = $this->create();

        $this->assertSame(['draft', 'preset-launch'], [$page->status, $page->slug]);
        $this->assertSame(['hero', 'benefits', 'image_text', 'faq', 'order'], array_column($page->draft_blocks, 'type'));
        $this->assertSame('Preset Pack', $page->draft_blocks[0]['data']['headline']);
        $this->assertSame(3000, $page->offers->first()->price_cents);
        $this->assertCount(1, $page->offers->first()->items);
        $this->get('/p/preset-launch')->assertNotFound();

        $this->signIn($this->makeAdmin('admin'))->get("/admin/selling-pages/{$page->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->component('SellingPages/Edit')->has('blocks', 5)->has('offers', 1)->has('library.hero.fields')->has('problems', 1));
    }

    public function test_a_page_cannot_be_published_with_placeholder_text_or_without_one_order_block(): void
    {
        $page = $this->create();
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('error');
        $this->assertSame('draft', $page->refresh()->status);

        // Remove the order block: still blocked, with the reason.
        $blocks = array_values(array_filter($page->draft_blocks, fn ($b) => in_array($b['type'], ['hero', 'faq'], true)));
        $blocks[1]['data']['items'][2]['a'] = 'Within 14 days.';
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['blocks' => $blocks]))->assertSessionHasNoErrors();
        $response = $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish");
        $this->assertStringContainsString('Add an Order block', session('error'));
        $response->assertSessionHas('error');

        // Two order blocks are also refused.
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['blocks' => [...$blocks, Blocks::make('order'), Blocks::make('order')]]));
        $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish");
        $this->assertStringContainsString('only one Order block', session('error'));

        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['blocks' => [...$blocks, Blocks::make('order')]]));
        $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');

        $page->refresh();
        $this->assertSame('published', $page->status);
        $this->assertCount(1, $page->versions);
        $this->get('/p/preset-launch')->assertOk()->assertSee('Within 14 days.')->assertSee('One pack')->assertSee('$19.00');
        $this->assertTrue(ActivityLog::query()->where('module', 'selling_pages')->where('action', 'publish')->exists());
    }

    public function test_the_live_page_changes_only_on_publish_and_ten_versions_are_kept(): void
    {
        $page = $this->create('blank');
        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');

        $draft = [Blocks::make('rich_text', ['html' => '<p>New paragraph</p><script>x()</script>']), ...$page->refresh()->draft_blocks];
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['blocks' => $draft]))->assertSessionHasNoErrors();

        $this->assertSame('<p>New paragraph</p>', $page->refresh()->draft_blocks[0]['data']['html']);
        $this->get('/p/preset-launch')->assertOk()->assertDontSee('New paragraph');

        for ($i = 0; $i < 11; $i++) {
            $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');
        }
        $this->get('/p/preset-launch')->assertOk()->assertSee('New paragraph');
        $this->assertSame(10, $page->versions()->count());

        $this->signIn($admin)->post("/admin/selling-pages/{$page->id}/unpublish");
        $this->get('/p/preset-launch')->assertNotFound();
    }

    public function test_packs_are_validated(): void
    {
        $page = $this->create('blank');
        $admin = $this->makeAdmin('admin');

        $shirt = Product::factory()->create(['name' => 'Font family']);
        $option = ProductOption::create(['product_id' => $shirt->id, 'name' => 'Licence', 'position' => 0]);
        $variant = Variant::create(['product_id' => $shirt->id, 'price_cents' => 900, 'stock' => 0, 'position' => 0]);
        unset($option);

        $offer = fn (array $items, array $extra = []) => $extra + ['label' => 'Bundle', 'price' => '25', 'items' => $items];

        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['offers' => []]))->assertSessionHasErrors('offers');
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['offers' => array_fill(0, 5, $offer([['product_id' => $page->product_id, 'quantity' => 1]]))]))->assertSessionHasErrors('offers');
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['offers' => [$offer([])]]))->assertSessionHasErrors('offers.0.items');
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['offers' => [$offer([['product_id' => $shirt->id, 'variant_id' => null, 'quantity' => 1]])]]))
            ->assertSessionHasErrors('offers.0.items.0.variant_id');
        $this->signIn($admin)->put("/admin/selling-pages/{$page->id}", $this->payload($page, ['slug' => 'New Address!', 'offers' => [
            $offer([['product_id' => $page->product_id, 'quantity' => 1], ['product_id' => $shirt->id, 'variant_id' => $variant->id, 'quantity' => 2]]),
            $offer([['product_id' => $page->product_id, 'quantity' => 3]], ['label' => 'Triple', 'price' => '60.00', 'is_default' => true]),
        ]]))->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('new-address', $page->slug);
        $this->assertSame([[2500, false, 2], [6000, true, 1]], $page->offers->map(fn ($o) => [$o->price_cents, $o->is_default, $o->items->count()])->all());
    }

    public function test_permissions_and_delete(): void
    {
        $page = $this->create('blank');

        $moderator = $this->makeAdmin('moderator');
        $this->signIn($moderator)->get('/admin/selling-pages')->assertForbidden();
        $this->signIn($moderator)->put("/admin/selling-pages/{$page->id}", $this->payload($page))->assertForbidden();
        $this->signIn($moderator)->post("/admin/selling-pages/{$page->id}/publish")->assertForbidden();

        $staff = $this->makeAdmin('staff');
        $this->signIn($staff)->post("/admin/selling-pages/{$page->id}/publish")->assertSessionHas('success');
        $this->get('/p/preset-launch')->assertOk();
        $this->signIn($staff)->get('/admin/selling-pages')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('SellingPages/Index')->where('pages.0.views', 1)->where('pages.0.orders', 0)->where('pages.0.conversion', 0));

        $this->signIn($staff)->delete("/admin/selling-pages/{$page->id}")->assertRedirect('/admin/selling-pages');
        $this->get('/p/preset-launch')->assertNotFound();
        $this->assertSoftDeleted($page);

        // The address is free again.
        $this->signIn($staff)->post('/admin/selling-pages', ['name' => 'Preset launch', 'product_id' => $page->product_id, 'start' => 'blank'])->assertRedirect();
        $this->assertSame('preset-launch', SellingPage::query()->latest('id')->first()->slug);
    }

    public function test_a_product_sold_by_a_selling_page_cannot_be_deleted_and_demo_data_includes_a_sample_page(): void
    {
        $page = $this->create('blank');
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->delete("/admin/products/{$page->product_id}")->assertSessionHas('error');
        $this->assertNotNull(Product::find($page->product_id));

        $this->signIn($admin)->delete("/admin/selling-pages/{$page->id}");
        $this->signIn($admin)->delete("/admin/products/{$page->product_id}")->assertSessionHas('success');
        $this->assertNull(Product::find($page->product_id));

        $this->artisan('demo:seed', ['--no-images' => true])->assertSuccessful();
        $this->get('/p/demo-pitch-deck')->assertOk()->assertSee('Founder bundle')->assertSee('$39.00');
        $this->artisan('demo:clear')->assertSuccessful();
        $this->get('/p/demo-pitch-deck')->assertNotFound();
        $this->assertSame(0, SellingPage::withTrashed()->count());
    }
}
