<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('email-magic-link::messages.sign_in')) &middot; {{ config('app.name') }}</title>

    {{-- The application's per-response CSP nonce, or null when it has no policy.
         The WireKit layout has noncing its inline <style> since the CSP work; this one
         did not, and the omission is worth naming because the consequence is not subtle:
         under a strict policy the block below is blocked, and the block below IS the
         entire styling of these screens — the sign-in page arrives completely unstyled.
         The countdown script degrades politely when it is blocked. This does not.

         Same seam the countdown partial uses. --}}
    @php($emlNonce = app(\EmailMagicLink\Contracts\ScriptNonce::class)->value())

    {{-- An expression rather than @if: a Blade conditional inside a tag leaves its
         whitespace behind, so the tag renders as `<style >`.

         INLINE, not a file, and that was decided rather than inherited.
         The countdown's script moved out of the page so a strict `script-src 'self'`
         accepts it without a nonce, and the same argument appears to apply here. It does
         not: this whole screen fits inside one initial congestion window, so it paints in
         ONE round trip. A stylesheet in a file would cost a second one, render-blocking,
         on a page that is entirely above the fold — to save bytes the browser has already
         received. That is the standard treatment of critical CSS, and CriticalCssBudgetTest
         holds the threshold the decision rests on. --}}
    <style{!! $emlNonce === null ? '' : ' nonce="'.e($emlNonce).'"' !!}>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: Canvas;
            color: CanvasText;
        }
        main {
            width: 100%;
            max-width: 24rem;
            padding: 2rem;
            box-sizing: border-box;
        }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { line-height: 1.5; margin: 0 0 1rem; }
        label { display: block; font-weight: 600; margin: 0 0 0.25rem; }
        input[type="email"], input[type="text"] {
            width: 100%;
            padding: 0.6rem 0.75rem;
            font-size: 1rem;
            box-sizing: border-box;
            border: 1px solid GrayText;
            border-radius: 0.5rem;
            background: Field;
            color: FieldText;
        }
        button {
            width: 100%;
            margin-top: 1rem;
            padding: 0.65rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            border: 0;
            border-radius: 0.5rem;
            background: AccentColor;
            color: AccentColorText;
            cursor: pointer;
        }
        .status { padding: 0.75rem 1rem; border-radius: 0.5rem; background: color-mix(in srgb, AccentColor 15%, transparent); margin-bottom: 1rem; }
        .error { color: #b00020; margin: 0.4rem 0 0; font-size: 0.9rem; }
        fieldset { border: 0; padding: 0; margin: 0 0 1rem; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
