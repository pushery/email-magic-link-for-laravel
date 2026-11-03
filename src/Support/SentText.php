<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

/**
 * The input a refused submit flashed back, as the text a form field can hold.
 *
 * Laravel flashes the whole input back when validation refuses it, a list included, and `old()`
 * returns it as it was sent. Written into a field, a list reaches `htmlspecialchars()` or a string
 * cast and throws, so the refusal would end on an error page instead of at its field.
 */
final class SentText
{
    /**
     * A scalar as its text, a list as an empty text, and nothing sent as null.
     */
    public static function old(string $key): ?string
    {
        $value = Container::getInstance()->make(Request::class)->old($key);

        return match (true) {
            $value === null => null,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
