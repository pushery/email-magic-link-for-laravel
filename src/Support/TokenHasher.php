<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Keyed hashing for token secrets.
 *
 * The application key is used as the HMAC key so a leaked database alone cannot
 * be used to forge or recognize tokens. The raw secret is never stored.
 *
 * Retired keys are carried alongside the current one, because the hash is what FINDS a
 * row. Laravel supports rotating `APP_KEY` gently -- the new key signs, the listed
 * previous ones still verify -- and this package's link signatures already honor that
 * list. Its hashes did not, so a rotation quietly orphaned every live token: a
 * fifteen-minute sign-in link barely notices, but an invitation lives seven days by
 * default, and every open one came back as the same generic refusal an unknown token
 * gets. Nothing told the operator, and nothing told the invited person.
 */
final readonly class TokenHasher
{
    /**
     * @var list<string>
     */
    private array $keys;

    /**
     * @param  list<string>  $previousKeys  Retired keys, newest first, exactly as
     *                                      `app.previous_keys` holds them. They verify;
     *                                      they never sign.
     */
    public function __construct(string $key, array $previousKeys = [])
    {
        // Deduplicated so a host that leaves the current key in its previous list does
        // not pay for the same lookup twice, and empty entries are dropped rather than
        // producing a hash of the empty key that some other row might match.
        $this->keys = array_values(array_unique(array_filter([$key, ...$previousKeys], static fn (string $candidate): bool => $candidate !== '')));
    }

    public function hash(string $plaintext): string
    {
        return $this->hashWith($plaintext, $this->keys[0] ?? '');
    }

    /**
     * Every hash this plaintext could be stored under, current key first.
     *
     * A lookup takes this list; a write takes hash(). That asymmetry is the whole
     * feature: rows written before a rotation stay findable, rows written after it are
     * written under the new key alone, and the old key stops mattering as soon as the
     * host drops it from the list.
     *
     * @return list<string>
     */
    public function candidates(string $plaintext): array
    {
        if (count($this->keys) < 2) {
            return [$this->hash($plaintext)];
        }

        return array_map(fn (string $key): string => $this->hashWith($plaintext, $key), $this->keys);
    }

    public function matches(string $plaintext, string $expectedHash): bool
    {
        $matched = false;

        // Every candidate is compared even after one matches. Returning early would make
        // the answer's TIMING depend on which key was used, and the whole point of
        // hash_equals() here is that comparing a secret leaks nothing.
        foreach ($this->candidates($plaintext) as $candidate) {
            $matched = hash_equals($expectedHash, $candidate) || $matched;
        }

        return $matched;
    }

    private function hashWith(string $plaintext, string $key): string
    {
        return hash_hmac('sha256', $plaintext, $key);
    }
}
