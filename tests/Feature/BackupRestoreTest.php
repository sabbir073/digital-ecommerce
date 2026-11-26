<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Backups\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The restore is tested for real: tables are dropped and rebuilt from the file, so no transaction wraps these tests. */
class BackupRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        // The next test class that refreshes the database builds it again from the migrations.
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_a_backup_puts_the_database_back_as_it_was(): void
    {
        Storage::fake('local');
        $product = Product::factory()->create(['name' => "Studio's \"Big\" Template\nLine two; with a semicolon", 'description' => '<p>Ünïcode ✓ and a backslash \\ here</p>']);
        Product::factory()->count(3)->create();
        Setting::put('store.name', 'Before the backup');
        $tables = count(Schema::getTables());

        $name = app(DatabaseBackup::class)->create()['name'];

        Product::query()->whereKeyNot($product->id)->delete();
        $product->update(['name' => 'Changed after the backup']);
        Setting::put('store.name', 'After the backup');
        Schema::drop('event_logs');

        $this->artisan('backup:restore', ['file' => $name, '--force' => true])->assertSuccessful();

        $this->assertSame(4, Product::query()->count());
        $restored = Product::query()->findOrFail($product->id);
        $this->assertSame("Studio's \"Big\" Template\nLine two; with a semicolon", $restored->name);
        $this->assertSame('<p>Ünïcode ✓ and a backslash \\ here</p>', $restored->description);
        $this->assertSame($product->price_cents, $restored->price_cents);
        $this->assertSame('Before the backup', Setting::get('store.name'));
        $this->assertTrue(Schema::hasTable('event_logs'));
        $this->assertCount($tables, Schema::getTables());

        // The store still works on the restored tables: new rows get new ids.
        $this->assertGreaterThan(Product::query()->max('id') - 1, Product::factory()->create()->id);
    }

    public function test_a_file_that_is_not_a_backup_is_refused_and_nothing_changes(): void
    {
        Storage::fake('local');
        Product::factory()->create();
        Storage::disk('local')->put('backups/backup-2026-01-01-031000-abcdef.sql', "DROP TABLE products;\n-- end of statement\n");

        $this->artisan('backup:restore', ['file' => 'backup-2026-01-01-031000-abcdef.sql', '--force' => true])
            ->expectsOutputToContain('This file is not a backup made by this store.')->assertFailed();
        $this->artisan('backup:restore', ['file' => 'missing.sql', '--force' => true])->assertFailed();

        $this->assertSame(1, Product::query()->count());
    }
}
