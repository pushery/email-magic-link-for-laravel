<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Support\Facades\Schema;
use stdClass;
use WeakMap;

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
 * statement bound for DDL, so the bound is a setting of the session there, and the value the
 * session had before is put back when the migration ends. MariaDB, which Laravel names a driver of
 * its own, keeps the same setting and takes the same bound. A later migration of the same run, the
 * application's own included, and the seeders of `migrate --seed` then wait as long as they did
 * without it. Under `migrate --pretend` nothing runs, so nothing is read and nothing is put back.
 * SQLite takes no such locks.
 *
 * Internal: no migration this package publishes calls it. The first one that does makes it public
 * again, because a published migration runs in the host's own namespace.
 *
 * @internal
 */
final class MigrationLocks
{
    /**
     * The MySQL and MariaDB connections whose session waits for the end of a migration to get its lock
     * wait back, each with the mark of the one listener that puts it back.
     *
     * @var WeakMap<Connection, stdClass>|null
     */
    private static ?WeakMap $restoring = null;

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
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            self::restoreWhenTheMigrationEnds($connection);

            $connection->statement('SET SESSION lock_wait_timeout = 3');
        }
    }

    /**
     * Put the session's lock wait back once the migration that bounds it ends.
     *
     * Laravel announces that end with MigrationEnded, after up() or down() returns and on the same
     * connection. The value is read once per migration: a second call in the same migration would
     * read the bound itself, so it finds a restore already waiting and leaves it alone. A listener
     * acts once, for its own mark, and is idle afterwards, so the end of the next migration never
     * puts back a value an earlier one read.
     */
    private static function restoreWhenTheMigrationEnds(Connection $connection): void
    {
        $events = $connection->getEventDispatcher();
        $restoring = self::$restoring ??= new WeakMap;

        if ($events === null || $connection->pretending() || isset($restoring[$connection])) {
            return;
        }

        $previous = $connection->scalar('select @@session.lock_wait_timeout');

        if (! is_numeric($previous)) {
            return;
        }

        $mark = new stdClass;
        $restoring[$connection] = $mark;

        $events->listen(MigrationEnded::class, static function () use ($connection, $restoring, $mark, $previous): void {
            if (! $restoring->offsetExists($connection)) {
                return;
            }

            if ($restoring[$connection] !== $mark) {
                return;
            }

            unset($restoring[$connection]);

            $connection->statement('SET SESSION lock_wait_timeout = ?', [(int) $previous]);
        });
    }
}
