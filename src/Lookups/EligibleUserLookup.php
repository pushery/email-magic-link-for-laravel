<?php

declare(strict_types=1);

namespace EmailMagicLink\Lookups;

use EmailMagicLink\Contracts\SignInEligibility;
use EmailMagicLink\Contracts\UserLookup;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wraps the resolved UserLookup so an account that may not sign in resolves to
 * null -- the same answer, in the same shape, as an address nobody has ever
 * registered.
 *
 * The gate sits on the binding rather than in the controllers that call it. Two
 * endpoints resolve a user by address today and both already branch on null, so
 * neither has to learn about eligibility; a third added later inherits the gate
 * instead of forgetting it, and a host that binds its own UserLookup is covered
 * too rather than quietly bypassing it.
 *
 * Null rather than an exception, because the issuing endpoint's whole design is
 * to answer identically whether or not an address resolves, down to the timing.
 * A distinguishable refusal would turn the suspension into an oracle: send a
 * link, read the difference, and learn both that the account exists and that it
 * is suspended. Null puts a refused account on exactly the path an unknown one
 * takes, which is the only shape that leaks nothing.
 *
 * This closes the issuing half only. A token issued before the account was
 * refused is a credential that already left the building, and no lookup runs
 * when it comes back -- redemption resolves the user from the token's id. That
 * half is closed in CompletesMagicLinkLogin.
 */
final readonly class EligibleUserLookup implements UserLookup
{
    public function __construct(
        private UserLookup $inner,
        private SignInEligibility $eligibility,
    ) {}

    public function findByEmail(string $email, string $guard): ?Authenticatable
    {
        $user = $this->inner->findByEmail($email, $guard);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return $this->eligibility->allows($user, $guard) ? $user : null;
    }
}
