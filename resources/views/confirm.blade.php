@extends('email-magic-link::layout')

@section('title', 'Confirm sign in')

@section('content')
    <h1>Sign in to {{ config('app.name') }}</h1>
    <p>For your security, confirm that you want to sign in. This link can only be used once.</p>

    @error('email')
        <p class="error">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ $action }}">
        @csrf
        <button type="submit">Sign in</button>
    </form>
@endsection
