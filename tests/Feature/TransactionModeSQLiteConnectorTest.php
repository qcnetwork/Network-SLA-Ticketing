<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class TransactionModeSQLiteConnectorTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'netticket-lock-');
        config(['database.connections.lock_test' => [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 0,
            'journal_mode' => 'wal',
            'transaction_mode' => 'IMMEDIATE',
        ]]);
        DB::connection('lock_test')->statement('create table items (name text)');
    }

    protected function tearDown(): void
    {
        DB::purge('lock_test');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->databasePath.$suffix);
        }

        parent::tearDown();
    }

    public function test_transaction_takes_the_write_lock_before_its_first_write(): void
    {
        $otherUser = new PDO("sqlite:{$this->databasePath}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $otherUser->exec('pragma busy_timeout = 0');

        DB::connection('lock_test')->transaction(function () use ($otherUser) {
            DB::connection('lock_test')->table('items')->count();

            $this->expectException(PDOException::class);
            $this->expectExceptionMessage('database is locked');
            $otherUser->exec('insert into items (name) values ("other")');
        });
    }

    public function test_committed_rows_persist_and_rolled_back_rows_do_not(): void
    {
        $connection = DB::connection('lock_test');

        $connection->transaction(fn () => $connection->table('items')->insert(['name' => 'kept']));
        $connection->beginTransaction();
        $connection->table('items')->insert(['name' => 'discarded']);
        $connection->rollBack();

        $this->assertSame(['kept'], $connection->table('items')->pluck('name')->all());
        $this->assertSame(0, $connection->transactionLevel());
    }
}
