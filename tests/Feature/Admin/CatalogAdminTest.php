<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CatalogAdminTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Golden Presets', 'slug' => 'golden-presets', 'status' => 'active', 'price' => '22.00',
            'track_stock' => true, 'stock' => 0, 'tags' => ['Lightroom'],
            'options' => [['name' => 'Tone', 'display' => 'swatch', 'values' => [['value' => 'Warm', 'swatch_hex' => '#C98B5B'], ['value' => 'Cool', 'swatch_hex' => '#5B8BC9']]]],
            'variants' => [
                ['values' => ['Warm'], 'price' => '22.00', 'stock' => 5],
                ['values' => ['Cool'], 'price' => '25.00', 'stock' => 0],
            ],
        ];
    }

    public function test_a_product_created_in_admin_appears_on_the_storefront(): void
    {
        $this->signIn($this->makeAdmin('admin'))
            ->post('/admin/products', $this->payload())
            ->assertRedirect();

        $product = Product::query()->where('slug', 'golden-presets')->firstOrFail();
        $this->assertCount(2, $product->variants);
        $this->assertTrue(ActivityLog::query()->where('module', 'products')->where('action', 'create')->exists());

        $this->get('/product/golden-presets')->assertOk()->assertSee('Golden Presets')->assertSee('Warm')->assertSee('Cool');
        $this->get('/products')->assertSee('Golden Presets');
    }

    public function test_updating_a_product_keeps_the_ids_of_unchanged_variants(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->post('/admin/products', $this->payload());
        $product = Product::query()->firstOrFail();
        $warmId = $product->variants->first()->id;

        $payload = $this->payload();
        $payload['options'][0]['values'][] = ['value' => 'Mono', 'swatch_hex' => '#2B2B2B'];
        $payload['variants'][] = ['values' => ['Mono'], 'price' => '19.00', 'stock' => 2];
        $this->signIn($admin)->put("/admin/products/{$product->id}", $payload)->assertRedirect();

        $product->refresh();
        $this->assertCount(3, $product->variants);
        $this->assertSame($warmId, $product->variants->first()->id);
        $this->assertSame(1900, $product->price_cents);
    }

    public function test_compare_at_price_must_be_higher_than_the_price(): void
    {
        $this->signIn($this->makeAdmin('admin'))
            ->post('/admin/products', $this->payload(['options' => [], 'variants' => [], 'compare_at' => '10']))
            ->assertSessionHasErrors('compare_at');
        $this->assertSame(0, Product::query()->count());
    }

    public function test_roles_without_catalog_permission_are_refused_on_the_server(): void
    {
        $product = Product::factory()->create();

        $this->signIn($this->makeAdmin('moderator'))->get('/admin/products')->assertForbidden();
        $this->signIn($this->makeAdmin('moderator'))->post('/admin/products', $this->payload())->assertForbidden();
        $this->signIn($this->makeAdmin('moderator'))->delete("/admin/products/{$product->id}")->assertForbidden();
        $this->signIn($this->makeAdmin('moderator'))->post('/admin/categories', ['name' => 'X'])->assertForbidden();
        $media = Media::create(['disk' => 'public', 'path' => 'media/x/a.jpg', 'filename' => 'a.jpg', 'type' => 'image', 'mime' => 'image/jpeg']);
        $this->signIn($this->makeAdmin('moderator'))->delete("/admin/media/{$media->id}")->assertForbidden();
        $this->assertSame(1, Product::query()->count());
    }

    public function test_uploaded_images_are_resized_to_webp(): void
    {
        Storage::fake('public');

        $this->signIn($this->makeAdmin('admin'))
            ->post('/admin/media', ['files' => [UploadedFile::fake()->image('cover.jpg', 2000, 1500)]], ['Accept' => 'application/json'])
            ->assertSuccessful();

        $media = Media::query()->firstOrFail();
        $this->assertSame(2000, $media->width);
        $this->assertSame([320, 640, 960, 1440, 1920], array_map('intval', array_keys($media->sizes)));
        foreach ($media->sizes as $path) {
            $this->assertStringEndsWith('.webp', $path);
            Storage::disk('public')->assertExists($path);
        }
        $this->assertStringContainsString('320w', $media->srcset());
    }

    public function test_files_that_are_not_images_or_videos_are_rejected(): void
    {
        Storage::fake('public');

        $this->signIn($this->makeAdmin('admin'))
            ->post('/admin/media', ['files' => [UploadedFile::fake()->create('script.php', 10, 'application/x-php')]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_digital_files_are_stored_on_the_private_disk(): void
    {
        Storage::fake('downloads');
        $product = Product::factory()->create();

        $this->signIn($this->makeAdmin('admin'))
            ->post("/admin/products/{$product->id}/files", ['file' => UploadedFile::fake()->create('pack.zip', 300), 'version' => '1.0'])
            ->assertRedirect();

        $asset = $product->digitalAssets()->firstOrFail();
        $this->assertSame('downloads', $asset->disk);
        Storage::disk('downloads')->assertExists($asset->path);
        $this->assertStringNotContainsString('public', Storage::disk('downloads')->path($asset->path));
    }

    public function test_csv_import_creates_and_updates_and_export_round_trips(): void
    {
        Category::create(['name' => 'Audio', 'slug' => 'audio']);
        $admin = $this->makeAdmin('admin');
        $csv = "name,price,category,tags,status\nLoop Pack,16.00,audio,\"Music, Demo\",active\nBad Row,abc,,,active\n";

        $this->signIn($admin)
            ->post('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv)])
            ->assertRedirect();

        $product = Product::query()->where('name', 'Loop Pack')->firstOrFail();
        $this->assertSame(1600, $product->price_cents);
        $this->assertSame('Audio', $product->category->name);
        $this->assertSame(1, Product::query()->count(), 'the row with a bad price is skipped');

        $export = $this->signIn($admin)->get('/admin/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Loop Pack', $export);
        $this->assertStringContainsString('16.00', $export);
    }

    public function test_categories_have_at_most_two_levels(): void
    {
        $admin = $this->makeAdmin('admin');
        $top = Category::create(['name' => 'Top', 'slug' => 'top']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $top->id]);

        $this->signIn($admin)->post('/admin/categories', ['name' => 'Grandchild', 'slug' => 'grandchild', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->signIn($admin)->post('/admin/categories', ['name' => 'Second child', 'slug' => 'second-child', 'parent_id' => $top->id])->assertSessionHasNoErrors();
        $this->assertSame(3, Category::query()->count());
    }
}
