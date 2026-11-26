<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvitation;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $me = $request->user('admin');

        return Inertia::render('Staff/Index', [
            'staff' => AdminUser::query()->with('role')->orderBy('name')->get()->map(fn (AdminUser $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roleId' => $u->role_id,
                'role' => $u->role?->name,
                'isSuper' => $u->isSuper(),
                'status' => $u->status,
                'hasTwoFactor' => $u->hasTwoFactor(),
                'lastLoginAt' => $u->last_login_at?->toIso8601String(),
                'isMe' => $u->id === $me->id,
                // Only a Super Admin may manage another Super Admin.
                'canManage' => $u->id !== $me->id && ($me->isSuper() || ! $u->isSuper()),
            ]),
            'roles' => Role::query()->orderBy('id')->get()->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'isSuper' => $r->is_super,
                'assignable' => $this->canAssign($me, $r),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('admin_users', 'email')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ]);

        $role = Role::query()->findOrFail($data['role_id']);
        $this->ensureCanAssign($me, $role);

        $user = new AdminUser($data);
        $user->status = AdminUser::STATUS_INVITED;
        $token = $this->issueInvite($user);
        $this->sendInvite($user, $token, $me);

        $this->activity->log('invite', 'staff', $user, $user->name, ['email' => $user->email, 'role' => $role->name]);

        return back()->with('success', "Invitation sent to {$user->email}.");
    }

    public function resend(Request $request, AdminUser $staff): RedirectResponse
    {
        $me = $request->user('admin');
        $this->ensureCanManage($me, $staff);

        if ($staff->status !== AdminUser::STATUS_INVITED) {
            throw ValidationException::withMessages(['staff' => 'This person has already accepted their invitation.']);
        }

        $token = $this->issueInvite($staff);
        $this->sendInvite($staff, $token, $me);
        $this->activity->log('resend_invite', 'staff', $staff, $staff->name);

        return back()->with('success', "Invitation sent again to {$staff->email}.");
    }

    public function update(Request $request, AdminUser $staff): RedirectResponse
    {
        $me = $request->user('admin');
        $this->ensureCanManage($me, $staff);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ]);

        $newRole = Role::query()->findOrFail($data['role_id']);
        $oldRole = $staff->role;
        $changes = [];

        if ($newRole->id !== $staff->role_id) {
            $this->ensureCanAssign($me, $newRole);
            if ($oldRole?->is_super && ! $newRole->is_super) {
                $this->ensureNotLastSuper($staff);
            }
            $changes['role'] = ['from' => $oldRole?->name, 'to' => $newRole->name];
        }
        if ($data['name'] !== $staff->name) {
            $changes['name'] = ['from' => $staff->name, 'to' => $data['name']];
        }

        $staff->update($data);

        if ($changes !== []) {
            $this->activity->log(isset($changes['role']) ? 'role_change' : 'edit', 'staff', $staff, $staff->name, $changes);
        }

        return back()->with('success', "{$staff->name} was updated.");
    }

    public function suspend(Request $request, AdminUser $staff): RedirectResponse
    {
        $this->ensureCanManage($request->user('admin'), $staff);
        $this->ensureNotLastSuper($staff);

        $staff->forceFill(['status' => AdminUser::STATUS_SUSPENDED])->save();
        $staff->invalidateSessions();
        $this->activity->log('suspend', 'staff', $staff, $staff->name);

        return back()->with('success', "{$staff->name} was suspended and signed out.");
    }

    public function reactivate(Request $request, AdminUser $staff): RedirectResponse
    {
        $this->ensureCanManage($request->user('admin'), $staff);

        if ($staff->status === AdminUser::STATUS_SUSPENDED) {
            $staff->forceFill([
                'status' => $staff->password === null ? AdminUser::STATUS_INVITED : AdminUser::STATUS_ACTIVE,
            ])->save();
            $this->activity->log('reactivate', 'staff', $staff, $staff->name);
        }

        return back()->with('success', "{$staff->name} can sign in again.");
    }

    public function signOut(Request $request, AdminUser $staff): RedirectResponse
    {
        $this->ensureCanManage($request->user('admin'), $staff);

        $staff->invalidateSessions();
        $this->activity->log('force_sign_out', 'staff', $staff, $staff->name);

        return back()->with('success', "{$staff->name} was signed out of every session.");
    }

    public function destroy(Request $request, AdminUser $staff): RedirectResponse
    {
        $this->ensureCanManage($request->user('admin'), $staff);
        $this->ensureNotLastSuper($staff);

        $name = $staff->name;
        $this->activity->log('delete', 'staff', $staff, $name, ['email' => $staff->email]);
        $staff->delete();

        return back()->with('success', "{$name} was removed.");
    }

    private function canAssign(AdminUser $me, Role $role): bool
    {
        if ($me->isSuper()) {
            return true;
        }
        if ($role->is_super) {
            return false;
        }

        // A person can never hand out more access than they hold themselves.
        foreach ($role->permissionMap() as $module => $actions) {
            foreach ($actions as $action) {
                if (! $me->allows($module, $action)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function ensureCanAssign(AdminUser $me, Role $role): void
    {
        abort_unless($this->canAssign($me, $role), 403, 'You cannot give this role.');
    }

    private function ensureCanManage(AdminUser $me, AdminUser $target): void
    {
        if ($me->id === $target->id) {
            throw ValidationException::withMessages(['staff' => 'You cannot change your own account here. Use My account.']);
        }
        abort_if($target->isSuper() && ! $me->isSuper(), 403, 'Only a Super Admin can manage a Super Admin.');
    }

    private function ensureNotLastSuper(AdminUser $target): void
    {
        if (! $target->isSuper() || ! $target->isActive()) {
            return;
        }

        $others = AdminUser::query()
            ->where('id', '!=', $target->id)
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('is_super', true))
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages(['staff' => 'At least one active Super Admin must always exist.']);
        }
    }

    private function issueInvite(AdminUser $user): string
    {
        $token = Str::random(48);
        $user->forceFill(['invite_token' => hash('sha256', $token), 'invited_at' => now()])->save();

        return $token;
    }

    private function sendInvite(AdminUser $user, string $token, AdminUser $inviter): void
    {
        Mail::to($user->email)->send(new StaffInvitation($user, route('admin.invite.show', $token), $inviter->name));
    }
}
