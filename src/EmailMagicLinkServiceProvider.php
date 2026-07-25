<?php

declare(strict_types=1);

namespace EmailMagicLink;

use EmailMagicLink\Authenticators\DefaultAuthenticator;
use EmailMagicLink\Captcha\NullCaptchaGuard;
use EmailMagicLink\Console\Commands\InstallCommand;
use EmailMagicLink\Console\Commands\PurgeExpiredTokensCommand;
use EmailMagicLink\Contracts\CaptchaGuard;
use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Contracts\MagicLinkIssuer;
use EmailMagicLink\Contracts\ResendGuard;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Http\Responses\DefaultInvalidLinkResponder;
use EmailMagicLink\Lookups\DefaultUserLookup;
use EmailMagicLink\Stores\DefaultTokenStore;
use EmailMagicLink\Support\DefaultMagicLinkIssuer;
use EmailMagicLink\Support\DefaultResendGuard;
use EmailMagicLink\Support\EntropyGuard;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\RateLimits;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

        $this->app->singleton(CaptchaGuard::class, function (Application $app): CaptchaGuard {
            $custom = $app->make(MagicLinkConfig::class)->captcha();

            return $this->resolveContract($app, CaptchaGuard::class, $custom, NullCaptchaGuard::class);
        });

        $this->app->singleton(InvalidLinkResponder::class, function (Application $app): InvalidLinkResponder {
            $custom = $app->make(MagicLinkConfig::class)->invalidLinkResponderClass();

            return $this->resolveContract($app, InvalidLinkResponder::class, $custom, DefaultInvalidLinkResponder::class);
        });

        $this->app->singleton(MagicLinkAuthenticator::class, DefaultAuthenticator::class);

        $this->app->singleton(MagicLinkIssuer::class, fn (Application $app): MagicLinkIssuer => new DefaultMagicLinkIssuer(
            $app->make(TokenStore::class),
            $app->make(MagicLinkConfig::class),
            $app->make(AuthManager::class),
        ));

        $this->app->singleton(ResendGuard::class, fn (Application $app): ResendGuard => new DefaultResendGuard(
            $app->make(CacheFactory::class),
            $app->make(MagicLinkConfig::class),
        ));
    }

    public function boot(): void
    {
        $config = $this->app->make(MagicLinkConfig::class);

        $this->registerPublishing();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-magic-link');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'email-magic-link');

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

            // Every group is registered TWICE: under its own tag, and under the
            // umbrella tag `email-magic-link`. Without the umbrella there is no way
            // to publish everything in one command — `vendor:publish --tag` takes one
            // tag, and `--provider` publishes untagged groups only. A consumer would
            // otherwise have to know all four names, and would silently miss any group
            // added later.
            $this->publishes([
                __DIR__.'/../config/email-magic-link.php' => config_path('email-magic-link.php'),
            ], ['email-magic-link-config', 'email-magic-link']);

            // publishesMigrations(), not publishes(): it rewrites the bundled
            // 0001_01_01_00000N ordering prefix to the publish date. With a plain
            // copy the published migrations keep that prefix and sort BEFORE the
            // application's own create_users_table, so `migrate` on a fresh app
            // tries to create a table with a foreign key to users that does not
            // exist yet. The bundled prefix is correct for auto-loading (it must
            // sort deterministically among the package's own three) and wrong the
            // moment the files land in the app's migrations directory.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], ['email-magic-link-migrations', 'email-magic-link']);

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/email-magic-link'),
            ], ['email-magic-link-views', 'email-magic-link']);

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/email-magic-link'),
            ], ['email-magic-link-lang', 'email-magic-link']);
        }
    }
}
