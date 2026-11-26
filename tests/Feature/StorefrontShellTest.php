<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_has_the_shared_header_and_footer(): void
    {
        Setting::put('store.name', 'Northlane Digital');
        Category::create(['name' => 'Templates', 'slug' => 'templates']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('Northlane Digital')
            ->assertSee('Templates')
            ->assertSee('All rights reserved');
    }

    public function test_announcement_bar_follows_its_settings(): void
    {
        Setting::put('announcement', ['enabled' => true, 'text' => 'Launch week: 20% off']);
        $this->get('/')->assertSee('Launch week: 20% off');

        Setting::put('announcement', ['enabled' => true, 'text' => 'Launch week: 20% off', 'ends_at' => now()->subDay()->toIso8601String()]);
        $this->get('/')->assertDontSee('Launch week: 20% off');

        Setting::put('announcement', ['enabled' => false, 'text' => 'Launch week: 20% off']);
        $this->get('/')->assertDontSee('Launch week: 20% off');
    }

    public function test_unknown_pages_show_a_friendly_404(): void
    {
        $this->get('/nope')->assertNotFound()->assertSee('Page not found');
    }
}
