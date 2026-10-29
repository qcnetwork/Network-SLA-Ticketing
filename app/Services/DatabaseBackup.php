<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

/**
 * Copies the database to a standalone file: a .sqlite snapshot for SQLite, a .sql dump for MySQL.
 * VACUUM INTO writes a consistent snapshot even while the app is in use (WAL mode), unlike copying
 * the .sqlite file, which can miss recent writes.
 */
class DatabaseBackup
{
    public const DIRECTORY = 'app/private/backups';

    private const VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    private const EXTENSIONS = ['sqlite', 'sql'];

    private const ROWS_PER_INSERT = 200;

    /**
     * Write a snapshot into the backup directory and return its path.
     */
    public function create(?string $directory = null): string
    {
        $connection = DB::connection();
        $extension = match ($connection->getDriverName()) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'sql',
            default => throw new RuntimeException('Backup dari aplikasi hanya tersedia untuk database SQLite dan MySQL'),
        };

        $directory ??= $this->defaultDirectory();
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'netticket-backup-'.now()->format('Ymd-His').'.'.$extension;

        if ($extension === 'sqlite') {
            $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($path));
            $this->stripLiveState($path);
        } else {
            $this->dumpMysql($connection, $path);
        }

        return $path;
    }

    /**
     * Content type for a backup file, for the download response.
     */
    public static function contentType(string $path): string
    {
        return str_ends_with($path, '.sql') ? 'application/sql' : 'application/vnd.sqlite3';
    }

    /**
     * Write every table as CREATE TABLE plus INSERTs, restorable with `mysql` or phpMyAdmin. Done in
     * PHP so the server needs no mysqldump; one consistent-snapshot transaction keeps the tables in
     * step while the app keeps writing. Like the SQLite snapshot, live sessions and cache are left out.
     */
    private function dumpMysql(Connection $connection, string $path): void
    {
        $pdo = $connection->getPdo();
        $file = fopen($path, 'wb');
        if ($file === false) {
            throw new RuntimeException("Tidak bisa menulis file backup: {$path}");
        }

        try {
            fwrite($file, '-- NetTicket backup '.now()->format('Y-m-d H:i:s')." (database {$connection->getDatabaseName()})\n"
                ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $tables = $pdo->query("select table_name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' order by table_name")
                    ->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $table) {
                    $create = $pdo->query("show create table `{$table}`")->fetch(PDO::FETCH_NUM)[1];
                    fwrite($file, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
                    if (! in_array($table, self::VOLATILE_TABLES, true)) {
                        $this->dumpRows($pdo, $table, $file);
                    }
                    fwrite($file, "\n");
                }
            } finally {
                $pdo->exec('COMMIT');
            }

            fwrite($file, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) {
            fclose($file);
            File::delete($path);

            throw $e;
        }

        fclose($file);
    }

    /**
     * @param  resource  $file
     */
    private function dumpRows(PDO $pdo, string $table, $file): void
    {
        $statement = $pdo->query("select * from `{$table}`");
        $columns = null;
        $values = [];
        $flush = function () use (&$values, &$columns, $table, $file) {
            if ($values) {
                fwrite($file, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $values).";\n");
                $values = [];
            }
        };

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns ??= implode(', ', array_map(fn (string $column) => "`{$column}`", array_keys($row)));
            $values[] = '('.implode(', ', array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), $row)).')';
            if (count($values) === self::ROWS_PER_INSERT) {
                $flush();
            }
        }
        $flush();
    }

    /**
     * Live sessions in a backup would let whoever holds the file sign in as anyone logged in at
     * the time, and the cache is rebuilt anyway. VACUUM afterwards so the deleted rows do not
     * linger in free pages.
     */
    private function stripLiveState(string $path): void
    {
        $snapshot = new PDO('sqlite:'.$path);
        $snapshot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $snapshot->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        foreach (array_intersect(self::VOLATILE_TABLES, $tables) as $table) {
            $snapshot->exec("delete from \"{$table}\"");
        }
        $snapshot->exec('VACUUM');
    }

    /**
     * Delete all but the newest $keep backups in the directory.
     *
     * @return int how many were deleted
     */
    public function prune(int $keep, ?string $directory = null): int
    {
        $directory ??= $this->defaultDirectory();
        // Both kinds, so the SQLite backups made before a move to MySQL age out too.
        $old = collect(self::EXTENSIONS)
            ->flatMap(fn (string $extension) => File::glob($directory.DIRECTORY_SEPARATOR."netticket-backup-*.{$extension}"))
            ->sortByDesc(fn (string $path) => basename($path))
            ->slice($keep);
        $old->each(fn (string $path) => File::delete($path));

        return $old->count();
    }

    private function defaultDirectory(): string
    {
        return storage_path(str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY));
    }
}
