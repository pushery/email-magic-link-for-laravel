<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * A value as it stands in a cache key: hashed with the application key.
 *
 * A rate-limit count, the issuance lock and a resend record are cache entries, and Sentry and
 * Flare record the key of every cache entry they see. A key that holds a client's address, a mail
 * address or a token, or a plain hash of one, hands it to both: a plain hash of a version 4
 * address is undone by hashing all four billion of them, and one of a mail address by hashing a
 * list of known addresses. A hash keyed with the application key cannot be tried back without the
 * key, and the same value still names the same bucket, lock and record.
 *
 * Rotating the application key starts every count, lock and record afresh, once. The key is read
 * as it is configured, `base64:` prefix included, and an application without one gets a hash with
 * an empty key rather than an error in the middle of a sign-in.
 */
final class KeyedDigest
{
    public static function of(string $value): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $value, is_string($key) ? $key : '');
    }
}
