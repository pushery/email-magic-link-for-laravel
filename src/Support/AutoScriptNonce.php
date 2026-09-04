<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\ScriptNonce;
use Throwable;

/**
 * Finds the application's CSP nonce without the application configuring anything.
 *
 * Two probes, in order, because the ecosystem moved and this class did not notice.
 *
 * 1. The container binding `csp-nonce`. That is what spatie/laravel-csp actually
 *    registers — `$this->app->scoped('csp-nonce', …)` in its service provider — and
 *    it is what the package's own `@cspNonce` directive reads. Scoped means one nonce
 *    per request, so resolving it here yields the same value the policy header carries.
 * 2. A global `csp_nonce()` function, kept for hosts that define one themselves.
 *
 * The order is not arbitrary, and the second probe used to be the ONLY one. That was
 * wrong for every current consumer: `csp_nonce()` existed in spatie/laravel-csp from
 * 1.2.0 through 2.10.3, the last 2.x release, and exists nowhere in 3.x — measured against the package's own metadata, where every
 * 3.x release publishes `autoload.psr-4` and no `autoload.files`, so it cannot register
 * a global function at all. `function_exists('csp_nonce')` was therefore false for every
 * v3 host, this class returned null, and the countdown script shipped with no nonce
 * under exactly the strict policy the seam exists to serve. It failed silently, which is
 * why it took three separate consumers to notice.
 *
 * Probed by name and by key so this package never references that package's symbols and
 * stays installable without it.
 *
 * Everything here fails to NULL rather than throwing. A missing nonce degrades to
 * exactly the behavior that shipped before the seam existed (an inline script with
 * no nonce, fine under a permissive policy); an exception would take down the whole
 * sign-in screen over a progressive enhancement. That trade is the wrong way round.
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
