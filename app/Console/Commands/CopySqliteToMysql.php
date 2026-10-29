<?php

namespace App\Console\Commands;

use App\Services\DatabaseCopier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off move from the SQLite file to MySQL. The SQLite file is only read, so switching .env
 * back to SQLite undoes the move. The MySQL tables must already exist (migrate --database=mysql)
 * and be empty, and every copied table is counted on both sides before the copy is committed.
 */
#[Signature('db:copy-sqlite-to-mysql
    {--source= : File SQLite sumber (default: database/database.sqlite)}
    {--target=mysql : Koneksi tujuan di config/database.php}
    {--force : Jalankan di production tanpa konfirmasi}')]
#[Description('Salin semua data dari file SQLite ke database MySQL yang sudah di-migrate')]
class CopySqliteToMysql extends Command
{
    use ConfirmableTrait;

    private const IN_USE_TABLES = ['users', 'tickets'];

    public function __construct(private DatabaseCopier $copier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            [$source, $target] = $this->connections();
            $tables = $this->tablesToCopy($source, $target);
            $this->checkFits($source, $target, $tables);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sumber : '.$source->getDatabaseName());
        $this->info("Tujuan : {$target->getDatabaseName()} (koneksi {$this->option('target')})");
        if (! $this->confirmToProceed('Data akan disalin ke MySQL.')) {
            return self::FAILURE;
        }

        try {
            $counts = $this->copy($source, $target, $tables);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->error('Tidak ada data yang tersimpan di MySQL. File SQLite tidak diubah.');

            return self::FAILURE;
        }

        $this->table(['Tabel', 'Jumlah baris'], collect($counts)->map(fn (int $count, string $table) => [$table, $count])->values());
        $this->info('Selesai. Semua jumlah baris di MySQL sama dengan SQLite.');
        $this->line('Langkah berikutnya: ubah DB_CONNECTION di .env ke mysql, lalu jalankan php artisan config:cache.');

        return self::SUCCESS;
    }

    /**
     * @return array{Connection, Connection}
     */
    private function connections(): array
    {
        $path = $this->option('source') ?: database_path('database.sqlite');
        if (! is_file($path)) {
            throw new RuntimeException("File SQLite tidak ditemukan: {$path}");
        }
        config(['database.connections.sqlite_copy_source' => [
            'driver' => 'sqlite',
            'database' => realpath($path),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection('sqlite_copy_source');

        $targetName = $this->option('target');
        if (! config("database.connections.{$targetName}")) {
            throw new RuntimeException("Koneksi '{$targetName}' tidak ada di config/database.php");
        }
        $target = DB::connection($targetName);
        if (! in_array($target->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Koneksi '{$targetName}' bukan MySQL/MariaDB");
        }
        try {
            $target->getPdo();
        } catch (\Throwable $e) {
            throw new RuntimeException('Tidak bisa terhubung ke MySQL. Cek DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME dan DB_PASSWORD di .env. '.$e->getMessage());
        }

        return [$source, $target];
    }

    /**
     * Tables to copy, after checking MySQL has the same migrations and holds no data yet.
     *
     * @return array<string>
     */
    private function tablesToCopy(Connection $source, Connection $target): array
    {
        if (! $target->getSchemaBuilder()->hasTable('migrations')) {
            throw new RuntimeException("Tabel di MySQL belum dibuat. Jalankan dulu: php artisan migrate --database={$this->option('target')}");
        }
        $missing = $source->table('migrations')->pluck('migration')->diff($target->table('migrations')->pluck('migration'));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException("MySQL belum menjalankan semua migration ({$missing->first()}, dst). Jalankan dulu: php artisan migrate --database={$this->option('target')}");
        }

        $tables = $this->copier->tables($source);

        foreach ($tables as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Tabel '{$table}' ada di SQLite tetapi tidak ada di MySQL");
            }
        }
        // Some migrations seed default rows (categories, modem types), which the copy replaces. Users or
        // tickets mean MySQL is already in use, and that is never overwritten.
        foreach (self::IN_USE_TABLES as $table) {
            if ($target->table($table)->exists()) {
                throw new RuntimeException("Tabel '{$table}' di MySQL sudah berisi data. Kalau memang boleh dihapus, kosongkan dulu dengan: php artisan migrate:fresh --database={$this->option('target')}");
            }
        }

        return $tables;
    }

    /**
     * SQLite never enforces a column's length or even its presence, so check every value fits before writing any.
     *
     * @param  array<string>  $tables
     */
    private function checkFits(Connection $source, Connection $target, array $tables): void
    {
        $problems = [];
        foreach ($tables as $table) {
            $columns = collect($target->select(
                'select column_name as name, character_maximum_length as length, data_type as type from information_schema.columns where table_schema = ? and table_name = ?',
                [$target->getDatabaseName(), $table],
            ))->keyBy('name');

            foreach ($source->getSchemaBuilder()->getColumnListing($table) as $column) {
                if (! $columns->has($column)) {
                    $problems[] = "{$table}.{$column} tidak ada di MySQL";

                    continue;
                }
                $limit = $columns[$column]->length;
                if (in_array($columns[$column]->type, ['varchar', 'char'], true) && $limit) {
                    $longest = (int) $source->table($table)->max($source->raw("length(\"{$column}\")"));
                    if ($longest > $limit) {
                        $problems[] = "{$table}.{$column}: ada isi sepanjang {$longest} karakter, batas MySQL {$limit}";
                    }
                }
            }
        }

        if ($problems) {
            throw new RuntimeException("Data tidak muat di MySQL, belum ada yang disalin:\n - ".implode("\n - ", $problems));
        }
    }

    /**
     * @param  array<string>  $tables
     * @return array<string, int> rows per table
     */
    private function copy(Connection $source, Connection $target, array $tables): array
    {
        try {
            return $this->copier->copy($source, $target, $tables, fn (string $table, callable $copy) => $this->components->task($table, $copy));
        } catch (\Throwable $e) {
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Gagal menyalin: '.$e->getMessage(), previous: $e);
        }
    }
}
