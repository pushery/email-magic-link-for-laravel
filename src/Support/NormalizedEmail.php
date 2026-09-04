<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Str;

/**
 * The one spelling of an address every lookup, limiter key and cache key agrees on.
 *
 * Str::trim() rather than trim(): it also strips the invisible characters a paste
 * carries (NBSP, zero-width space, BOM), which PHP's trim() leaves in place. Under
 * the framework's default middleware stack the input already arrived trimmed that
 * way, so the two spellings only diverged for a host that removed TrimStrings --
 * and then quietly, into a key that matched nothing.
 */
final class NormalizedEmail
{
    public static function from(string $email): string
    {
        return Str::lower(Str::trim($email));
    }
}
