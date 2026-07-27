@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.code_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                <x-wirekit::heading>{{ __('email-magic-link::messages.code_heading') }}</x-wirekit::heading>
                <x-wirekit::text>{{ __('email-magic-link::messages.code_intro') }}</x-wirekit::text>

                @if (session('status'))
                    <x-wirekit::alert variant="success" :icon="false">{{ session('status') }}</x-wirekit::alert>
                @endif

                <x-wirekit::stack as="form" method="POST" action="{{ route('email-magic-link.code.consume') }}">
                    @csrf
                    @if ($guard ?: old('guard'))
                        <input type="hidden" name="guard" value="{{ $guard ?: old('guard') }}">
                    @endif

                    <x-wirekit::input
                        name="email"
                        type="email"
                        :label="__('email-magic-link::messages.email_label')"
                        autocomplete="email"
                        required
                        :value="$email ?: old('email')"
                        :error="$errors->first('email')"
                    />

                    {{-- WireKit's otp-input is digits-only, and it enforces that in four
                         places: typing a non-digit clears the box, pasting strips every
                         non-digit, inputmode is numeric and pattern is [0-9]. Our default
                         code_alphabet is ABCDEFGHJKMNPQRSTUVWXYZ23456789 — mostly letters —
                         so on a default install those boxes cannot accept the code the
                         package itself just mailed out.

                         So the boxed field is used only when the configured alphabet really
                         is numeric, where it is both correct and nicer. Otherwise this falls
                         back to a single mono input, which is what the plain Blade twin has
                         always used. Nothing is lost by the swap: auto-advance, arrow
                         navigation and paste-distribution are all digit-filtered, so they
                         never worked for a letter in the first place.

                         The single field goes away again once WireKit's boxed input
                         accepts a caller-supplied alphabet; the branch is reported
                         upstream and tracked privately until then. --}}
                    @php($emlAlphabet = (string) config('email-magic-link.code_alphabet', ''))
                    @php($emlCodeLength = (int) config('email-magic-link.code_length', 8))

                    @if ($emlAlphabet !== '' && ctype_digit($emlAlphabet))
                        <x-wirekit::otp-input
                            name="code"
                            class="eml-otp"
                            :length="$emlCodeLength"
                            :label="__('email-magic-link::messages.code_label')"
                            :error="$errors->first('code')"
                        />
                    @else
                        <x-wirekit::input
                            name="code"
                            type="text"
                            mono
                            inputmode="text"
                            autocomplete="one-time-code"
                            autocapitalize="characters"
                            spellcheck="false"
                            :maxlength="$emlCodeLength"
                            :label="__('email-magic-link::messages.code_label')"
                            :error="$errors->first('code')"
                            required
                            autofocus
                        />
                    @endif

                    <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>
                </x-wirekit::stack>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
