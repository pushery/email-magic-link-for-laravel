<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\MagicLinkToken;
use Illuminate\Support\Facades\URL;

/**
 * Builds the signed, single-use confirmation URL for an issued link token.
 *
 * Centralised so the bundled email flow and the Mint-API emit the byte-for-byte
 * same signed GET URL: the inert confirmation page. Only the POST consume route
 * mutates state, so this URL is safe for link-following scanners and prefetch.
 */
final class ConfirmationUrl
{
    /**
     * @param  string|null  $baseUrl  Override the host the link is built for (e.g.
     *                                a tenant domain). The signature is computed
     *                                over that final host, so the link verifies
     *                                only when visited there — never an attacker's.
     */
    public static function for(MagicLinkToken $record, string $plaintext, ?string $baseUrl = null): string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return self::sign($record, $plaintext);
        }

        // Force the host AND scheme from the base URL for this one signing call,
        // then restore both so no later URL generation in the request inherits
        // the tenant host. Forcing the scheme too makes an https base URL sign as
        // https even when the app itself is served over http.
        URL::forceRootUrl($baseUrl);
        URL::forceScheme(parse_url($baseUrl, PHP_URL_SCHEME) ?: '');

        try {
            return self::sign($record, $plaintext);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme('');
        }
    }

    private static function sign(MagicLinkToken $record, string $plaintext): string
    {
        return URL::temporarySignedRoute(
            'email-magic-link.confirm',
            $record->expires_at,
            ['token' => $plaintext],
        );
    }
}
