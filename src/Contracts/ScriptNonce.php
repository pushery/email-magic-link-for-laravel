<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

/**
 * Supplies the CSP nonce the bundled screens put on their inline script.
 *
 * An application with a strict Content-Security-Policy sets a per-response nonce
 * on `script-src`. Any inline script without it is blocked — and blocked
 * SILENTLY, which is what makes this worth a seam: the resend countdown is
 * progressive enhancement, so a blocked script produces no console error the user
 * would report, just a button that never counts down and a rejection when they
 * click too early. The apps most likely to run a strict CSP are exactly the ones
 * that care about the rest of this package.
 *
 * The default implementation detects the fleet-standard `csp_nonce()` helper, so
 * spatie/laravel-csp works with no configuration. Point `ui.script_nonce` at your
 * own implementation when the nonce lives somewhere else — a request attribute, a
 * middleware-set container binding, your own helper.
 *
 * A class-string rather than a closure on purpose: `config:cache` cannot serialize
 * a closure, so a closure here would work in development and fatal on deploy.
 */
interface ScriptNonce
{
    /**
     * The nonce for the current response, or null when the application does not
     * use one. Null means the attribute is omitted entirely — an empty
     * `nonce=""` is not the same thing and would fail a strict policy anyway.
     */
    public function value(): ?string;
}
