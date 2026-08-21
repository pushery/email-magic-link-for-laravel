<?php

declare(strict_types=1);

namespace EmailMagicLink;

use EmailMagicLink\Authenticators\DefaultAuthenticator;
use EmailMagicLink\Captcha\NullCaptchaGuard;
use EmailMagicLink\Console\Commands\DoctorCommand;
use EmailMagicLink\Console\Commands\InstallCommand;
use EmailMagicLink\Console\Commands\PurgeExpiredTokensCommand;
use EmailMagicLink\Contracts\CaptchaGuard;
use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Contracts\InvitationHandler;
use EmailMagicLink\Contracts\InvitationIssuer;
use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Contracts\MagicLinkIssuer;
use EmailMagicLink\Contracts\ResendGuard;
use EmailMagicLink\Contracts\ScriptNonce;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Exceptions\InvitationsMisconfiguredException;
use EmailMagicLink\Http\Responses\DefaultInvalidLinkResponder;
use EmailMagicLink\Lookups\DefaultUserLookup;
use EmailMagicLink\Stores\DefaultInvitationStore;
use EmailMagicLink\Stores\DefaultTokenStore;
use EmailMagicLink\Support\AutoScriptNonce;
use EmailMagicLink\Support\ConfigMerger;
use EmailMagicLink\Support\DefaultInvitationIssuer;
use EmailMagicLink\Support\DefaultMagicLinkIssuer;
use EmailMagicLink\Support\DefaultResendGuard;
use EmailMagicLink\Support\EntropyGuard;
use EmailMagicLink\Support\InvitationGuard;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\RateLimits;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Auth\AuthManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
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
        $this->mergeConfigDeeplyFrom(__DIR__.'/../config/email-magic-link.php', 'email-magic-link');

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

        $this->app->singleton(ScriptNonce::class, function (Application $app): ScriptNonce {
            $custom = $app->make(MagicLinkConfig::class)->scriptNonce();

            return $this->resolveContract($app, ScriptNonce::class, $custom, AutoScriptNonce::class);
        });

        $this->app->singleton(MagicLinkAuthenticator::class, DefaultAuthenticator::class);

        $this->app->singleton(MagicLinkIssuer::class, fn (Application $app): MagicLinkIssuer => new DefaultMagicLinkIssuer(
            $app->make(TokenStore::class),
            $app->make(MagicLinkConfig::class),
            $app->make(AuthManager::class),
        ));

        $this->app->singleton(InvitationStore::class, function (Application $app): InvitationStore {
            $custom = $app->make(MagicLinkConfig::class)->invitationStore();

            return $this->resolveContract($app, InvitationStore::class, $custom, DefaultInvitationStore::class);
        });

        $this->app->singleton(InvitationIssuer::class, fn (Application $app): InvitationIssuer => new DefaultInvitationIssuer(
            $app->make(InvitationStore::class),
            $app->make(MagicLinkConfig::class),
        ));

        // Resolved lazily and never defaulted. There is no sensible fallback: what
        // accepting an invitation means is the one thing the package cannot know, so a
        // missing handler is an error rather than a no-op. The boot guard normally
        // catches it first; this closure is what keeps the failure honest if something
        // resolves the contract on an installation the guard never ran on.
        $this->app->singleton(InvitationHandler::class, function (Application $app): InvitationHandler {
            $class = $app->make(MagicLinkConfig::class)->invitationHandler();

            if ($class === null) {
                throw InvitationsMisconfiguredException::missingHandler();
            }

            if (! class_exists($class)) {
                throw InvitationsMisconfiguredException::handlerContract($class);
            }

            $resolved = $app->make($class);

            if ($resolved instanceof InvitationHandler) {
                return $resolved;
            }

            throw InvitationsMisconfiguredException::handlerContract($class);
        });

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
        new InvitationGuard($config)->validate();

        $this->registerRateLimiters($config);
        $this->registerRoutes($config);
        $this->registerPruneSchedule($config);
    }

    /**
     * Register the daily purge in the host's scheduler, when asked to.
     *
     * Every request can create a token row, so without a purge the table grows
     * unbounded — and wiring that schedule was setup every consumer had to
     * remember. Opt-in rather than opt-out: a package that deletes rows on a
     * schedule nobody asked for is making an operator's decision, and a host that
     * already wires the command itself would end up with two entries for one job.
     *
     * callAfterResolving, not a direct resolve: the scheduler may never be built
     * at all (a plain HTTP request), and forcing it into existence to register a
     * cleanup job would be a cost on every request for a benefit on none.
     */
    private function registerPruneSchedule(MagicLinkConfig $config): void
    {
        if (! $config->pruneSchedule()) {
            return;
        }

        // The config flag above answers whether the consumer WANTS the purge. This one
        // answers whether it CAN run at all: a consumer that called ignoreMigrations()
        // declined the tables, so the command would hit a relation that does not exist —
        // a non-zero exit every night, and with schedule monitoring one entry in the error
        // tracker per night, for a table it deliberately refused. Read from the flag rather
        // than from the schema: this runs at BOOT, and asking the database there would put
        // a query on every request to answer a question about a nightly job.
        if (! self::$runsMigrations) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($config): void {
            $event = $schedule->command('email-magic-link:purge');

            // withoutOverlapping so a long purge on a large table cannot stack:
            // the next tick would otherwise start a second delete against rows the
            // first one is already working through.
            match ($config->pruneFrequency()) {
                'hourly' => $event->hourly(),
                'weekly' => $event->weekly(),
                'monthly' => $event->monthly(),
                default => $event->daily(),
            };

            $event->withoutOverlapping();
        });
    }

    /**
     * Laravel's own mergeConfigFrom, but recursive — so a published config is a
     * starting point rather than a ceiling.
     *
     * The shallow version supplies missing TOP-LEVEL keys only, so a host that ran
     * `vendor:publish` once never receives a key added later inside a block it
     * already has. It gets `null` instead of the shipped default, silently. See
     * ConfigMerger for why the recursion stops at lists.
     *
     * The cached-configuration check is Laravel's and is kept verbatim: with a
     * cached config there is nothing to merge into, and writing here would produce
     * a config that differs between cached and uncached boots.
     */
    private function mergeConfigDeeplyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);
        $published = $config->get($key, []);

        // `require` returns mixed; the shipped file returns an array, but the type
        // system cannot know that and a non-array here would be a broken package
        // rather than a host mistake — so it falls back to nothing to merge.
        $defaults = require $path;

        $config->set($key, ConfigMerger::deep(
            is_array($defaults) ? $defaults : [],
            is_array($published) ? $published : [],
        ));
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
                DoctorCommand::class,
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
            // sort deterministically among the package's own) and wrong the
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
