<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\EventLog;
use App\Models\Integration;
use App\Models\Product;
use App\Models\SellingPage;
use App\Models\Setting;
use App\Services\Integrations\Consent;
use App\Services\Integrations\CustomScripts;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\MailSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

class IntegrationAdminTest extends AdminTestCase
{
    private const TOKEN = 'EAAB-secret-access-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function integrations(): Integrations
    {
        return app(Integrations::class);
    }

    public function test_a_service_is_saved_switched_on_and_listed_with_its_status(): void
    {
        $this->signIn($this->makeAdmin('admin'));

        $this->get('/admin/integrations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Index')
            ->has('services', count(Integrations::SERVICES))
            ->where('services.1.key', 'ga4')
            ->where('services.1.status', 'off')
            ->has('feeds', 2));

        $this->put('/admin/integrations/ga4', ['enabled' => true, 'settings' => ['measurement_id' => '  G-ABC1234567 ']])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Google Analytics 4 saved.');

        $this->assertTrue($this->integrations()->active('ga4'));
        $this->assertSame('G-ABC1234567', $this->integrations()->setting('ga4', 'measurement_id'));
        $this->assertTrue(ActivityLog::query()->where('module', 'integrations')->where('subject_label', 'like', '%Google Analytics 4 switched on%')->exists());

        $this->get('/admin/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.1.status', 'on')
            ->where('services.1.active', true)
            ->where('services.1.fields.0.value', 'G-ABC1234567'));
    }

    public function test_a_wrong_or_missing_id_is_refused_with_a_plain_message(): void
    {
        $this->signIn($this->makeAdmin('admin'));

        $this->put('/admin/integrations/ga4', ['enabled' => true, 'settings' => ['measurement_id' => 'UA-12345-1']])
            ->assertSessionHasErrors(['settings.measurement_id' => 'This does not look like a valid measurement id. Starts with G-.']);
        $this->put('/admin/integrations/meta_pixel', ['enabled' => true, 'settings' => ['pixel_id' => '']])
            ->assertSessionHasErrors(['settings.pixel_id' => 'Enter the pixel id before switching this on.']);
        $this->assertFalse($this->integrations()->active('ga4'));

        // Switched off, an empty card can be saved.
        $this->put('/admin/integrations/meta_pixel', ['enabled' => false, 'settings' => ['pixel_id' => '']])->assertSessionHasNoErrors();
        $this->put('/admin/integrations/nothing', ['enabled' => true, 'settings' => []])->assertNotFound();
    }

    public function test_a_secret_is_stored_encrypted_shown_masked_and_kept_when_left_empty(): void
    {
        $this->signIn($this->makeAdmin('admin'));
        $this->put('/admin/integrations/meta_pixel', ['enabled' => true, 'settings' => ['pixel_id' => '123456789012']])->assertSessionHasNoErrors();

        $this->put('/admin/integrations/meta_capi', ['enabled' => true, 'settings' => ['access_token' => self::TOKEN, 'test_event_code' => 'TEST1234']])->assertSessionHasNoErrors();

        $raw = (string) DB::table('integrations')->where('service', 'meta_capi')->value('settings');
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, $this->integrations()->setting('meta_capi', 'access_token'));

        $response = $this->get('/admin/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.4.key', 'meta_capi')
            ->where('services.4.fields.0.value', '')
            ->where('services.4.fields.0.saved', 'EAAB…6789')
            ->where('services.4.canTestFromServer', true));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        // Saving again with the field left empty keeps the token.
        $this->put('/admin/integrations/meta_capi', ['enabled' => true, 'settings' => ['access_token' => '', 'test_event_code' => '']])->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, $this->integrations()->setting('meta_capi', 'access_token'));
        $this->assertSame('', $this->integrations()->setting('meta_capi', 'test_event_code'));
    }

    public function test_a_saved_secret_can_be_removed(): void
    {
        $this->signIn($this->makeAdmin('admin'));
        $this->put('/admin/integrations/tiktok', ['enabled' => true, 'settings' => ['pixel_id' => 'C4ABCDEFGHIJ1234567', 'access_token' => 'tiktok-token-0123456789']])->assertSessionHasNoErrors();
        $this->assertSame('tiktok-token-0123456789', $this->integrations()->setting('tiktok', 'access_token'));

        $this->delete('/admin/integrations/tiktok/access_token')->assertSessionHas('success', 'Removed.');

        $this->assertSame('', $this->integrations()->setting('tiktok', 'access_token'));
        $this->assertSame('C4ABCDEFGHIJ1234567', $this->integrations()->setting('tiktok', 'pixel_id'));
        // Only a secret can be removed this way.
        $this->delete('/admin/integrations/tiktok/pixel_id')->assertNotFound();
    }

    public function test_the_conversions_api_needs_the_pixel_switched_on_first(): void
    {
        $this->signIn($this->makeAdmin('admin'));

        $this->put('/admin/integrations/meta_capi', ['enabled' => true, 'settings' => ['access_token' => self::TOKEN]])
            ->assertSessionHasErrors(['enabled' => 'Switch on Meta Pixel first: this service uses its ID.']);
        $this->assertFalse($this->integrations()->active('meta_capi'));
    }

    public function test_only_roles_with_the_integrations_permission_get_in(): void
    {
        foreach (['moderator', 'staff'] as $role) {
            $this->signIn($this->makeAdmin($role));
            $this->get('/admin/integrations')->assertForbidden();
            $this->get('/admin/integrations/log')->assertForbidden();
            $this->put('/admin/integrations/ga4', ['enabled' => true, 'settings' => ['measurement_id' => 'G-ABC1234567']])->assertForbidden();
            $this->put('/admin/integrations/consent', [])->assertForbidden();
            $this->delete('/admin/integrations/log')->assertForbidden();
        }
        $this->assertFalse($this->integrations()->active('ga4'));
    }

    public function test_send_test_event_reports_what_the_service_answered(): void
    {
        $this->signIn($this->makeAdmin('admin'));
        $this->postJson('/admin/integrations/meta_capi/test')->assertOk()->assertJson(['ok' => false, 'message' => 'Switch this service on and save it first.']);

        $this->put('/admin/integrations/meta_pixel', ['enabled' => true, 'settings' => ['pixel_id' => '123456789012']]);
        $this->put('/admin/integrations/meta_capi', ['enabled' => true, 'settings' => ['access_token' => self::TOKEN, 'test_event_code' => 'TEST1234']]);
        $this->postJson('/admin/integrations/meta_pixel/test')->assertOk()->assertJson(['ok' => false]);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['events_received' => 1], 200)
            ->push(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        $this->postJson('/admin/integrations/meta_capi/test')->assertOk()->assertJson(['ok' => true, 'message' => 'The service accepted the test event. It is in the event log.']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/123456789012/events')
            && $request['test_event_code'] === 'TEST1234' && $request['data'][0]['event_name'] === 'ViewContent');
        $row = Integration::query()->where('service', 'meta_capi')->first();
        $this->assertSame('on', $row->status);
        $this->assertNotNull($row->last_event_at);
        $log = EventLog::query()->where('service', 'meta_capi')->first();
        $this->assertTrue($log->ok);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($log->payload).$log->response);

        $this->postJson('/admin/integrations/meta_capi/test')->assertOk()->assertJson(['ok' => false, 'message' => 'Invalid OAuth access token.']);
        $this->integrations()->flush();
        $this->get('/admin/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.4.status', 'error')
            ->where('services.4.error', 'Invalid OAuth access token.'));
    }

    public function test_the_event_log_lists_filters_and_clears(): void
    {
        EventLog::record('page_view', 'browser', ['id' => 'a'], '', true);
        EventLog::record('Purchase', 'meta_capi', ['id' => 'b'], '{"error":"bad"}', false);
        $this->signIn($this->makeAdmin('admin'));

        $this->get('/admin/integrations/log')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Log')
            ->has('events.data', 2)
            ->where('events.data.0.event', 'Purchase')
            ->where('events.data.0.serviceName', 'Meta Conversions API')
            ->where('events.data.1.serviceName', 'Browser')
            ->where('keep', 500));
        $this->get('/admin/integrations/log?result=failed')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1)->where('events.data.0.ok', false));
        $this->get('/admin/integrations/log?service=browser')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1)->where('events.data.0.event', 'page_view'));

        $this->put('/admin/integrations/logging', ['log_browser_events' => false])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Setting::get('integrations.log_browser_events', true));

        $this->delete('/admin/integrations/log')->assertSessionHas('success', 'The event log is empty.');
        $this->assertSame(0, EventLog::query()->count());
    }

    public function test_the_log_keeps_only_the_newest_500_events(): void
    {
        for ($i = 1; $i <= EventLog::KEEP + 5; $i++) {
            EventLog::record('page_view', 'browser', ['n' => $i], '', true);
        }

        $this->assertSame(EventLog::KEEP, EventLog::query()->count());
        $this->assertSame(6, EventLog::query()->orderBy('id')->first()->payload['n']);
    }

    public function test_the_cookie_banner_text_is_saved_and_checked(): void
    {
        $this->signIn($this->makeAdmin('admin'));
        $this->get('/admin/integrations/consent')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Consent')->where('consent.enabled', true)->where('anyActive', false));

        $data = ['title' => 'Your privacy', 'region' => 'europe', 'policy_page_id' => null] + Consent::DEFAULTS;
        $data['categories']['analytics']['label'] = 'Measurement';
        $this->put('/admin/integrations/consent', $data)->assertSessionHasNoErrors()->assertSessionHas('success', 'Cookie banner saved.');

        $saved = app(Consent::class)->settings();
        $this->assertSame('Your privacy', $saved['title']);
        $this->assertSame('europe', $saved['region']);
        $this->assertSame('Measurement', $saved['categories']['analytics']['label']);

        $this->put('/admin/integrations/consent', ['title' => '', 'region' => 'mars'] + $data)->assertSessionHasErrors(['title', 'region']);
        $this->assertSame('Your privacy', app(Consent::class)->settings()['title']);
    }

    public function test_only_a_super_admin_changes_the_email_account_and_the_password_stays_hidden(): void
    {
        $smtp = ['driver' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'store@example.com',
            'password' => 'smtp-password-0123456789', 'from_address' => 'hello@example.com', 'from_name' => 'Northlane'];

        $this->signIn($this->makeAdmin('admin'));
        $this->put('/admin/integrations/email', $smtp)->assertForbidden();
        $this->assertSame('server', app(MailSettings::class)->all()['driver']);

        $this->signIn($this->makeAdmin('super-admin'));
        $this->put('/admin/integrations/email', ['host' => 'https://smtp.example.com/'] + $smtp)->assertSessionHasErrors(['host' => 'Enter the server name only, for example smtp.example.com.']);
        $this->put('/admin/integrations/email', ['from_address' => ''] + $smtp)->assertSessionHasErrors(['from_address']);
        $this->put('/admin/integrations/email', $smtp)->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('smtp-password-0123456789', (string) DB::table('integrations')->where('service', 'email')->value('settings'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('hello@example.com', config('mail.from.address'));

        $response = $this->get('/admin/integrations/email')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Email')
            ->where('mail.host', 'smtp.example.com')
            ->where('mail.password', '')
            ->where('savedPassword', 'smtp…6789'));
        $this->assertStringNotContainsString('smtp-password-0123456789', $response->getContent());

        // An empty password keeps the saved one.
        $this->put('/admin/integrations/email', ['password' => '', 'port' => 465, 'encryption' => 'ssl'] + $smtp)->assertSessionHasNoErrors();
        $this->assertSame('smtp-password-0123456789', app(MailSettings::class)->all()['password']);
        $this->assertSame(465, app(MailSettings::class)->all()['port']);
    }

    public function test_a_test_email_is_sent_and_the_result_is_remembered(): void
    {
        Mail::fake();
        $this->signIn($this->makeAdmin('super-admin'));
        $this->put('/admin/integrations/email', ['driver' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_address' => 'hello@example.com', 'from_name' => '']);

        $this->postJson('/admin/integrations/email/test', ['to' => 'not-an-address'])->assertStatus(422);
        $this->postJson('/admin/integrations/email/test', ['to' => 'me@example.com'])->assertOk()->assertJson(['ok' => true]);

        $this->assertNotNull(app(MailSettings::class)->status()['last_event_at']);
        $this->assertSame('on', app(MailSettings::class)->status()['status']);
    }

    public function test_only_a_super_admin_saves_custom_scripts_and_they_reach_the_right_pages(): void
    {
        $scripts = ['scripts' => [
            ['id' => '', 'name' => 'Heatmap', 'placement' => 'head', 'scope' => 'all', 'paths' => [], 'code' => '<script>window.heatmap=1</script>', 'enabled' => true],
            ['id' => '', 'name' => 'Blog widget', 'placement' => 'body_end', 'scope' => 'paths', 'paths' => ['/blog/*', ' '], 'code' => '<script>window.blogWidget=1</script>', 'enabled' => true],
            ['id' => '', 'name' => 'Paused', 'placement' => 'head', 'scope' => 'all', 'paths' => [], 'code' => '<script>window.paused=1</script>', 'enabled' => false],
        ]];

        $this->signIn($this->makeAdmin('admin'));
        $this->get('/admin/integrations/scripts')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Integrations/Scripts')->where('canEdit', false));
        $this->put('/admin/integrations/scripts', $scripts)->assertForbidden();
        $this->assertSame([], app(CustomScripts::class)->all());

        $this->signIn($this->makeAdmin('super-admin'));
        $bad = $scripts;
        $bad['scripts'][1]['paths'] = ['blog'];
        $this->put('/admin/integrations/scripts', $bad)->assertSessionHasErrors(['scripts.1.paths.0']);
        $bad['scripts'][1]['paths'] = [];
        $this->put('/admin/integrations/scripts', $bad)->assertSessionHasErrors(['scripts.1.paths' => 'Add at least one address, or choose "Every page".']);
        $bad['scripts'][0]['code'] = '';
        $this->put('/admin/integrations/scripts', $bad)->assertSessionHasErrors(['scripts.0.code' => 'Paste the code, or remove this script.']);

        $this->put('/admin/integrations/scripts', $scripts)->assertSessionHasNoErrors()->assertSessionHas('success', 'Custom scripts saved.');
        $saved = app(CustomScripts::class)->all();
        $this->assertCount(3, $saved);
        $this->assertNotSame('', $saved[0]['id']);
        $this->assertSame(['/blog/*'], $saved[1]['paths']);

        $this->get('/admin/integrations/scripts')->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true)->has('scripts', 3));

        auth('admin')->logout();
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('window.heatmap=1', $home);
        $this->assertStringNotContainsString('window.blogWidget=1', $home);
        $this->assertStringNotContainsString('window.paused=1', $home);
        $blog = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('window.blogWidget=1', $blog);
        $this->assertStringContainsString('window.heatmap=1', $blog);

        $this->assertTrue(CustomScripts::matches('/blog/*', '/blog/first-post'));
        $this->assertTrue(CustomScripts::matches('/blog/*', '/blog'));
        $this->assertFalse(CustomScripts::matches('/blog/*', '/blogging'));
        $this->assertFalse(CustomScripts::matches('/p/summer', '/p/summer-sale'));
    }

    public function test_a_selling_page_takes_extra_tracking_ids_and_refuses_wrong_ones(): void
    {
        $product = Product::factory()->create();
        $page = SellingPage::create(['name' => 'Preset launch', 'slug' => 'preset-launch', 'product_id' => $product->id, 'status' => SellingPage::DRAFT, 'draft_blocks' => [], 'published_blocks' => []]);

        $this->signIn($this->makeAdmin('moderator'));
        $this->put("/admin/selling-pages/{$page->id}/pixels", ['pixels' => ['meta_pixel' => '999888777666']])->assertForbidden();

        $this->signIn($this->makeAdmin('staff'));
        $this->put("/admin/selling-pages/{$page->id}/pixels", ['pixels' => ['meta_pixel' => 'abc', 'ga4' => 'G-PAGE123456']])
            ->assertSessionHasErrors(['pixels.meta_pixel' => 'This does not look like a valid pixel id.']);
        $this->assertNull($page->fresh()->pixel_ids);

        $this->put("/admin/selling-pages/{$page->id}/pixels", ['pixels' => ['meta_pixel' => ' 999888777666 ', 'ga4' => '', 'tiktok' => '']])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Tracking IDs saved.');
        $this->assertSame(['meta_pixel' => '999888777666'], $page->fresh()->pixel_ids);

        // The page's own ID works by itself, and is added to the site-wide one when there is one.
        $this->assertSame(['999888777666'], $this->integrations()->browser($page->fresh())['meta_pixel']['ids']);
        $this->assertSame([], $this->integrations()->browser());
        $this->integrations()->save('meta_pixel', ['pixel_id' => '123456789012'], true);
        $this->assertSame(['123456789012', '999888777666'], $this->integrations()->browser($page->fresh())['meta_pixel']['ids']);

        $this->put("/admin/selling-pages/{$page->id}/pixels", ['pixels' => []])->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->pixel_ids);
    }
}
