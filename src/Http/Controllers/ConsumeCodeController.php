<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Http\Controllers\Concerns\CompletesMagicLinkLogin;
use EmailMagicLink\Http\Requests\ConsumeCodeRequest;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\GuardSelection;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumes a one-time code (email + code).
 *
 * The user is resolved by email, then the code is verified and atomically
 * claimed with per-token attempt accounting and lockout. Every failure
 * collapses to one generic message so neither account existence nor "wrong
 * code versus no code" leaks.
 */
final class ConsumeCodeController
{
    use CompletesMagicLinkLogin;

    public function __invoke(
        ConsumeCodeRequest $request,
        UserLookup $lookup,
        TokenStore $store,
        GuardSelection $guards,
    ): Response {
        $guard = $guards->guardFor($request, $request->requestedGuard());
        $user = $lookup->findByEmail($request->email(), $guard);

        if (! $user instanceof Authenticatable) {
            return $this->failedConsumption($request, 'email-magic-link.code.form', ClaimFailure::NotFound);
        }

        $result = $store->claimCode($user, $request->code(), $guard);
        $credential = 'code|'.$guard.'|'.mb_strtolower($request->email()).'|'.$request->code();
        if (! $result->succeeded()) {
            // A spent code reads as no code at all, so the second request of a double click is found by its note.
            return $this->repeatedLogin($request, $credential)
                ?? $this->failedConsumption($request, 'email-magic-link.code.form', $result->failure);
        }

        return $this->completeLogin($request, $result->token, 'email-magic-link.code.form', $credential);
    }
}
