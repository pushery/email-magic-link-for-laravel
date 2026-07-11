<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\IssuedCode;
use EmailMagicLink\Support\IssuedLink;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Issues magic links and one-time codes for a known user WITHOUT sending mail.
 *
 * Use it to mint a credential and deliver it over any channel — SMS, chat, an
 * existing email, a queued job. The returned secret is single-use and hashed at
 * rest exactly like the bundled email flow; only the plaintext handed back here
 * is ever in the clear. Pass an already-resolved user (resolve from an email via
 * the UserLookup contract).
 */
interface MagicLinkIssuer
{
    /**
     * Issue a magic link for the user on the given guard.
     *
     * @param  string|null  $guard  An allowed guard, or null for the default.
     * @param  int|null  $maxUses  How many times the link may be redeemed; null
     *                             uses the configured `max_uses` (1 = single-use).
     *                             Each redemption is decremented atomically, so
     *                             concurrent claims can never exceed the limit.
     * @param  string|null  $passphrase  A shared secret the recipient must enter
     *                                   on the confirmation page before the link
     *                                   is consumed. A lightweight gate, NOT the
     *                                   Fortify two-factor challenge.
     * @param  string|null  $baseUrl  Build the link for this host (e.g. a tenant
     *                                domain) instead of the app's. The signature
     *                                binds to that host, so there is no open
     *                                redirect — the link verifies only there.
     */
    public function issueLink(Authenticatable $user, ?string $guard = null, ?int $maxUses = null, ?string $passphrase = null, ?string $baseUrl = null): IssuedLink;

    /**
     * Issue a one-time code for the user on the given guard.
     *
     * Issuing a code invalidates any previously active code for the same user
     * and guard, so only the most recently issued code can be claimed.
     *
     * @param  string|null  $guard  An allowed guard, or null for the default.
     */
    public function issueCode(Authenticatable $user, ?string $guard = null): IssuedCode;
}
