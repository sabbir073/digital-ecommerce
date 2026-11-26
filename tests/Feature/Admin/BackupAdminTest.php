<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Backups\DatabaseBackup;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

class BackupAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_only_a_super_admin_reaches_backups(): void
    {
        $name = app(DatabaseBackup::class)->create()['name'];

        $this->signIn($this->makeAdmin('admin'));
        $this->get('/admin/backups')->assertForbidden();
        $this->post('/admin/backups')->assertForbidden();
        $this->put('/admin/backups', ['keep' => 5])->assertForbidden();
        $this->get("/admin/backups/{$name}")->assertForbidden();
        $this->delete("/admin/backups/{$name}")->assertForbidden();

        $this->assertCount(1, app(DatabaseBackup::class)->all());
    }

    public function test_a_super_admin_makes_downloads_and_deletes_a_backup(): void
    {
        $this->signIn($this->makeAdmin('super-admin', ['email' => 'owner@example.com']));
        $this->get('/admin/backups')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Backups')->has('backups', 0)->where('keep', 14)->where('last', null));

        $this->post('/admin/backups')->assertSessionHas('success', 'Backup made.');

        $backups = app(DatabaseBackup::class)->all();
        $this->assertCount(1, $backups);
        $name = $backups[0]['name'];
        $this->assertTrue(DatabaseBackup::valid($name));
        $this->assertTrue(Setting::get('backups.last')['ok']);
        $this->get('/admin/backups')->assertInertia(fn (AssertableInertia $page) => $page->has('backups', 1)->where('backups.0.name', $name)->where('last.ok', true));

        $response = $this->get("/admin/backups/{$name}")->assertOk()->assertDownload($name);
        $sql = file_get_contents($response->baseResponse->getFile()->getPathname());
        $sql = str_ends_with($name, '.gz') ? gzdecode($sql) : $sql;
        $this->assertStringStartsWith('-- Store backup. Driver: ', $sql);
        $this->assertStringContainsString('owner@example.com', $sql);
        $this->assertTrue(ActivityLog::query()->where('subject_label', 'Downloaded backup '.$name)->exists());

        $this->delete("/admin/backups/{$name}")->assertSessionHas('success', 'Backup deleted.');
        $this->assertSame([], app(DatabaseBackup::class)->all());
        $this->get("/admin/backups/{$name}")->assertNotFound();
    }

    public function test_a_request_cannot_reach_a_file_that_is_not_a_backup(): void
    {
        Storage::disk('local')->put('backups/notes.txt', 'private');
        Storage::disk('local')->put('downloads/file.zip', 'private');
        $this->signIn($this->makeAdmin('super-admin'));

        $this->get('/admin/backups/notes.txt')->assertNotFound();
        $this->get('/admin/backups/..%2Fdownloads%2Ffile.zip')->assertNotFound();
        $this->delete('/admin/backups/notes.txt')->assertNotFound();
        Storage::disk('local')->assertExists('backups/notes.txt');
        $this->get('/admin/backups')->assertInertia(fn (AssertableInertia $page) => $page->has('backups', 0));
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        foreach (['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05'] as $day) {
            Storage::disk('local')->put("backups/backup-{$day}-031000-abcdef.sql", '-- old');
        }
        $this->signIn($this->makeAdmin('super-admin'));

        $this->put('/admin/backups', ['keep' => 2])->assertSessionHasErrors('keep');
        $this->put('/admin/backups', ['keep' => 3])->assertSessionHasNoErrors();
        $this->assertSame(['backup-2026-01-05-031000-abcdef.sql', 'backup-2026-01-04-031000-abcdef.sql', 'backup-2026-01-03-031000-abcdef.sql'], array_column(app(DatabaseBackup::class)->all(), 'name'));

        // The nightly command adds one and drops the oldest.
        $this->artisan('backup:run')->assertSuccessful();
        $names = array_column(app(DatabaseBackup::class)->all(), 'name');
        $this->assertCount(3, $names);
        $this->assertNotContains('backup-2026-01-03-031000-abcdef.sql', $names);
    }
}
