<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\ScriptNonce;
use Throwable;

/**
 * Finds the application's CSP nonce without the application configuring anything.
 *
 * `csp_nonce()` is the helper spatie/laravel-csp registers, and it is the common
 * case by a wide margin. Probed by name so this package never references that
 * package's symbols and stays installable without it.
 *
 * Everything here fails to NULL rather than throwing. A missing nonce degrades to
 * exactly the behaviour that shipped before the seam existed (an inline script with
 * no nonce, fine under a permissive policy); an exception would take down the whole
 * sign-in screen over a progressive enhancement. That trade is the wrong way round.
 */
final class AutoScriptNonce implements ScriptNonce
{
    /**
     * The helper to probe. Overridable so a test can prove the probe without
     * installing a CSP package — the name is not a callable until it exists,
     * which is the entire point of probing it.
     */
    public static string $helper = 'csp_nonce';

    public function value(): ?string
    {
        $helper = self::$helper;

        if (! function_exists($helper)) {
            return null;
        }

        try {
            $nonce = $helper();
        } catch (Throwable) {
            // The helper exists but the policy is not active on this response —
            // spatie's throws when no nonce was generated. Not our error to raise.
            return null;
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
