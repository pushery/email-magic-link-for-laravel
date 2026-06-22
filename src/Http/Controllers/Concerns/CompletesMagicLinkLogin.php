<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\MagicLinkConsumptionFailed;
use EmailMagicLink\Events\MagicLinkVerified;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\ClaimFailure;
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
            return $this->failedConsumption($request, $failureRoute, ClaimFailure::NotFound);
        }

        event(new MagicLinkVerified($user, $request));

        return app(MagicLinkAuthenticator::class)->authenticate($request, $user, $token->guard, false);
    }

    protected function resolveUser(MagicLinkToken $token): ?Authenticatable
    {
        $providerName = config("auth.guards.{$token->guard}.provider");

        return app(AuthManager::class)
            ->createUserProvider(is_string($providerName) ? $providerName : null)
            ?->retrieveById($token->user_id);
    }

    protected function failedConsumption(Request $request, string $failureRoute, ClaimFailure $reason): Response
    {
        event(new MagicLinkConsumptionFailed($reason, $request));

        $message = __('email-magic-link::messages.consume_failed');

        if ($this->wantsJson($request)) {
            return $this->apiError($message, 'invalid_or_expired', 422);
        }

        // Flash the email and guard (never the secret code) so the code form can
        // re-prefill them on retry without putting them in the redirect URL, which
        // keeps the failure response shape identical and enumeration-resistant.
        return redirect()->route($failureRoute)
            ->withErrors(['email' => $message])
            ->withInput($request->except('code'));
    }
}
