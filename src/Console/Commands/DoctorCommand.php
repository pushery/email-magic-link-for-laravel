<?php

declare(strict_types=1);

namespace EmailMagicLink\Console\Commands;

use EmailMagicLink\Contracts\ScriptNonce;
use EmailMagicLink\Support\AutoScriptNonce;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Reports what a published config file does not know about.
 *
 * A host that ran `vendor:publish` freezes its copy at that version. The recursive
 * merge means those keys still take effect — but the FILE never mentions them, so
 * an operator reading it cannot see that a subsystem exists, let alone tune it.
 *
 * That is not hypothetical. A consumer's published copy carried 33 dot-keys against
 * the package's 56: no `resend` block at all, while the application actively injected
 * the resend guard. A security-relevant subsystem running on defaults its operator
 * could not see and did not know existed. Nobody chose that; the file simply never
 * learned, and the better the defaults the quieter the drift.
 *
 * So this command answers one question — "what is in the package that my file does
 * not mention?" — and answers it against the file on disk, never against the merged
 * runtime config, which by design always looks complete.
 */
final class DoctorCommand extends Command
{
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

        // Reporting, never gating: this runs in a deploy pipeline and a drifted
        // config is a thing to read, not a thing to fail a release on. `unknown`
        // in particular is often a deliberate leftover during a staged upgrade.
        return self::SUCCESS;
    }

    /**
     * Report WHERE a CSP nonce would come from — never what it is.
     *
     * `AutoScriptNonce` deliberately falls back to null instead of throwing, because
     * an exception would take down the sign-in screen over a progressive
     * enhancement. The price is that a nonce which cannot be resolved produces NO
     * signal at all: the script ships without the attribute, a strict policy blocks
     * it, and the only symptom is a resend button that never counts down. That took
     * four separate consumers to notice.
     *
     * A log warning would be the wrong instrument — most hosts have no policy at
     * all, so it would fire almost always, get filtered, and take the one meaningful
     * warning with it. This command is already the place someone reads when they
     * have a question.
     *
     * ⚠️ It reports the SOURCE, not the value, and that is a correctness point
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
            $this->line('            Under a strict Content-Security-Policy the bundled inline script');
            $this->line('            would be blocked, and it fails silently. See ui.script_nonce.');

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
