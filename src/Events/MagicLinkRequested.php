<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired when a link or code has been issued for a resolved user.
 *
 * Observability only: never dispatched for an unknown email, so it must not be
 * used to decide whether an account exists in a response.
 */
final readonly class MagicLinkRequested
{
    public function __construct(
        public Authenticatable $user,
        public string $channel,
        public Request $request,
    ) {}
}
