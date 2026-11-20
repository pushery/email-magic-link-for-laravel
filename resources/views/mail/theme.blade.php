{{--
    The stylesheet of the mail with a one-time code.

    Laravel inlines one stylesheet into a mail, and a theme named on the message takes the place of
    the application's rather than adding to it. So this one begins with the application's own theme,
    found the way Laravel finds `mail.markdown.theme`, and adds one rule: the code in its panel is
    what the mail is for, so it stands large, dark and spaced, where the panel's own paragraph is
    the theme's body size in its gray. The text part prints the code alone either way, since a theme
    styles the HTML part and writes nothing.
--}}
@php
    // Named as the application's theme, this one would include itself without end.
    $base = config('mail.markdown.theme');
    $base = is_string($base) && $base !== '' && $base !== 'email-magic-link::mail.theme' ? $base : 'default';
    $custom = \Illuminate\Support\Str::start($base, 'mail.');
    $base = view()->exists($custom) ? $custom : (str_contains($base, '::') ? $base : 'mail::themes.'.$base);
@endphp
@include($base)

.inner-body .panel-item p {
    color: #18181b;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 0.2em;
    line-height: 1.3em;
}
