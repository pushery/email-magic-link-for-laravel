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

    /**
     * Whether the claim succeeded, stated so the type system knows what that implies.
     *
     * The constructor is private and there are exactly two ways in, so `successful` and
     * `token` are two views of one fact: success always carries a token, failure never
     * does and always carries a reason. Callers could not express that, and so wrote it
     * out again as a second check on every branch -- a condition no input can reach, which
     * is exactly the shape that leaves a mutant alive with no test able to kill it.
     *
     * @phpstan-assert-if-true !null $this->token
     *
     * @phpstan-assert-if-false !null $this->failure
     */
    public function succeeded(): bool
    {
        return $this->successful;
    }
}
