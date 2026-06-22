@extends('email-magic-link::layout')

@section('title', 'Enter your code')

@section('content')
    <h1>Enter your sign-in code</h1>
    <p>We emailed you a one-time code. Enter it below to finish signing in.</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('email-magic-link.code.consume') }}">
        @csrf

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="{{ $email ?: old('email') }}">

        <label for="code" style="margin-top:1rem">Sign-in code</label>
        <input id="code" name="code" type="text" inputmode="text" autocomplete="one-time-code" required autofocus>

        @error('code')
            <p class="error">{{ $message }}</p>
        @enderror

        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        <button type="submit">Sign in</button>
    </form>
@endsection
