<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Database\Connection;

/**
 * The isolation level each purge chunk runs at.
 *
 * Under MySQL's default REPEATABLE READ, InnoDB takes next-key locks: on every index entry a
 * locking read scans, and on the gap after the last one. The chunk's select scans the
 * `expires_at` and `consumed_at` indexes, and measured on MySQL 8.0.36 with 20,000 tokens it
 * held both up to their ends, so every claim and every new link waited until the chunk
 * committed. READ COMMITTED takes no gap locks and lets go of the rows the WHERE rejects;
 * measured the same way, both went through at once. The purge itself never waits at either
 * level, because `skip locked` passes over a row somebody else holds.
 *
 * PostgreSQL has no gap locks and already runs at READ COMMITTED, so it is left as it is.
 */
final readonly class PurgeChunkIsolation
{
    private function __construct(private ?Connection $connection) {}

    public static function for(Connection $connection): self
    {
        return new self(self::readCommittedAvailable($connection) ? $connection : null);
    }

    /**
     * Called before every chunk, because SET TRANSACTION covers the next transaction only.
     */
    public function beforeChunk(): void
    {
        $this->connection?->statement('set transaction isolation level read committed');
    }

    private static function readCommittedAvailable(Connection $connection): bool
    {
        // Outside a transaction only. Inside one, MySQL refuses SET TRANSACTION (error 1568),
        // and a host that purges within a transaction of its own has chosen the level.
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() > 0) {
            return false;
        }

        // A server that writes its binary log as statements refuses DML at READ COMMITTED
        // (error 1665), so there the chunk keeps the default. Asked on the write connection,
        // where the delete runs. SHOW VARIABLES answers a variable a server does not have with
        // no row rather than an error.
        $row = $connection->selectOne("show variables like 'binlog_format'", [], false);
        $format = is_object($row) && property_exists($row, 'Value') ? $row->Value : null;

        return ! is_string($format) || strcasecmp($format, 'STATEMENT') !== 0;
    }
}
