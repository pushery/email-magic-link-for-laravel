<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('email-magic-link::messages.sign_in')) &middot; {{ config('app.name') }}</title>

    {{-- WireKit's design tokens (the --color-wk-*, --padding-wk-*, --radius-wk-*
         … custom properties every component reads) ship in dist/wirekit.css and
         are injected ONLY by this directive. Without it every var(--*-wk-*)
         resolves to nothing and the components render completely unstyled. --}}
    @wirekitStyles

    {{-- The host's compiled stylesheet (Tailwind v4 with WireKit's views
         @source'd) supplies the component utility classes. ui.vite points at
         the host's Vite entrypoint; set it false for a non-Vite host. ui.styles
         <link>s plain pre-compiled stylesheets (a CDN bundle, an asset() path). --}}
    @if ($emlVite = config('email-magic-link.ui.vite', ['resources/css/app.css']))
        @vite($emlVite)
    @endif
    @foreach ((array) config('email-magic-link.ui.styles', []) as $emlStylesheet)
        <link rel="stylesheet" href="{{ $emlStylesheet }}">
    @endforeach

    {{-- Self-contained page shell — deliberately NOT Tailwind utilities. Those
         class strings would live only in this vendor view, so a host Tailwind
         build would not emit them unless it @source'd our path. Keeping the
         shell in a tiny inline stylesheet centers the sign-in screen in any
         host, scanned or not. The card's own surface, spacing, and type still
         come from the WireKit tokens loaded above. --}}
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: var(--color-wk-bg, Canvas);
            color: var(--color-wk-text, CanvasText);
        }
        .eml-shell { width: 100%; max-width: 24rem; padding: 2rem; box-sizing: border-box; }
        /* WireKit's one-time-code digits are fixed-width boxes; for a long code
           their row is wider than a phone (and than this narrow card), so let it
           wrap to a second line instead of forcing the page to scroll sideways. */
        .eml-otp [role="group"] { flex-wrap: wrap; justify-content: center; }
    </style>
</head>
<body>
    <main class="eml-shell">
        @yield('content')
    </main>
    @livewireScripts
    @wirekitScripts
</body>
</html>
