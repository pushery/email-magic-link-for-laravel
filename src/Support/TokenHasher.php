<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Keyed hashing for token secrets.
 *
 * The application key is used as the HMAC key so a leaked database alone cannot
 * be used to forge or recognize tokens. The raw secret is never stored.
 */
final readonly class TokenHasher
{
    public function __construct(private string $key) {}

    public function hash(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->key);
    }

    public function matches(string $plaintext, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($plaintext));
    }
}
