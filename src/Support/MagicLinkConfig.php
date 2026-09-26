<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Closure;
use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Contracts\ScriptNonce;
use EmailMagicLink\Notifications\MagicLinkNotification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Typed gateway to the package configuration.
 *
 * Every configuration read in the package goes through here so the rest of the
 * code never touches the loosely typed config repository directly -- with two
 * exceptions by design. Replaceable::model() asks the container for the model map,
 * because it has to answer outside an application too, where there is no
 * repository to build this class from. And `email-magic-link:doctor` reads
 * `ui.script_nonce` raw, because it reports a configured value this class would
 * discard as unusable.
 */
final readonly class MagicLinkConfig
{
    /**
     * @param  Repository|Closure(): Repository  $config  the repository, or how to find the current
     *                                                    one. A long-lived worker swaps the
     *                                                    repository in for every request; a
     *                                                    resolver reads the one in force, the
     *                                                    instance held at boot does not.
     */
    public function __construct(private Repository|Closure $config) {}

    public function enabled(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.enabled'), true);
    }

    /**
     * @return 'link'|'code'|'both'
     */
    public function mode(): string
    {
        return match ($this->string($this->repository()->get('email-magic-link.mode'), 'link')) {
            'code' => 'code',
            'both' => 'both',
            default => 'link',
        };
    }

    public function ttl(): int
    {
        return $this->int($this->repository()->get('email-magic-link.ttl'), 900);
    }

    /**
     * The lifetime, in seconds, for a given channel's tokens.
     *
     * A positive `{channel}_ttl` override wins; anything else inherits `ttl`,
     * which the entropy guard keeps positive — so this never returns a
     * non-positive lifetime.
     *
     * @param  'link'|'code'  $channel
     */
    public function ttlFor(string $channel): int
    {
        // The same rule every other integer key follows: an int or a numeric string,
        // nothing else, so an override reads the way `ttl` itself does.
        $override = $this->int($this->repository()->get("email-magic-link.{$channel}_ttl"), 0);

        return $override > 0 ? $override : $this->ttl();
    }

    /**
     * The default number of times a link may be redeemed. Clamped to at least 1,
     * so it can never mint a link that is dead on arrival.
     */
    public function maxUses(): int
    {
        return max(1, $this->int($this->repository()->get('email-magic-link.max_uses'), 1));
    }

    public function codeLength(): int
    {
        return $this->int($this->repository()->get('email-magic-link.code_length'), 8);
    }

    public function codeAlphabet(): string
    {
        return $this->string($this->repository()->get('email-magic-link.code_alphabet'), '');
    }

    /**
     * The distinct characters of the code alphabet, multibyte-aware.
     *
     * Both the entropy guardrail and the code generator use this single
     * canonical representation, so the keyspace the guard certifies is exactly
     * the uniform distribution the generator emits.
     *
     * @return list<string>
     */
    public function codeAlphabetCharacters(): array
    {
        return array_values(array_unique(mb_str_split($this->codeAlphabet())));
    }

    /**
     * How a submitted code must be folded before it is compared, derived from the
     * configured alphabet rather than assumed.
     *
     * `null` means DO NOT FOLD. That is the case for an alphabet that writes both
     * cases: there `a` and `A` are two different characters, the generator mints
     * both, and folding either way destroys codes that can then never be redeemed
     * -- silently, looking exactly like an expired code while the attempt counter
     * runs down.
     *
     * The direction matters as much as the decision. Folding upward is right for
     * the shipped alphabet and wrong for a lower-case one: the generator would mint
     * `abc`, the comparison would see `ABC`, and that alphabet would be as unusable
     * as a mixed one. So the direction follows the alphabet.
     *
     * Digits and symbols answer `null` too, and correctly so: they have no case,
     * so there is nothing to fold and no behavior to change.
     *
     * @return 'upper'|'lower'|null
     */
    public function codeAlphabetCaseFolding(): ?string
    {
        $alphabet = $this->codeAlphabet();

        // The same derivation the rendered field uses to decide whether it may widen
        // its validation pattern. Keeping ONE copy is the point: a field that accepts a
        // case the request then folds away, or the reverse, is exactly the drift two
        // independent derivations produce.
        $hasUpper = preg_match('/\p{Lu}/u', $alphabet) === 1;
        $hasLower = preg_match('/\p{Ll}/u', $alphabet) === 1;

        if ($hasUpper === $hasLower) {
            return null;
        }

        return $hasUpper ? 'upper' : 'lower';
    }

    /**
     * The alphabet as it EXISTS AT COMPARISON TIME, which is what the entropy
     * guardrail must certify.
     *
     * Under a fold, characters collapse into one another, so counting the
     * configured distinct characters overstates the keyspace -- the guard whose
     * only job is to refuse a weak scheme would certify one that is weaker than
     * it measured. With the fold derived from the alphabet (a mixed alphabet is
     * not folded at all) the two sets agree in every case, and this method is what
     * keeps them agreeing if the derivation ever changes.
     *
     * @return list<string>
     */
    public function effectiveCodeAlphabetCharacters(): array
    {
        $fold = $this->codeAlphabetCaseFolding();

        if ($fold === null) {
            return $this->codeAlphabetCharacters();
        }

        $alphabet = $fold === 'upper'
            ? mb_strtoupper($this->codeAlphabet())
            : mb_strtolower($this->codeAlphabet());

        return array_values(array_unique(mb_str_split($alphabet)));
    }

    public function maxAttemptsPerToken(): int
    {
        return $this->int($this->repository()->get('email-magic-link.max_attempts_per_token'), 0);
    }

    public function entropySafetyFactor(): int
    {
        return $this->int($this->repository()->get('email-magic-link.entropy_safety_factor'), 1_000_000);
    }

    public function guard(): string
    {
        $guard = $this->repository()->get('email-magic-link.guard');

        if (is_string($guard) && $guard !== '') {
            return $guard;
        }

        return $this->string($this->repository()->get('auth.defaults.guard'), 'web');
    }

    /**
     * The guards a request may sign in to: the default plus any in "guards".
     *
     * @return list<string>
     */
    public function allowedGuards(): array
    {
        $list = [$this->guard()];

        $guards = $this->repository()->get('email-magic-link.guards');

        if (is_array($guards)) {
            foreach ($guards as $guard) {
                if (is_string($guard) && $guard !== '' && ! in_array($guard, $list, true)) {
                    $list[] = $guard;
                }
            }
        }

        return $list;
    }

    /**
     * Resolve the guard for a request: the requested one when it is on the
     * allowlist, otherwise the default. An unknown guard falls back silently so
     * the request endpoint stays enumeration-resistant.
     */
    public function resolveGuard(?string $requested): string
    {
        return $requested !== null && in_array($requested, $this->allowedGuards(), true)
            ? $requested
            : $this->guard();
    }

    /**
     * The user provider configured for a guard, or null to fall back to the
     * application's default provider. Mirrors how the consume flow resolves the
     * user, so issuance and consumption always agree on the provider.
     */
    public function providerForGuard(string $guard): ?string
    {
        $provider = $this->repository()->get("auth.guards.{$guard}.provider");

        return is_string($provider) ? $provider : null;
    }

    public function userLookup(): ?string
    {
        $lookup = $this->repository()->get('email-magic-link.user_lookup');

        return is_string($lookup) && $lookup !== '' ? $lookup : null;
    }

    public function eligibility(): ?string
    {
        $eligibility = $this->repository()->get('email-magic-link.eligibility');

        return is_string($eligibility) && $eligibility !== '' ? $eligibility : null;
    }

    public function tokenStore(): ?string
    {
        $store = $this->repository()->get('email-magic-link.token_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    public function captcha(): ?string
    {
        $captcha = $this->repository()->get('email-magic-link.captcha');

        return is_string($captcha) && $captcha !== '' ? $captcha : null;
    }

    /**
     * @return class-string<MagicLinkNotification>
     */
    public function notification(): string
    {
        $notification = $this->repository()->get('email-magic-link.notification');

        if (is_string($notification) && is_a($notification, MagicLinkNotification::class, true)) {
            return $notification;
        }

        return MagicLinkNotification::class;
    }

    public function routePrefix(): string
    {
        return $this->string($this->repository()->get('email-magic-link.routes.prefix'), '');
    }

    /**
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        $middleware = $this->repository()->get('email-magic-link.routes.middleware');

        if (! is_array($middleware)) {
            return ['web'];
        }

        $result = [];

        foreach ($middleware as $entry) {
            if (is_string($entry) && $entry !== '') {
                $result[] = $entry;
            }
        }

        return $result === [] ? ['web'] : $result;
    }

    public function redirectTo(): string
    {
        return $this->string($this->repository()->get('email-magic-link.routes.redirect_to'), '/');
    }

    public function redirectToIntended(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.routes.intended'), true);
    }

    /**
     * Whether the package registers the daily purge in the host's scheduler.
     */
    public function pruneSchedule(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.prune.schedule'), false);
    }

    /**
     * The cadence for the self-registered purge.
     *
     * Mapped through a fixed set rather than passed to the scheduler as a method
     * name: a config value that reaches ->{$method}() is a config value that can
     * call anything on the Schedule object. An unrecognized cadence falls back to
     * daily — a typo in a cleanup interval must not take down a boot.
     *
     * @return 'hourly'|'daily'|'weekly'|'monthly'
     */
    public function pruneFrequency(): string
    {
        return match ($this->string($this->repository()->get('email-magic-link.prune.frequency'), 'daily')) {
            'hourly' => 'hourly',
            'weekly' => 'weekly',
            'monthly' => 'monthly',
            default => 'daily',
        };
    }

    public function apiEnabled(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.api.enabled'), false);
    }

    /**
     * A custom invalid-link responder class named by `via`, or null to use the
     * bundled default. Only a `via` that names a class implementing
     * {@see InvalidLinkResponder} counts; the built-in strategy keywords
     * (view/redirect/abort/json) return null and are handled by the default.
     *
     * @return class-string<InvalidLinkResponder>|null
     */
    public function invalidLinkResponderClass(): ?string
    {
        $via = $this->repository()->get('email-magic-link.invalid_response.via');

        return is_string($via) && is_a($via, InvalidLinkResponder::class, true) ? $via : null;
    }

    /**
     * The built-in invalid-link strategy. Any value that is not a recognized
     * strategy (including a custom responder class-string, handled separately)
     * falls back to the enumeration-safe redirect.
     *
     * @return 'redirect'|'view'|'abort'|'json'
     */
    public function invalidResponseMode(): string
    {
        return match ($this->string($this->repository()->get('email-magic-link.invalid_response.via'), 'redirect')) {
            'view' => 'view',
            'abort' => 'abort',
            'json' => 'json',
            default => 'redirect',
        };
    }

    public function invalidResponseView(): string
    {
        return $this->string(
            $this->repository()->get('email-magic-link.invalid_response.view'),
            'email-magic-link::invalid',
        );
    }

    /**
     * Where the redirect strategy sends the user; null keeps them on the sign-in
     * form (with the error flashed), which is the default.
     */
    public function invalidResponseRedirectTo(): ?string
    {
        $to = $this->repository()->get('email-magic-link.invalid_response.redirect_to');

        return is_string($to) && $to !== '' ? $to : null;
    }

    /**
     * Rows deleted per statement by the purge. One unbounded DELETE holds a row lock
     * on everything it removes until commit; on a table that grew for months that is
     * minutes of contention with the claims that are running at the same time.
     */
    public function pruneChunk(): int
    {
        $chunk = $this->int($this->repository()->get('email-magic-link.prune.chunk'), 1000);

        return $chunk > 0 ? $chunk : 1000;
    }

    public function invalidResponseAbortStatus(): int
    {
        $status = $this->int($this->repository()->get('email-magic-link.invalid_response.abort_status'), 403);

        // A refusal answers with an error status or not at all: 200 would make the
        // error page a success, and 0 or 999 is a 500 the moment Symfony renders it.
        // Same shape as pruneFrequency(): a typo must not take down a sign-in.
        return $status >= 400 && $status <= 599 ? $status : 403;
    }

    /**
     * The stable machine-readable code the JSON envelope returns for an
     * invalid/expired link, so a client can branch on it without parsing prose.
     */
    public function invalidResponseErrorCode(): string
    {
        return $this->string(
            $this->repository()->get('email-magic-link.invalid_response.error_code'),
            'invalid_or_expired',
        );
    }

    /**
     * The host's Vite entrypoints for the WireKit layout, or false for a non-Vite host.
     *
     * @return list<string>|false
     */
    public function uiVite(): array|false
    {
        $vite = $this->repository()->get('email-magic-link.ui.vite', ['resources/css/app.css']);

        if (in_array($vite, [false, null, []], true)) {
            return false;
        }

        if (is_string($vite)) {
            return $vite === '' ? false : [$vite];
        }

        return is_array($vite) ? array_values(array_filter($vite, is_string(...))) ?: false : false;
    }

    /**
     * Pre-compiled stylesheets the WireKit layout links, in order.
     *
     * @return list<string>
     */
    public function uiStyles(): array
    {
        $styles = $this->repository()->get('email-magic-link.ui.styles', []);

        if (is_string($styles)) {
            return $styles === '' ? [] : [$styles];
        }

        return is_array($styles) ? array_values(array_filter($styles, is_string(...))) : [];
    }

    /**
     * A view rendered ABOVE the card on every screen this package draws, or null for none.
     *
     * The confirm screen is the one place a person decides, out of an e-mail, whether to sign in
     * here, and this package draws it without knowing the host's wordmark or language switcher.
     * Every other screen of a host application has both. A screen whose entire purpose is a trust
     * decision is the worst one to strip of the marks that earn it.
     *
     * A SLOT RATHER THAN A `view` OVERRIDE PER SCREEN, and the choice is deliberate. An override
     * hands the consumer the whole screen — the form, the CSRF field, the token handling — so
     * every later change to any of that has to be carried by hand into every fork, and a
     * security-shaped surface becomes theirs to maintain for the sake of a wordmark. Two slots
     * keep the screen here and give away exactly the two places where the consumer has something
     * to say.
     *
     * A NAME THAT RESOLVES TO NOTHING IS DROPPED, NOT THROWN. `invalid_response.view` lets a
     * bad name throw, and for an error page that is defensible. This one renders on the SIGN-IN
     * path: a typo there would lock every user out of the application rather than spoil a screen.
     * The miss is logged at `warning` with the key, because silence would leave a consumer
     * looking at an unchanged page with nothing to read.
     */
    public function uiHeaderView(): ?string
    {
        return $this->existingView('email-magic-link.ui.header_view');
    }

    /** A view rendered BELOW the card on every screen this package draws, or null for none. */
    public function uiFooterView(): ?string
    {
        return $this->existingView('email-magic-link.ui.footer_view');
    }

    /**
     * The class attribute of the WireKit layout's `<html>` element, or null for none.
     *
     * WireKit declares its dark tokens under `.dark` on the root element, and the layout's body slots
     * cannot reach that element. Whitespace around the value is dropped, and a blank or non-string
     * value renders no attribute at all rather than an empty one. Unicode whitespace too: HTML
     * splits classes at ASCII whitespace only, so a no-break space copied in with the value would
     * make it a class that `.dark` never matches.
     */
    public function uiHtmlClass(): ?string
    {
        $class = $this->repository()->get('email-magic-link.ui.html_class');

        if (! is_string($class)) {
            return null;
        }

        $class = Str::trim($class);

        return $class === '' ? null : $class;
    }

    /**
     * The view named by a config key, or null when it is unset, not a string, or not a view this
     * installation can resolve.
     */
    private function existingView(string $key): ?string
    {
        $name = $this->repository()->get($key);

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (View::exists($name)) {
            return $name;
        }

        Log::warning('email-magic-link: a configured layout slot names a view that does not exist — nothing is rendered there', [
            'setting' => $key,
            'view' => $name,
        ]);

        return null;
    }

    /**
     * @return 'auto'|'blade'
     */
    public function uiMode(): string
    {
        return $this->string($this->repository()->get('email-magic-link.ui.mode'), 'auto') === 'blade'
            ? 'blade'
            : 'auto';
    }

    /**
     * A custom CSP-nonce source, or null to auto-detect one.
     *
     * @return class-string<ScriptNonce>|null
     */
    public function scriptNonce(): ?string
    {
        $source = $this->repository()->get('email-magic-link.ui.script_nonce');

        return is_string($source) && is_a($source, ScriptNonce::class, true) ? $source : null;
    }

    public function usesWireKit(): bool
    {
        return $this->uiMode() === 'auto' && WireKit::installed();
    }

    /**
     * Resolve a view to its WireKit variant when WireKit is active and that
     * variant exists, else the plain Blade view. The existence check keeps the
     * sign-in UI from breaking if a WireKit view was never published or removed.
     *
     * The names are the screens this package renders itself, so every name this
     * returns is one it ships.
     *
     * @param  'request'|'confirm'|'code'  $name
     *
     * @phpstan-return view-string
     */
    public function view(string $name): string
    {
        $wirekit = "email-magic-link::wirekit.{$name}";

        return $this->usesWireKit() && View::exists($wirekit)
            ? $wirekit
            : "email-magic-link::{$name}";
    }

    /**
     * @return 'auto'|bool
     */
    public function fortifyMode(): string|bool
    {
        $mode = $this->repository()->get('email-magic-link.fortify.mode', 'auto');

        if (is_bool($mode)) {
            return $mode;
        }

        // A set but empty EMAIL_MAGIC_LINK_FORTIFY arrives as "", which `filter_var` reads as
        // false -- and false switches the two-factor handoff off. Blank means not set here,
        // so it stays on auto.
        if (! is_string($mode) || $mode === 'auto' || Str::trim($mode) === '') {
            return 'auto';
        }

        // Every spelling the other switches accept -- "0", "off", "no" and their
        // opposites -- decides here too, so EMAIL_MAGIC_LINK_FORTIFY=0 means off and
        // never falls back to auto, the one value it was written to leave.
        $decided = filter_var($mode, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($decided) ? $decided : 'auto';
    }

    public function respectTwoFactor(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.fortify.respect_two_factor'), true);
    }

    public function challengeRoute(): string
    {
        return $this->string($this->repository()->get('email-magic-link.fortify.challenge_route'), 'two-factor.login');
    }

    /**
     * Whether a guard's user provider matches Fortify's guard provider.
     *
     * The two-factor handoff completes inside Fortify, which always challenges
     * and logs in on its own guard's provider. A guard whose provider differs
     * therefore cannot carry a two-factor user through the challenge.
     */
    public function guardSharesFortifyProvider(string $guard): bool
    {
        $fortifyGuard = $this->string($this->repository()->get('fortify.guard'), '');

        if ($fortifyGuard === '') {
            $fortifyGuard = $this->string($this->repository()->get('auth.defaults.guard'), 'web');
        }

        $guardProvider = $this->repository()->get("auth.guards.{$guard}.provider");
        $fortifyProvider = $this->repository()->get("auth.guards.{$fortifyGuard}.provider");

        return is_string($guardProvider) && is_string($fortifyProvider) && $guardProvider === $fortifyProvider;
    }

    public function requestLimiter(): string
    {
        return $this->string($this->repository()->get('email-magic-link.limiters.request'), 'email-magic-link:request');
    }

    public function consumeLimiter(): string
    {
        return $this->string($this->repository()->get('email-magic-link.limiters.consume'), 'email-magic-link:consume');
    }

    /**
     * The limiter on the one route that is throttled without spending anything: the
     * GET that displays an invitation. Separate from the consume limiter so viewing
     * an invitation cannot use up the budget accepting one needs.
     */
    public function invitationViewLimiter(): string
    {
        return $this->string($this->repository()->get('email-magic-link.limiters.invitation_view'), 'email-magic-link:invitation-view');
    }

    /**
     * @return array{max: int, per_minutes: int}
     */
    public function requestLimit(): array
    {
        return $this->readLimit('request', 5);
    }

    /**
     * @return array{max: int, per_minutes: int}
     */
    public function consumeLimit(): array
    {
        return $this->readLimit('consume', 10);
    }

    /**
     * Higher than the consume default on purpose: this guards a page load, not a
     * credential being spent, and a person may open their invitation more than once.
     *
     * @return array{max: int, per_minutes: int}
     */
    public function invitationViewLimit(): array
    {
        return $this->readLimit('invitation_view', 30);
    }

    public function resendEnabled(): bool
    {
        return EnvFlag::protection($this->repository()->get('email-magic-link.resend.enabled'), true);
    }

    /**
     * Base cooldown, growth factor, and ceiling for the escalating resend delay.
     * Clamped so the ladder always climbs (base and factor at least 1) and never
     * caps below the base.
     *
     * @return array{base: int, factor: int, max: int}
     */
    public function resendCooldown(): array
    {
        $cooldown = $this->repository()->get('email-magic-link.resend.cooldown');
        $cooldown = is_array($cooldown) ? $cooldown : [];

        $base = max(1, $this->int($cooldown['base'] ?? null, 30));
        $factor = max(1, $this->int($cooldown['factor'] ?? null, 2));

        return [
            'base' => $base,
            'factor' => $factor,
            'max' => max($base, $this->int($cooldown['max'] ?? null, 900)),
        ];
    }

    /**
     * The rolling window as seconds plus the maximum sends allowed within it.
     *
     * @return array{seconds: int, max_sends: int}
     */
    public function resendWindow(): array
    {
        $window = $this->repository()->get('email-magic-link.resend.window');
        $window = is_array($window) ? $window : [];

        return [
            'seconds' => max(1, $this->int($window['minutes'] ?? null, 60)) * 60,
            'max_sends' => max(1, $this->int($window['max_sends'] ?? null, 5)),
        ];
    }

    /**
     * The cache store the issuance lock uses, or null for the default one.
     *
     * Separate from resendStore() on purpose: a host may have exactly one lockable store and
     * point both here, or keep resend state somewhere cheap and lock somewhere reliable.
     */
    public function lockStore(): ?string
    {
        $store = $this->repository()->get('email-magic-link.lock_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * How long a second issuance for the same subject WAITS for the first.
     *
     * A latency ceiling on the request path rather than a correctness knob: the waiter
     * holds a worker for this long. Giving up is answered with the endpoint's ordinary
     * uniform response, because the request holding the lock is already sending.
     */
    public function lockBlockSeconds(): int
    {
        $seconds = $this->int($this->repository()->get('email-magic-link.lock_block_seconds'), 5);

        return $seconds > 0 ? $seconds : 5;
    }

    /**
     * The lock's TTL -- how long it survives a process that died holding it.
     *
     * Deliberately NOT the same number as the wait budget. A TTL shorter than the work it
     * protects expires mid-issuance and lets a second request in, which is the failure the
     * lock exists to prevent and the one that leaves no trace.
     */
    public function lockHoldSeconds(): int
    {
        $seconds = $this->int($this->repository()->get('email-magic-link.lock_hold_seconds'), 60);

        return $seconds > 0 ? $seconds : 60;
    }

    public function resendStore(): ?string
    {
        $store = $this->repository()->get('email-magic-link.resend.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * @return array{max: int, per_minutes: int}
     */
    private function readLimit(string $key, int $defaultMax): array
    {
        $limit = $this->repository()->get("email-magic-link.limits.{$key}");
        $limit = is_array($limit) ? $limit : [];

        return [
            'max' => max(1, $this->int($limit['max'] ?? null, $defaultMax)),
            'per_minutes' => max(1, $this->int($limit['per_minutes'] ?? null, 1)),
        ];
    }

    /**
     * Whether the invitation channel is registered at all.
     *
     * Off by default and deliberately so: invitations need a host-supplied handler and
     * acceptance view to mean anything, so a package that switched them on for everybody
     * would boot into a misconfiguration nobody asked for.
     */
    public function invitationsEnabled(): bool
    {
        return $this->bool($this->repository()->get('email-magic-link.invitations.enabled'), false);
    }

    /**
     * How long an invitation stays valid, in seconds. Floored at a minute, because a
     * lifetime shorter than the mail takes to arrive is never what anyone meant.
     */
    public function invitationTtl(): int
    {
        return max(60, $this->int($this->repository()->get('email-magic-link.invitations.ttl'), 604800));
    }

    public function invitationStore(): ?string
    {
        $store = $this->repository()->get('email-magic-link.invitations.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * The host class that decides what acceptance MEANS -- setting a password, creating
     * membership, granting roles. Returned raw rather than resolved so the boot guard can
     * name the class that is missing or does not implement the contract.
     */
    public function invitationHandler(): ?string
    {
        $handler = $this->repository()->get('email-magic-link.invitations.handler');

        return is_string($handler) && $handler !== '' ? $handler : null;
    }

    /**
     * The host's acceptance view. A raw view NAME, not a resolved view: this package
     * bundles no acceptance screen, because one shipping with a password field would put
     * credential handling inside a package that deliberately owns none.
     */
    public function invitationView(): ?string
    {
        $view = $this->repository()->get('email-magic-link.invitations.view');

        return is_string($view) && $view !== '' ? $view : null;
    }

    public function invitationRedirectTo(): string
    {
        return $this->string($this->repository()->get('email-magic-link.invitations.redirect_to'), '/');
    }

    /**
     * How long settled invitations are kept before the purge removes them.
     *
     * These rows carry the invited address in the clear, so the window is a data-retention
     * decision rather than a technical one -- which is why it is configurable and why zero
     * (delete as soon as they settle) is a legitimate answer.
     */
    public function invitationRetainAcceptedDays(): int
    {
        return max(0, $this->int($this->repository()->get('email-magic-link.invitations.retain_accepted_days'), 30));
    }

    /**
     * `config()` hands back whatever the host wrote, so the value arrives as `mixed`. This
     * narrows it to a string and answers the declared default for anything else.
     */
    private function repository(): Repository
    {
        return $this->config instanceof Closure ? ($this->config)() : $this->config;
    }

    private function string(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * `config()` hands back whatever the host wrote, so the value arrives as `mixed`. This
     * narrows it to an integer: an int as it is, a numeric string converted, and anything
     * else, an empty `.env` line included, the declared default.
     */
    private function int(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    private function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        // FILTER_NULL_ON_FAILURE, and the fallback is the DECLARED default rather than
        // false. Without it every unreadable value answers false, so a typo does not
        // fail -- it turns the switch off. On `enabled` that closes an authentication
        // path and on `respect_two_factor` it stops a second factor from being honored,
        // neither of which anyone asked for by mistyping.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
