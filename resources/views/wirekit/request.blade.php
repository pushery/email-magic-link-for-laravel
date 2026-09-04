@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                <x-wirekit::heading :level="1">{{ __('email-magic-link::messages.heading', ['app' => config('app.name')]) }}</x-wirekit::heading>
                <x-wirekit::text>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</x-wirekit::text>

                @if (session('status'))
                    <x-wirekit::alert intent="success" :icon="false">{{ session('status') }}</x-wirekit::alert>
                @endif

                <x-wirekit::stack as="form" method="POST" action="{{ route('email-magic-link.request') }}">
                    @csrf

                    <x-wirekit::input
                        name="email"
                        type="email"
                        :label="__('email-magic-link::messages.email_label')"
                        autocomplete="email"
                        required
                        autofocus
                        :value="old('email')"
                        :error="session('resend_retry_after') ? null : $errors->first('email')"
                    />

                    @if ($mode === 'both')
                        {{-- A fieldset with a legend, not a bare stack: the two radios are a
                             choice, and without a group label a screen reader announces
                             "Magic link" and "One-time code" with nothing saying what is
                             being chosen. The plain Blade twin has always used
                             fieldset + legend; this set had lost it. field.set brings its
                             own spacing, so it replaces the stack rather than wrapping it. --}}
                        <x-wirekit::field.set :legend="__('email-magic-link::messages.delivery_legend')">
                            {{-- old('channel'): a failed submit keeps the choice, or a person who picked
                                 the code, mistyped the address and corrected it receives a link. --}}
                            <x-wirekit::radio name="channel" value="link" :label="__('email-magic-link::messages.delivery_link')" :checked="old('channel', 'link') === 'link'" />
                            <x-wirekit::radio name="channel" value="code" :label="__('email-magic-link::messages.delivery_code')" :checked="old('channel') === 'code'" />
                        </x-wirekit::field.set>
                    @endif

                    {{-- Inside the form and before the button, so a keyboard user meets the
                         reason for the hold-back before the button that is held back. --}}
                    @include('email-magic-link::partials.resend-countdown')

                    <x-wirekit::button type="submit" :aria-describedby="session('resend_retry_after') ? 'eml-resend-countdown' : null">
                        {{ $mode === 'code' ? __('email-magic-link::messages.request_send_code') : __('email-magic-link::messages.request_send_link') }}
                    </x-wirekit::button>
                </x-wirekit::stack>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
