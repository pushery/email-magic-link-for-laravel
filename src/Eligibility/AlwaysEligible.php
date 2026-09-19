<?php

declare(strict_types=1);

namespace EmailMagicLink\Eligibility;

use EmailMagicLink\Contracts\SignInEligibility;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default: every account that exists may sign in.
 *
 * Deliberately not a guess. The package cannot know which column on a host's
 * user model means "suspended", and a default that inspected one would either
 * find nothing and lull, or find the wrong thing and lock people out of an
 * application that never asked for the feature. Allowing is the only answer
 * that is correct for an install which has not configured anything.
 */
final class AlwaysEligible implements SignInEligibility
{
    public function allows(Authenticatable $user, string $guard): bool
    {
        return true;
    }
}
