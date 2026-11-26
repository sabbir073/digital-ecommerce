<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class AccountTest extends CheckoutTestCase
{
    public function test_a_customer_can_register_sign_out_and_sign_in_again(): void
    {
        $this->post('/register', ['name' => 'Sam Buyer', 'email' => 'Sam@Example.com', 'password' => 'short'])->assertSessionHasErrors('password');
        $this->post('/register', ['name' => 'Sam Buyer', 'email' => 'Sam@Example.com', 'password' => 'long-enough-1'])->assertRedirect('/account');

        $user = User::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->get('/account')->assertOk()->assertSee('sam@example.com');

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->get('/account')->assertRedirect('/login');

        $this->post('/login', ['email' => 'sam@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'SAM@example.com', 'password' => 'long-enough-1'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_email_can_only_be_registered_once(): void
    {
        User::factory()->create(['email' => 'sam@example.com']);

        $this->post('/register', ['name' => 'Other', 'email' => 'SAM@example.com', 'password' => 'long-enough-1'])->assertSessionHasErrors('email');
        $this->assertSame(1, User::query()->count());
    }

    public function test_order_history_shows_only_the_customers_own_orders(): void
    {
        // A guest buys first.
        $this->addToCart($this->product(['name' => 'Guest Pack']));
        $this->startCheckout()->assertOk();
        $guestOrder = Order::query()->firstOrFail();
        $this->paySucceeded($guestOrder)->assertOk();

        // Someone else registers with that email: the guest order must not appear.
        $this->post('/register', ['name' => 'Impostor', 'email' => 'buyer@example.com', 'password' => 'long-enough-1'])->assertRedirect();
        $this->get('/account')->assertOk()->assertDontSee($guestOrder->number);

        // An order placed while signed in does appear, with its download.
        $this->addToCart($this->product(['name' => 'Member Pack']));
        $this->startCheckout()->assertOk();
        $memberOrder = Order::query()->latest('id')->firstOrFail();
        $this->paySucceeded($memberOrder, 'evt_member')->assertOk();

        $this->get('/account')->assertOk()->assertSee($memberOrder->number)->assertSee('Member Pack')->assertSee('pack.zip')->assertDontSee($guestOrder->number);
    }

    public function test_a_forgotten_password_can_be_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'sam@example.com']);

        // The answer is the same whether or not the account exists.
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('success');
        $this->post('/forgot-password', ['email' => 'Sam@Example.com'])->assertSessionHas('success');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->get("/reset-password/{$token}?email=sam@example.com")->assertOk();
        $this->post('/reset-password', ['token' => 'wrong', 'email' => 'sam@example.com', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => 'sam@example.com', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));
    }
}
