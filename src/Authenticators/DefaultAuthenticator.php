<?php

declare(strict_types=1);

namespace EmailMagicLink\Authenticators;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\MagicLinkAuthenticated;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standalone post-verification flow: log the user into the stateful guard and
 * redirect (or return JSON for API clients).
 *
 * There is no second factor here by design. When Fortify is installed and a
 * user has confirmed TOTP, the bridge wraps this authenticator and intercepts
 * first.
 */
final readonly class DefaultAuthenticator implements MagicLinkAuthenticator
{
    public function __construct(
        private AuthManager $auth,
        private Redirector $redirector,
        private MagicLinkConfig $config,
    ) {}

    public function authenticate(Request $request, Authenticatable $user, string $guard, bool $remember): Response
    {
        $stateful = $this->auth->guard($guard);

        if (! $stateful instanceof StatefulGuard) {
            throw new RuntimeException(
                "The [{$guard}] guard is not stateful and cannot be used for magic-link login.",
            );
        }

        $stateful->login($user, $remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        event(new MagicLinkAuthenticated($user, $guard, $request));

        $redirect = $this->config->redirectToIntended()
            ? $this->redirector->intended($this->config->redirectTo())
            : $this->redirector->to($this->config->redirectTo());

        if ($request->expectsJson() && $this->config->apiEnabled()) {
            return new JsonResponse([
                'authenticated' => true,
                'two_factor' => false,
                'redirect' => $redirect->getTargetUrl(),
            ]);
        }

        return $redirect;
    }
}
