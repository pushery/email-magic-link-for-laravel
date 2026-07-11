@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.confirm_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.heading', ['app' => config('app.name')]) }}</h1>
    <p>{{ __('email-magic-link::messages.confirm_intro') }}</p>

    @error('email')
        <p class="error">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($requiresPassphrase ?? false)
            <label for="passphrase">{{ __('email-magic-link::messages.passphrase_label') }}</label>
            <input type="password" name="passphrase" id="passphrase" autocomplete="off" required autofocus>
        @endif
        <button type="submit">{{ __('email-magic-link::messages.sign_in') }}</button>
    </form>
@endsection
