@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.heading', ['app' => config('app.name')]) }}</h1>
    <p>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.request') }}">
        @csrf

        <label for="email">{{ __('email-magic-link::messages.email_label') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}">

        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        @if ($mode === 'both')
            <fieldset>
                <legend>{{ __('email-magic-link::messages.delivery_legend') }}</legend>
                <label><input type="radio" name="channel" value="link" checked> {{ __('email-magic-link::messages.delivery_link') }}</label>
                <label><input type="radio" name="channel" value="code"> {{ __('email-magic-link::messages.delivery_code') }}</label>
            </fieldset>
        @endif

        <button type="submit">{{ $mode === 'code' ? __('email-magic-link::messages.request_send_code') : __('email-magic-link::messages.request_send_link') }}</button>
    </form>
@endsection
