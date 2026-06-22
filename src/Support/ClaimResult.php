<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\MagicLinkToken;

/**
 * Outcome of an atomic claim: either the consumed token, or the reason it failed.
 */
final readonly class ClaimResult
{
    private function __construct(
        public bool $successful,
        public ?MagicLinkToken $token,
        public ?ClaimFailure $failure,
    ) {}

    public static function success(MagicLinkToken $token): self
    {
        return new self(true, $token, null);
    }

    public static function failed(ClaimFailure $failure): self
    {
        return new self(false, null, $failure);
    }
}
