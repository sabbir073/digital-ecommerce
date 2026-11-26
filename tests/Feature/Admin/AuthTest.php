<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AuthenticateAdmin;
use App\Mail\StaffInvitation;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

class AuthTest extends AdminTestCase
{
    public function test_sign_in_with_correct_details(): void
    {
        $user = $this->makeAdmin('admin');

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password-123'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user, 'admin');
        $this->get('/admin')->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->makeAdmin('admin');

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $user = $this->makeAdmin('admin');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'nope']);
        }

        // The sixth try is refused even with the right password.
        $response = $this->post('/admin/login', ['email' => $user->email, 'password' => 'password-123']);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
        $this->assertGuest('admin');
    }

    public function test_suspended_and_invited_people_cannot_sign_in(): void
    {
        foreach (['suspended', 'invited'] as $status) {
            $user = $this->makeAdmin('admin', ['status' => $status]);
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'password-123'])->assertSessionHasErrors('email');
            $this->assertGuest('admin');
        }
    }

    public function test_two_factor_is_required_when_enabled(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);
        $user = $this->makeAdmin('admin');
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['abcde-fghij'],
        ])->save();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password-123'])->assertRedirect('/admin/two-factor');
        $this->assertGuest('admin');
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->post('/admin/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post('/admin/two-factor', ['code' => $google2fa->getCurrentOtp($secret)])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'admin');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->makeAdmin('admin');
        $user->forceFill([
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['abcde-fghij', 'klmno-pqrst'],
        ])->save();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password-123']);
        $this->post('/admin/two-factor', ['code' => 'abcde-fghij'])->assertRedirect('/admin');
        $this->assertSame(['klmno-pqrst'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_two_factor_can_be_set_up_from_my_account(): void
    {
        $user = $this->makeAdmin('staff');
        $this->signIn($user);

        $this->post('/admin/account/two-factor')->assertRedirect();
        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post('/admin/account/two-factor/confirm', ['code' => '123'])->assertSessionHasErrors('code');
        $this->post('/admin/account/two-factor/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);

        $this->delete('/admin/account/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->delete('/admin/account/two-factor', ['password' => 'password-123'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_forced_sign_out_ends_the_other_persons_session(): void
    {
        $super = $this->makeAdmin('super-admin');
        $staff = $this->makeAdmin('staff');

        $this->signIn($staff)->get('/admin')->assertOk();
        $oldVersion = $staff->session_version;

        $this->signIn($super)->post("/admin/staff/{$staff->id}/sign-out")->assertSessionHasNoErrors();

        // The staff member's old session no longer matches.
        $this->actingAs($staff->fresh(), 'admin')
            ->withSession([AuthenticateAdmin::SESSION_KEY => $oldVersion])
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_invitation_flow(): void
    {
        Mail::fake();
        $super = $this->makeAdmin('super-admin');
        $role = Role::where('slug', 'moderator')->first();

        $this->signIn($super)->post('/admin/staff', ['name' => 'Mina Mod', 'email' => 'mina@example.com', 'role_id' => $role->id])
            ->assertSessionHasNoErrors();

        $invited = AdminUser::where('email', 'mina@example.com')->firstOrFail();
        $this->assertSame('invited', $invited->status);

        $url = null;
        Mail::assertSent(StaffInvitation::class, function (StaffInvitation $mail) use (&$url) {
            $url = $mail->url;

            return $mail->hasTo('mina@example.com');
        });

        $this->post('/admin/logout');
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk();
        $this->post($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($path, ['password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])->assertRedirect('/admin/login');

        $this->assertSame('active', $invited->fresh()->status);
        $this->get($path)->assertNotFound(); // the link works once

        $this->post('/admin/login', ['email' => 'mina@example.com', 'password' => 'a-long-password-1'])->assertRedirect('/admin');
    }

    public function test_expired_invitation_links_do_not_work(): void
    {
        $user = $this->makeAdmin('staff', ['status' => 'invited']);
        $user->forceFill(['invite_token' => hash('sha256', 'tok'), 'invited_at' => now()->subDays(8), 'password' => null])->save();

        $this->get('/admin/invite/tok')->assertNotFound();
    }

    public function test_password_policy_from_admin_is_enforced(): void
    {
        $super = $this->makeAdmin('super-admin');
        $this->signIn($super)->put('/admin/staff-security', ['min_length' => 14, 'mixed_case' => true, 'numbers' => true, 'symbols' => true])
            ->assertSessionHasNoErrors();

        $this->put('/admin/account/password', [
            'current_password' => 'password-123', 'password' => 'password-1234', 'password_confirmation' => 'password-1234',
        ])->assertSessionHasErrors('password');

        $this->put('/admin/account/password', [
            'current_password' => 'password-123', 'password' => 'Long-Password-99!', 'password_confirmation' => 'Long-Password-99!',
        ])->assertSessionHasNoErrors();

        // Still signed in on this session after changing the password.
        $this->get('/admin')->assertOk();
    }
}
