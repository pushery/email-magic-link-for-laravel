@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.confirm_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                {{-- Read once: an anonymous component takes a bound attribute as data and as an attribute, so an
                     expression bound here would run twice, and an unusable level would be logged twice. --}}
                @php($emlHeadingLevel = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiHeadingLevel())
                <x-wirekit::heading :level="$emlHeadingLevel" :size="app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiHeadingSize()">{{ __('email-magic-link::messages.heading', ['app' => app(\EmailMagicLink\Support\MagicLinkConfig::class)->applicationName()]) }}</x-wirekit::heading>
                <x-wirekit::text>{{ __('email-magic-link::messages.confirm_intro') }}</x-wirekit::text>

                {{-- The host's error summary, where errors_view names one: it lists every message and
                     takes the focus, so the form below tells its fields not to announce them again. --}}
                @if ($emlErrorsView = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiErrorsView())
                    @include($emlErrorsView)
                @endif

                {{-- Left to the summary where there is one, which would otherwise announce it twice. --}}
                @if ($emlErrorsView === null)
                    @error('email')
                        <x-wirekit::alert intent="danger" :icon="false">{{ $message }}</x-wirekit::alert>
                    @enderror
                @endif

                {{-- A form whose controls inherit the announcement policy: off while a summary of the
                     host's carries the errors, and WireKit's own setting otherwise. --}}
                <x-wirekit::form :announce-errors="$emlErrorsView !== null ? false : null" method="POST" action="{{ $action }}" data-email-magic-link-form="confirm">
                    <x-wirekit::stack>
                        @csrf
                        @if ($requiresPassphrase ?? false)
                            <x-wirekit::input
                                name="passphrase"
                                type="password"
                                :label="__('email-magic-link::messages.passphrase_label')"
                                autocomplete="off"
                                required
                                autofocus
                            />
                        @endif
                        @if ($offerRemember ?? false)
                            <x-wirekit::checkbox name="remember" value="1" :label="__('email-magic-link::messages.remember_label')" />
                        @endif
                        <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>

                        {{-- The way back to the application's own sign-in, where it names one, so a person
                             who opened the link and would rather sign in another way is not stuck here. --}}
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
