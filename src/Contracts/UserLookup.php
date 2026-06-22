<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the authenticatable a magic link should be issued for.
 *
 * Implementations must perform a constant-shape lookup: returning null for an
 * unknown email is expected and must not differ observably (in timing or
 * exceptions) from a hit, so the request endpoint stays enumeration-resistant.
 */
interface UserLookup
{
    public function findByEmail(string $email, string $guard): ?Authenticatable;
}
