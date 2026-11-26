<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Write a database backup to storage and delete the oldest ones';

    public function handle(DatabaseBackup $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (Throwable $e) {
            Log::error('The database backup failed.', ['error' => $e->getMessage()]);
            $this->error('The backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Saved {$backup['name']} (".number_format($backup['size'] / 1024).' KB).');

        return self::SUCCESS;
    }
}
