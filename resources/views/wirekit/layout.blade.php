{{-- The language the page SPEAKS, not the one that was asked for: on a locale this package
     has no bundle for (and the host published none), the strings fall back, and a `lang`
     that still names the requested locale sends a screen reader to the wrong voice. --}}
@php($emlLocale = app('translator')->has('email-magic-link::messages.sign_in', app()->getLocale(), false) ? app()->getLocale() : (string) config('app.fallback_locale', 'en'))
@php($emlConfig = app(\EmailMagicLink\Support\MagicLinkConfig::class))
@php($emlCodeBoxes = new \EmailMagicLink\Support\CodeBoxLayout($emlConfig->codeLength()))
<!DOCTYPE html>
{{-- The host's class for the root element, so a dark host renders these screens dark: WireKit declares
     its dark tokens under `.dark` on <html>, which the body slots below cannot reach. Null renders no
     attribute, so an install that says nothing renders this element exactly as before. --}}
<html lang="{{ str_replace('_', '-', $emlLocale) }}"@if ($emlHtmlClass = $emlConfig->uiHtmlClass()) class="{{ $emlHtmlClass }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('email-magic-link::messages.sign_in')) &raquo; {{ config('app.name') }}</title>

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

    {{-- The kit's fonts. @wirekitStyles delivers the tokens, --font-wk-sans among them, and not
         the @font-face rules and preloads that make that family load; only this component renders
         those. Without it the screens fell back to the system font while every other page of the
         host stood in its configured kit font. Nonced like the rest: its <style> is inline. --}}
    <x-wirekit::fonts :nonce="$emlNonce" />

    {{-- The host's compiled stylesheet (Tailwind v4 with WireKit's views
         @source'd) supplies the component utility classes. ui.vite points at
         the host's Vite entrypoint; set it false for a non-Vite host. ui.styles
         <link>s plain pre-compiled stylesheets (a CDN bundle, an asset() path). --}}
    @if ($emlVite = $emlConfig->uiVite())
        @vite($emlVite)
    @endif
    @foreach ($emlConfig->uiStyles() as $emlStylesheet)
        <link rel="stylesheet" href="{{ $emlStylesheet }}">
    @endforeach

    {{-- Self-contained page shell — deliberately NOT Tailwind utilities. Those
         class strings would live only in this vendor view, so a host Tailwind
         build would not emit them unless it @source'd our path. Keeping the
         shell in a tiny inline stylesheet centers the sign-in screen in any
         host, scanned or not. The card's own surface, spacing, and type still
         come from the WireKit tokens loaded above. --}}
    {{-- No color-scheme here: WireKit declares `light` on :root and `dark` under .dark
         (both at zero specificity, since 2.29.0), and an override to `light dark` painted
         native widgets dark on a page whose tokens stayed light. --}}
    <style{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}>
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            /* Three rows with the header and footer slots. A grid at least a viewport tall stretches
               its rows (align-content: normal) and centers each item inside its own, which put the
               wordmark ~230px above the card on a 1117px-tall desktop. Centering the rows keeps
               header, card and footer together. */
            align-content: center;
            background: var(--color-wk-bg, Canvas);
            color: var(--color-wk-text, CanvasText);
        }
        .eml-shell { width: 100%; max-width: 24rem; padding: 2rem; box-sizing: border-box; }
        /* The code boxes as a grid, because WireKit's row wraps wherever the width runs out: on this
           24rem card an eight-character code broke 6+2 on a desktop and 5+3 on a phone, at no
           boundary the code has. One row while it fits, the card widening for a long code; below
           that an even split, 4+4 or 3+3, halving again while a row still would not fit. The
           numbers come from CodeBoxLayout. Reaching in through `role="group"` is a consumer patch
           until WireKit's otp-input can split evenly on its own. */
        .eml-shell:has(.eml-otp) { max-width: {{ $emlCodeBoxes->cardMaxWidthRem() }}rem; }
        .eml-otp { container-type: inline-size; }
        .eml-otp [role="group"] { display: grid; grid-template-columns: repeat({{ $emlCodeBoxes->columns() }}, 2.5rem); justify-content: center; }
        @foreach ($emlCodeBoxes->splits() as $emlSplit)
        @@container (width < {{ $emlSplit['below'] }}rem) { .eml-otp [role="group"] { grid-template-columns: repeat({{ $emlSplit['columns'] }}, 2.5rem); } }
        @endforeach
    </style>
</head>
<body>
    {{-- The consumer's two slots, above and below the card. Both are null by default and
         render nothing; `ui.header_view` is where a wordmark belongs and `ui.footer_view`
         where a language switcher does. They sit OUTSIDE <main> on purpose: a brand mark and
         a locale control are not the main content of a sign-in screen. The body's grid stacks
         all three as centered rows, kept together by `align-content`, without either slot
         touching the card's width. --}}
    @if ($emlHeaderView = $emlConfig->uiHeaderView())
        @include($emlHeaderView)
    @endif
    <main class="eml-shell">
        @yield('content')
    </main>
    @if ($emlFooterView = $emlConfig->uiFooterView())
        @include($emlFooterView)
    @endif
    {{-- ORDER IS LOAD-BEARING, and it used to be wrong here. @wirekitScripts
         registers WireKit's Alpine plugins on the `alpine:init` event, and
         @livewireScripts is what BOOTS Alpine — so WireKit has to come first or
         its plugins can miss the event they are waiting for. WireKit's own
         installer enforces exactly this order when it writes a layout, and its
         source says why. This layout had the two the other way round, and nothing
         caught it: the browser suite asserts that the screens render styled, which
         is CSS, so a missed plugin registration is invisible to it.

         Nonced for the same reason as the stylesheet above. A host that cannot
         grant 'unsafe-eval' needs Livewire's CSP build (`livewire.csp_safe`), not
         WireKit's: Livewire's tag carries no `defer` and runs first, so its Alpine is
         the one that starts; WireKit's `csp` bundle detects it, registers against it
         and never starts an Alpine of its own. See "WireKit screens" in the docs.

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
