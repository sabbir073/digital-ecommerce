<?php

namespace Tests\Feature\Admin;

use App\Mail\OrderConfirmation;
use App\Mail\PasswordReset;
use App\Mail\RefundIssued;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailTemplates;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

class EmailTemplateTest extends AdminTestCase
{
    private function order(array $attributes = []): Order
    {
        $order = Order::create($attributes + ['number' => '261126-ABCDEF', 'email' => 'rina@example.test', 'name' => 'Rina', 'status' => Order::PAID,
            'subtotal_cents' => 2000, 'total_cents' => 2000, 'paid_at' => now()]);
        $order->items()->create(['name' => 'Preset pack', 'quantity' => 1, 'unit_price_cents' => 2000, 'line_total_cents' => 2000]);

        return $order;
    }

    public function test_every_customer_email_renders_from_its_default_template(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->signIn($admin)->get('/admin/settings/emails')->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Emails')
            ->has('templates', count(EmailTemplates::definitions()))->where('templates.0.key', 'order_confirmation')->where('templates.0.custom', false));

        foreach (EmailTemplates::definitions() as $key => $definition) {
            $response = $this->signIn($admin)->postJson("/admin/settings/emails/{$key}/preview", ['subject' => $definition['subject'], 'body' => $definition['body']])->assertOk();
            $this->assertStringNotContainsString('{{', $response->json('subject'), $key);
            $this->assertStringNotContainsString('{{', $response->json('html'), $key);
            $this->assertStringContainsString('Alex Morgan', $response->json('html'), $key);
        }
    }

    public function test_an_edited_template_is_what_customers_get(): void
    {
        $admin = $this->makeAdmin('admin');
        Setting::put('store.name', 'Paper & Pixel');
        Setting::put('store.address', '1 Main Street, Springfield');

        $this->signIn($admin)->put('/admin/settings/emails/order_confirmation', [
            'subject' => 'Thanks {{customer_name}}! Order {{order_number}}',
            'body' => "# You are all set\n\nHello {{customer_name}}, here are your files from {{store_name}}.\n\n{{downloads}}\n\n{{button:Open my order}}\n\nCheers,\nThe team",
        ])->assertSessionHasNoErrors();

        $mail = new OrderConfirmation($this->order());
        $mail->assertHasSubject('Thanks Rina! Order 261126-ABCDEF');
        $mail->assertSeeInHtml('You are all set');
        $mail->assertSeeInHtml('Open my order');
        $mail->assertSeeInHtml('1 Main Street, Springfield');
        $mail->assertSeeInHtml('Cheers,<br>', false);
        $mail->assertDontSeeInHtml('Thank you for your order');
        // The items table was left out of this version.
        $mail->assertDontSeeInHtml('Qty');

        $this->signIn($admin)->delete('/admin/settings/emails/order_confirmation')->assertSessionHas('success');
        (new OrderConfirmation($this->order(['number' => '261126-GHJKLM'])))->assertSeeInHtml('Thank you for your order')->assertSeeInHtml('Qty');
    }

    public function test_values_cannot_inject_html_or_links_into_an_email(): void
    {
        $mail = new RefundIssued($this->order(['name' => '<script>alert(1)</script> [click](https://evil.example)', 'status' => Order::REFUNDED]), 2000);

        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $mail->assertSeeInHtml('its download links no longer work');
        $mail->assertSeeInHtml('$20.00');

        // A partial refund leaves the note out.
        (new RefundIssued($this->order(['number' => '261126-PARTLY', 'status' => Order::PARTIALLY_REFUNDED]), 500))->assertDontSeeInHtml('no longer work');
    }

    public function test_a_template_must_keep_what_the_email_needs_and_use_only_known_placeholders(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->put('/admin/settings/emails/order_confirmation', ['subject' => 'Your order', 'body' => 'Thanks {{customer_name}}.'])->assertSessionHasErrors('body');
        $this->signIn($admin)->put('/admin/settings/emails/order_confirmation', ['subject' => 'Your order', 'body' => '{{downloads}} {{card_number}}'])->assertSessionHasErrors('body');
        $this->signIn($admin)->put('/admin/settings/emails/order_confirmation', ['subject' => '{{downloads}}', 'body' => '{{downloads}}'])->assertSessionHasErrors('subject');
        $this->signIn($admin)->put('/admin/settings/emails/nothing', ['subject' => 'x', 'body' => 'y'])->assertNotFound();
        $this->assertSame([], (array) Setting::get('email.templates', []));

        // While typing, the preview answers with the problem instead of failing.
        $this->signIn($admin)->postJson('/admin/settings/emails/order_confirmation/preview', ['subject' => 'Your order', 'body' => 'No links here'])->assertOk()->assertJsonStructure(['error']);

        // Admin text is text, not code or HTML.
        $this->signIn($admin)->postJson('/admin/settings/emails/chat_reply/preview', ['subject' => 'Hi', 'body' => "<img src=x onerror=alert(1)> {{ config('app.key') }} @php echo 1; @endphp\n\n{{message}}"])
            ->assertOk()->assertJsonPath('html', fn (string $html) => ! str_contains($html, '<img src=x') && ! str_contains($html, (string) config('app.key')) && str_contains($html, '@php'));

        $this->signIn($this->makeAdmin('moderator'))->get('/admin/settings/emails')->assertForbidden();
    }

    public function test_the_password_reset_email_uses_its_template(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'rina@example.test', 'name' => 'Rina']);

        $this->post('/forgot-password', ['email' => 'rina@example.test'])->assertSessionHas('success');

        Mail::assertSent(PasswordReset::class, function (PasswordReset $mail) {
            $mail->assertHasSubject('Reset your '.Setting::get('store.name', config('app.name')).' password');
            $mail->assertSeeInHtml('Choose a new password');
            $mail->assertSeeInHtml('/reset-password/');

            return $mail->hasTo('rina@example.test');
        });
    }
}
