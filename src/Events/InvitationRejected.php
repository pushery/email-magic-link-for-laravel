<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\ClaimFailure;
use Illuminate\Http\Request;

/**
 * An invitation link was refused, with the reason the visitor never sees.
 *
 * Its own type rather than MagicLinkConsumptionFailed, and that distinction is
 * operational: a link-following scanner touching an invitation URL is routine, and
 * folding it into the sign-in failure event would fire whatever alerting sits on
 * that one. These are different things happening to different people.
 */
final readonly class InvitationRejected
{
    public function __construct(
        public ClaimFailure $reason,
        public Request $request,
    ) {}
}
