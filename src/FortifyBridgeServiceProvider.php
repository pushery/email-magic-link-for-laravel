<?php

declare(strict_types=1);

namespace EmailMagicLink;

use EmailMagicLink\Authenticators\FortifyAwareAuthenticator;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Activates the optional Fortify two-factor handoff.
 *
 * Registered by the core provider only when Fortify is installed and the bridge
 * is enabled. It wraps the bound MagicLinkAuthenticator with the Fortify-aware
 * decorator so a confirmed-2FA user is routed through Fortify's challenge. This
 * file is the only one that participates in the Fortify-internal coupling.
 */
final class FortifyBridgeServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->extend(
            MagicLinkAuthenticator::class,
            fn (MagicLinkAuthenticator $default, Application $app): FortifyAwareAuthenticator => new FortifyAwareAuthenticator(
                $default,
                $app->make(MagicLinkConfig::class),
            ),
        );
    }

    public function boot(): void
    {
        $config = $this->app->make(MagicLinkConfig::class);

        if (! $config->enabled()) {
            return;
        }

        if (! $config->respectTwoFactor()) {
            Log::warning(
                '[email-magic-link] fortify.respect_two_factor is disabled: magic-link logins skip the two-factor challenge for users who have it enabled.',
            );

            return;
        }

        foreach ($config->allowedGuards() as $guard) {
            if (! $config->guardSharesFortifyProvider($guard)) {
                Log::warning(
                    "[email-magic-link] the [{$guard}] guard's user provider differs from fortify.guard; two-factor sign-in on that guard fails closed. Align the providers or keep two-factor users on the Fortify guard.",
                );
            }
        }
    }
}
