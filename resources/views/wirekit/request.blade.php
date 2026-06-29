@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::card.body>
            <x-wirekit::stack>
                <x-wirekit::heading>{{ __('email-magic-link::messages.heading', ['app' => config('app.name')]) }}</x-wirekit::heading>
                <x-wirekit::text>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</x-wirekit::text>

                @if (session('status'))
                    <x-wirekit::alert variant="success" :icon="false">{{ session('status') }}</x-wirekit::alert>
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
                        :error="$errors->first('email')"
                    />

                    @if ($mode === 'both')
                        <x-wirekit::stack gap="sm">
                            <x-wirekit::radio name="channel" value="link" :label="__('email-magic-link::messages.delivery_link')" checked />
                            <x-wirekit::radio name="channel" value="code" :label="__('email-magic-link::messages.delivery_code')" />
                        </x-wirekit::stack>
                    @endif

                    <x-wirekit::button type="submit">
                        {{ $mode === 'code' ? __('email-magic-link::messages.request_send_code') : __('email-magic-link::messages.request_send_link') }}
                    </x-wirekit::button>
                </x-wirekit::stack>
            </x-wirekit::stack>
        </x-wirekit::card.body>
    </x-wirekit::card>
@endsection
