<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Notifications\MagicLinkNotification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\View;

/**
 * Typed gateway to the package configuration.
 *
 * Every configuration read in the package goes through here so the rest of the
 * code never touches the loosely typed config repository directly.
 */
final readonly class MagicLinkConfig
{
    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return $this->bool($this->config->get('email-magic-link.enabled'), true);
    }

    /**
     * @return 'link'|'code'|'both'
     */
    public function mode(): string
    {
        return match ($this->string($this->config->get('email-magic-link.mode'), 'link')) {
            'code' => 'code',
            'both' => 'both',
            default => 'link',
        };
    }

    public function ttl(): int
    {
        return $this->int($this->config->get('email-magic-link.ttl'), 900);
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
        $override = $this->config->get("email-magic-link.{$channel}_ttl");

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        return $this->ttl();
    }

    public function codeLength(): int
    {
        return $this->int($this->config->get('email-magic-link.code_length'), 8);
    }

    public function codeAlphabet(): string
    {
        return $this->string($this->config->get('email-magic-link.code_alphabet'), '');
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

    public function maxAttemptsPerToken(): int
    {
        return $this->int($this->config->get('email-magic-link.max_attempts_per_token'), 0);
    }

    public function entropySafetyFactor(): int
    {
        return $this->int($this->config->get('email-magic-link.entropy_safety_factor'), 1_000_000);
    }

    public function guard(): string
    {
        $guard = $this->config->get('email-magic-link.guard');

        if (is_string($guard) && $guard !== '') {
            return $guard;
        }

        return $this->string($this->config->get('auth.defaults.guard'), 'web');
    }

    /**
     * The guards a request may sign in to: the default plus any in "guards".
     *
     * @return list<string>
     */
    public function allowedGuards(): array
    {
        $list = [$this->guard()];

        $guards = $this->config->get('email-magic-link.guards');

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
        $provider = $this->config->get("auth.guards.{$guard}.provider");

        return is_string($provider) ? $provider : null;
    }

    public function userLookup(): ?string
    {
        $lookup = $this->config->get('email-magic-link.user_lookup');

        return is_string($lookup) && $lookup !== '' ? $lookup : null;
    }

    public function tokenStore(): ?string
    {
        $store = $this->config->get('email-magic-link.token_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    public function captcha(): ?string
    {
        $captcha = $this->config->get('email-magic-link.captcha');

        return is_string($captcha) && $captcha !== '' ? $captcha : null;
    }

    /**
     * @return class-string<MagicLinkNotification>
     */
    public function notification(): string
    {
        $notification = $this->config->get('email-magic-link.notification');

        if (is_string($notification) && is_a($notification, MagicLinkNotification::class, true)) {
            return $notification;
        }

        return MagicLinkNotification::class;
    }

    public function routePrefix(): string
    {
        return $this->string($this->config->get('email-magic-link.routes.prefix'), '');
    }

    /**
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        $middleware = $this->config->get('email-magic-link.routes.middleware');

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
        return $this->string($this->config->get('email-magic-link.routes.redirect_to'), '/');
    }

    public function redirectToIntended(): bool
    {
        return $this->bool($this->config->get('email-magic-link.routes.intended'), true);
    }

    public function apiEnabled(): bool
    {
        return $this->bool($this->config->get('email-magic-link.api.enabled'), false);
    }

    /**
     * @return 'auto'|'blade'
     */
    public function uiMode(): string
    {
        return $this->string($this->config->get('email-magic-link.ui.mode'), 'auto') === 'blade'
            ? 'blade'
            : 'auto';
    }

    public function usesWireKit(): bool
    {
        return $this->uiMode() === 'auto' && WireKit::installed();
    }

    /**
     * Resolve a view to its WireKit variant when WireKit is active and that
     * variant exists, else the plain Blade view. The existence check keeps the
     * sign-in UI from breaking if a WireKit view was never published or removed.
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
        $mode = $this->config->get('email-magic-link.fortify.mode', 'auto');

        if (is_bool($mode)) {
            return $mode;
        }

        return match ($mode) {
            'false' => false,
            'true' => true,
            default => 'auto',
        };
    }

    public function respectTwoFactor(): bool
    {
        return $this->bool($this->config->get('email-magic-link.fortify.respect_two_factor'), true);
    }

    public function challengeRoute(): string
    {
        return $this->string($this->config->get('email-magic-link.fortify.challenge_route'), 'two-factor.login');
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
        $fortifyGuard = $this->string($this->config->get('fortify.guard'), '');

        if ($fortifyGuard === '') {
            $fortifyGuard = $this->string($this->config->get('auth.defaults.guard'), 'web');
        }

        $guardProvider = $this->config->get("auth.guards.{$guard}.provider");
        $fortifyProvider = $this->config->get("auth.guards.{$fortifyGuard}.provider");

        return is_string($guardProvider) && is_string($fortifyProvider) && $guardProvider === $fortifyProvider;
    }

    public function requestLimiter(): string
    {
        return $this->string($this->config->get('email-magic-link.limiters.request'), 'email-magic-link:request');
    }

    public function consumeLimiter(): string
    {
        return $this->string($this->config->get('email-magic-link.limiters.consume'), 'email-magic-link:consume');
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
     * @return array{max: int, per_minutes: int}
     */
    private function readLimit(string $key, int $defaultMax): array
    {
        $limit = $this->config->get("email-magic-link.limits.{$key}");
        $limit = is_array($limit) ? $limit : [];

        return [
            'max' => max(1, $this->int($limit['max'] ?? null, $defaultMax)),
            'per_minutes' => max(1, $this->int($limit['per_minutes'] ?? null, 1)),
        ];
    }

    private function string(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

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

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
