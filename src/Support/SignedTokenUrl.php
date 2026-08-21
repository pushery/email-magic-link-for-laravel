<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Builds a temporary signed URL carrying a plaintext token, optionally against
 * another host.
 *
 * Extracted so the sign-in confirmation link and the invitation link are built by
 * the SAME code. The host override is the part worth centralising: it forces the
 * root URL and the scheme for one signing call and restores both in a `finally`,
 * and a second copy of that would be a second chance to forget the restore -- at
 * which point every later URL in the request silently inherits a tenant host.
 */
final class SignedTokenUrl
{
    /**
     * @param  string|null  $baseUrl  Override the host the URL is built for (e.g. a
     *                                tenant domain). The signature is computed over
     *                                that final host, so the URL verifies only when
     *                                visited there -- never an attacker's.
     */
    public static function for(string $routeName, CarbonInterface $expiresAt, string $plaintext, ?string $baseUrl = null): string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return self::sign($routeName, $expiresAt, $plaintext);
        }

        // Force the host AND scheme from the base URL for this one signing call,
        // then restore both so no later URL generation in the request inherits
        // the tenant host. Forcing the scheme too makes an https base URL sign as
        // https even when the app itself is served over http.
        URL::forceRootUrl($baseUrl);
        URL::forceScheme(parse_url($baseUrl, PHP_URL_SCHEME) ?: '');

        try {
            return self::sign($routeName, $expiresAt, $plaintext);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme('');
        }
    }

    private static function sign(string $routeName, CarbonInterface $expiresAt, string $plaintext): string
    {
        return URL::temporarySignedRoute($routeName, $expiresAt, ['token' => $plaintext]);
    }
}
