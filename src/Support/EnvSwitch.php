<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Reads a switch from the value Laravel's env helper gives for it.
 *
 * The rule is a method and not an expression in config/, because a host that publishes the config
 * keeps the file as it was on that day. A rule written into the file never takes a later fix; a
 * call of this method takes it with the next update of the package.
 *
 * The env helper turns only `true`, `false`, `(true)` and `(false)` into booleans, `empty` into an empty
 * string and `null` into null. Every other spelling stays a string, so the spellings that switch
 * something off and on are named here, trimmed and in any case. Anything else keeps the value the
 * package ships: a missing line, an empty line as copying a template of a .env leaves one, and a
 * spelling that names neither.
 */
final class EnvSwitch
{
    /** @var list<string> */
    private const array OFF = ['0', 'false', 'no', 'off'];

    /** @var list<string> */
    private const array ON = ['1', 'true', 'yes', 'on'];

    /**
     * @param  mixed  $value  what the env helper returned for the switch
     * @param  bool  $default  the value the package ships, kept for anything the host did not spell out
     */
    public static function read(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $default;
        }

        $spelling = strtolower(trim($value));

        return match (true) {
            in_array($spelling, self::OFF, true) => false,
            in_array($spelling, self::ON, true) => true,
            default => $default,
        };
    }
}
