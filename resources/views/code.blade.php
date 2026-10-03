@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.code_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.code_heading') }}</h1>
    <p>{{ __('email-magic-link::messages.code_intro') }}</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.code.consume') }}">
        @csrf
        @if ($guard ?: old('guard'))
            <input type="hidden" name="guard" value="{{ $guard ?: old('guard') }}">
        @endif

        <label for="email">{{ __('email-magic-link::messages.email_label') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="{{ $email ?: old('email') }}"
            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>

        <label for="code" class="eml-code-label">{{ __('email-magic-link::messages.code_label') }}</label>
        {{-- Capitals from the keyboard only where the server folds to them. A mixed alphabet is
             compared as typed, so a capital the keyboard chose is a wrong character, and a
             lower-case one is folded downward: both keep the case the person types. --}}
        @php($emlConfig = app(\EmailMagicLink\Support\MagicLinkConfig::class))
        @php($emlNumeric = $emlConfig->codeAlphabetIsNumeric())
        <input id="code" name="code" type="text" inputmode="{{ $emlNumeric ? 'numeric' : 'text' }}"
            @unless ($emlNumeric) autocapitalize="{{ $emlConfig->codeAlphabetCaseFolding() === 'upper' ? 'characters' : 'off' }}" spellcheck="false" @endunless
            autocomplete="one-time-code" required autofocus
            @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>

        @error('code')
            <p class="error" id="code-error">{{ $message }}</p>
        @enderror

        @error('email')
            <p class="error" id="email-error">{{ $message }}</p>
        @enderror

        <button type="submit">{{ __('email-magic-link::messages.sign_in') }}</button>
    </form>
@endsection
