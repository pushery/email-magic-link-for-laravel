<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Reads an env-backed number that is a ceiling or a lifetime.
 *
 * `env()` hands every value back as a string, and `(int)` makes 0 of anything that is not a
 * number: a mistyped `EMAIL_MAGIC_LINK_REQUEST_MAX` would then switch the rate limit off rather
 * than tighten it. A number below 1 is no usable ceiling or lifetime either, and `(int)` turns
 * `0.5` into 0 as well.
 *
 * So the value has to be numeric and its whole part at least 1. Anything else takes the declared
 * default, which is the value the config file ships, and an unset or empty variable does too.
 *
 * A retention is the one kind of number where zero is a setting of its own: `0` keeps nothing.
 * `atLeastZero()` reads those, and holds a negative value to the default the same way.
 *
 * A value that was set and could not be used is remembered under the variable it came from, when
 * the caller names it, so that `email-magic-link:doctor` can say which setting is not in effect.
 */
final class EnvNumber
{
    /**
     * The values read since the list was last cleared that could not be used, by variable.
     *
     * @var array<string, array{value: string, default: int}>
     */
    private static array $setAside = [];

    public static function atLeastOne(mixed $value, int $default, ?string $variable = null): int
    {
        return self::atLeast(1, $value, $default, $variable);
    }

    public static function atLeastZero(mixed $value, int $default, ?string $variable = null): int
    {
        return self::atLeast(0, $value, $default, $variable);
    }

    private static function atLeast(int $floor, mixed $value, int $default, ?string $variable): int
    {
        $number = self::usable($value, $floor);

        if ($number !== null) {
            return $number;
        }

        // Unset and empty are no setting at all, so there is nothing to report for them.
        if ($variable !== null && $value !== null && $value !== '') {
            self::$setAside[$variable] = ['value' => self::spelled($value), 'default' => $default];
        }

        return $default;
    }

    /**
     * The values set aside since the list was last cleared, keyed by the variable they came from.
     *
     * @return array<string, array{value: string, default: int}>
     */
    public static function setAside(): array
    {
        return self::$setAside;
    }

    public static function clearSetAside(): void
    {
        self::$setAside = [];
    }

    /**
     * The value as a whole number, or null when it is no number or lies below the floor.
     *
     * Compared before the cast, so a negative fraction such as `-0.5` stays below a floor of 0
     * rather than reaching it as the 0 that `(int)` makes of it.
     */
    private static function usable(mixed $value, int $floor): ?int
    {
        if (is_int($value)) {
            return $value >= $floor ? $value : null;
        }

        if (! is_string($value) || ! is_numeric($value) || (float) $value < $floor) {
            return null;
        }

        return (int) $value;
    }

    /**
     * A value as it was written: `env()` turns `true` and `false` into booleans on the way in.
     */
    private static function spelled(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
