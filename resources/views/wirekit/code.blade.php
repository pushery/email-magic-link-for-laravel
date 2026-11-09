@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.code_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                {{-- Read once: an anonymous component takes a bound attribute as data and as an attribute, so an
                     expression bound here would run twice, and an unusable level would be logged twice. --}}
                @php($emlHeadingLevel = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiHeadingLevel())
                <x-wirekit::heading :level="$emlHeadingLevel" :size="app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiHeadingSize()">{{ __('email-magic-link::messages.code_heading') }}</x-wirekit::heading>
                <x-wirekit::text>{{ __('email-magic-link::messages.code_intro') }}</x-wirekit::text>

                {{-- The host's error summary, where errors_view names one: it lists every message and
                     takes the focus, so the form below tells its fields not to announce them again. --}}
                @if ($emlErrorsView = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiErrorsView())
                    @include($emlErrorsView)
                @endif

                {{-- As on the request screen: a title, the status, and the optional hint below it. --}}
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
                <x-wirekit::form :announce-errors="$emlErrorsView !== null ? false : null" method="POST" action="{{ route('email-magic-link.code.consume') }}" data-email-magic-link-form="code">
                    <x-wirekit::stack>
                        @csrf
                        @if ($guard ?: \EmailMagicLink\Support\SentText::old('guard'))
                            <input type="hidden" name="guard" value="{{ $guard ?: \EmailMagicLink\Support\SentText::old('guard') }}">
                        @endif

                        <x-wirekit::input
                            name="email"
                            type="email"
                            :label="__('email-magic-link::messages.email_label')"
                            autocomplete="email"
                            required
                            :value="$email ?: \EmailMagicLink\Support\SentText::old('email')"
                            :error="$errors->first('email')"
                        />

                        {{-- One field for every alphabet, through otp-input's `alphabet` prop
                             (WireKit 2.22.0 and later). Without it the component is digits-only
                             in four places — typing a non-digit clears the box, pasting strips
                             every non-digit, inputmode is numeric and pattern is [0-9] — while
                             the default code_alphabet is ABCDEFGHJKMNPQRSTUVWXYZ23456789, mostly
                             letters: the boxes could not accept the code the package just mailed.

                             The prop derives every one of those four constraints from the
                             alphabet, which is why one argument is enough here — a prop that
                             only relaxed `pattern` would leave the keystroke filter discarding
                             the code while looking configurable.

                             Passed only when it is non-empty: the component falls back to
                             digits on an empty alphabet, and silently handing it '' would
                             bring back exactly that failure. An unconfigured code
                             channel never reaches this screen anyway — EntropyGuard refuses to
                             mint from defaults it was not given — so this is the belt for a
                             config that is present but blank. --}}
                        @php($emlAlphabet = app(\EmailMagicLink\Support\MagicLinkConfig::class)->codeAlphabet())
                        @php($emlCodeLength = app(\EmailMagicLink\Support\MagicLinkConfig::class)->codeLength())

                        {{-- `group` rather than a grid over the component's markup: the groups do not
                             break inside, so a row that fits stays one row and one that does not breaks
                             where the code itself has a boundary. Half the code, rounded up, from the
                             same class that decides how wide the card may grow — see CodeBoxLayout.
                             `justify` (2.54) centers the row like everything else on the card; before
                             it, that took a rule over the component's internal markup. --}}
                        @php($emlCodeGroup = new \EmailMagicLink\Support\CodeBoxLayout($emlCodeLength)->group())

                        <x-wirekit::otp-input
                            name="code"
                            class="eml-otp"
                            autofocus
                            :length="$emlCodeLength"
                            :group="$emlCodeGroup"
                            justify="center"
                            :alphabet="$emlAlphabet !== '' ? $emlAlphabet : '0123456789'"
                            :label="__('email-magic-link::messages.code_label')"
                            :error="$errors->first('code')"
                        />

                        {{-- After a refused code: the code field keeps the refusal, and here is the way
                             forward to a new code. The way back to the application's own sign-in stands
                             below the button in every state. --}}
                        @if (\EmailMagicLink\Support\MagicLinkRejection::flashed())
                            <x-wirekit::text size="sm">
                                <x-wirekit::link :href="route('email-magic-link.request.form')">{{ __('email-magic-link::messages.request_new_code') }}</x-wirekit::link>
                            </x-wirekit::text>
                        @endif

                        {{-- Kept across a refused code: the refusal flashes the choice back with the email. --}}
                        @if ($offerRemember ?? false)
                            <x-wirekit::checkbox
                                name="remember"
                                value="1"
                                :checked="old('remember') === '1'"
                                :label="__('email-magic-link::messages.remember_label')"
                            />
                        @endif

                        <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>

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
