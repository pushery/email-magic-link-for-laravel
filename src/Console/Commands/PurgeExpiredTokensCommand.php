<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Models\Invitation;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Connection;

/**
 * Deletes expired and consumed magic-link tokens — and expired and settled
 * invitations in the same run, whenever their table exists.
 *
 * Schedule it (for example daily) so neither table grows unbounded:
 * Schedule::command('email-magic-link:purge')->daily();
 *
 * Isolatable, so `--isolated` refuses a second copy while one runs -- the framework's
 * own overlap guard, for a host that schedules the command itself. Deletes in chunks
 * (config `prune.chunk`), so no single statement holds its row locks for long.
 */
final class PurgeExpiredTokensCommand extends Command implements Isolatable
{
    protected $signature = 'email-magic-link:purge';

    protected $description = 'Delete expired and consumed magic-link tokens, and expired or settled invitations.';

    public function handle(TokenStore $store, MagicLinkConfig $config): int
    {
        // The query log is an array that only ever grows, and this command is the one
        // place in the package that issues an unbounded number of statements: two per
        // chunk, a locking select and a delete that binds every id of the chunk, so a
        // table of ten million rows is twenty thousand log entries at the default chunk
        // of 1000. Measured at roughly 25 kB per chunk, most of it the delete's bindings
        // -- on the order of 250 MB held for the whole run, for a log nobody reads.
        //
        // Off by default, so a stock installation was never affected. It is on for any
        // host that calls enableQueryLog() somewhere in a scheduled context -- which is
        // exactly the context this command runs in, and exactly the host least likely to
        // notice. Telescope and Debugbar are not among them: they record queries through
        // the QueryExecuted event, never turn this log on, and are not touched by the
        // switch below.
        //
        // Restored rather than left off: the connection is shared, and a command that
        // silently disarms someone's query log for the rest of the process would be a
        // worse bug than the one it fixes.
        //
        // Both models' connections, each once: a host may map the invitation model onto a
        // connection of its own, and the invitation chunks are then logged there.
        $connections = [];

        foreach ([MagicLinkToken::resolve()->getConnection(), Invitation::resolve()->getConnection()] as $connection) {
            $connections[$connection->getName() ?? ''] ??= $connection;
        }

        $wasLogging = array_map(static fn (Connection $connection): bool => $connection->logging(), $connections);

        foreach ($connections as $connection) {
            $connection->disableQueryLog();
        }

        try {
            $removed = $store->purge();

            $this->info("Purged {$removed} magic-link token(s).");

            // One command for both tables rather than a second one to schedule and a second
            // one to forget. Whether the table exists decides, not whether invitations are
            // switched on: the migrations create it on every install, and a host that used
            // invitations and switched them off again still holds the settled rows, each
            // with an address in the clear, under the retention `retain_accepted_days`
            // promises. The line appears when invitations are on or something was deleted,
            // so an installation that never used them sees the output it always saw.
            $invitations = 0;

            if ($this->invitationTableExists()) {
                $invitations = $this->laravel->make(InvitationStore::class)->purge();
            }

            if ($config->invitationsEnabled() || $invitations > 0) {
                $this->info("Purged {$invitations} invitation(s).");
            }
        } finally {
            foreach ($connections as $name => $connection) {
                if ($wasLogging[$name]) {
                    $connection->enableQueryLog();
                }
            }
        }

        return self::SUCCESS;
    }

    private function invitationTableExists(): bool
    {
        $model = Invitation::resolve();

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
    }
}
