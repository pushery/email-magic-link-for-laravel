<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired by the Fortify bridge when a verified user with confirmed TOTP is being
 * handed off to the two-factor challenge instead of being logged in directly.
 *
 * Observability only: the user is not yet authenticated when this fires.
 */
final readonly class TwoFactorChallengeRequired
{
    public function __construct(
        public Authenticatable $user,
        public Request $request,
    ) {}
}
