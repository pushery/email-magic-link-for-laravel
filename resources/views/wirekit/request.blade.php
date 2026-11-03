@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                <x-wirekit::heading :level="1" :size="app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiHeadingSize()">{{ __('email-magic-link::messages.heading', ['app' => app(\EmailMagicLink\Support\MagicLinkConfig::class)->applicationName()]) }}</x-wirekit::heading>
                <x-wirekit::text>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</x-wirekit::text>

                {{-- The host's error summary, where errors_view names one: it lists every message and
                     takes the focus, so the form below tells its fields not to announce them again. --}}
                @if ($emlErrorsView = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiErrorsView())
                    @include($emlErrorsView)
                @endif

                {{-- The title says what to do now, the status what happened without saying whether the
                     address has an account, and the hint below it is optional: a host that publishes
                     the translations and empties status_sent_hint shows none. --}}
                @if (session('status'))
                    @php($emlSentHint = __('email-magic-link::messages.status_sent_hint'))
                    <x-wirekit::alert intent="success" :icon="false" :title="__('email-magic-link::messages.status_sent_title')">
                        {{ session('status') }}
                        @if ($emlSentHint !== '')
                            <span class="eml-status-hint">{{ $emlSentHint }}</span>
                        @endif
                    </x-wirekit::alert>
                @endif

                {{-- A form whose controls inherit the announcement policy: off while a summary of the
                     host's carries the errors, and WireKit's own setting otherwise. --}}
                <x-wirekit::form :announce-errors="$emlErrorsView !== null ? false : null" method="POST" action="{{ route('email-magic-link.request') }}" data-email-magic-link-form="request">
                    <x-wirekit::stack>
                        @csrf

                        {{-- The field's own error line carries the wait of a held-back resend, and the
                             countdown script keeps it current. Without a live region: the line changes
                             every second, and a polite region would read each change out. --}}
                        <x-wirekit::input
                            id="email"
                            name="email"
                            type="email"
                            :label="__('email-magic-link::messages.email_label')"
                            autocomplete="email"
                            required
                            autofocus
                            :value="\EmailMagicLink\Support\SentText::old('email')"
                            :announce-error="session('resend_retry_after') ? false : null"
                        />

                        @if ($mode === 'both')
                            {{-- A fieldset with a legend, not a bare stack: the two radios are a
                                 choice, and without a group label a screen reader announces
                                 "Magic link" and "One-time code" with nothing saying what is
                                 being chosen. The plain Blade twin uses fieldset + legend
                                 too. field.set brings its own spacing, so it replaces the
                                 stack rather than wrapping it. --}}
                            <x-wirekit::field.set :legend="__('email-magic-link::messages.delivery_legend')">
                                {{-- old('channel'): a failed submit keeps the choice, or a person who picked
                                     the code, mistyped the address and corrected it receives a link. --}}
                                <x-wirekit::radio name="channel" value="link" :label="__('email-magic-link::messages.delivery_link')" :checked="old('channel', 'link') === 'link'" />
                                <x-wirekit::radio name="channel" value="code" :label="__('email-magic-link::messages.delivery_code')" :checked="old('channel') === 'code'" />
                            </x-wirekit::field.set>
                        @endif

                        {{-- The host's captcha widget, where captcha_view names one. Inside the form,
                             so the token it writes is posted with the request the captcha guard reads. --}}
                        @if (($emlCaptchaView = app(\EmailMagicLink\Support\MagicLinkConfig::class)->captchaView()) !== null)
                            @include($emlCaptchaView)
                        @endif

                        {{-- Inside the form and before the button, so a keyboard user meets the
                             reason for the hold-back before the button that is held back. --}}
                        @include('email-magic-link::partials.resend-countdown')

                        {{-- After a send, and after a resend that was held back, the same button sends
                             another mail, and its text says so. --}}
                        @php($emlSent = session()->has('status') || session()->has('resend_retry_after'))
                        @php($emlSendLabel = $mode === 'code'
                            ? ($emlSent ? __('email-magic-link::messages.request_resend_code') : __('email-magic-link::messages.request_send_code'))
                            : ($emlSent ? __('email-magic-link::messages.request_resend_link') : __('email-magic-link::messages.request_send_link')))
                        <x-wirekit::button type="submit" :aria-describedby="session('resend_retry_after') ? 'email-error' : null">
                            {{ $emlSendLabel }}
                        </x-wirekit::button>

                        {{-- The way back to the application's own sign-in, where it names one, in every
                             state: a person who chose the magic link by mistake, or would rather use a
                             password, finds it before a refusal as well as after one. --}}
                        @if (($emlSignInUrl = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiSignInUrl()) !== null)
                            <x-wirekit::text size="sm">
                                <x-wirekit::link :href="$emlSignInUrl">{{ __('email-magic-link::messages.back_to_sign_in') }}</x-wirekit::link>
                            </x-wirekit::text>
                        @endif
                    </x-wirekit::stack>
                </x-wirekit::form>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
