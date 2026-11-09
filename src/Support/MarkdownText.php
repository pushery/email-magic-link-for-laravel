<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A value somebody typed, set into a Markdown mail as text.
 *
 * A mail notification is Markdown: Laravel renders its view and parses the result with CommonMark. The escaping of
 * `{{ }}` is HTML escaping and leaves Markdown alone, so a name typed as `[Support](https://example.com)` arrives as a
 * link in a mail the application sent. `Markdown::withSecuredEncoding()` is a switch of the application, not of this
 * package, and it covers `[`, `<` and `>` only.
 *
 * Kept on one line and without whitespace at its ends, a value can carry Markdown only through ASCII punctuation, and
 * a numeric character reference stands for its character as text, never as syntax. So a line break becomes a space,
 * and every ASCII punctuation character becomes its reference. The mail shows the characters as they were typed, and
 * its text version decodes the references back.
 *
 * A mail program links an address it finds in the text by itself, so an address in a value is broken as well: a
 * doubled slash or backslash, which opens a web address or a network path, becomes a pair of look-alikes, and so does
 * a dot followed by a label that starts with a letter. An e-mail address stays as typed, because a mail program opens
 * it as a new mail rather than as a page, and an address somebody copies has to be the one that was typed. Every value
 * is text, so a URL the application means to link belongs in the line or in the action of the mail, never in a value.
 *
 * Both methods return an HtmlString, which `{{ }}` prints as it is. The result is safe as HTML by itself, because the
 * references cover `<`, `>`, `&` and both quotes.
 *
 * @internal
 */
final class MarkdownText
{
    /** The 32 characters CommonMark counts as ASCII punctuation, in the order of the code table. */
    private const string PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** An e-mail address as a mail program finds one in text. */
    private const string MAIL_ADDRESS = '/([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/';

    /**
     * A trusted line, such as a translation, with each placeholder set to its value as text.
     *
     * The line keeps its Markdown and is escaped the way `{{ }}` escapes a string. The placeholders are the
     * translator's: `:key` takes the value, `:Key` the value with its first letter in capitals, and `:KEY` the value
     * in capitals. The line is read once, so a value that holds a placeholder of its own is text like the rest of it.
     *
     * @param  array<string, string>  $values
     */
    public static function interpolate(string $line, array $values): HtmlString
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            $replacements[':'.Str::ucfirst($key)] = self::neutral(Str::ucfirst($value));
            $replacements[':'.Str::upper($key)] = self::neutral(Str::upper($value));
            $replacements[':'.$key] = self::neutral($value);
        }

        return new HtmlString(strtr(htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $replacements));
    }

    /**
     * A value on its own, such as a greeting, as text.
     */
    public static function text(string $value): HtmlString
    {
        return new HtmlString(self::neutral($value));
    }

    private static function neutral(string $value): string
    {
        // A sequence that is not UTF-8 becomes U+FFFD, as it does under `{{ }}`, because the Markdown parser refuses a
        // mail that holds one. Everything else comes back as it was.
        $value = htmlspecialchars_decode(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES);

        // Every run of whitespace that holds a line break becomes one space, and the ends are trimmed, so the value
        // stays on the line it was put on and cannot indent itself into a code block.
        $value = trim(preg_replace('/(?:\h*\R)+\h*/u', ' ', $value) ?? '');

        // An e-mail address keeps its characters: the references it gets show the same ones. An address holds no slash
        // and no backslash, so a doubled one is broken wherever it stands, and a scheme in front of an address still
        // loses its slashes.
        $parts = preg_split(self::MAIL_ADDRESS, $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text = '';

        foreach ($parts === false ? [$value] : $parts as $index => $part) {
            // The captured parts, at the odd places, are the addresses.
            $text .= $index % 2 === 1 ? self::references($part) : self::broken(self::references($part));
        }

        return $text;
    }

    /** Every ASCII punctuation character of the text as its numeric reference. */
    private static function references(string $text): string
    {
        $references = [];

        foreach (str_split(self::PUNCTUATION) as $character) {
            $references[$character] = '&#'.ord($character).';';
        }

        return strtr($text, $references);
    }

    /** The text with every web address and network path in it broken, so that no mail program links it. */
    private static function broken(string $text): string
    {
        // The look-alikes are U+2044 (fraction slash), U+2216 (set minus) and U+2024 (one dot leader).
        $text = str_replace(['&#47;&#47;', '&#92;&#92;'], ['&#8260;&#8260;', '&#8726;&#8726;'], $text);

        return preg_replace('/&#46;(?=\p{L}[\p{L}\p{N}])/u', '&#8228;', $text) ?? '';
    }
}
