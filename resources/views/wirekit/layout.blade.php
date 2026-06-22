<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('email-magic-link::messages.sign_in')) &middot; {{ config('app.name') }}</title>
    @vite(config('email-magic-link.ui.vite', ['resources/css/app.css']))
</head>
<body class="min-h-screen grid place-items-center bg-neutral-50 dark:bg-neutral-950">
    <main class="w-full max-w-sm p-8">
        @yield('content')
    </main>
    @livewireScripts
    @wirekitScripts
</body>
</html>
