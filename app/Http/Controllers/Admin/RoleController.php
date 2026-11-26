<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->with('permissions')->withCount('adminUsers')->orderBy('id')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'description' => $r->description,
                    'isSystem' => $r->is_system,
                    'isSuper' => $r->is_super,
                    'people' => $r->admin_users_count,
                    'permissions' => (object) $r->permissionMap(),
                ]),
            'modules' => config('permissions.modules'),
            'actions' => config('permissions.actions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->ensureWithinOwnAccess($request->user('admin'), $data['permissions']);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
            ]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        $this->activity->log('create', 'staff', $role, "Role: {$role->name}", ['permissions' => $role->load('permissions')->permissionMap()]);

        return back()->with('success', "Role {$role->name} was created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->ensureEditable($role);
        $me = $request->user('admin');

        if ($me->role_id === $role->id && ! $me->isSuper()) {
            throw ValidationException::withMessages(['name' => 'You cannot change your own role.']);
        }

        $data = $this->validated($request, $role);
        $this->ensureWithinOwnAccess($me, $data['permissions']);

        $before = $role->load('permissions')->permissionMap();
        DB::transaction(function () use ($role, $data) {
            $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->syncPermissions($data['permissions']);
        });

        $this->activity->log('edit', 'staff', $role, "Role: {$role->name}", [
            'permissions' => ['from' => $before, 'to' => $role->load('permissions')->permissionMap()],
        ]);

        return back()->with('success', "Role {$role->name} was saved.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->ensureEditable($role);

        if (AdminUser::query()->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Move the people in this role to another role first.']);
        }

        $name = $role->name;
        $this->activity->log('delete', 'staff', $role, "Role: {$name}");
        $role->delete();

        return back()->with('success', "Role {$name} was deleted.");
    }

    /** @return array{name:string, description:?string, permissions:array<string, list<string>>} */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:190'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string', Rule::in(config('permissions.actions'))],
        ]);

        $data['permissions'] = array_intersect_key($data['permissions'], config('permissions.modules'));

        return $data;
    }

    private function ensureEditable(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Built-in roles cannot be changed. Create a custom role instead.']);
        }
    }

    /** @param array<string, list<string>> $permissions */
    private function ensureWithinOwnAccess(AdminUser $me, array $permissions): void
    {
        foreach ($permissions as $module => $actions) {
            foreach ($actions as $action) {
                abort_unless($me->allows($module, $action), 403, 'You cannot grant a permission you do not have.');
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;
        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
