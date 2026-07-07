{{-- Renders a polite countdown that disables the submit button after a resend
     was held back, re-enabling it when the wait elapses. Progressive
     enhancement only: with JavaScript off the button stays usable and the
     server simply holds the next request back again. --}}
@php($resendSeconds = (int) session('resend_retry_after'))

@if ($resendSeconds > 0)
    <p
        class="eml-resend-countdown"
        role="status"
        aria-live="polite"
        data-eml-resend
        data-seconds="{{ $resendSeconds }}"
        data-template="{{ __('email-magic-link::messages.resend_throttled', ['seconds' => '__seconds__']) }}"
    >{{ __('email-magic-link::messages.resend_throttled', ['seconds' => $resendSeconds]) }}</p>

    <script>
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
