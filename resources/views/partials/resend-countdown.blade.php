{{-- Renders a polite countdown that disables the submit button after a resend
     was held back, re-enabling it when the wait elapses. Progressive
     enhancement only: with JavaScript off the button stays usable and the
     server simply holds the next request back again.

     The script is an EXTERNAL same-origin file, not an inline block, and that is
     a CSP decision rather than a tidiness one. Inline, it needed a nonce under any
     strict policy — and a host whose policy issues no nonces had nothing to pass
     through, so for them the countdown could not be made to run at all. A file
     from the app's own origin satisfies `script-src 'self'` with no host action.

     It still carries the nonce when there is one, and that is not belt-and-braces:
     a policy built on 'strict-dynamic' IGNORES 'self', so under one the nonce is
     the only thing that grants the tag. See the ScriptNonce contract. --}}
@php($resendSeconds = (int) session('resend_retry_after'))
@php($emlNonce = app(\EmailMagicLink\Contracts\ScriptNonce::class)->value())

@if ($resendSeconds > 0)
    {{-- Inside the conditional, not above it: this partial is included on every render
         of the request screen and the countdown shows on almost none of them. --}}
    @php($emlCountdownScript = route('email-magic-link.resend-countdown-script', ['v' => \EmailMagicLink\Support\ResendCountdownScript::version()]))

    {{-- role="timer", not role="status". A status region is aria-live="polite", so a
         value that changes every second is announced every second — a screen reader
         reader would hear the remaining time read out eight times instead of being
         told once that they have to wait. role="timer" is implicitly aria-live="off",
         so the text is read when the reader navigates to it and not on every tick.
         The aria-label keeps the region named now that the live text is not the
         announcement. (WireKit's own countdown component reaches the same conclusion
         for the same reason.) --}}
    <p
        id="eml-resend-countdown"
        class="eml-resend-countdown"
        role="timer"
        aria-label="{{ __('email-magic-link::messages.resend_countdown_label') }}"
        data-eml-resend
        data-seconds="{{ $resendSeconds }}"
        data-template="{{ __('email-magic-link::messages.resend_throttled', ['seconds' => '__seconds__']) }}"
    >{{ __('email-magic-link::messages.resend_throttled', ['seconds' => $resendSeconds]) }}</p>

    {{-- An expression rather than @if: a Blade conditional inside a tag leaves its
         whitespace behind, so the tag renders as `<script >`. The nonce is escaped
         because it arrives from a host implementation of the contract.

         `defer` because the script only touches the DOM and must not block the parse
         of a sign-in screen for a progressive enhancement. --}}
    <script src="{{ $emlCountdownScript }}" defer{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}></script>
@endif
