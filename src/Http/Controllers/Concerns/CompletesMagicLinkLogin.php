<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Contracts\ResendGuard;
use EmailMagicLink\Events\MagicLinkConsumptionFailed;
use EmailMagicLink\Events\MagicLinkVerified;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\ResendKey;
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

        // A verified token proves the address reached its owner, so clear the
        // resend cooldown for it — covers both the direct and the two-factor
        // handoff path, which both flow through here after the atomic claim.
        $this->resetResendCooldown($user);

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

    /**
     * Clear the resend cooldown keyed on the verified user's current email.
     *
     * The token stores only a user id (never the address it was issued to, by
     * design), so this resets by the user's present email. In the ordinary case
     * that is the address the cooldown was armed on; if the user changed it
     * between requesting and verifying, the old key simply ages out on its own —
     * a harmless miss, never an error, and it clears no one else's state.
     */
    protected function resetResendCooldown(Authenticatable $user): void
    {
        $email = data_get($user, 'email');

        if (is_string($email) && $email !== '') {
            app(ResendGuard::class)->reset(ResendKey::forRequest($email));
        }
    }

    protected function failedConsumption(Request $request, string $failureRoute, ClaimFailure $reason): Response
    {
        event(new MagicLinkConsumptionFailed($reason, $request));

        $message = (string) __('email-magic-link::messages.consume_failed');
        $config = app(MagicLinkConfig::class);

        // A client that negotiated JSON always gets the stable envelope; its
        // shape is a fixed contract independent of the browser strategy below.
        if ($this->wantsJson($request)) {
            return $this->apiError($message, $config->invalidResponseErrorCode(), 422);
        }

        // The browser response is host-configurable (view/redirect/abort/json,
        // or a custom class bound in the service provider) but never varies by
        // the failure reason, so it stays enumeration-resistant.
        return app(InvalidLinkResponder::class)->respond($request, $message, $failureRoute);
    }
}
