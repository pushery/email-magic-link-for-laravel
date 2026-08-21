<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\Invitation;

/**
 * Outcome of a peek or an atomic claim: either the invitation, or the reason it failed.
 *
 * Reuses ClaimFailure rather than introducing a parallel enum. The reasons are the same
 * three an invitation can have -- unknown, expired, already spent -- and a caller that
 * already handles the sign-in failures handles these unchanged.
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
