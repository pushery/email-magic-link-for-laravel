<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;

/**
 * Deletes the package's expired keys from a database cache store.
 *
 * A database store deletes the row of an expired key only when that key is read again, and the package writes keys that
 * nobody reads again: the note of every sign-in made from a session, in the default store, and the resend guard's
 * record of every address that asked for a link and never signed in, in the store `resend.store` names. On a database
 * store their rows stay for good. `email-magic-link:purge` calls this in the same run as it purges the tokens, and it
 * deletes the rows a thousand to a statement.
 *
 * It does nothing where neither store is a database store. Only the row of a key that has expired goes, and the store
 * already treats such a row as missing. The counts of the package's rate limiters stand under keys Laravel's throttle
 * middleware builds, by default an MD5 of the limiter's name and its key, which nothing tells apart from any other
 * count, so they are left alone. The locks live in the lock table of the store, whose expired rows the store deletes
 * itself.
 */
final readonly class PruneExpiredCacheKeys
{
    /** The most rows one statement deletes, so a large table is pruned in short statements. */
    private const int CHUNK = 1000;

    public function __construct(
        private Repository $config,
        private ConnectionResolverInterface $connections,
        private MagicLinkConfig $settings,
    ) {}

    /** Delete every expired key of the package, and return how many rows went. */
    public function __invoke(): int
    {
        $now = Carbon::now()->getTimestamp();
        $deleted = 0;

        foreach ($this->stores() as $store) {
            $connection = $this->connections->connection($store['connection']);

            do {
                $chunk = $connection->table($store['table'])
                    ->where('key', 'like', $store['pattern'])
                    ->where('expiration', '<=', $now)
                    ->limit(self::CHUNK)
                    ->delete();

                $deleted += $chunk;
            } while ($chunk === self::CHUNK);
        }

        return $deleted;
    }

    /**
     * The database stores the package keeps its keys in, each with the keys it holds: the notes of sign-ins in the default
     * store, and the records of the resend guard in the store `resend.store` names, the default one where it names none.
     *
     * @return list<array{table: string, connection: ?string, pattern: string}>
     */
    private function stores(): array
    {
        $stores = [];

        foreach (['email-magic-link:sign-in:' => null, 'eml:resend:' => $this->settings->resendStore()] as $keys => $name) {
            $store = $this->databaseStore($name, $keys);

            if ($store !== null) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    /**
     * What pruning a store takes, its table, its connection and the keys that start with `$keys` as a LIKE pattern, or
     * null for a store that is no database store.
     *
     * A character of the store's prefix that LIKE reads as more than itself on one of the engines, `%`, `\` and `[`,
     * stands as `_`, any one character, so the pattern means the same on every engine without an escape clause. It can
     * then match a key beside the package's, and so can a LIKE that ignores case, as SQLite's does and MySQL's under its
     * default collation. Such a key goes only once it has expired, and the store already treats it as missing then.
     *
     * @return array{table: string, connection: ?string, pattern: string}|null
     */
    private function databaseStore(?string $configured, string $keys): ?array
    {
        $name = $configured ?? $this->config->get('cache.default');
        $stores = $this->config->get('cache.stores');
        $store = is_array($stores) && is_string($name) ? ($stores[$name] ?? null) : null;

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            return null;
        }

        $table = $store['table'] ?? null;
        $connection = $store['connection'] ?? null;
        // As Laravel builds the store: its own prefix, or the one every store shares.
        $prefix = $store['prefix'] ?? $this->config->get('cache.prefix');

        if (! is_string($table) || $table === '') {
            return null;
        }

        return [
            'table' => $table,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'pattern' => str_replace(['%', '\\', '['], '_', is_string($prefix) ? $prefix : '').$keys.'%',
        ];
    }
}
