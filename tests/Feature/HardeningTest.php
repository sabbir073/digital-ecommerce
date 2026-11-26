<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach (['/', '/login', '/admin/login', '/no-such-page'] as $url) {
            $response = $this->get($url);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_a_live_https_store_never_answers_on_plain_http(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://shop.example.com']);

        $this->get('http://shop.example.com/products?sort=new')->assertStatus(301)->assertRedirect('https://shop.example.com/products?sort=new');
        // A form post is not redirected, because a redirect would drop what was sent.
        $this->post('http://shop.example.com/login', [])->assertStatus(419);

        $this->get('https://shop.example.com/products')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        // Behind a proxy that ends HTTPS, the visitor is already on https: no redirect loop.
        $this->get('http://shop.example.com/products', ['X-Forwarded-Proto' => 'https'])->assertOk();
        $this->get('http://shop.example.com/products', ['CF-Visitor' => '{"scheme":"https"}'])->assertOk();
    }

    public function test_forwarded_addresses_are_believed_only_from_trusted_proxies(): void
    {
        $this->get('/', ['X-Forwarded-For' => '198.51.100.7'])->assertOk();
        $this->assertNotSame('198.51.100.7', request()->ip());

        config(['app.trusted_proxies' => '*']);
        $this->get('/', ['X-Forwarded-For' => '198.51.100.7'])->assertOk();
        $this->assertSame('198.51.100.7', request()->ip());
    }

    public function test_the_launch_checklist_fails_on_a_live_server_that_is_not_ready(): void
    {
        $this->artisan('store:check')->expectsOutputToContain('to-do list for launch')->assertSuccessful();

        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        $this->artisan('store:check')->expectsOutputToContain('must be fixed before this store is safe to run live')->assertFailed();
    }

    public function test_the_cron_heartbeat_is_recorded(): void
    {
        $this->artisan('schedule:test', ['--name' => 'cron-heartbeat'])->assertSuccessful();
        $this->assertNotNull(Setting::get('system.cron_at'));
    }
}
