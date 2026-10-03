@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.heading', ['app' => app(\EmailMagicLink\Support\MagicLinkConfig::class)->applicationName()]) }}</h1>
    <p>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.request') }}">
        @csrf

        <label for="email">{{ __('email-magic-link::messages.email_label') }}</label>
        {{-- The field names the paragraph only while it is rendered: during a held-back resend
             the paragraph is left out, and a reference to a missing id describes nothing. --}}
        <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}"
            @error('email') aria-invalid="true" @if (! session('resend_retry_after')) aria-describedby="email-error" @endif @enderror>

        {{-- Skipped while a resend is held back: the countdown below carries that message and
             keeps it current; a second, static copy would say "30 seconds" for the whole wait. --}}
        @if (! session('resend_retry_after'))
            @error('email')
                <p class="error" id="email-error">{{ $message }}</p>
            @enderror
        @endif

        @if ($mode === 'both')
            <fieldset>
                <legend>{{ __('email-magic-link::messages.delivery_legend') }}</legend>
                <label><input type="radio" name="channel" value="link" @checked(old('channel', 'link') === 'link')> {{ __('email-magic-link::messages.delivery_link') }}</label>
                <label><input type="radio" name="channel" value="code" @checked(old('channel') === 'code')> {{ __('email-magic-link::messages.delivery_code') }}</label>
            </fieldset>
        @endif

        @include('email-magic-link::partials.resend-countdown')

        <button type="submit"{!! session('resend_retry_after') ? ' aria-describedby="eml-resend-countdown"' : '' !!}>{{ $mode === 'code' ? __('email-magic-link::messages.request_send_code') : __('email-magic-link::messages.request_send_link') }}</button>
    </form>
@endsection
