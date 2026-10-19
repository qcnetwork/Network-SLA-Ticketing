<?php

namespace App\Support;

use PDO;

/**
 * PDO that starts transactions with the configured SQLite mode (e.g. IMMEDIATE).
 *
 * Laravel only honours `transaction_mode` on PHP 8.4+. Before that every transaction is DEFERRED:
 * it reads first and upgrades to a write lock later, and when another user wrote in between SQLite
 * fails with "database is locked" straight away instead of waiting for busy_timeout.
 */
class TransactionModeSQLitePdo extends PDO
{
    private bool $isInTransaction = false;

    private string $transactionMode = 'DEFERRED';

    public function setTransactionMode(string $transactionMode): void
    {
        $this->transactionMode = strtoupper($transactionMode);
    }

    public function beginTransaction(): bool
    {
        $this->exec("BEGIN {$this->transactionMode} TRANSACTION");
        $this->isInTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->exec('COMMIT');
        $this->isInTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->exec('ROLLBACK');
        $this->isInTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->isInTransaction;
    }
}
