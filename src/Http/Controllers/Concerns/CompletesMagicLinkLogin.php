<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\MagicLinkVerified;
use EmailMagicLink\Models\MagicLinkToken;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared post-verification flow for the consume endpoints.
 *
 * The verified user is loaded through the same guard provider that issued the
 * token, the observability event is fired, and the swappable authenticator
 * decides what happens next (log in, or hand off to a second factor).
 */
trait CompletesMagicLinkLogin
{
    use RespondsToApiClients;

    protected function completeLogin(Request $request, MagicLinkToken $token, string $failureRoute): Response
    {
        $user = $this->resolveUser($token);

        if ($user === null) {
            return $this->failedConsumption($request, $failureRoute);
        }

        event(new MagicLinkVerified($user, $request));

        return app(MagicLinkAuthenticator::class)->authenticate($request, $user, false);
    }

    protected function resolveUser(MagicLinkToken $token): ?Authenticatable
    {
        $providerName = config("auth.guards.{$token->guard}.provider");

        return app(AuthManager::class)
            ->createUserProvider(is_string($providerName) ? $providerName : null)
            ?->retrieveById($token->user_id);
    }

    protected function failedConsumption(Request $request, string $failureRoute): Response
    {
        $message = 'This sign-in request is invalid or has expired. Please request a new one.';

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->route($failureRoute)->withErrors(['email' => $message]);
    }
}
