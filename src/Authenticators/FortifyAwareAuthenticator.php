<?php

declare(strict_types=1);

namespace EmailMagicLink\Authenticators;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\TwoFactorChallengeRequired;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's only Fortify-internal coupling.
 *
 * When the verified user has confirmed TOTP, the user is handed off to
 * Fortify's own challenge in a NOT-yet-authenticated state: the login.id /
 * login.remember session keys are set and the request is redirected to the
 * challenge route. The actual login happens inside Fortify, only after the code
 * passes. Calling the guard's login() before the redirect would defeat the
 * guest middleware on the challenge route and bypass the second factor, so this
 * decorator must never do so.
 *
 * Users without confirmed two-factor fall through to the wrapped default
 * authenticator and are logged in directly.
 */
final readonly class FortifyAwareAuthenticator implements MagicLinkAuthenticator
{
    public function __construct(
        private MagicLinkAuthenticator $default,
        private MagicLinkConfig $config,
    ) {}

    public function authenticate(Request $request, Authenticatable $user, bool $remember): Response
    {
        if (! $this->config->respectTwoFactor() || ! $this->hasConfirmedTwoFactor($user)) {
            return $this->default->authenticate($request, $user, $remember);
        }

        // Hand off as a guest. Do not log in here: Fortify completes the login
        // after the TOTP code is verified.
        $request->session()->put([
            'login.id' => $user->getAuthIdentifier(),
            'login.remember' => $remember,
        ]);

        event(new TwoFactorChallengeRequired($user, $request));

        return redirect()->route($this->config->challengeRoute());
    }

    private function hasConfirmedTwoFactor(Authenticatable $user): bool
    {
        // Gate on confirmation, not mere secret presence, so a user mid-setup
        // is not locked out of their own magic-link login.
        return data_get($user, 'two_factor_confirmed_at') !== null;
    }
}
