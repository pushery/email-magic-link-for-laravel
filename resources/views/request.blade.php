@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.request_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.heading', ['app' => app(\EmailMagicLink\Support\MagicLinkConfig::class)->applicationName()]) }}</h1>
    <p>{{ $mode === 'code' ? __('email-magic-link::messages.request_intro_code') : __('email-magic-link::messages.request_intro_link') }}</p>

    {{-- A title, the status, and the optional hint below it, as on the WireKit screens. --}}
    @if (session('status'))
        @php($emlSentHint = __('email-magic-link::messages.status_sent_hint'))
        <div class="status" role="status">
            <strong class="eml-status-title">{{ __('email-magic-link::messages.status_sent_title') }}</strong>
            {{ session('status') }}
            @if ($emlSentHint !== '')
                <span class="eml-status-hint">{{ $emlSentHint }}</span>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.request') }}" data-email-magic-link-form="request">
        @csrf

        <label for="email">{{ __('email-magic-link::messages.email_label') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ \EmailMagicLink\Support\SentText::old('email') }}"
            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>

        {{-- During a held-back resend this line carries the wait, and the countdown script keeps
             it current and takes it away when the wait is over. --}}
        @error('email')
            <p class="error" id="email-error">{{ $message }}</p>
        @enderror

        @if ($mode === 'both')
            <fieldset>
                <legend>{{ __('email-magic-link::messages.delivery_legend') }}</legend>
                <label><input type="radio" name="channel" value="link" @checked(old('channel', 'link') === 'link')> {{ __('email-magic-link::messages.delivery_link') }}</label>
                <label><input type="radio" name="channel" value="code" @checked(old('channel') === 'code')> {{ __('email-magic-link::messages.delivery_code') }}</label>
            </fieldset>
        @endif

        @if (($emlCaptchaView = app(\EmailMagicLink\Support\MagicLinkConfig::class)->captchaView()) !== null)
            @include($emlCaptchaView)
        @endif

        @include('email-magic-link::partials.resend-countdown')

        {{-- After a send, and after a resend that was held back, the same button sends another mail. --}}
        @php($emlSent = session()->has('status') || session()->has('resend_retry_after'))
        @php($emlSendLabel = $mode === 'code'
            ? ($emlSent ? __('email-magic-link::messages.request_resend_code') : __('email-magic-link::messages.request_send_code'))
            : ($emlSent ? __('email-magic-link::messages.request_resend_link') : __('email-magic-link::messages.request_send_link')))
        <button type="submit"{!! session('resend_retry_after') ? ' aria-describedby="email-error"' : '' !!}>{{ $emlSendLabel }}</button>

        {{-- The way back to the application's own sign-in, where it names one, in every state. --}}
        @if (($emlSignInUrl = app(\EmailMagicLink\Support\MagicLinkConfig::class)->uiSignInUrl()) !== null)
            <p class="eml-sign-in"><a href="{{ $emlSignInUrl }}">{{ __('email-magic-link::messages.back_to_sign_in') }}</a></p>
        @endif
    </form>
@endsection
