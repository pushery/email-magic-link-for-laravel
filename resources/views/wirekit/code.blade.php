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

                    {{-- One field for every alphabet, since WireKit 2.22.0 gave otp-input
                         an `alphabet` prop. Until then it was digits-only and
                         enforced it in four places — typing a non-digit cleared the box,
                         pasting stripped every non-digit, inputmode was numeric and pattern
                         was [0-9] — while our default code_alphabet is
                         ABCDEFGHJKMNPQRSTUVWXYZ23456789, mostly letters. A default install
                         therefore had boxes that could not accept the code the package had
                         just mailed. This view carried a branch to a single mono input for
                         exactly that case; the branch is now retired.

                         The prop derives every one of those four constraints from the
                         alphabet, which is why one argument is enough here — a prop that
                         only relaxed `pattern` would have left the keystroke filter
                         discarding the code while looking configurable.

                         Passed only when it is non-empty: the component falls back to
                         digits on an empty alphabet, and silently handing it '' would
                         reinstate the exact failure this replaces. An unconfigured code
                         channel never reaches this screen anyway — EntropyGuard refuses to
                         mint from defaults it was not given — so this is the belt for a
                         config that is present but blank. --}}
                    @php($emlAlphabet = (string) config('email-magic-link.code_alphabet', ''))
                    @php($emlCodeLength = (int) config('email-magic-link.code_length', 8))

                    <x-wirekit::otp-input
                        name="code"
                        class="eml-otp"
                        :length="$emlCodeLength"
                        :alphabet="$emlAlphabet !== '' ? $emlAlphabet : '0123456789'"
                        :label="__('email-magic-link::messages.code_label')"
                        :error="$errors->first('code')"
                    />

                    <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>
                </x-wirekit::stack>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
