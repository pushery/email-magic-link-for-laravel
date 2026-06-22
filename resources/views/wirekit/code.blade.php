@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.code_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::heading>{{ __('email-magic-link::messages.code_heading') }}</x-wirekit::heading>
        <x-wirekit::text>{{ __('email-magic-link::messages.code_intro') }}</x-wirekit::text>

        @if (session('status'))
            <x-wirekit::alert variant="success">{{ session('status') }}</x-wirekit::alert>
        @endif

        <form method="POST" action="{{ route('email-magic-link.code.consume') }}">
            @csrf

            <x-wirekit::input
                name="email"
                type="email"
                :label="__('email-magic-link::messages.email_label')"
                autocomplete="email"
                required
                :value="$email ?: old('email')"
                :error="$errors->first('email')"
            />

            <x-wirekit::otp-input
                name="code"
                :length="(int) config('email-magic-link.code_length', 8)"
                :label="__('email-magic-link::messages.code_label')"
                :error="$errors->first('code')"
            />

            <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>
        </form>
    </x-wirekit::card>
@endsection
