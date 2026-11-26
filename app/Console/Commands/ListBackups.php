<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackup;
use Illuminate\Console\Command;

class ListBackups extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'List the saved database backups';

    public function handle(DatabaseBackup $backups): int
    {
        $this->table(['Backup', 'Size', 'Made'], array_map(fn (array $b) => [$b['name'], number_format($b['size'] / 1024).' KB', date('Y-m-d H:i', $b['at'])], $backups->all()));

        return self::SUCCESS;
    }
}
