<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Closure;
use Illuminate\Database\Connection;

/**
 * Runs reads in a transaction with a consistent view (REPEATABLE READ), so
 * versions recorded meanwhile are either entirely visible or not at all.
 * Inside a caller's transaction the caller's isolation level applies.
 */
final class ReadView
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(Connection $connection, Closure $callback): mixed
    {
        $driver = $connection->getDriverName();
        $outermost = $connection->transactionLevel() === 0;

        // MySQL and MariaDB apply the level to the next transaction only.
        if ($outermost && in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($connection, $driver, $outermost, $callback): mixed {
            // PostgreSQL defaults to READ COMMITTED; set before the first query.
            if ($outermost && $driver === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        });
    }
}
