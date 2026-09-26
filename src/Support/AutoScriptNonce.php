<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\ScriptNonce;
use Throwable;

/**
 * Finds the application's CSP nonce without the application configuring anything.
 *
 * Two probes, in order:
 *
 * 1. The container binding `csp-nonce`. That is what spatie/laravel-csp registers —
 *    `$this->app->scoped('csp-nonce', …)` in its service provider — and it is what that
 *    package's own `@cspNonce` directive reads. Scoped means one nonce per request, so
 *    resolving it here yields the same value the policy header carries.
 * 2. A global `csp_nonce()` function, for hosts that define one themselves.
 *
 * The binding comes first because it is the only one spatie/laravel-csp 3.x offers:
 * `csp_nonce()` exists from 1.2.0 through 2.10.3 and nowhere in 3.x, whose releases
 * publish `autoload.psr-4` and no `autoload.files`, so they cannot register a global
 * function at all. Asked only for the function, a 3.x host has no nonce here, and the
 * screens' tags ship without one under exactly the strict policy this seam serves.
 *
 * Probed by name and by key so this package never references that package's symbols and
 * stays installable without it.
 *
 * Everything here fails to NULL rather than throwing. A missing nonce degrades to tags
 * without one, fine under a permissive policy; an exception would take down the whole
 * sign-in screen over a missing attribute.
 */
final class AutoScriptNonce implements ScriptNonce
{
    /**
     * The container key to probe. Overridable so a test can prove the binding path
     * without installing a CSP package.
     */
    public static string $binding = 'csp-nonce';

    /**
     * The function to probe. Overridable so a test can prove the probe without
     * installing a CSP package — the name is not a callable until it exists,
     * which is the entire point of probing it.
     */
    public static string $helper = 'csp_nonce';

    public function value(): ?string
    {
        return $this->fromContainer() ?? $this->fromHelper();
    }

    private function fromContainer(): ?string
    {
        $container = app();

        if (! $container->bound(self::$binding)) {
            return null;
        }

        try {
            $nonce = $container->make(self::$binding);
        } catch (Throwable) {
            // A binding that exists but cannot be resolved on this request — a
            // generator needing state a console command does not have, say.
            return null;
        }

        return $this->usable($nonce);
    }

    private function fromHelper(): ?string
    {
        $helper = self::$helper;

        if (! function_exists($helper)) {
            return null;
        }

        try {
            $nonce = $helper();
        } catch (Throwable) {
            // The helper exists but the policy is not active on this response —
            // spatie's 2.x helper throws when no nonce was generated. Not our
            // error to raise.
            return null;
        }

        return $this->usable($nonce);
    }

    /**
     * `nonce=""` is not the same thing as no nonce: it fails a strict policy just
     * as hard while looking like the feature works.
     */
    private function usable(mixed $nonce): ?string
    {
        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
