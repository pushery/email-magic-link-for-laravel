<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\MagicLinkToken;

/**
 * A freshly issued token: the plaintext secret to deliver, plus its stored row.
 *
 * The plaintext exists only in memory for the lifetime of the request that
 * issues it. Only the keyed hash on the record is ever persisted.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $plaintext,
        public MagicLinkToken $record,
    ) {}
}
