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
    public static function for(MagicLinkToken $record, string $plaintext): string
    {
        return URL::temporarySignedRoute(
            'email-magic-link.confirm',
            $record->expires_at,
            ['token' => $plaintext],
        );
    }
}
