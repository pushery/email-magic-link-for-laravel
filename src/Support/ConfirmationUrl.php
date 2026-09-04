<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Models\MagicLinkToken;

/**
 * Builds the signed, single-use confirmation URL for an issued link token.
 *
 * Centralized so the bundled email flow and the Mint-API emit the byte-for-byte
 * same signed GET URL: the inert confirmation page. Only the POST consume route
 * mutates state, so this URL is safe for link-following scanners and prefetch.
 *
 * The signing itself now lives in SignedTokenUrl, shared with the invitation link.
 * This signature is public API and stays exactly as it was.
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
        return SignedTokenUrl::for('email-magic-link.confirm', $record->expires_at, $plaintext, $baseUrl);
    }
}
