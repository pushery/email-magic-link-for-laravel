<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\TokenStore;
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
        $removed = $store->purge();

        $this->info("Purged {$removed} magic-link token(s).");

        // One command for both tables rather than a second one to schedule and a second
        // one to forget. The line only appears when invitations are switched on, so an
        // installation that does not use them sees exactly the output it always saw.
        if ($config->invitationsEnabled()) {
            $invitations = $this->laravel->make(InvitationStore::class)->purge();

            $this->info("Purged {$invitations} invitation(s).");
        }

        return self::SUCCESS;
    }
}
