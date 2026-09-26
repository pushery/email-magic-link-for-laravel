<?php

declare(strict_types=1);

namespace EmailMagicLink\Lookups;

use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Support\AccountAddress;
use EmailMagicLink\Support\NormalizedEmail;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wraps the resolved UserLookup so an account resolves only for its own address.
 *
 * A lookup matches an address the way its database compares strings, and that can be
 * looser than the address. MySQL's default collations fold accents, so a lookup asked
 * for `victim@exämple.com` answers with the account stored as `victim@example.com`.
 * The typed string is a different mailbox on a domain anyone can register, and the code
 * screen looks the account up again by what is typed there.
 *
 * So the stored address and the one asked for are both normalized the way every key in
 * this package is, and they have to be byte-for-byte equal. Anything else resolves to
 * null, the answer an unknown address gets, in the same shape. An account with no
 * readable `email` attribute cannot be checked and is refused the same way.
 *
 * It sits on the binding, inside the eligibility gate, for the reason that gate gives:
 * every caller already branches on null, and a host that binds its own lookup is
 * covered instead of bypassing the check.
 */
final readonly class AddressMatchingUserLookup implements UserLookup
{
    public function __construct(
        private UserLookup $inner,
    ) {}

    public function findByEmail(string $email, string $guard): ?Authenticatable
    {
        $user = $this->inner->findByEmail($email, $guard);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $stored = AccountAddress::of($user);

        return $stored !== null && hash_equals(NormalizedEmail::from($stored), NormalizedEmail::from($email))
            ? $user
            : null;
    }
}
