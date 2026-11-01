<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Copies table rows between two connections whose tables were built by the same migrations, e.g.
 * SQLite to MySQL when moving the app, or MySQL to a fresh SQLite file for a backup.
 */
class DatabaseCopier
{
    /**
     * Rebuilt by the app or filled by the migrations themselves; copying sessions would also carry live logins across.
     */
    public const SKIPPED_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'password_reset_tokens'];

    private const BATCH_SIZE = 500;

    /**
     * Data tables of the connection, without the skipped ones.
     *
     * @return array<string>
     */
    public function tables(Connection $connection): array
    {
        $names = match ($connection->getDriverName()) {
            'sqlite' => $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"),
            'mysql', 'mariadb' => $connection->select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' order by table_name"),
            default => throw new RuntimeException("Driver {$connection->getDriverName()} tidak didukung"),
        };

        return collect($names)->pluck('name')
            ->reject(fn (string $table) => in_array($table, self::SKIPPED_TABLES, true))
            ->values()->all();
    }

    /**
     * Replace the rows of each table in the target with the source's, all in one transaction, and
     * check every table ends with the same row count. Rows the migrations seeded (categories,
     * modem types) are cleared first.
     *
     * @param  array<string>  $tables
     * @param  ?callable(string, callable): void  $task  wraps each table's copy, e.g. to show progress
     * @return array<string, int> rows per table
     */
    public function copy(Connection $source, Connection $target, array $tables, ?callable $task = null): array
    {
        $task ??= fn (string $table, callable $copy) => $copy();

        // Tables are copied one by one, so a ticket may arrive before the user who made it.
        return $target->getSchemaBuilder()->withoutForeignKeyConstraints(fn () => $target->transaction(function () use ($source, $target, $tables, $task) {
            $counts = [];
            foreach ($tables as $table) {
                $task($table, function () use ($source, $target, $table) {
                    // DELETE rather than TRUNCATE, which would commit the transaction on MySQL.
                    $target->table($table)->delete();
                    $batch = [];
                    foreach ($this->ordered($source, $table)->lazy(self::BATCH_SIZE) as $row) {
                        $batch[] = (array) $row;
                        if (count($batch) === self::BATCH_SIZE) {
                            $target->table($table)->insert($batch);
                            $batch = [];
                        }
                    }
                    if ($batch) {
                        $target->table($table)->insert($batch);
                    }
                });

                $expected = $source->table($table)->count();
                $copied = $target->table($table)->count();
                if ($copied !== $expected) {
                    throw new RuntimeException("Jumlah baris {$table} tidak sama: sumber {$expected}, tujuan {$copied}");
                }
                $counts[$table] = $copied;
            }

            return $counts;
        }));
    }

    /**
     * The table's rows in a stable order, so paging through them never skips or repeats one.
     */
    private function ordered(Connection $source, string $table): Builder
    {
        $query = $source->table($table);
        $primary = collect($source->getSchemaBuilder()->getIndexes($table))->firstWhere('primary', true);

        foreach ($primary['columns'] ?? [] as $column) {
            $query->orderBy($column);
        }

        return $primary ? $query : $query->orderByRaw($source->getDriverName() === 'sqlite' ? 'rowid' : '1');
    }
}
