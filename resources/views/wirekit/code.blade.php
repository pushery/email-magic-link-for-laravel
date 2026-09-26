@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.code_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                <x-wirekit::heading :level="1">{{ __('email-magic-link::messages.code_heading') }}</x-wirekit::heading>
                <x-wirekit::text>{{ __('email-magic-link::messages.code_intro') }}</x-wirekit::text>

                @if (session('status'))
                    <x-wirekit::alert intent="success" :icon="false">{{ session('status') }}</x-wirekit::alert>
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

                    <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>
                </x-wirekit::stack>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
