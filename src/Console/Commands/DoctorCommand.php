<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\ScriptNonce;
use EmailMagicLink\EmailMagicLinkServiceProvider;
use EmailMagicLink\Models\Invitation;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Stores\DefaultTokenStore;
use EmailMagicLink\Support\AutoScriptNonce;
use EmailMagicLink\Support\EnvNumber;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use ReflectionProperty;

/**
 * Reports what a published config file does not know about.
 *
 * A host that ran `vendor:publish` freezes its copy at that version. The recursive
 * merge means those keys still take effect — but the FILE never mentions them, so
 * an operator reading it cannot see that a subsystem exists, let alone tune it.
 *
 * A published copy can lag far behind: 33 dot-keys against the package's 56 is a copy
 * with no `resend` block at all, while the application injects the resend guard, a
 * security-relevant subsystem running on defaults its operator cannot see. The better
 * the defaults, the quieter that drift.
 *
 * So this command answers one question — "what is in the package that my file does
 * not mention?" — and answers it against the file on disk, never against the merged
 * runtime config, which by design always looks complete.
 */
final class DoctorCommand extends Command
{
    /** The section that explains the link-origin advice, printed in full so a terminal can open it. */
    public const string LINK_ORIGIN_DOCS = 'https://docs.pushery.com/email-magic-link-for-laravel/security-model#the-host-in-the-emailed-link';

    protected $signature = 'email-magic-link:doctor';

    protected $description = 'Compare the published config against the one this version ships.';

    public function handle(Application $app, Repository $config): int
    {
        $publishedPath = $app->configPath('email-magic-link.php');

        if (! is_file($publishedPath)) {
            $this->info('No published config — this application uses the package defaults, so nothing can drift.');
            $this->line('  Publish one with: php artisan vendor:publish --tag=email-magic-link-config');

            // Also on this path: a host with no published config has the same CSP
            // question, and this branch returns before the report below.
            $this->reportScriptNonce($config);
            $this->reportLinkOrigin($app);
            $this->reportPruneSchedule($app);
            $this->reportIssueLock($app);
            $this->reportFallbackLocale($app);
            $this->reportSetAsideEnvironment($app);
            $this->reportRememberColumn($app);

            return self::SUCCESS;
        }

        $published = self::flatten($this->load($publishedPath));
        $shipped = self::flatten($this->load(__DIR__.'/../../../config/email-magic-link.php'));

        $missing = array_diff_key($shipped, $published);
        $unknown = array_diff_key($published, $shipped);

        $this->line("Published config: {$publishedPath}");
        $this->line(sprintf('  %d keys published, %d keys shipped by this version.', count($published), count($shipped)));

        if ($missing !== []) {
            $this->newLine();
            $this->warn(sprintf('%d key(s) this version ships are not in your file:', count($missing)));

            foreach ($missing as $key => $value) {
                $this->line("  {$key} = ".self::render($value));
            }

            $this->newLine();
            $this->line('These are in effect with the values above — the package merges its defaults');
            $this->line('underneath your file. Add the ones you want to tune; the rest need no action.');
        }

        if ($unknown !== []) {
            $this->newLine();
            $this->warn(sprintf('%d key(s) in your file are not known to this version:', count($unknown)));

            foreach (array_keys($unknown) as $key) {
                $this->line("  {$key}");
            }

            $this->newLine();
            $this->line('They are ignored. Either they were removed in an upgrade, or the key is a typo —');
            $this->line('a typo reads exactly like a setting that stopped working, so both are worth a look.');
        }

        if ($missing === [] && $unknown === []) {
            $this->newLine();
            $this->info('Your published config matches this version key for key.');
        }

        $this->reportScriptNonce($config);
        $this->reportLinkOrigin($app);
        $this->reportPruneSchedule($app);
        $this->reportIssueLock($app);
        $this->reportFallbackLocale($app);
        $this->reportSetAsideEnvironment($app);
        $this->reportRememberColumn($app);

        // Reporting, never gating: this runs in a deploy pipeline and a drifted
        // config is a thing to read, not a thing to fail a release on. `unknown`
        // in particular is often a deliberate leftover during a staged upgrade.
        return self::SUCCESS;
    }

    /**
     * Report a purge that nothing schedules.
     *
     * Every request writes a token row, and without a purge the token table grows without
     * bound. `prune.schedule` asks the package to schedule it, and a host that called
     * ignoreMigrations() without saying its tables exist gets no entry, because the
     * provider cannot tell a copy migrated under another name from tables that were
     * declined. With the switch off, which is how the package ships, the host schedules
     * the command itself, and one that never did is told so here. A purge run outside the
     * scheduler, from a cron line or a job of the host's, is invisible to this check.
     *
     * Two hosts are left alone, because the line would claim what the command cannot know:
     * one that declined the package's tables, where the provider schedules nothing either,
     * and one whose token store is its own rather than the bundled one or a subclass of it,
     * whose purge() may have nothing to do.
     */
    private function reportPruneSchedule(Application $app): void
    {
        $config = $app->make(MagicLinkConfig::class);

        if ($config->pruneSchedule()) {
            if (! EmailMagicLinkServiceProvider::$runsMigrations && ! EmailMagicLinkServiceProvider::$tablesMigratedElsewhere) {
                $this->line('Purge       requested by prune.schedule, and NOT scheduled.');
                $this->line('            ignoreMigrations() was called without tablesExist: true, so the');
                $this->line('            package cannot tell whether its tables exist. Pass it if they do,');
                $this->line('            or schedule email-magic-link:purge yourself.');
            }

            return;
        }

        $store = $config->tokenStore();

        if ($store !== null && ! is_a($store, DefaultTokenStore::class, true)) {
            return;
        }

        if (! EmailMagicLinkServiceProvider::$runsMigrations && ! EmailMagicLinkServiceProvider::$tablesMigratedElsewhere) {
            return;
        }

        // Any entry whose command names the purge counts, so one under a tenancy runner does too.
        foreach ($app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'email-magic-link:purge')) {
                return;
            }
        }

        $this->line('Purge       nothing in the scheduler runs email-magic-link:purge, so spent');
        $this->line('            and expired tokens are never deleted. Set prune.schedule to true,');
        $this->line('            or schedule the command yourself. A purge you run outside the');
        $this->line('            scheduler, from a cron line or a job, is fine; then ignore this.');
    }

    /**
     * Report a fallback locale this package has no strings for.
     *
     * Laravel tries the requested locale and then `app.fallback_locale`, and nothing in
     * between. When neither has a bundle, every string of the screens and the mail renders as
     * its key, "email-magic-link::messages.heading" and the like, for every visitor whose
     * locale the package does not ship. A published copy under lang/vendor counts as a bundle.
     *
     * An empty or missing fallback is no fallback at all: the translator then tries the
     * requested locale alone, and Translator::has() would read an empty locale as the current
     * one. A blank APP_FALLBACK_LOCALE reaches the configuration as exactly that empty string.
     */
    private function reportFallbackLocale(Application $app): void
    {
        $fallback = $app->make(Repository::class)->get('app.fallback_locale');

        if (! is_string($fallback) || $fallback === '') {
            $this->line('Fallback    app.fallback_locale is empty, so there is no fallback locale.');
            $this->line('            A visitor whose locale has no bundle of this package sees every');
            $this->line('            text as its key. Set it to a locale the package ships.');

            return;
        }

        if ($app->make(Translator::class)->has('email-magic-link::messages.sign_in', $fallback, false)) {
            return;
        }

        $this->line("Fallback    {$fallback} has no strings of this package.");
        $this->line('            A visitor whose locale has no bundle either sees every text as');
        $this->line('            its key. Fall back to a locale the package ships, or publish the');
        $this->line("            language files and add {$fallback}.");
    }

    /**
     * Report an EMAIL_MAGIC_LINK_* number that is set and could not be used.
     *
     * The shipped config keeps its own value when such a variable is not a number of at least 1,
     * so a typo cannot switch a limit off. The value in effect is then the shipped one, which
     * reads exactly like a setting that was applied. The config is evaluated here once more, and
     * EnvNumber lists each variable it had to set aside on the way.
     *
     * A cached configuration loads no .env file, so only variables of the process environment
     * are seen then, and the report says so.
     */
    private function reportSetAsideEnvironment(Application $app): void
    {
        EnvNumber::clearSetAside();
        $this->load(__DIR__.'/../../../config/email-magic-link.php');

        foreach (EnvNumber::setAside() as $variable => $entry) {
            $this->line("Environment {$variable} is \"{$entry['value']}\", which is not a number of at least 1.");
            $this->line("            The shipped config keeps {$entry['default']} for it. Correct the value to apply it.");
        }

        if ($app->bound('config_loaded_from_cache') && $app->make('config_loaded_from_cache') === true) {
            $this->line('Environment The configuration is cached, so only the process environment was read here.');
            $this->line('            Values from .env are checked after php artisan config:clear.');
        }
    }

    /**
     * Report a guard whose users cannot be kept signed in.
     *
     * A ticked "Stay signed in" signs in through Auth::login($user, true), and Laravel then saves
     * a remember token through the guard's user provider, into the column the model names, which
     * is remember_token unless the model says otherwise. A table without that column fails the
     * save with a database error, and by then the link or code has been spent. Nobody can tick
     * the box while remember.enabled is off, so the check runs only while it is on, for every
     * guard the package signs in on.
     *
     * The two providers the framework ships are read, eloquent through its model and database
     * through its table. A guard with any other provider, or a table this command cannot read, is
     * named as not checked, because silence would read as a pass.
     */
    private function reportRememberColumn(Application $app): void
    {
        $config = $app->make(MagicLinkConfig::class);

        if (! $config->rememberEnabled()) {
            return;
        }

        foreach ($config->allowedGuards() as $guard) {
            $target = $this->rememberTarget($app->make(Repository::class), $guard);

            if ($target === null) {
                $this->line("Remember    the guard \"{$guard}\" has no eloquent or database provider this command");
                $this->line('            can read, so its table was not checked for the column a remembered sign-in needs.');

                continue;
            }

            [$connection, $table, $column] = $target;

            if ($column === '') {
                $this->line("Remember    the user model of the guard \"{$guard}\" names no remember token column, so a");
                $this->line('            ticked "Stay signed in" keeps nobody signed in past the session.');

                continue;
            }

            try {
                $schema = $app->make(DatabaseManager::class)->connection($connection)->getSchemaBuilder();
                $exists = $schema->hasTable($table);
                $present = $exists && $schema->hasColumn($table, $column);
            } catch (InvalidArgumentException|QueryException $e) {
                $this->line("Remember    the table \"{$table}\" of the guard \"{$guard}\" could not be read: {$e->getMessage()}");
                $this->line('            Whether it has the column a remembered sign-in needs was not checked.');

                continue;
            }

            if (! $exists) {
                $this->line("Remember    the table \"{$table}\" of the guard \"{$guard}\" does not exist on this connection,");
                $this->line('            so it was not checked for the column a remembered sign-in needs.');

                continue;
            }

            if ($present) {
                continue;
            }

            $this->line("Remember    the table \"{$table}\" of the guard \"{$guard}\" has no {$column} column.");
            $this->line('            remember.enabled offers "Stay signed in", and a ticked box then fails the');
            $this->line('            sign-in with a database error, after the link or code is spent. Add the');
            $this->line('            column, or turn remember.enabled off.');
        }
    }

    /**
     * Where a guard's user provider saves a remember token: the connection, the table and the
     * column. Null when the provider is not one of the two the framework ships, and for a guard
     * the auth configuration does not define.
     *
     * @return array{?string, string, string}|null
     */
    private function rememberTarget(Repository $repository, string $guard): ?array
    {
        $name = $repository->get("auth.guards.{$guard}.provider");
        $provider = is_string($name) ? $repository->get("auth.providers.{$name}") : null;
        $provider = is_array($provider) ? $provider : [];

        $driver = $provider['driver'] ?? null;
        $table = $provider['table'] ?? null;

        if ($driver === 'database' && is_string($table)) {
            $connection = $provider['connection'] ?? null;

            return [is_string($connection) ? $connection : null, $table, 'remember_token'];
        }

        $model = $provider['model'] ?? null;

        if ($driver === 'eloquent' && is_string($model) && is_subclass_of($model, Model::class)) {
            $instance = new $model;

            if ($instance instanceof Authenticatable) {
                return [$instance->getConnectionName(), $instance->getTable(), $instance->getRememberTokenName()];
            }
        }

        return null;
    }

    /**
     * Report an issuance lock that would end the caller's transaction on PostgreSQL.
     *
     * The database cache store takes a lock with an INSERT and, when the key is taken, an
     * UPDATE on the same connection. Inside a transaction on PostgreSQL the failed INSERT
     * aborts the transaction, so an invite() or issueLink() called within one fails at once
     * with SQLSTATE 25P02 when another issue for the same subject holds the lock, instead of
     * waiting for it, and the caller's transaction cannot be used any further. Measured with
     * laravel/framework 13.34 on PostgreSQL 18.4: the same lock on a connection of its own
     * waits and times out as usual, and the transaction stays usable. The store's
     * `lock_connection` setting exists for exactly that.
     *
     * The transaction that aborts is the caller's, so the lock is reported on the connections
     * the package's tables use and on the application's default, where DB::transaction()
     * opens it. The two differ once the models are mapped to a connection of their own.
     */
    private function reportIssueLock(Application $app): void
    {
        $name = $app->make(MagicLinkConfig::class)->lockStore();

        try {
            $store = $app->make(CacheFactory::class)->store($name)->getStore();
        } catch (InvalidArgumentException $e) {
            // The framework's own words name the store, whether it came from lock_store or is
            // the default one.
            $this->line('Issue lock  '.$e->getMessage());
            $this->line('            Issuing a credential fails until it is. See email-magic-link.lock_store.');

            return;
        }

        if (! $store instanceof DatabaseStore) {
            return;
        }

        $connection = $store->getLockConnection();
        $tables = [MagicLinkToken::resolve()->getConnection()->getName(), Invitation::resolve()->getConnection()->getName()];
        $default = $app->make(ConnectionResolverInterface::class)->getDefaultConnection();

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql' || ! in_array($connection->getName(), [...$tables, $default], true)) {
            return;
        }

        $where = in_array($connection->getName(), $tables, true) ? 'the tables use' : "that is your application's default";

        $this->line("Issue lock  the database cache store, on the PostgreSQL connection \"{$connection->getName()}\" {$where}.");
        $this->line('            An invite() or issueLink() inside a transaction of yours on that connection');
        $this->line('            fails at once with SQLSTATE 25P02 while another issue for the same address');
        $this->line('            holds the lock, and your transaction is aborted. Give the store a');
        $this->line('            lock_connection of its own, or point email-magic-link.lock_store elsewhere.');
    }

    /**
     * Report WHERE the host of an emailed link comes from.
     *
     * Laravel builds every URL from a forced origin when one is set and from the
     * request's Host header otherwise. Behind a catch-all virtual host that means a
     * forged Host lands in the victim's email with a valid token in the path. The
     * three answers are "forced", "restricted by TrustHosts" and "the request as it
     * came in" -- and only the third needs the operator's attention.
     */
    private function reportLinkOrigin(Application $app): void
    {
        $this->newLine();

        if ($this->originIsForced($app)) {
            $this->line('Link origin forced by the application (URL::useOrigin), so a request cannot choose it.');

            return;
        }

        if ($this->trustsHosts($app)) {
            $this->line('Link origin the request host, restricted by the TrustHosts middleware.');

            return;
        }

        $this->line('Link origin the request\'s Host header, unrestricted.');
        $this->line('            The host in every emailed link is whatever the request that asked');
        $this->line('            for it carried. Restrict it with the TrustHosts middleware or force');
        $this->line('            the origin from app.url. See "The host in the emailed link":');
        $this->line('            '.self::LINK_ORIGIN_DOCS);
    }

    private function originIsForced(Application $app): bool
    {
        // The container's generator, not the facade's root: the facade answers `mixed`
        // and would need a guard branch no application can ever take.
        $generator = $app->make(UrlGenerator::class);

        // The generator has no getter for a forced origin; the property is the only witness.
        $property = new ReflectionProperty(UrlGenerator::class, 'forcedRoot');

        return $property->getValue($generator) !== null;
    }

    private function trustsHosts(Application $app): bool
    {
        $kernel = $app->make($this->kernelBinding());

        // The contract does not expose the stack; the framework's kernel does, and every
        // application kernel extends it.
        return $kernel instanceof HttpKernel
            && in_array(TrustHosts::class, $kernel->getGlobalMiddleware(), true);
    }

    /**
     * The binding as a value rather than a literal: the analyzer otherwise resolves it
     * to the concrete kernel of whichever application it runs in and reads the
     * instanceof above as settled.
     *
     * @return class-string<KernelContract>
     */
    private function kernelBinding(): string
    {
        return KernelContract::class;
    }

    /**
     * Report WHERE a CSP nonce would come from — never what it is.
     *
     * `AutoScriptNonce` deliberately falls back to null instead of throwing, because
     * an exception would take down the sign-in screen over a progressive
     * enhancement. The price is that a nonce which cannot be resolved produces NO
     * signal at all: the tags ship without the attribute, a strict policy blocks
     * them, and the symptom is a screen without its styles, with nothing logged.
     *
     * A log warning would be the wrong instrument — most hosts have no policy at
     * all, so it would fire almost always, get filtered, and take the one meaningful
     * warning with it. This command is already the place someone reads when they
     * have a question.
     *
     * It reports the SOURCE, not the value, and that is a correctness point
     * rather than caution. The nonce is scoped per request; a console command either
     * cannot resolve the binding at all or resolves one that no response will ever
     * carry. Printing it would show a value that is real and useless. Whether the
     * SOURCE exists is the same question in the console as in a request, so that is
     * what gets answered.
     */
    private function reportScriptNonce(Repository $config): void
    {
        $this->newLine();

        $custom = $config->get('email-magic-link.ui.script_nonce');

        if (is_string($custom) && is_a($custom, ScriptNonce::class, true)) {
            $this->line("CSP nonce   custom ({$custom} via ui.script_nonce)");

            return;
        }

        if (is_string($custom) && $custom !== '') {
            // Configured but unusable: the value is ignored and the package falls
            // back to auto-detection, which looks identical to having configured
            // nothing. Naming it is the whole point of this command.
            $this->line("CSP nonce   ui.script_nonce is set to \"{$custom}\", which does not implement ".ScriptNonce::class);
            $this->line('            The setting is IGNORED and auto-detection runs instead.');

            return;
        }

        $sources = [];

        if ($this->laravel->bound(AutoScriptNonce::$binding)) {
            $sources[] = 'the "'.AutoScriptNonce::$binding.'" container binding';
        }

        if (function_exists(AutoScriptNonce::$helper)) {
            $sources[] = 'the global '.AutoScriptNonce::$helper.'() function';
        }

        if ($sources === []) {
            $this->line('CSP nonce   no source detected — fine if this app has no policy');
            $this->line('            Under a strict Content-Security-Policy the screens\' inline styles');
            $this->line('            would be blocked, silently, and they render unstyled. See ui.script_nonce.');

            return;
        }

        $this->line('CSP nonce   resolved from '.implode(', then ', $sources));
        $this->line('            Source only: the nonce itself is per-request, so a console run');
        $this->line('            cannot show the value a response would carry.');
    }

    /**
     * The keys a config file defines, flattened to dot notation.
     *
     * A LIST is one value, not a set of numeric sub-keys — the same rule the merge
     * uses. Without it, `guards => ['web', 'admin']` would report `guards.0` and
     * `guards.1` as separate keys, and a host with a different number of entries
     * would show phantom "missing" keys on every run.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private static function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...self::flatten($value, $dotted)];

                continue;
            }

            $flat[$dotted] = $value;
        }

        return $flat;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function load(string $path): array
    {
        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * A value as an operator would write it in the config file.
     *
     * `mixed`, because it renders whatever a config file holds, nested arrays included; each
     * arm of the match narrows the value before it is used.
     */
    private static function render(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => '['.implode(', ', array_map(self::render(...), $value)).']',
            is_string($value) => "'{$value}'",
            default => (string) (is_scalar($value) ? $value : gettype($value)),
        };
    }
}
