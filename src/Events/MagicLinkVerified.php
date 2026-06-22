<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired after a token has been verified and atomically consumed, before the
 * authenticator runs.
 *
 * Fire-and-forget observability. It must never be used to control the
 * login-versus-two-factor decision: a listener cannot reliably interrupt and
 * redirect the response. Flow control lives in the MagicLinkAuthenticator.
 */
final readonly class MagicLinkVerified
{
    public function __construct(
        public Authenticatable $user,
        public Request $request,
    ) {}
}
