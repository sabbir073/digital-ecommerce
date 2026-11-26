<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\ActivityLogger;
use App\Services\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public const VALID_DAYS = 7;

    public function show(string $token, PasswordPolicy $policy): Response
    {
        $user = $this->find($token);

        return Inertia::render('Auth/AcceptInvite', [
            'token' => $token,
            'name' => $user->name,
            'email' => $user->email,
            'passwordHint' => $policy->describe(),
        ]);
    }

    public function accept(Request $request, string $token, PasswordPolicy $policy, ActivityLogger $activity): RedirectResponse
    {
        $user = $this->find($token);

        $data = $request->validate([
            'password' => ['required', 'confirmed', $policy->rule()],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'status' => AdminUser::STATUS_ACTIVE,
            'invite_token' => null,
        ])->save();

        $activity->log('accept_invite', 'staff', $user, $user->name, [], $user);

        return redirect()->route('admin.login')->with('success', 'Your password is set. You can sign in now.');
    }

    private function find(string $token): AdminUser
    {
        $user = AdminUser::query()
            ->where('invite_token', hash('sha256', $token))
            ->where('status', AdminUser::STATUS_INVITED)
            ->first();

        abort_if($user === null || $user->invited_at === null || $user->invited_at->lt(now()->subDays(self::VALID_DAYS)), 404);

        return $user;
    }
}
