<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * The resend countdown's client script, and the version stamp its URL carries.
 *
 * It used to be an inline `<script>` in the partial, which meant a strict policy
 * needed a nonce for it -- and a host whose policy issues NO nonces had nothing to
 * pass through, so for them the countdown could not be made to run at all. An
 * external same-origin file satisfies a plain `script-src 'self'` with no nonce and
 * no host action, which is the reason for the move.
 *
 * WHY THE SOURCE LIVES IN A PHP CONSTANT rather than a `.js` file. This package
 * deliberately has no client-asset path: no npm, no build step, no `dist/`, and no
 * publishable assets, so `resources/` carries only `views/` and `boost/`. Every
 * alternative reintroduces one of those, and each brings a way to be absent at
 * runtime -- an unpublished asset, an unbuilt bundle, a host that never ran the
 * publish command. A constant is in the package the moment the package is
 * installed, which is the property that actually matters for something a strict
 * policy is meant to make reliable.
 *
 * The script is static: every value it needs arrives through `data-` attributes on
 * the element it drives, so nothing here is rendered per request and the file can
 * be cached forever.
 */
final class ResendCountdownScript
{
    /**
     * Progressive enhancement, and it degrades in the right direction: with the
     * script blocked or absent the button stays usable and the server simply holds
     * the next request back again.
     */
    private const string SOURCE = <<<'JS'
        (function () {
            var el = document.querySelector('[data-eml-resend]');

            if (! el) {
                return;
            }

            var form = (el.closest('main') || document).querySelector('form');
            var button = form ? form.querySelector('button[type="submit"], button:not([type])') : null;
            var template = el.getAttribute('data-template') || '';
            var remaining = parseInt(el.getAttribute('data-seconds'), 10) || 0;

            // aria-disabled, not disabled: the button stays in the tab order and announces
            // as dimmed, and aria-describedby on it names the countdown as the reason.
            var block = function (e) { e.preventDefault(); };

            if (button) {
                button.setAttribute('aria-disabled', 'true');
                form.addEventListener('submit', block);
            }

            (function tick() {
                if (remaining <= 0) {
                    if (button) {
                        button.removeAttribute('aria-disabled');
                        button.removeAttribute('aria-describedby');
                        form.removeEventListener('submit', block);
                    }

                    el.textContent = '';

                    return;
                }

                el.textContent = template.replace('__seconds__', remaining);
                remaining -= 1;
                window.setTimeout(tick, 1000);
            })();
        })();

        JS;

    public static function contents(): string
    {
        return self::SOURCE;
    }

    /**
     * A short digest of the source, carried in the URL as `?v=`.
     *
     * This is what lets the response be cached immutably: the bytes and the URL move
     * together, so a changed script is a changed URL and no browser can be left
     * holding the old one. Not a security boundary -- a cache key -- which is why a
     * truncated digest is enough.
     *
     * Memoized because the input is a compile-time constant: the answer cannot change
     * within a process, and this is called from a view.
     */
    public static function version(): string
    {
        return self::$version ??= substr(hash('sha256', self::SOURCE), 0, 12);
    }

    private static ?string $version = null;
}
