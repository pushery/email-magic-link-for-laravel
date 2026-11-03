<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Str;

/**
 * Reads an env-backed switch that an empty value must not switch off.
 *
 * `env()` hands a variable that is set but empty back as "", and `filter_var` reads "" as
 * false. A line like `EMAIL_MAGIC_LINK_RESEND=`, copied from an .env template, turned the
 * flood guard off without a word. `EMAIL_MAGIC_LINK_ENABLED=` switched the channel off,
 * while an application that reads the same variable with an empty value as not set still
 * counted the channel among its ways to sign in, so its check for at least one way passed
 * with none.
 *
 * Here an empty or blank value counts as not set and takes the declared default. Every
 * other value reads exactly as the other switches read it, and an unreadable one falls
 * back to the default as well.
 */
final class EnvFlag
{
    public static function protection(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || (is_string($value) && Str::trim($value) === '')) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
