<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\TokenStore;
use Illuminate\Console\Command;

/**
 * Deletes expired and consumed magic-link tokens.
 *
 * Schedule it (for example daily) so the token table does not grow unbounded:
 * Schedule::command('email-magic-link:purge')->daily();
 */
final class PurgeExpiredTokensCommand extends Command
{
    protected $signature = 'email-magic-link:purge';

    protected $description = 'Delete expired and consumed magic-link tokens.';

    public function handle(TokenStore $store): int
    {
        $removed = $store->purge();

        $this->info("Purged {$removed} magic-link token(s).");

        return self::SUCCESS;
    }
}
