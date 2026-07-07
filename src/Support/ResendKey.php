<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Builds the resend-guard keys the package uses for its own endpoints.
 *
 * Centralizing the key shape keeps the request endpoint (which arms the guard)
 * and the successful-login path (which resets it) in agreement, and applies the
 * same email normalization both use so a differently-cased address still maps to
 * the same guarded key.
 */
final class ResendKey
{
    public const string REQUEST_PREFIX = 'request:';

    public static function forRequest(string $email): string
    {
        return self::REQUEST_PREFIX.mb_strtolower(trim($email));
    }
}
