<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

/**
 * Supplies the CSP nonce the bundled screens put on the tags a strict policy would reject.
 *
 * An application with a strict Content-Security-Policy sets a per-response nonce, and
 * without it the screens lose what they cannot load: the inline stylesheet both layouts
 * carry, so they render unstyled under a strict `style-src`; the `<link>` and `<script>`
 * tags WireKit and Livewire write; and, only under a policy built on 'strict-dynamic',
 * which ignores 'self', the resend countdown's script, a same-origin file since 0.22.0.
 * All of it is blocked SILENTLY, which is what makes this worth a seam: nothing on the
 * page reports it, and the user sees a broken screen rather than an error.
 *
 * The default implementation reads the `csp-nonce` container binding spatie/laravel-csp
 * registers and falls back to a global `csp_nonce()` for hosts that define one, so
 * spatie/laravel-csp works with no configuration; see `AutoScriptNonce`. Point
 * `ui.script_nonce` at your
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
