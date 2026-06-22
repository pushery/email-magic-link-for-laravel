<?php

declare(strict_types=1);

namespace EmailMagicLink;

use EmailMagicLink\Authenticators\DefaultAuthenticator;
use EmailMagicLink\Console\Commands\InstallCommand;
use EmailMagicLink\Console\Commands\PurgeExpiredTokensCommand;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Lookups\DefaultUserLookup;
use EmailMagicLink\Stores\DefaultTokenStore;
use EmailMagicLink\Support\EntropyGuard;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\RateLimits;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Override;

final class EmailMagicLinkServiceProvider extends ServiceProvider
{
    /**
     * Probed via class_exists so the core never imports a Fortify symbol.
     * Overridable in tests to simulate Fortify being absent.
     */
    public static string $fortifyClass = 'Laravel\\Fortify\\Fortify';

    /**
     * Whether the bundled migration is registered automatically. Disable it
     * with self::ignoreMigrations() when publishing and managing it yourself.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-magic-link.php', 'email-magic-link');

        $this->app->singleton(
            MagicLinkConfig::class,
            fn (Application $app): MagicLinkConfig => new MagicLinkConfig($app->make(Repository::class)),
        );

        $this->app->singleton(TokenHasher::class, function (Application $app): TokenHasher {
            $key = $app->make(Repository::class)->get('app.key');

            return new TokenHasher(is_string($key) ? $key : '');
        });

        $this->app->singleton(TokenStore::class, function (Application $app): TokenStore {
            $custom = $app->make(MagicLinkConfig::class)->tokenStore();

            return $this->resolveContract($app, TokenStore::class, $custom, DefaultTokenStore::class);
        });

        $this->app->singleton(UserLookup::class, function (Application $app): UserLookup {
            $custom = $app->make(MagicLinkConfig::class)->userLookup();

            return $this->resolveContract($app, UserLookup::class, $custom, DefaultUserLookup::class);
        });

        $this->app->singleton(MagicLinkAuthenticator::class, DefaultAuthenticator::class);
    }

    public function boot(): void
    {
        $config = $this->app->make(MagicLinkConfig::class);

        $this->registerPublishing();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-magic-link');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Decided at boot so published config is in force (the authenticator is
        // only resolved at request time, after this wrapping is applied).
        $this->registerFortifyBridge($config);

        if (! $config->enabled()) {
            return;
        }

        // Fail closed before anything user-facing is registered.
        new EntropyGuard($config)->validate();

        $this->registerRateLimiters($config);
        $this->registerRoutes($config);
    }

    private function registerFortifyBridge(MagicLinkConfig $config): void
    {
        $mode = $config->fortifyMode();

        if ($mode === false) {
            return;
        }

        // Probed as a string so the core never references a Fortify symbol and
        // stays loadable when Fortify is absent.
        if (class_exists(self::$fortifyClass)) {
            $this->app->register(FortifyBridgeServiceProvider::class);

            return;
        }

        if ($mode === true) {
            Log::warning(
                '[email-magic-link] fortify.mode is true but laravel/fortify is not installed; the two-factor handoff is inactive.',
            );
        }
    }

    /**
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @param  class-string<TContract>  $default
     * @return TContract
     */
    private function resolveContract(Application $app, string $contract, ?string $custom, string $default): object
    {
        $concrete = $custom ?? $default;
        $instance = $app->make($concrete);

        if ($instance instanceof $contract) {
            return $instance;
        }

        throw new InvalidArgumentException("[{$concrete}] must implement [{$contract}].");
    }

    private function registerRoutes(MagicLinkConfig $config): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware($config->routeMiddleware())
                ->prefix($config->routePrefix())
                ->group(__DIR__.'/../routes/email-magic-link.php');
        }
    }

    private function registerRateLimiters(MagicLinkConfig $config): void
    {
        $limits = $this->app->make(RateLimits::class);

        RateLimiter::for($config->requestLimiter(), fn (Request $http): array => $limits->forRequest($http));
        RateLimiter::for($config->consumeLimiter(), fn (Request $http): array => $limits->forConsume($http));
    }

    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PurgeExpiredTokensCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/email-magic-link.php' => config_path('email-magic-link.php'),
            ], 'email-magic-link-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'email-magic-link-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/email-magic-link'),
            ], 'email-magic-link-views');
        }
    }
}
