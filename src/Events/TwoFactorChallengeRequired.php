<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\LeavesTheRequestBehind;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired by the Fortify bridge when a verified user Fortify considers
 * two-factor-enabled is handed off to the two-factor challenge instead of being
 * logged in directly.
 *
 * Observability only: the user is not yet authenticated when this fires.
 */
final readonly class TwoFactorChallengeRequired
{
    use LeavesTheRequestBehind;

    public function __construct(
        public Authenticatable $user,
        public ?Request $request,
    ) {}
}
