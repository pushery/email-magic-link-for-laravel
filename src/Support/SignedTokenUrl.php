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
     * Matches an RFC 3986 scheme followed by the authority marker.
     *
     * `parse_url()` is not used for this test. It reads `localhost:8080` as a host
     * and a port on some inputs and as a scheme and a path on others, so "does this
     * string carry a scheme" is exactly the question it answers inconsistently.
     * Requiring `://` is the right question here anyway: a base URL is an origin, and
     * an origin without an authority is not one.
     */
    private const string SCHEME_PATTERN = '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#';

    /**
     * @param  string|null  $baseUrl  Override the host the URL is built for (e.g. a
     *                                tenant domain). The signature is computed over
     *                                that final host, so the URL verifies only when
     *                                visited there -- never an attacker's. A base URL
     *                                with no scheme is completed with the application's
     *                                own; see `absolute()`.
     */
    public static function for(string $routeName, CarbonInterface $expiresAt, string $plaintext, ?string $baseUrl = null): string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return self::sign($routeName, $expiresAt, $plaintext);
        }

        [$baseUrl, $scheme] = self::absolute($baseUrl);

        // Force the host AND scheme from the base URL for this one signing call,
        // then restore both so no later URL generation in the request inherits
        // the tenant host. Forcing the scheme too makes an https base URL sign as
        // https even when the app itself is served over http.
        URL::forceRootUrl($baseUrl);
        URL::forceScheme($scheme);

        try {
            return self::sign($routeName, $expiresAt, $plaintext);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme('');
        }
    }

    /**
     * Completes a base URL that carries no scheme with the application's own.
     *
     * A bare host used to travel straight through: `forceRootUrl('//tenant.test')`
     * holds a root with no scheme of its own, the root then decides, and the link
     * came out as `tenant.test/magic-link/...`. Defensible for a caller that
     * deliberately passed a bare host -- and poor for the place these links actually
     * go, which is an email. A mail client turns a schemeless string into a link,
     * into no link, or into a relative one, depending on the client; for an
     * invitation that is the difference between "works" and "the invited person
     * never gets in".
     *
     * The application's scheme is the one completion that invents nothing a host has
     * not already stated: it is what every other URL the app emits already uses. A
     * base URL that does carry a scheme is returned untouched, tenant scheme and all.
     *
     * @return array{0: string, 1: string} the absolute base URL and its scheme
     */
    private static function absolute(string $baseUrl): array
    {
        if (preg_match(self::SCHEME_PATTERN, $baseUrl, $matches) === 1) {
            return [$baseUrl, $matches[1]];
        }

        // `formatScheme()` rather than parsing `url('/')`: it is typed to return a
        // string, it honours a host that has called `forceScheme()` behind a proxy,
        // and in the console it reads the request Laravel builds from `app.url`. It
        // yields the scheme with its separator, e.g. `https://`.
        $scheme = rtrim(URL::formatScheme(), ':/');

        // Both schemeless forms reach here -- `//tenant.test` and `tenant.test` --
        // and both mean the same host.
        return [$scheme.'://'.ltrim($baseUrl, '/'), $scheme];
    }

    private static function sign(string $routeName, CarbonInterface $expiresAt, string $plaintext): string
    {
        return URL::temporarySignedRoute($routeName, $expiresAt, ['token' => $plaintext]);
    }
}
