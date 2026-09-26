<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Str;

/**
 * Reads an env-backed switch whose OFF position loosens a protection.
 *
 * `env()` hands a variable that is set but empty back as "", and `filter_var` reads "" as
 * false. On a switch that gates a path that is the safe direction: the path closes, and
 * somebody notices. On a switch that protects something it is the dangerous one. A line
 * like `EMAIL_MAGIC_LINK_RESEND=`, copied from an .env template, turned the flood guard off
 * without a word.
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
