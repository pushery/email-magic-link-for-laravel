<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\InvitationIssuer;
use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Exceptions\InvitationsDisabledException;
use EmailMagicLink\Exceptions\InvitationsMisconfiguredException;
use EmailMagicLink\Exceptions\UnknownGuardException;
use Illuminate\Contracts\Routing\UrlGenerator;
use InvalidArgumentException;

/**
 * Mints invitations and the signed URL that carries them.
 */
final readonly class DefaultInvitationIssuer implements InvitationIssuer
{
    private const string ROUTE = 'email-magic-link.invitation.show';

    public function __construct(
        private InvitationStore $store,
        private MagicLinkConfig $config,
    ) {}

    public function invite(string $email, ?string $guard = null, ?array $context = null, ?string $invitedBy = null, ?string $baseUrl = null): IssuedInvitation
    {
        $resolvedGuard = $this->prepare($guard);

        // Before anything is written. The link is built after the row exists, and writing the
        // row revokes the invitation the address held until now, so without the route the
        // address would lose its live invitation and never receive the new one. The URL
        // generator is asked, the way the link itself is built, so the answer cannot differ
        // from the one the link would get. A missing route is the InvalidArgumentException its
        // contract declares.
        try {
            app(UrlGenerator::class)->route(self::ROUTE, ['token' => 'route-check'], false);
        } catch (InvalidArgumentException $e) {
            throw InvitationsMisconfiguredException::routeMissing(self::ROUTE, $e);
        }

        $issued = $this->store->issue($email, $resolvedGuard, $context, $invitedBy);

        return new IssuedInvitation(
            SignedTokenUrl::for(self::ROUTE, $issued->record->expires_at, $issued->plaintext, $baseUrl),
            $issued->record->email,
            $resolvedGuard,
            $issued->record->expires_at,
            (int) ceil($this->config->invitationTtl() / 60),
        );
    }

    public function revoke(string $email, ?string $guard = null): int
    {
        // Not narrowed to the guards that are open today. Revoking closes something and
        // opens nothing, and after an operator closes a guard, its outstanding invitations
        // are exactly the ones that have to stay withdrawable.
        $this->assertEnabled();

        return $this->store->revoke($email, $guard ?? $this->config->guard());
    }

    /**
     * Validate the request and return the guard to issue against. Nothing is
     * persisted until every check here has passed, so a rejected request mints
     * no row.
     *
     * Note what is deliberately ABSENT compared to the sign-in issuer: there is no
     * assertUserBelongsToGuard(). That check re-resolves the user through the
     * guard's provider, and requiring it is precisely what makes an invitation to
     * somebody without an account impossible — the gap this whole feature exists to
     * close. The guard is still narrowed to the configured allow-list, so an
     * invitation can never be minted against a guard the host has not opened up.
     */
    private function prepare(?string $guard): string
    {
        $this->assertEnabled();

        if ($guard === null) {
            return $this->config->guard();
        }

        $allowed = $this->config->allowedGuards();

        if (! in_array($guard, $allowed, true)) {
            throw UnknownGuardException::for($guard, $allowed);
        }

        return $guard;
    }

    private function assertEnabled(): void
    {
        if (! $this->config->enabled()) {
            throw InvitationsDisabledException::channelOff();
        }

        if (! $this->config->invitationsEnabled()) {
            throw InvitationsDisabledException::make();
        }
    }
}
