<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\Invitation;

/**
 * A freshly issued invitation, as the store hands it back: the plaintext secret
 * and the row that carries only its hash.
 *
 * Internal. The public Mint surface is IssuedInvitation, which deliberately does
 * NOT expose the plaintext -- it exposes the URL that already contains it.
 */
final readonly class IssuedInvitationToken
{
    public function __construct(
        public string $plaintext,
        public Invitation $record,
    ) {}
}
