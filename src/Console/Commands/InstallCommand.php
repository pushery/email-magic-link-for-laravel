<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use Illuminate\Console\Command;

/**
 * Publishes the configuration (and optionally the views) and prints the next steps.
 *
 * The migration is loaded automatically, so a fresh app works without publishing
 * anything; this command is a convenience for customizing the config or views.
 */
final class InstallCommand extends Command
{
    protected $signature = 'email-magic-link:install
        {--force : Overwrite existing published files}
        {--views : Also publish the Blade views for customizing}';

    protected $description = 'Publish the configuration and print the setup steps.';

    public function handle(): int
    {
        $force = $this->option('force');

        $this->callSilently('vendor:publish', [
            '--tag' => 'email-magic-link-config',
            '--force' => $force,
        ]);
        $this->info('Published config/email-magic-link.php');

        if ($this->option('views')) {
            $this->callSilently('vendor:publish', [
                '--tag' => 'email-magic-link-views',
                '--force' => $force,
            ]);
            $this->info('Published the views to resources/views/vendor/email-magic-link');
        }

        $this->newLine();
        $this->info('Email Magic Link is ready. Next steps:');
        $this->newLine();
        $this->line('  1. Create the token table by running your migrations:');
        $this->line('       php artisan migrate');
        $this->newLine();
        $this->line('  2. Run a queue worker. The magic-link email is queued, so it is');
        $this->line('     only delivered once a worker processes the job:');
        $this->line('       php artisan queue:work');
        $this->line('     (or set QUEUE_CONNECTION=sync in .env to send mail synchronously');
        $this->line('     during local development).');
        $this->newLine();
        $this->line("  3. Point your sign-in link at route('email-magic-link.request.form').");
        $this->newLine();
        $this->line('  4. Later, after upgrading the package, check what your published config');
        $this->line('     does not know about yet:');
        $this->line('       php artisan email-magic-link:doctor');
        $this->newLine();
        $this->line('The migrations are loaded automatically; publish them with');
        $this->line('  php artisan vendor:publish --tag=email-magic-link-migrations');
        $this->line('only if you need to customize them. Once a published copy exists the bundled');
        $this->line('files are no longer loaded, so nothing runs twice. If you rename the copy, call');
        $this->line('EmailMagicLinkServiceProvider::ignoreMigrations() in a service provider.');

        return self::SUCCESS;
    }
}
