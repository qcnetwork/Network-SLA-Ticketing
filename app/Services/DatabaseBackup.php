<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

/**
 * Copies the SQLite database to a standalone file. VACUUM INTO writes a consistent snapshot even
 * while the app is in use (WAL mode), unlike copying the .sqlite file, which can miss recent writes.
 */
class DatabaseBackup
{
    public const DIRECTORY = 'app/private/backups';

    private const VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    /**
     * Write a snapshot into the backup directory and return its path.
     */
    public function create(?string $directory = null): string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Backup dari aplikasi hanya tersedia untuk database SQLite');
        }

        $directory ??= $this->defaultDirectory();
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'netticket-backup-'.now()->format('Ymd-His').'.sqlite';

        $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($path));
        $this->stripLiveState($path);

        return $path;
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
        $old = collect(File::glob(($directory ?? $this->defaultDirectory()).DIRECTORY_SEPARATOR.'netticket-backup-*.sqlite'))
            ->sortDesc()
            ->slice($keep);
        $old->each(fn (string $path) => File::delete($path));

        return $old->count();
    }

    private function defaultDirectory(): string
    {
        return storage_path(str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY));
    }
}
