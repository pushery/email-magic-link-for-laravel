<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Outcome of a resend-guard check: allowed, or denied with the seconds until
 * the next attempt would pass and the limit that was hit.
 */
final readonly class ResendDecision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
        public ?ResendDenialReason $reason,
    ) {}

    public static function allowed(): self
    {
        return new self(true, 0, null);
    }

    public static function denied(ResendDenialReason $reason, int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds), $reason);
    }
}
