<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * The state the redirect strategy leaves when it turns a link or a code away.
 *
 * A form could tell a refusal from any other error only by the text of the message on a field, and
 * that holds only as long as the message and the field stay the same. The redirect strategy flashes
 * this key beside the message, for an unknown, an expired and a used credential alike, so a screen
 * can offer its own ways forward without comparing text. The bundled screens read it the same way.
 */
final class MagicLinkRejection
{
    /** The session key the redirect strategy flashes when it turns a link or a code away. */
    public const string SESSION_KEY = 'email-magic-link.rejected';

    /** Whether the request that draws this screen comes back from a link or a code that was turned away. */
    public static function flashed(): bool
    {
        return session()->has(self::SESSION_KEY);
    }
}
