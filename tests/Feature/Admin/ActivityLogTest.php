<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use LogicException;

class ActivityLogTest extends AdminTestCase
{
    public function test_every_admin_action_is_logged(): void
    {
        Mail::fake();
        $super = $this->makeAdmin('super-admin');
        $target = $this->makeAdmin('staff');
        $moderator = Role::where('slug', 'moderator')->first();

        $this->post('/admin/login', ['email' => $super->email, 'password' => 'password-123']);
        $this->post('/admin/staff', ['name' => 'New Person', 'email' => 'new@example.com', 'role_id' => $moderator->id]);
        $this->put("/admin/staff/{$target->id}", ['name' => $target->name, 'role_id' => $moderator->id]);
        $this->post("/admin/staff/{$target->id}/sign-out");
        $this->post("/admin/staff/{$target->id}/suspend");
        $this->post("/admin/staff/{$target->id}/reactivate");
        $this->post('/admin/roles', ['name' => 'Helper', 'permissions' => ['media' => ['view']]]);
        $helper = Role::where('name', 'Helper')->first();
        $this->put("/admin/roles/{$helper->id}", ['name' => 'Helper', 'permissions' => ['media' => ['view', 'create']]]);
        $this->delete("/admin/roles/{$helper->id}");
        $this->put('/admin/staff-security', ['min_length' => 12, 'mixed_case' => false, 'numbers' => true, 'symbols' => false]);
        $this->delete("/admin/staff/{$target->id}");
        $this->post('/admin/logout');

        $actions = ActivityLog::orderBy('id')->pluck('action')->all();
        $this->assertSame([
            'sign_in', 'invite', 'role_change', 'force_sign_out', 'suspend', 'reactivate',
            'create', 'edit', 'delete', 'edit', 'delete', 'sign_out',
        ], $actions);

        $roleChange = ActivityLog::where('action', 'role_change')->first();
        $this->assertSame($super->id, $roleChange->admin_user_id);
        $this->assertSame($super->name, $roleChange->actor_name);
        // MySQL JSON columns do not keep key order, so compare without order.
        $this->assertEquals(['from' => 'Staff', 'to' => 'Moderator'], $roleChange->changes['role']);
        $this->assertNotNull($roleChange->ip);
        $this->assertNotNull($roleChange->created_at);
    }

    public function test_log_entries_cannot_be_edited_or_deleted(): void
    {
        $log = ActivityLog::create(['actor_name' => 'Tester', 'action' => 'create', 'module' => 'staff']);

        try {
            $log->update(['action' => 'changed']);
            $this->fail('Editing a log entry should not be possible.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Deleting a log entry should not be possible.');
        } catch (LogicException) {
        }

        $this->assertSame('create', $log->fresh()->action);
    }

    public function test_log_stays_when_the_person_is_removed(): void
    {
        $super = $this->makeAdmin('super-admin');
        $staff = $this->makeAdmin('staff');
        ActivityLog::create(['admin_user_id' => $staff->id, 'actor_name' => $staff->name, 'action' => 'create', 'module' => 'products']);

        $this->signIn($super)->delete("/admin/staff/{$staff->id}");

        $this->assertDatabaseHas('activity_logs', ['actor_name' => $staff->name, 'action' => 'create', 'admin_user_id' => null]);
    }

    public function test_log_can_be_searched_and_filtered(): void
    {
        $super = $this->makeAdmin('super-admin');
        $staff = $this->makeAdmin('staff');
        ActivityLog::create(['admin_user_id' => $staff->id, 'actor_name' => $staff->name, 'action' => 'create', 'module' => 'products', 'subject_label' => 'Blue lamp']);
        ActivityLog::create(['admin_user_id' => $super->id, 'actor_name' => $super->name, 'action' => 'edit', 'module' => 'settings', 'subject_label' => 'Store name']);

        $this->signIn($super);
        $this->get('/admin/activity?search=lamp')->assertInertia(fn ($page) => $page->component('Activity/Index')->has('logs.data', 1)->where('logs.data.0.label', 'Blue lamp'));
        $this->get('/admin/activity?module=settings')->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.module', 'settings'));
        $this->get("/admin/activity?person={$staff->id}")->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.actor', $staff->name));
    }

    public function test_there_is_no_route_to_change_the_log(): void
    {
        $super = $this->makeAdmin('super-admin');
        $log = ActivityLog::create(['actor_name' => 'Tester', 'action' => 'create', 'module' => 'staff']);

        $this->signIn($super);
        $this->delete("/admin/activity/{$log->id}")->assertNotFound();
        $this->put("/admin/activity/{$log->id}", [])->assertNotFound();
    }
}
