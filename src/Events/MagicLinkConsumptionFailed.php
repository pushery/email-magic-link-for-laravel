<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\ClaimFailure;
use Illuminate\Http\Request;

/**
 * Fired when a magic link or code consumption fails.
 *
 * The reason distinguishes a stale or unknown token from a wrong code or a
 * brute-force lockout (ClaimFailure::LockedOut), so a host can log every failure
 * and alert specifically on repeated invalid codes or lockouts. The response the
 * caller sees stays generic and enumeration-resistant regardless of the reason.
 */
final readonly class MagicLinkConsumptionFailed
{
    public function __construct(
        public ClaimFailure $reason,
        public Request $request,
    ) {}
}
