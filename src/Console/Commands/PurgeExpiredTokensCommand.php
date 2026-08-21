<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Console\Command;

/**
 * Deletes expired and consumed magic-link tokens — and, when invitations are
 * switched on, expired and claimed invitations in the same run.
 *
 * Schedule it (for example daily) so neither table grows unbounded:
 * Schedule::command('email-magic-link:purge')->daily();
 */
final class PurgeExpiredTokensCommand extends Command
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
