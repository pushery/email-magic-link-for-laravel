<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Deletes expired and consumed magic-link tokens — and, when invitations are
 * switched on, expired and claimed invitations in the same run.
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

    protected $description = 'Delete expired and consumed magic-link tokens, and invitations when they are enabled.';

    public function handle(TokenStore $store, MagicLinkConfig $config): int
    {
        // The query log is an array that only ever grows, and this command is the one
        // place in the package that issues an unbounded number of statements: one per
        // chunk, so a table of ten million rows is ten thousand of them. Measured at
        // roughly 3.4 kB per entry -- about 34 MB held for the whole run, for a log
        // nobody reads.
        //
        // Off by default, so a stock installation was never affected. It is on under
        // Telescope, a debugbar, or any host that calls enableQueryLog() somewhere in a
        // scheduled context -- which is exactly the context this command runs in, and
        // exactly the host least likely to notice.
        //
        // Restored rather than left off: the connection is shared, and a command that
        // silently disarms someone's profiling for the rest of the process would be a
        // worse bug than the one it fixes.
        $connection = (new MagicLinkToken)->getConnection();
        $wasLogging = $connection->logging();

        $connection->disableQueryLog();

        try {
            $removed = $store->purge();

            $this->info("Purged {$removed} magic-link token(s).");

            // One command for both tables rather than a second one to schedule and a second
            // one to forget. The line only appears when invitations are switched on, so an
            // installation that does not use them sees exactly the output it always saw.
            if ($config->invitationsEnabled()) {
                $invitations = $this->laravel->make(InvitationStore::class)->purge();

                $this->info("Purged {$invitations} invitation(s).");
            }
        } finally {
            if ($wasLogging) {
                $connection->enableQueryLog();
            }
        }

        return self::SUCCESS;
    }
}
