<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what happens once a token has been verified and atomically consumed.
 *
 * This is the package's single flow-control seam. The default binding logs the
 * user in; the Fortify bridge rebinds it to hand a two-factor user off to the
 * TOTP challenge. Rebind it in the container to take over post-verification
 * flow entirely.
 */
interface MagicLinkAuthenticator
{
    /**
     * Called after a token has been successfully verified and consumed.
     *
     * Implementations either log the user in and return a redirect/JSON
     * response, or hand off to a second factor. They must return a response;
     * they must not rely on events to redirect.
     */
    public function authenticate(Request $request, Authenticatable $user, bool $remember): Response;
}
