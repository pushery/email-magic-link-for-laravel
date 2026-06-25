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
     * Issue a single-use magic link for the user on the given guard.
     *
     * @param  string|null  $guard  An allowed guard, or null for the default.
     */
    public function issueLink(Authenticatable $user, ?string $guard = null): IssuedLink;

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
