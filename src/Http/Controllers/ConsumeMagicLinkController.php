<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Http\Controllers\Concerns\CompletesMagicLinkLogin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumes a magic-link token — the only side-effecting step of the link flow.
 *
 * The token is verified and atomically claimed here; only this POST mutates
 * state. On success the swappable authenticator decides login versus 2FA.
 */
final class ConsumeMagicLinkController
{
    use CompletesMagicLinkLogin;

    public function __invoke(Request $request, string $token, TokenStore $store): Response
    {
        // Read as raw input, not through string(): that helper casts, and an array
        // value ("passphrase[]=a") was a 500 before the claim ran. Anything but a
        // non-empty string is "no passphrase", which the claim then refuses.
        $raw = $request->input('passphrase');
        $passphrase = is_string($raw) && $raw !== '' ? $raw : null;

        $result = $store->claimLink($token, $passphrase);
        if (! $result->succeeded()) {
            return $this->failedConsumption($request, 'email-magic-link.request.form', $result->failure);
        }

        return $this->completeLogin($request, $result->token, 'email-magic-link.request.form');
    }
}
