{{-- Renders a polite countdown that disables the submit button after a resend
     was held back, re-enabling it when the wait elapses. Progressive
     enhancement only: with JavaScript off the button stays usable and the
     server simply holds the next request back again.

     The script carries the application's CSP nonce when there is one. Without
     it, a strict policy blocks the script and the countdown never runs — and
     "progressive enhancement" means it fails silently, so the user just meets a
     rejection when they click too early. See the ScriptNonce contract. --}}
@php($resendSeconds = (int) session('resend_retry_after'))
@php($emlNonce = app(\EmailMagicLink\Contracts\ScriptNonce::class)->value())

@if ($resendSeconds > 0)
    {{-- role="timer", not role="status". A status region is aria-live="polite", so a
         value that changes every second is announced every second — a screen reader
         reader would hear the remaining time read out eight times instead of being
         told once that they have to wait. role="timer" is implicitly aria-live="off",
         so the text is read when the reader navigates to it and not on every tick.
         The aria-label keeps the region named now that the live text is not the
         announcement. (WireKit's own countdown component reaches the same conclusion
         for the same reason.) --}}
    <p
        class="eml-resend-countdown"
        role="timer"
        aria-label="{{ __('email-magic-link::messages.resend_countdown_label') }}"
        data-eml-resend
        data-seconds="{{ $resendSeconds }}"
        data-template="{{ __('email-magic-link::messages.resend_throttled', ['seconds' => '__seconds__']) }}"
    >{{ __('email-magic-link::messages.resend_throttled', ['seconds' => $resendSeconds]) }}</p>

    {{-- An expression rather than @if: a Blade conditional inside a tag leaves its
         whitespace behind, so the tag renders as `<script >`. The nonce is escaped
         because it arrives from a host implementation of the contract. --}}
    <script{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}>
        (function () {
            var el = document.querySelector('[data-eml-resend]');

            if (! el) {
                return;
            }

            var form = (el.closest('main') || document).querySelector('form');
            var button = form ? form.querySelector('button[type="submit"], button:not([type])') : null;
            var template = el.getAttribute('data-template') || '';
            var remaining = parseInt(el.getAttribute('data-seconds'), 10) || 0;

            if (button) {
                button.disabled = true;
            }

            (function tick() {
                if (remaining <= 0) {
                    if (button) {
                        button.disabled = false;
                    }

                    el.textContent = '';

                    return;
                }

                el.textContent = template.replace('__seconds__', remaining);
                remaining -= 1;
                window.setTimeout(tick, 1000);
            })();
        })();
    </script>
@endif
