{{-- Hands the countdown script what it needs after a resend was held back: the id of the
     email field's error line, which already says how long to wait, the seconds, both plural
     forms of the text and the name the line takes while it counts. The script keeps that
     line current, holds the submit button back, and takes the line away when the wait is
     over. Progressive enhancement only: with JavaScript off the line stays as the server
     rendered it, the button stays usable, and the server holds the next request back again.

     The script is an external same-origin file, not an inline block, and that is
     a CSP decision rather than a tidiness one. Inline, it needed a nonce under any
     strict policy — and a host whose policy issues no nonces had nothing to pass
     through, so for them the countdown could not be made to run at all. A file
     from the app's own origin satisfies `script-src 'self'` with no host action.

     It still carries the nonce when there is one, and that is not belt-and-braces:
     a policy built on 'strict-dynamic' ignores 'self', so under one the nonce is
     the only thing that grants the tag. See the ScriptNonce contract. --}}
@php($resendSeconds = (int) session('resend_retry_after'))
@php($emlNonce = app(\EmailMagicLink\Contracts\ScriptNonce::class)->value())

@if ($resendSeconds > 0)
    {{-- Inside the conditional, not above it: this partial is included on every render
         of the request screen and the countdown shows on almost none of them. --}}
    @php($emlCountdownScript = route('email-magic-link.resend-countdown-script', ['v' => \EmailMagicLink\Support\ResendCountdownScript::version()]))

    {{-- No line of its own: a second line beside the field's error said the same thing twice.
         The script gives the error line role="timer" while it counts, not role="status": a
         status region is aria-live="polite", so a value that changes every second would be
         read out every second. role="timer" is implicitly aria-live="off", and data-label is
         the name the line carries meanwhile. (WireKit's own countdown component reaches the
         same conclusion for the same reason.) --}}
    <span
        hidden
        data-eml-resend
        data-target="email-error"
        data-label="{{ __('email-magic-link::messages.resend_countdown_label') }}"
        data-seconds="{{ $resendSeconds }}"
        {{-- Both forms, because the tick counts down through one: a single template
             would have to bake a plural in and say "1 seconds" for a whole second, in
             every language. The script picks by the number it is about to render. --}}
        data-template-one="{{ trans_choice('email-magic-link::messages.resend_throttled', 1, ['seconds' => '__seconds__']) }}"
        data-template-other="{{ trans_choice('email-magic-link::messages.resend_throttled', 2, ['seconds' => '__seconds__']) }}"
    ></span>

    {{-- An expression rather than @if: a Blade conditional inside a tag leaves its
         whitespace behind, so the tag renders as `<script >`. The nonce is escaped
         because it arrives from a host implementation of the contract.

         `defer` because the script only touches the DOM and must not block the parse
         of a sign-in screen for a progressive enhancement. --}}
    <script src="{{ $emlCountdownScript }}" defer{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}></script>
@endif
