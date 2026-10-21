<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * The resend countdown's client script, and the version stamp its URL carries.
 *
 * An external same-origin file rather than an inline `<script>`: an inline script needs
 * a nonce under a strict policy, and a host whose policy issues no nonces has none to pass
 * through, so an inline countdown could not run there at all. A same-origin file
 * satisfies a plain `script-src 'self'` with no nonce and no host action.
 *
 * Why the `SOURCE` lives in a PHP constant rather than a `.js` file. This package
 * deliberately has no client-asset path: no npm, no build step, no `dist/`, and no
 * publishable assets, so `resources/` carries only `views/` and `boost/`. Every
 * alternative reintroduces one of those, and each brings a way to be absent at
 * runtime -- an unpublished asset, an unbuilt bundle, a host that never ran the
 * publish command. A constant is in the package the moment the package is
 * installed, which is the property that actually matters for something a strict
 * policy is meant to make reliable.
 *
 * The script is static: every value it needs arrives through `data-` attributes on
 * the element the partial renders, so nothing here is rendered per request and the file
 * can be cached forever.
 */
final class ResendCountdownScript
{
    /**
     * Progressive enhancement, and it degrades in the right direction: with the
     * script blocked or absent the button stays usable and the server simply holds
     * the next request back again.
     *
     * The reasons live here rather than in the script, because the script is sent to
     * every browser that shows the countdown:
     *
     * - Two templates, `data-template-one` and `data-template-other`: the countdown
     *   passes through one on its way down, and a baked-in plural would read "1 seconds"
     *   for a whole second in every language. The server renders both forms, and the
     *   script picks between them.
     * - The line that counts is the email field's error line (`data-target`), which the
     *   server renders with the wait already in it: one message, red and under the field,
     *   in the plain view and in the WireKit view alike. Where a view renders no such line,
     *   as a published copy from before may, the element of the partial shows the wait.
     * - While it counts the line is `role="timer"` with `aria-live="off"` and the name in
     *   `data-label`, so it is read when a reader reaches it and not on every tick.
     * - When the wait is over the line goes, and what stood below it moves up. The field
     *   loses `aria-invalid` and the reference to the line, so nothing points at an id
     *   that is gone.
     * - `aria-disabled`, not `disabled`: the button stays in the tab order and announces
     *   as dimmed, and `aria-describedby` on it names the line as the reason.
     */
    private const string SOURCE = <<<'JS'
        (function () {
            var holder = document.querySelector('[data-eml-resend]');

            if (! holder) {
                return;
            }

            var line = document.getElementById(holder.getAttribute('data-target') || '') || holder;
            var form = (holder.closest('main') || document).querySelector('form');
            var button = form ? form.querySelector('button[type="submit"], button:not([type])') : null;
            var field = line.id ? document.querySelector('[aria-describedby~="' + line.id + '"]:not(button)') : null;
            var one = holder.getAttribute('data-template-one') || '';
            var other = holder.getAttribute('data-template-other') || '';
            var remaining = parseInt(holder.getAttribute('data-seconds'), 10) || 0;

            line.hidden = false;
            line.setAttribute('role', 'timer');
            line.setAttribute('aria-live', 'off');
            line.setAttribute('aria-label', holder.getAttribute('data-label') || '');

            var block = function (e) { e.preventDefault(); };

            if (button) {
                button.setAttribute('aria-disabled', 'true');

                if (line.id) {
                    button.setAttribute('aria-describedby', line.id);
                }

                form.addEventListener('submit', block);
            }

            (function tick() {
                if (remaining <= 0) {
                    if (button) {
                        button.removeAttribute('aria-disabled');
                        button.removeAttribute('aria-describedby');
                        form.removeEventListener('submit', block);
                    }

                    if (field) {
                        var rest = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) {
                            return id !== '' && id !== line.id;
                        });

                        if (rest.length > 0) {
                            field.setAttribute('aria-describedby', rest.join(' '));
                        } else {
                            field.removeAttribute('aria-describedby');
                        }

                        field.removeAttribute('aria-invalid');
                    }

                    line.remove();

                    return;
                }

                line.textContent = (remaining === 1 ? one : other).replace('__seconds__', remaining);
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
