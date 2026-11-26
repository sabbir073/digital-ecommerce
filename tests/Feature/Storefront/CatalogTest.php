<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\HomeSection;
use App\Models\Product;
use App\Models\Tag;
use App\Services\ProductWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_product_appears_on_home_listing_and_its_own_page(): void
    {
        $category = Category::create(['name' => 'Templates', 'slug' => 'templates']);
        $product = Product::factory()->create(['name' => 'Studio Template', 'slug' => 'studio-template', 'category_id' => $category->id, 'price_cents' => 4900, 'compare_at_cents' => 6900, 'short_description' => 'A clean template.']);

        $this->get('/')->assertOk()->assertSee('Studio Template')->assertSee('Shop by category')->assertSee('Best sellers');
        $this->get('/products')->assertOk()->assertSee('Studio Template')->assertSee('$49.00')->assertSee('$69.00');
        $this->get('/c/templates')->assertOk()->assertSee('Studio Template');
        $this->get('/product/'.$product->slug)->assertOk()
            ->assertSee('A clean template.')
            ->assertSee('Save $20.00')
            ->assertSee('Delivery');
    }

    public function test_draft_and_archived_products_are_hidden(): void
    {
        Product::factory()->draft()->create(['name' => 'Hidden Draft', 'slug' => 'hidden-draft']);
        Product::factory()->create(['name' => 'Old One', 'slug' => 'old-one', 'status' => 'archived']);

        $this->get('/products')->assertDontSee('Hidden Draft')->assertDontSee('Old One');
        $this->get('/product/hidden-draft')->assertNotFound();
        $this->get('/product/old-one')->assertNotFound();
    }

    public function test_search_matches_name_category_and_tags(): void
    {
        $audio = Category::create(['name' => 'Audio', 'slug' => 'audio']);
        Product::factory()->create(['name' => 'Loop Pack', 'slug' => 'loop-pack', 'category_id' => $audio->id]);
        $tagged = Product::factory()->create(['name' => 'Grain Overlays', 'slug' => 'grain-overlays']);
        $tagged->tags()->attach(Tag::create(['name' => 'Photography', 'slug' => 'photography']));
        Product::factory()->create(['name' => 'Pitch Deck', 'slug' => 'pitch-deck']);

        $this->get('/search?q=loop')->assertSee('Loop Pack')->assertDontSee('Pitch Deck');
        $this->get('/search?q=audio')->assertSee('Loop Pack')->assertDontSee('Grain Overlays');
        $this->get('/search?q=photog')->assertSee('Grain Overlays')->assertDontSee('Loop Pack');
        $this->get('/search?q=100%25')->assertOk()->assertSee('No products found');
    }

    public function test_an_empty_result_links_back_to_all_products(): void
    {
        $this->get('/search?q=nothing-here')
            ->assertOk()
            ->assertSee('No products found')
            ->assertSee('Browse all products')
            ->assertSee(route('products'));
    }

    public function test_filters_and_sort(): void
    {
        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $child = Category::create(['name' => 'A child', 'slug' => 'a-child', 'parent_id' => $a->id]);
        Product::factory()->create(['name' => 'Cheap Thing', 'slug' => 'cheap', 'price_cents' => 500, 'category_id' => $a->id, 'sales_count' => 1]);
        Product::factory()->create(['name' => 'Middle Thing', 'slug' => 'middle', 'price_cents' => 2500, 'category_id' => $child->id, 'sales_count' => 9]);
        Product::factory()->stock(0)->create(['name' => 'Costly Thing', 'slug' => 'costly', 'price_cents' => 9000]);

        $this->get('/products?min=10&max=50')->assertSee('Middle Thing')->assertDontSee('Cheap Thing')->assertDontSee('Costly Thing');
        $this->get('/products?in_stock=1')->assertSee('Cheap Thing')->assertDontSee('Costly Thing');
        $this->get('/products?category=a')->assertSee('Cheap Thing')->assertSee('Middle Thing')->assertDontSee('Costly Thing');
        $this->get('/c/a-child')->assertSee('Middle Thing')->assertDontSee('Cheap Thing');

        $this->get('/products?sort=price_asc')->assertSeeInOrder(['Cheap Thing', 'Middle Thing', 'Costly Thing']);
        $this->get('/products?sort=price_desc')->assertSeeInOrder(['Costly Thing', 'Middle Thing', 'Cheap Thing']);
        $this->get('/products?sort=best')->assertSeeInOrder(['Middle Thing', 'Cheap Thing']);
        $this->get('/products?sort=bogus&min=abc')->assertOk();
    }

    public function test_listing_is_paginated(): void
    {
        Product::factory()->count(14)->create();

        $this->get('/products')->assertOk()->assertSee('14 products')->assertSee('rel="next"', false);
        $this->get('/products?page=2')->assertOk()->assertSee('rel="prev"', false);
    }

    public function test_product_page_carries_variant_state_and_marks_sold_out_values(): void
    {
        $product = app(ProductWriter::class)->save(new Product, [
            'name' => 'Preset Pack', 'slug' => 'preset-pack', 'status' => 'active', 'price' => '22', 'track_stock' => true,
            'options' => [['name' => 'Tone', 'display' => 'swatch', 'values' => [['value' => 'Warm', 'swatch_hex' => '#C98B5B'], ['value' => 'Cool', 'swatch_hex' => '#5B8BC9']]]],
            'variants' => [
                ['values' => ['Warm'], 'price' => '30', 'stock' => 0],
                ['values' => ['Cool'], 'price' => '22', 'compare_at' => '29', 'stock' => 3],
            ],
        ]);

        $this->assertSame(2200, $product->price_cents, 'the product price follows the cheapest variant');

        $response = $this->get('/product/preset-pack')->assertOk()
            ->assertSee('Tone')
            ->assertSee('Only 3 left')   // the first in-stock variant is preselected
            ->assertSee('Save $7.00')
            ->assertSee('max="3"', false);

        $state = $response->viewData('state');
        $this->assertTrue($state['tracked']);
        $this->assertCount(2, $state['variants']);
        $this->assertSame([0, 3], array_column($state['variants'], 'stock'));
        $this->assertSame([3000, 2200], array_column($state['variants'], 'price'));
    }

    public function test_related_products_come_from_the_same_category(): void
    {
        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $b = Category::create(['name' => 'B', 'slug' => 'b']);
        Product::factory()->create(['name' => 'Main Item', 'slug' => 'main', 'category_id' => $a->id]);
        Product::factory()->create(['name' => 'Sibling Item', 'slug' => 'sibling', 'category_id' => $a->id]);
        Product::factory()->create(['name' => 'Other Item', 'slug' => 'other', 'category_id' => $b->id]);

        $this->get('/product/main')->assertSee('You may also like')->assertSee('Sibling Item')->assertDontSee('Other Item');
    }

    public function test_home_sections_can_be_switched_off_reordered_and_edited(): void
    {
        Category::create(['name' => 'Templates', 'slug' => 'templates']);
        Product::factory()->create(['name' => 'Some Product', 'slug' => 'some-product']);

        HomeSection::create(['type' => 'categories', 'enabled' => false, 'position' => 1]);
        HomeSection::create(['type' => 'hero', 'enabled' => true, 'position' => 5, 'settings' => ['headline' => 'A custom headline']]);
        HomeSection::create(['type' => 'best_sellers', 'enabled' => true, 'position' => 2]);

        $this->get('/')->assertOk()
            ->assertDontSee('Shop by category')
            ->assertSeeInOrder(['Best sellers', 'A custom headline']);
    }

    public function test_demo_catalog_is_refused_on_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('demo:seed --no-images')->assertFailed();
        $this->assertSame(0, Product::query()->count());
    }

    public function test_demo_catalog_is_labelled_and_can_be_removed(): void
    {
        $this->artisan('demo:seed --no-images')->assertSuccessful();
        $this->assertSame(12, Product::query()->where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame(12, Tag::query()->where('slug', 'demo')->first()->products()->count());
        $this->get('/')->assertSee('Demo store');

        $this->artisan('demo:clear')->assertSuccessful();
        $this->assertSame(0, Product::query()->count());
        $this->get('/')->assertDontSee('Demo store');
    }
}
