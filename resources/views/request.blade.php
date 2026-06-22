@extends('email-magic-link::layout')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in to {{ config('app.name') }}</h1>
    <p>Enter your email address and we will send you a secure sign-in {{ $mode === 'code' ? 'code' : 'link' }}.</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.request') }}">
        @csrf

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}">

        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        @if ($mode === 'both')
            <fieldset>
                <legend>Delivery</legend>
                <label><input type="radio" name="channel" value="link" checked> Magic link</label>
                <label><input type="radio" name="channel" value="code"> One-time code</label>
            </fieldset>
        @endif

        <button type="submit">Send sign-in {{ $mode === 'code' ? 'code' : 'link' }}</button>
    </form>
@endsection
