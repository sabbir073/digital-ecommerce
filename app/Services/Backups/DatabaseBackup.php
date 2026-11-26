<?php

namespace App\Services\Backups;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Database backups as plain SQL files (PRD section 9: daily backups with a tested restore).
 *
 * Written in PHP instead of calling mysqldump, because shared hosting often blocks running programs.
 * The file can be restored with `php artisan backup:restore` or imported through phpMyAdmin.
 * Uploaded files (product downloads, images) are not in it: they live in storage/app.
 */
class DatabaseBackup
{
    public const FOLDER = 'backups';

    public const KEEP_DEFAULT = 14;

    /** Marks the end of a statement, so restoring never has to guess where one stops. */
    private const END = ";\n-- end of statement\n";

    /**
     * Write a backup and delete the ones beyond the number to keep.
     *
     * @return array{name:string, size:int}
     */
    public function create(): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::FOLDER);
        $gzip = function_exists('gzopen');
        $name = 'backup-'.now()->format('Y-m-d-His').'-'.bin2hex(random_bytes(3)).'.sql'.($gzip ? '.gz' : '');
        $path = $disk->path(self::FOLDER.'/'.$name);

        try {
            $handle = $gzip ? gzopen($path, 'wb6') : fopen($path, 'wb');
            if (! $handle) {
                throw new RuntimeException('The backup file could not be created. Check that the storage folder is writable.');
            }
            $write = fn (string $text) => $gzip ? gzwrite($handle, $text) : fwrite($handle, $text);
            $this->dump($write);
            $gzip ? gzclose($handle) : fclose($handle);
        } catch (Throwable $e) {
            @unlink($path);
            Setting::put('backups.last', ['ok' => false, 'at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }

        $size = (int) filesize($path);
        Setting::put('backups.last', ['ok' => true, 'at' => now()->toIso8601String(), 'name' => $name, 'size' => $size]);
        $this->prune();

        return ['name' => $name, 'size' => $size];
    }

    /** @return list<array{name:string, size:int, at:int}> newest first */
    public function all(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::FOLDER))
            ->filter(fn (string $file) => self::valid(basename($file)))
            ->map(fn (string $file) => ['name' => basename($file), 'size' => (int) $disk->size($file), 'at' => (int) $disk->lastModified($file)])
            ->sortByDesc('name')->values()->all();
    }

    public function path(string $name): ?string
    {
        $disk = Storage::disk('local');

        return self::valid($name) && $disk->exists(self::FOLDER.'/'.$name) ? $disk->path(self::FOLDER.'/'.$name) : null;
    }

    public function delete(string $name): bool
    {
        return self::valid($name) && Storage::disk('local')->delete(self::FOLDER.'/'.$name);
    }

    public function keep(): int
    {
        return max(3, min(90, (int) Setting::get('backups.keep', self::KEEP_DEFAULT)));
    }

    public function prune(): void
    {
        foreach (array_slice($this->all(), $this->keep()) as $old) {
            $this->delete($old['name']);
        }
    }

    /** Only names this class writes are ever opened, so a request cannot reach another file. */
    public static function valid(string $name): bool
    {
        return (bool) preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{6}\.sql(\.gz)?$/', $name);
    }

    /**
     * Replace the whole database with the content of a backup file.
     *
     * @return int the number of statements run
     */
    public function restore(string $path): int
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('The backup file could not be opened.');
        }
        $driver = DB::connection()->getDriverName();
        $first = (string) ($gzip ? gzgets($handle) : fgets($handle));
        if (! preg_match('/^-- Store backup\. Driver: (\w+)/', $first, $match)) {
            throw new RuntimeException('This file is not a backup made by this store.');
        }
        if ($match[1] !== $driver) {
            throw new RuntimeException("This backup was made on a {$match[1]} database and cannot be restored into {$driver}.");
        }

        $count = 0;
        $buffer = '';
        Schema::disableForeignKeyConstraints();
        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                if ($line === "-- end of statement\n") {
                    $statement = preg_replace('/;\s*$/', '', $buffer);
                    $buffer = '';
                    if (trim($statement) !== '') {
                        DB::unprepared($statement);
                        $count++;
                    }

                    continue;
                }
                $buffer .= $line;
            }
        } finally {
            Schema::enableForeignKeyConstraints();
            $gzip ? gzclose($handle) : fclose($handle);
        }

        return $count;
    }

    /** @param callable(string):mixed $write */
    private function dump(callable $write): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Backups are not available for a {$driver} database.");
        }
        $mysql = $driver !== 'sqlite';
        $pdo = $connection->getPdo();
        $q = fn (string $identifier) => $mysql ? '`'.str_replace('`', '``', $identifier).'`' : '"'.str_replace('"', '""', $identifier).'"';

        $write("-- Store backup. Driver: {$driver}. Made: ".now()->toIso8601String()."\n");
        $write("-- Restore with: php artisan backup:restore <file>   (replaces every table)\n");
        if ($mysql) {
            $write('SET FOREIGN_KEY_CHECKS=0'.self::END.'SET NAMES utf8mb4'.self::END);
        }

        $tables = collect(Schema::getTables())->filter(fn (array $table) => ! $mysql || ($table['schema'] ?? null) === null || $table['schema'] === $connection->getDatabaseName())
            ->pluck('name')->reject(fn (string $name) => str_starts_with($name, 'sqlite_'))->values();

        foreach ($tables as $table) {
            $write('DROP TABLE IF EXISTS '.$q($table).self::END);
            if ($mysql) {
                $create = (array) $connection->selectOne('SHOW CREATE TABLE '.$q($table));
                $write(array_values($create)[1].self::END);
            } else {
                foreach ($connection->select("select sql from sqlite_master where tbl_name = ? and sql is not null order by case type when 'table' then 0 else 1 end", [$table]) as $row) {
                    $write($row->sql.self::END);
                }
            }

            $columns = null;
            $rows = [];
            $flush = function () use (&$rows, &$columns, $write, $q, $table) {
                if ($rows !== []) {
                    $write('INSERT INTO '.$q($table).' ('.implode(', ', array_map($q, $columns)).") VALUES\n".implode(",\n", $rows).self::END);
                    $rows = [];
                }
            };
            foreach ($connection->cursor('select * from '.$q($table)) as $record) {
                $record = (array) $record;
                $columns ??= array_keys($record);
                $rows[] = '('.implode(', ', array_map(fn ($value) => match (true) {
                    $value === null => 'NULL',
                    is_int($value), is_float($value) => (string) $value,
                    is_bool($value) => $value ? '1' : '0',
                    default => $pdo->quote((string) $value),
                }, $record)).')';
                if (count($rows) >= 200) {
                    $flush();
                }
            }
            $flush();
        }
        if ($mysql) {
            $write('SET FOREIGN_KEY_CHECKS=1'.self::END);
        }
    }
}
