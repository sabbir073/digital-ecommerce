<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;

class RolePermissionTest extends AdminTestCase
{
    /** Which default role may open which admin area (PRD section 15). */
    public static function accessMatrix(): array
    {
        return [
            // role, url, allowed
            ['super-admin', '/admin/staff', true],
            ['super-admin', '/admin/roles', true],
            ['super-admin', '/admin/activity', true],
            ['super-admin', '/admin/staff-security', true],
            ['admin', '/admin/staff', true],
            ['admin', '/admin/roles', true],
            ['admin', '/admin/activity', true],
            ['moderator', '/admin/staff', false],
            ['moderator', '/admin/roles', false],
            ['moderator', '/admin/activity', false],
            ['moderator', '/admin/staff-security', false],
            ['staff', '/admin/staff', false],
            ['staff', '/admin/roles', false],
            ['staff', '/admin/activity', false],
        ];
    }

    #[DataProvider('accessMatrix')]
    public function test_default_roles_only_open_what_they_are_allowed(string $role, string $url, bool $allowed): void
    {
        $response = $this->signIn($this->makeAdmin($role))->get($url);

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_default_role_permissions_match_the_prd(): void
    {
        $moderator = Role::where('slug', 'moderator')->with('permissions')->first();
        $staff = Role::where('slug', 'staff')->with('permissions')->first();
        $admin = Role::where('slug', 'admin')->with('permissions')->first();

        // Moderator: reviews, chat, content and blog. No orders, settings or staff.
        $this->assertTrue($moderator->allows('reviews', 'edit'));
        $this->assertTrue($moderator->allows('chat', 'edit'));
        $this->assertTrue($moderator->allows('content', 'publish'));
        $this->assertTrue($moderator->allows('blog', 'publish'));
        foreach (['orders', 'refunds', 'settings', 'staff', 'products', 'integrations'] as $module) {
            $this->assertFalse($moderator->allows($module, 'view'), "moderator should not view {$module}");
        }

        // Staff: products, orders and selling pages. No settings, refunds, integrations or staff.
        $this->assertTrue($staff->allows('products', 'create'));
        $this->assertTrue($staff->allows('orders', 'edit'));
        $this->assertTrue($staff->allows('selling_pages', 'publish'));
        foreach (['settings', 'refunds', 'integrations', 'staff', 'reviews'] as $module) {
            $this->assertFalse($staff->allows($module, 'view'), "staff should not view {$module}");
        }

        // Admin: every module and action.
        foreach (array_keys(config('permissions.modules')) as $module) {
            foreach (config('permissions.actions') as $action) {
                $this->assertTrue($admin->allows($module, $action));
            }
        }
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/staff')->assertRedirect('/admin/login');
        $this->post('/admin/staff', [])->assertRedirect('/admin/login');
    }

    public function test_write_actions_are_checked_on_the_server(): void
    {
        $staff = $this->makeAdmin('staff');
        $target = $this->makeAdmin('moderator');
        $role = Role::where('slug', 'staff')->first();

        $this->signIn($staff);
        $this->post('/admin/staff', ['name' => 'X', 'email' => 'x@example.com', 'role_id' => $role->id])->assertForbidden();
        $this->put("/admin/staff/{$target->id}", ['name' => 'X', 'role_id' => $role->id])->assertForbidden();
        $this->post("/admin/staff/{$target->id}/suspend")->assertForbidden();
        $this->delete("/admin/staff/{$target->id}")->assertForbidden();
        $this->post('/admin/roles', ['name' => 'Hack', 'permissions' => ['staff' => ['view']]])->assertForbidden();
        $this->put('/admin/staff-security', ['min_length' => 8, 'mixed_case' => false, 'numbers' => false, 'symbols' => false])->assertForbidden();

        $this->assertDatabaseMissing('admin_users', ['email' => 'x@example.com']);
        $this->assertDatabaseMissing('roles', ['name' => 'Hack']);
    }

    public function test_admin_cannot_manage_a_super_admin_or_hand_out_the_super_role(): void
    {
        $admin = $this->makeAdmin('admin');
        $super = $this->makeAdmin('super-admin');
        $this->makeAdmin('super-admin');
        $superRole = Role::where('slug', 'super-admin')->first();
        $staffRole = Role::where('slug', 'staff')->first();

        $this->signIn($admin);
        $this->put("/admin/staff/{$super->id}", ['name' => $super->name, 'role_id' => $staffRole->id])->assertForbidden();
        $this->post("/admin/staff/{$super->id}/suspend")->assertForbidden();
        $this->delete("/admin/staff/{$super->id}")->assertForbidden();
        $this->post('/admin/staff', ['name' => 'New', 'email' => 'new@example.com', 'role_id' => $superRole->id])->assertForbidden();

        $this->assertSame('active', $super->fresh()->status);
        $this->assertSame($superRole->id, $super->fresh()->role_id);
    }

    public function test_a_super_admin_always_remains(): void
    {
        $super = $this->makeAdmin('super-admin');
        $other = $this->makeAdmin('super-admin');
        $staffRole = Role::where('slug', 'staff')->first();

        // Nobody can suspend, remove or demote their own account, so the
        // person acting always stays behind as an active Super Admin.
        $this->signIn($super);
        $this->post("/admin/staff/{$super->id}/suspend")->assertSessionHasErrors('staff');
        $this->delete("/admin/staff/{$super->id}")->assertSessionHasErrors('staff');
        $this->put("/admin/staff/{$super->id}", ['name' => 'Me', 'role_id' => $staffRole->id])->assertSessionHasErrors('staff');
        $this->assertTrue($super->fresh()->isSuper());
        $this->assertSame('active', $super->fresh()->status);

        // A Super Admin can manage another Super Admin.
        $this->post("/admin/staff/{$other->id}/suspend")->assertSessionHasNoErrors();
        $this->assertSame('suspended', $other->fresh()->status);
    }

    public function test_custom_role_can_be_created_and_enforced(): void
    {
        $super = $this->makeAdmin('super-admin');

        $this->signIn($super)->post('/admin/roles', [
            'name' => 'Auditor',
            'description' => 'Reads the log',
            'permissions' => ['activity' => ['view'], 'bogus' => ['view'], 'staff' => ['fly']],
        ])->assertSessionHasErrors('permissions.staff.0');

        $this->signIn($super)->post('/admin/roles', [
            'name' => 'Auditor',
            'description' => 'Reads the log',
            'permissions' => ['activity' => ['view'], 'bogus' => ['view']],
        ])->assertSessionHasNoErrors();

        $role = Role::where('name', 'Auditor')->with('permissions')->firstOrFail();
        $this->assertSame(['activity' => ['view']], $role->permissionMap());

        $auditor = AdminUser::factory()->create(['role_id' => $role->id]);
        $this->signIn($auditor);
        $this->get('/admin/activity')->assertOk();
        $this->get('/admin/staff')->assertForbidden();

        // Removing the permission takes effect at once.
        $this->signIn($super)->put("/admin/roles/{$role->id}", ['name' => 'Auditor', 'permissions' => []])->assertSessionHasNoErrors();
        $this->signIn($auditor->fresh())->get('/admin/activity')->assertForbidden();
    }

    public function test_built_in_roles_cannot_be_edited_or_deleted_and_used_roles_cannot_be_deleted(): void
    {
        $super = $this->makeAdmin('super-admin');
        $staffRole = Role::where('slug', 'staff')->first();

        $this->signIn($super);
        $this->put("/admin/roles/{$staffRole->id}", ['name' => 'Staff', 'permissions' => ['settings' => ['view']]])->assertSessionHasErrors('role');
        $this->delete("/admin/roles/{$staffRole->id}")->assertSessionHasErrors('role');

        $custom = Role::create(['name' => 'Temp', 'slug' => 'temp']);
        AdminUser::factory()->create(['role_id' => $custom->id]);
        $this->delete("/admin/roles/{$custom->id}")->assertSessionHasErrors('role');
        $this->assertNotNull($custom->fresh());
    }

    public function test_a_person_cannot_grant_permissions_they_do_not_hold(): void
    {
        $limited = Role::create(['name' => 'Team lead', 'slug' => 'team-lead']);
        $limited->syncPermissions(['staff' => ['view', 'create', 'edit'], 'products' => ['view']]);
        $lead = AdminUser::factory()->create(['role_id' => $limited->id]);
        $adminRole = Role::where('slug', 'admin')->first();

        $this->signIn($lead);
        $this->post('/admin/roles', ['name' => 'Everything', 'permissions' => ['settings' => ['edit']]])->assertForbidden();
        $this->post('/admin/staff', ['name' => 'Pal', 'email' => 'pal@example.com', 'role_id' => $adminRole->id])->assertForbidden();
        $this->put("/admin/roles/{$limited->id}", ['name' => 'Team lead', 'permissions' => ['staff' => ['view']]])->assertSessionHasErrors('name');

        $this->post('/admin/roles', ['name' => 'Viewer', 'permissions' => ['products' => ['view']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('roles', ['name' => 'Viewer']);
    }
}
