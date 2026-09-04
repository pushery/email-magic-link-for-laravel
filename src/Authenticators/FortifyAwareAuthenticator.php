<?php

declare(strict_types=1);

namespace EmailMagicLink\Authenticators;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\TwoFactorChallengeRequired;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's only Fortify-internal coupling.
 *
 * When the verified user has two-factor authentication enabled by Fortify's own
 * verdict, the user is handed off to
 * Fortify's own challenge in a NOT-yet-authenticated state: the login.id /
 * login.remember session keys are set and the request is redirected to the
 * challenge route. The actual login happens inside Fortify, only after the code
 * passes. Calling the guard's login() before the redirect would defeat the
 * guest middleware on the challenge route and bypass the second factor, so this
 * decorator must never do so.
 *
 * Users without an enabled second factor fall through to the wrapped default
 * authenticator and are logged in directly.
 */
final readonly class FortifyAwareAuthenticator implements MagicLinkAuthenticator
{
    public function __construct(
        private MagicLinkAuthenticator $default,
        private MagicLinkConfig $config,
    ) {}

    public function authenticate(Request $request, Authenticatable $user, string $guard, bool $remember): Response
    {
        if (! $this->config->respectTwoFactor() || ! $this->hasEnabledTwoFactor($user)) {
            return $this->default->authenticate($request, $user, $guard, $remember);
        }

        // Fortify challenges and logs in on its own guard's provider, not the
        // token's guard. If they differ, the handoff would resolve the wrong user
        // (or none), so fail closed rather than bypass the second factor.
        if (! $this->config->guardSharesFortifyProvider($guard)) {
            throw new RuntimeException(
                "Two-factor sign-in for the [{$guard}] guard cannot complete: its user provider must match fortify.guard. Align the providers, or do not expose this guard to two-factor users.",
            );
        }

        // The handoff stores Fortify's login.id in the session; without one it
        // cannot complete, so fail closed rather than crash or bypass the factor.
        if (! $request->hasSession()) {
            throw new RuntimeException(
                'The two-factor handoff requires a session and cannot complete for a sessionless request.',
            );
        }

        // Hand off as a guest. Do not log in here: Fortify completes the login
        // after the TOTP code is verified.
        $request->session()->put([
            'login.id' => $user->getAuthIdentifier(),
            'login.remember' => $remember,
        ]);

        event(new TwoFactorChallengeRequired($user, $request));

        $redirect = redirect()->route($this->config->challengeRoute());

        // Tell an API client it must complete the second factor and where to go,
        // rather than handing it a bare redirect it cannot follow.
        if ($request->expectsJson() && $this->config->apiEnabled()) {
            return new JsonResponse([
                'authenticated' => false,
                'two_factor' => true,
                'redirect' => $redirect->getTargetUrl(),
            ]);
        }

        return $redirect;
    }

    private function hasEnabledTwoFactor(Authenticatable $user): bool
    {
        // Fortify's own verdict, so the magic link challenges exactly the users the
        // password login challenges. With `confirm` on, that is a secret AND a
        // confirmed_at, which keeps a user mid-setup out of the challenge; with
        // `confirm` off -- Fortify's shipped feature registration -- it is a secret
        // alone, and confirmed_at is never written. Re-deriving the verdict from
        // confirmed_at let an enabled second factor through on every host that had
        // never turned confirmation on.
        if (method_exists($user, 'hasEnabledTwoFactorAuthentication')) {
            return (bool) $user->hasEnabledTwoFactorAuthentication();
        }

        return data_get($user, 'two_factor_confirmed_at') !== null;
    }
}
