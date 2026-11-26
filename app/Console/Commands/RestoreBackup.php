<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore {file : A backup name from storage, or the full path of a backup file} {--force : Do not ask for confirmation}';

    protected $description = 'Replace the whole database with a backup';

    public function handle(DatabaseBackup $backups): int
    {
        $file = (string) $this->argument('file');
        $path = is_file($file) ? $file : $backups->path($file);
        if (! $path) {
            $this->error('No such backup. Run "php artisan backup:list" to see the saved ones.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('This replaces every table in the database with the backup. Orders and changes made after the backup are lost. Continue?')) {
            return self::FAILURE;
        }

        try {
            $count = $backups->restore($path);
        } catch (Throwable $e) {
            $this->error('The restore failed: '.$e->getMessage());

            return self::FAILURE;
        }
        Cache::flush();
        $this->info("Restored. {$count} statements were run.");

        return self::SUCCESS;
    }
}
