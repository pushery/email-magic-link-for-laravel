<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('email-magic-link::messages.sign_in')) &middot; {{ config('app.name') }}</title>

    {{-- The application's per-response CSP nonce, or null when it has no policy.
         Resolved once here and handed to every tag on this page that a strict
         policy would otherwise reject: WireKit's <link> and <script>, and the
         inline <style> below. Same seam the countdown partial uses. --}}
    @php($emlNonce = app(\EmailMagicLink\Contracts\ScriptNonce::class)->value())

    {{-- WireKit's design tokens (the --color-wk-*, --padding-wk-*, --radius-wk-*
         … custom properties every component reads) ship in dist/wirekit.css and
         are injected ONLY by this directive. Without it every var(--*-wk-*)
         resolves to nothing and the components render completely unstyled.

         The nonce argument arrived in WireKit 2.22.0. Under a policy
         built on 'strict-dynamic' the nonce is the ONLY thing that grants a tag —
         same origin is not enough — so before this the WireKit screens could not
         be served under the very policy an auth page is most likely to carry. An
         empty expression is what the directive already defaulted to, so a host
         without a policy renders byte-identically. --}}
    @wirekitStyles($emlNonce)

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
    <style{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}>
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
        /* Only the CENTERING is still ours. The `flex-wrap: wrap` half of this rule
           was a workaround and is retired: WireKit 2.21.1 ships the
           digit row as `flex flex-wrap gap-2`, so a long code wraps on its own.
           It does not center the wrapped remainder, and on this 24rem card an
           eight-digit code always wraps — two boxes hanging at the left edge under
           a full row read as a rendering fault rather than a layout. Reaching in
           through `role="group"` is still a consumer patch, so it stays as narrow
           as the delta actually is. */
        .eml-otp [role="group"] { justify-content: center; }
    </style>
</head>
<body>
    <main class="eml-shell">
        @yield('content')
    </main>
    {{-- ORDER IS LOAD-BEARING, and it used to be wrong here. @wirekitScripts
         registers WireKit's Alpine plugins on the `alpine:init` event, and
         @livewireScripts is what BOOTS Alpine — so WireKit has to come first or
         its plugins can miss the event they are waiting for. WireKit's own
         installer enforces exactly this order when it writes a layout, and its
         source says why. This layout had the two the other way round, and nothing
         caught it: the browser suite asserts that the screens render styled, which
         is CSS, so a missed plugin registration is invisible to it.

         Nonced for the same reason as the stylesheet above. A host that cannot
         grant 'unsafe-eval' has a lever since WireKit 2.22.0 —
         `wirekit.scripts.bundle = 'csp'`, built against Alpine's CSP distribution
         — but it is only half the answer here, and the docs say which half:
         @wirekitScripts force-injects Livewire's assets so Alpine reaches a
         pure-Blade page, and Livewire compiles its own directives at runtime the
         same way Alpine's default build does.

         BOTH tags take the nonce, and the second one is easy to forget: WireKit
         emits its own <script>, and @livewireScripts emits Livewire's. Without an
         argument Livewire falls back to Vite::cspNonce(), which is a DIFFERENT
         source than this package's ScriptNonce contract — so a host that resolves
         its nonce through us and not through Vite got a nonced WireKit tag and a
         bare Livewire one. Under 'strict-dynamic' the nonce is the only thing that
         grants a tag, so that one was simply blocked and Alpine never booted.
         Passing null is byte-identical to passing nothing (`null ?? Vite::cspNonce()`
         still applies), so a host without a policy renders exactly as before. --}}
    @wirekitScripts($emlNonce)
    @livewireScripts(['nonce' => $emlNonce])
</body>
</html>
