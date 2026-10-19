<?php

namespace App\Support;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;

/**
 * Applies the connection's `transaction_mode` on PHP < 8.4, where Laravel ignores it.
 *
 * @see TransactionModeSQLitePdo
 */
class TransactionModeSQLiteConnector extends SQLiteConnector
{
    private string $transactionMode = 'DEFERRED';

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $this->transactionMode = $config['transaction_mode'] ?? 'DEFERRED';

        return parent::connect($config);
    }

    /**
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array<int, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): PDO
    {
        $pdo = new TransactionModeSQLitePdo($dsn, $username, $password, $options);
        $pdo->setTransactionMode($this->transactionMode);

        return $pdo;
    }
}
