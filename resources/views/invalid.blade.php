@extends('email-magic-link::layout')

@section('title', __('email-magic-link::messages.invalid_title'))

@section('content')
    <h1>{{ __('email-magic-link::messages.invalid_title') }}</h1>
    <p>{{ $message }}</p>

    <p><a class="button" href="{{ route('email-magic-link.request.form') }}">{{ __('email-magic-link::messages.sign_in') }}</a></p>
@endsection
