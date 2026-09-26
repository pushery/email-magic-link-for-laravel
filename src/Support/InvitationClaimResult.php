<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\Invitation;

/**
 * Outcome of a peek or an atomic claim: either the invitation, or the reason it failed.
 *
 * Reuses ClaimFailure rather than introducing a parallel enum. An invitation fails for four
 * reasons: unknown, expired and already spent, which a sign-in link shares, and revoked,
 * which only an invitation has -- withdrawn through revoke(), superseded by a newer
 * invitation to the same address, or issued on a guard the operator has since closed. A
 * caller that handles the sign-in failures still has to handle `Revoked`.
 */
final readonly class InvitationClaimResult
{
    private function __construct(
        public bool $successful,
        public ?Invitation $invitation,
        public ?ClaimFailure $failure,
    ) {}

    public static function success(Invitation $invitation): self
    {
        return new self(true, $invitation, null);
    }

    public static function failed(ClaimFailure $failure): self
    {
        return new self(false, null, $failure);
    }
}
