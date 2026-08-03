<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Lays the package's shipped defaults UNDER a host's published config.
 *
 * Laravel's `mergeConfigFrom` uses `array_merge`, which is shallow: a host that
 * published `config/email-magic-link.php` once keeps receiving new TOP-LEVEL keys
 * from later releases, but a key added inside a block it already published never
 * arrives — the host's whole block wins. The result is silent: no error, no
 * warning, the feature simply runs on `null` instead of its default.
 *
 * Measured on a real upgrade (0.6 → 0.17): `routes.intended` (added 0.7.0) stayed
 * null instead of true, so the intended-redirect was quietly off, and `ui.styles`
 * (added 0.15.0) stayed null instead of `[]`, so code iterating it got null.
 *
 * ## Why not array_merge_recursive
 *
 * Because it CONCATENATES lists rather than replacing them. A host setting
 * `guards => ['admin']` against a package default of `['web']` would end up with
 * `['web', 'admin']` — silently re-adding a guard it deliberately removed. For a
 * guard allowlist that is a security regression, not a merge.
 *
 * So the rule is: recurse into associative arrays, and treat a LIST as one
 * indivisible value the host either replaced or did not. That is the only reading
 * under which "the host wins on everything it set" stays true.
 */
final class ConfigMerger
{
    /**
     * @param  array<array-key, mixed>  $defaults  the package's shipped config
     * @param  array<array-key, mixed>  $published  whatever the host has in place
     * @return array<array-key, mixed>
     */
    public static function deep(array $defaults, array $published): array
    {
        $merged = $defaults;

        foreach ($published as $key => $value) {
            $default = $merged[$key] ?? null;

            // Both sides an associative array → recurse, so a key the host has
            // never seen arrives while every key it set is kept.
            $merged[$key] = is_array($value) && is_array($default) && ! self::isList($value) && ! self::isList($default)
                ? self::deep($default, $value)
                : $value;
        }

        return $merged;
    }

    /**
     * A list is a value, not a structure to merge into.
     *
     * `array_is_list([])` is true, which is the behavior we want: an empty array
     * the host published is an explicit "nothing here", and merging the package's
     * entries back into it would undo that choice.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
