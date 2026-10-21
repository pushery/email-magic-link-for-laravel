<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Bounds how long a migration of this package waits for a lock, and on PostgreSQL how long it may
 * hold one.
 *
 * The package's migrations run on the databases of the applications that install it, over tables
 * that already hold rows. An ALTER that cannot take its lock at once waits behind whatever holds
 * it, and every query that arrives afterwards waits behind the ALTER, so a change that takes
 * milliseconds can stall an application for as long as somebody's report runs. With a bound the
 * migration fails instead, and running it again is the remedy.
 *
 * Called first in the up() and down() of a migration that alters a table. On PostgreSQL the
 * settings are `SET LOCAL` and end with the transaction Laravel runs each migration in there, so no
 * other migration of the same run inherits them; a migration that sets `$withinTransaction` to
 * false runs outside one, where `SET LOCAL` changes nothing. MySQL has neither `SET LOCAL` nor a
 * statement bound for DDL, so it bounds the wait for the rest of the session. SQLite takes no such
 * locks.
 *
 * Part of the public API, because the migrations this package publishes call it. Once published, a
 * migration runs in the host's own namespace, and a host that analyzes its migrations reads every
 * call there into a class the package marks internal as an error it cannot fix without editing a
 * published file.
 */
final class MigrationLocks
{
    public static function bound(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            // Three seconds to get the lock and thirty to hold it. An ALTER that rewrites nothing
            // passes either bound only when something else holds the table, and waiting longer
            // makes the queue behind it longer. A migration that rewrites a table on purpose sets
            // its own statement_timeout after this call.
            $connection->statement("SET LOCAL lock_timeout = '3s'");
            $connection->statement("SET LOCAL statement_timeout = '30s'");
        } elseif ($driver === 'mysql') {
            $connection->statement('SET SESSION lock_wait_timeout = 3');
        }
    }
}
