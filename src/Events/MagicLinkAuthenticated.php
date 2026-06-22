<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired the moment a user is actually logged in via a magic link or code.
 *
 * Unlike MagicLinkVerified (which fires for every verified token, including one
 * being handed off to a two-factor challenge), this fires only after the login
 * has completed on the guard — the precise signal an audit log wants. The guard
 * the user was signed in to, and the request (for IP and user agent), are
 * carried for logging and alerting.
 */
final readonly class MagicLinkAuthenticated
{
    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public Request $request,
    ) {}
}
