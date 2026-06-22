@extends('email-magic-link::wirekit.layout')

@section('title', __('email-magic-link::messages.confirm_title'))

@section('content')
    <x-wirekit::card>
        <x-wirekit::heading>{{ __('email-magic-link::messages.heading', ['app' => config('app.name')]) }}</x-wirekit::heading>
        <x-wirekit::text>{{ __('email-magic-link::messages.confirm_intro') }}</x-wirekit::text>

        @error('email')
            <x-wirekit::alert variant="danger">{{ $message }}</x-wirekit::alert>
        @enderror

        <form method="POST" action="{{ $action }}">
            @csrf
            <x-wirekit::button type="submit">{{ __('email-magic-link::messages.sign_in') }}</x-wirekit::button>
        </form>
    </x-wirekit::card>
@endsection
