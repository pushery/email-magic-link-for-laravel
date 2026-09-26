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
use EmailMagicLink\Contracts\SignInEligibility;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Eligibility\AlwaysEligible;
use EmailMagicLink\Exceptions\InvitationsMisconfiguredException;
use EmailMagicLink\Http\Middleware\NoIndex;
use EmailMagicLink\Http\Responses\DefaultInvalidLinkResponder;
use EmailMagicLink\Lookups\AddressMatchingUserLookup;
use EmailMagicLink\Lookups\DefaultUserLookup;
use EmailMagicLink\Lookups\EligibleUserLookup;
use EmailMagicLink\Stores\DefaultInvitationStore;
use EmailMagicLink\Stores\DefaultTokenStore;
use EmailMagicLink\Support\AutoScriptNonce;
use EmailMagicLink\Support\ConfigMerger;
use EmailMagicLink\Support\DefaultInvitationIssuer;
use EmailMagicLink\Support\DefaultMagicLinkIssuer;
use EmailMagicLink\Support\DefaultResendGuard;
use EmailMagicLink\Support\EntropyGuard;
use EmailMagicLink\Support\InvitationGuard;
use EmailMagicLink\Support\IssuanceLock;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\RateLimits;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Auth\AuthManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Log;
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

    /**
     * Whether the tables exist although the bundled migrations are not loaded, because the
     * host migrates a copy of its own. Only ignoreMigrations(tablesExist: true) sets it, and
     * it is what keeps a requested purge schedule for such a host: $runsMigrations alone
     * cannot tell tables migrated elsewhere from tables that were declined.
     */
    public static bool $tablesMigratedElsewhere = false;

    public static function ignoreMigrations(bool $tablesExist = false): void
    {
        self::$runsMigrations = false;
        self::$tablesMigratedElsewhere = $tablesExist;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigDeeplyFrom(__DIR__.'/../config/email-magic-link.php', 'email-magic-link');

        // A resolver rather than the repository: Octane swaps a copy of the repository into a fresh
        // container for every request, and a singleton holding the instance from boot would never
        // see a value a request set, the default guard `Auth::shouldUse()` switches included.
        $this->app->singleton(
            MagicLinkConfig::class,
            fn (): MagicLinkConfig => new MagicLinkConfig(static fn (): Repository => Container::getInstance()->make(Repository::class)),
        );

        $this->app->singleton(TokenHasher::class, function (Application $app): TokenHasher {
            $config = $app->make(Repository::class);
            $key = $config->get('app.key');

            // Retired keys verify but never sign, so a gentle APP_KEY rotation does not
            // orphan tokens that are still live. Same list the framework hands its
            // encrypter, and the same one SignedTokenUrl resolves for signatures.
            $previous = $config->get('app.previous_keys');

            return new TokenHasher(
                is_string($key) ? $key : '',
                is_array($previous) ? array_values(array_filter($previous, is_string(...))) : [],
            );
        });

        // Bound explicitly rather than auto-resolved so the configured store reaches it.
        // Auto-resolution gave it the default store and no way to move it, which is how a
        // host following the resend.store advice ended up with a working guard and a
        // throwing issuer.
        $this->app->singleton(
            IssuanceLock::class,
            function (Application $app): IssuanceLock {
                $config = $app->make(MagicLinkConfig::class);

                return new IssuanceLock(
                    $app->make(CacheFactory::class),
                    $config->lockBlockSeconds(),
                    $config->lockHoldSeconds(),
                    $config->lockStore(),
                );
            },
        );

        $this->app->singleton(TokenStore::class, function (Application $app): TokenStore {
            $custom = $app->make(MagicLinkConfig::class)->tokenStore();

            return $this->resolveContract($app, TokenStore::class, $custom, DefaultTokenStore::class);
        });

        $this->app->singleton(SignInEligibility::class, function (Application $app): SignInEligibility {
            $custom = $app->make(MagicLinkConfig::class)->eligibility();

            return $this->resolveContract($app, SignInEligibility::class, $custom, AlwaysEligible::class);
        });

        $this->app->singleton(UserLookup::class, function (Application $app): UserLookup {
            $custom = $app->make(MagicLinkConfig::class)->userLookup();

            return $this->resolveContract($app, UserLookup::class, $custom, DefaultUserLookup::class);
        });

        // Wrapped rather than replaced, so a host that binds its own lookup is gated too. The
        // wrappers hang on an extender, not on the binding above: a host provider registers
        // after this one, and its `bind()` or `singleton()` replaces a binding whole, while the
        // container applies an extender to whatever the binding resolves. Putting the gate here
        // instead of in the two controllers that call it also means a third caller added later
        // inherits it rather than forgetting it -- and every caller keeps branching on null,
        // which it already did. The address check sits inside the gate: an account that only
        // matched a look-alike address is never asked whether it may sign in.
        $this->app->extend(UserLookup::class, static function (UserLookup $lookup, Application $app): UserLookup {
            if ($lookup instanceof EligibleUserLookup) {
                return $lookup;
            }

            return new EligibleUserLookup(new AddressMatchingUserLookup($lookup), $app->make(SignInEligibility::class));
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
                throw InvitationsMisconfiguredException::handlerNotFound($class);
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

        if (self::$runsMigrations && ! $this->migrationsArePublished()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Decided at boot so published config is in force (the authenticator is
        // only resolved at request time, after this wrapping is applied).
        $this->registerFortifyBridge($config);

        // Registered before the master switch so `php artisan about` shows a
        // disabled package as disabled instead of not at all.
        AboutCommand::add('Email Magic Link', fn (): array => [
            'Enabled' => $config->enabled() ? 'yes' : 'no',
            'Mode' => $config->mode(),
            'Fortify bridge' => match ($config->fortifyMode()) {
                true => 'forced',
                false => 'off',
                default => 'auto',
            },
            'Invitations' => $config->invitationsEnabled() ? 'on' : 'off',
        ]);

        if (! $config->enabled()) {
            return;
        }

        // Fail closed before anything user-facing is registered.
        new EntropyGuard($config)->validate();
        new InvitationGuard($config)->validate();

        $this->registerRateLimiters();
        $this->registerRoutes($config);
        $this->registerPruneSchedule($config);
    }

    /**
     * Register the scheduled purge in the host's scheduler, when asked to.
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
        // answers whether it CAN run at all. A consumer that called ignoreMigrations() may
        // have declined the tables, and the command would then hit a relation that does not
        // exist -- a non-zero exit on every scheduled run, and with schedule monitoring one entry
        // in the error tracker per run. Or it may migrate a copy under another name, and then
        // dropping the purge throws away what prune.schedule asked for, in silence. Only the
        // host knows which, and says so with ignoreMigrations(tablesExist: true); the doctor
        // command reports a schedule that was asked for and dropped. Read from flags rather
        // than from the schema: this runs at BOOT, and asking the database there would put
        // a query on every request to answer a question about a nightly job.
        if (! self::$runsMigrations && ! self::$tablesMigratedElsewhere) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($config): void {
            $event = $schedule->command('email-magic-link:purge');
            $frequency = $config->pruneFrequency();

            match ($frequency) {
                'hourly' => $event->hourly(),
                'weekly' => $event->weekly(),
                'monthly' => $event->monthly(),
                default => $event->daily(),
            };

            // withoutOverlapping so a long purge on a large table cannot stack: the next tick would
            // otherwise start a second delete against rows the first one is already working through.
            // The lock lapses within one interval, in minutes. A purge that is killed rather than
            // stopped (a memory or time limit) never releases it, and with the default of a day an
            // hourly purge would skip the next twenty-three runs without an error or a log line.
            //
            // A description only: for a command event `name()` is an alias of `description()`, so
            // setting both kept whichever came last. The mutex follows the command either way.
            $event->withoutOverlapping(match ($frequency) {
                'hourly' => 55,
                'weekly', 'monthly' => 6 * 24 * 60,
                default => 23 * 60,
            })
                ->description('Delete expired and consumed magic-link tokens, and settled invitations past their retention window.');
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

    /**
     * Whether the application already holds a published copy of the bundled migrations.
     *
     * publishesMigrations() gives each copy a fresh date prefix, so the copy and the
     * bundled file are two migrations by name for one table, and `migrate` on a fresh
     * database fails on the second CREATE with a message that names the table, not
     * the cause. A host that renames its copy is expected to call ignoreMigrations(); this is
     * the belt for the one that did not read that sentence. One readdir at boot.
     */
    private function migrationsArePublished(): bool
    {
        $copies = glob($this->app->databasePath('migrations/*_create_magic_link_tokens_table.php'));

        return is_array($copies) && $copies !== [];
    }

    private function registerRoutes(MagicLinkConfig $config): void
    {
        if (! $this->app->routesAreCached()) {
            // NoIndex is appended here rather than listed in `routes.middleware`, so a
            // host that overrides that key cannot drop it by accident.
            Route::middleware([...$config->routeMiddleware(), NoIndex::class])
                ->prefix($config->routePrefix())
                ->group(__DIR__.'/../routes/email-magic-link.php');
        }
    }

    /**
     * The pairing of limiter NAME to limiter closure lives on RateLimits, not here.
     *
     * It has to be reachable after boot: an application that swaps its cache per
     * tenant discards the RateLimiter singleton, and every named limiter goes with
     * it. A private method that already ran cannot put them back, and the first
     * request to a package route then answers 500.
     */
    private function registerRateLimiters(): void
    {
        $this->app->make(RateLimits::class)->define();
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
            // umbrella tag `email-magic-link`. The umbrella is a convenience, not the
            // only way: `vendor:publish --tag` takes several tags, and `--provider`
            // publishes every group of a provider, tagged or not. But one tag is easier
            // to remember than four, and a group added later joins it without the
            // consumer having to learn its name.
            $this->publishes([
                __DIR__.'/../config/email-magic-link.php' => config_path('email-magic-link.php'),
            ], ['email-magic-link-config', 'email-magic-link']);

            // publishesMigrations(), not publishes(): when the framework's
            // `database.migrations.update_date_on_publish` is on (its default), it
            // rewrites the bundled 0001_01_01_00000N ordering prefix to the publish
            // date. That prefix is right for auto-loading -- the migration files only have
            // to sort among themselves -- and wrong in the application's migrations
            // directory, where it would sort them ahead of everything the host has and
            // give every host the same file names. There is no foreign key at stake:
            // user_id is a plain string column, on purpose.
            //
            // The rewrite has a cost, and boot() pays it: a copy with a fresh prefix is
            // a DIFFERENT migration by name, so the bundled file and its copy would both
            // be pending for one table. See migrationsArePublished().
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
