<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\MagicLinkIssuer;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Exceptions\MagicLinkDisabledException;
use EmailMagicLink\Exceptions\UnknownGuardException;
use EmailMagicLink\Exceptions\UserNotInGuardException;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default issuer: mints links and codes through the same hashed-at-rest token
 * store and signed-route construction the bundled email flow uses, so a minted
 * credential is indistinguishable from an emailed one — only nothing is sent.
 */
final readonly class DefaultMagicLinkIssuer implements MagicLinkIssuer
{
    public function __construct(
        private TokenStore $store,
        private MagicLinkConfig $config,
        private AuthManager $auth,
    ) {}

    public function issueLink(Authenticatable $user, ?string $guard = null): IssuedLink
    {
        $resolvedGuard = $this->prepare($user, $guard);

        $issued = $this->store->issue($user, $resolvedGuard, 'link');

        return new IssuedLink(
            ConfirmationUrl::for($issued->record, $issued->plaintext),
            $issued->record->expires_at,
            $this->minutesFor('link'),
        );
    }

    public function issueCode(Authenticatable $user, ?string $guard = null): IssuedCode
    {
        $resolvedGuard = $this->prepare($user, $guard);

        $issued = $this->store->issue($user, $resolvedGuard, 'code');

        return new IssuedCode($issued->plaintext, $issued->record->expires_at, $this->minutesFor('code'));
    }

    /**
     * Validate the request and return the guard to issue against. Nothing is
     * persisted until every check here has passed, so a rejected request mints
     * no row.
     */
    private function prepare(Authenticatable $user, ?string $guard): string
    {
        if (! $this->config->enabled()) {
            throw MagicLinkDisabledException::make();
        }

        $resolvedGuard = $this->resolveGuardOrFail($guard);

        $this->assertUserBelongsToGuard($user, $resolvedGuard);

        return $resolvedGuard;
    }

    private function resolveGuardOrFail(?string $guard): string
    {
        if ($guard === null) {
            return $this->config->guard();
        }

        $allowed = $this->config->allowedGuards();

        if (! in_array($guard, $allowed, true)) {
            throw UnknownGuardException::for($guard, $allowed);
        }

        return $guard;
    }

    /**
     * Re-resolve the user through the guard's own provider — the exact path the
     * consume flow uses — so the minted row's (user_id, guard) can never point at
     * a different principal than the caller intended.
     */
    private function assertUserBelongsToGuard(Authenticatable $user, string $guard): void
    {
        $resolved = $this->auth
            ->createUserProvider($this->config->providerForGuard($guard))
            ?->retrieveById($user->getAuthIdentifier());

        if (! $resolved instanceof Authenticatable || $resolved::class !== $user::class) {
            throw UserNotInGuardException::for($guard);
        }
    }

    /**
     * @param  'link'|'code'  $channel
     */
    private function minutesFor(string $channel): int
    {
        return (int) ceil($this->config->ttlFor($channel) / 60);
    }
}
