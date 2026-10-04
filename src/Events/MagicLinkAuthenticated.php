<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\LeavesTheRequestBehind;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired the moment a user is actually logged in via a magic link or code.
 *
 * Unlike MagicLinkVerified (which fires for every verified token, including one
 * being handed off to a two-factor challenge), this fires only after the login
 * has completed on the guard, so it never records a sign-in that did not happen.
 * A two-factor user's sign-in completes inside Fortify instead, which fires
 * Laravel's Login event but not this one; an audit log that should cover those
 * sign-ins too listens for TwoFactorChallengeRequired and Login as well. The guard
 * the user was signed in to, and the request (for IP and user agent), are
 * carried for logging and alerting.
 */
final readonly class MagicLinkAuthenticated
{
    use LeavesTheRequestBehind;

    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public ?Request $request,
    ) {}
}
