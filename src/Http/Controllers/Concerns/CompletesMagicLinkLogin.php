<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Contracts\ResendGuard;
use EmailMagicLink\Contracts\SignInEligibility;
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
    use RejectsGenerically;

    protected function completeLogin(Request $request, MagicLinkToken $token, string $failureRoute): Response
    {
        // The guard was validated when the token was issued; re-checked here so an
        // operator who removes a guard from the allowlist closes the door for links
        // already in flight too. The row is spent either way, which is the fail-closed
        // direction.
        if (! in_array($token->guard, app(MagicLinkConfig::class)->allowedGuards(), true)) {
            return $this->failedConsumption($request, $failureRoute, ClaimFailure::NotFound);
        }

        $user = $this->resolveUser($token);

        if ($user === null) {
            return $this->failedConsumption($request, $failureRoute, ClaimFailure::NotFound);
        }

        // The half of the eligibility gate that only this path can close. Issuance is
        // gated by EligibleUserLookup, but a link handed out a minute before the
        // account was refused is a credential that already left the building, and
        // nothing looks the address up on the way back -- redemption resolves the user
        // from the token's id. The row is spent either way, which is the fail-closed
        // direction, same as the guard check above.
        //
        // Deliberately BEFORE the cooldown reset and the verified event. Announcing a
        // verification that yields no session would have hosts acting on a sign-in that
        // did not happen, and clearing the cooldown is observable: the next request for
        // this address would skip a throttle it is otherwise subject to, which is the
        // difference an enumerating caller is looking for.
        if (! app(SignInEligibility::class)->allows($user, $token->guard)) {
            return $this->failedConsumption($request, $failureRoute, ClaimFailure::Ineligible);
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
        return app(AuthManager::class)
            ->createUserProvider(app(MagicLinkConfig::class)->providerForGuard($token->guard))
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

        // The response itself lives in RejectsGenerically, shared with the invitation
        // flow. Two copies of "refuse without saying why" is one copy too many: the
        // moment they can drift, the difference between them is the answer.
        return $this->genericRejection($request, (string) __('email-magic-link::messages.consume_failed'), $failureRoute);
    }
}
