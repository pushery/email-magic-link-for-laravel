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
        $this->info('Email Magic Link is ready.');
        $this->line("Point your sign-in link at route('email-magic-link.request.form').");
        $this->line('The migration is loaded automatically; publish it with');
        $this->line('  php artisan vendor:publish --tag=email-magic-link-migrations');
        $this->line('if you need to customize it.');

        return self::SUCCESS;
    }
}
