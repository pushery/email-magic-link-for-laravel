{{--
    The mail with a one-time code: Laravel's notification template, with the line that is the code
    in a panel.

    In the HTML part the panel sets the code apart from the sentences around it. Its text part prints
    the content alone, so a reader who copies the code from a mail without HTML copies the code and
    nothing else, where bold or a heading in Markdown would reach that part with its marks. The lines
    stay the lines of the MailMessage, so whatever reads them finds the code where it was.

    $code — the code, the one of $introLines the panel takes.
--}}
<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@else
# @lang('Hello!')
@endif

@foreach ($introLines as $line)
@if (isset($code) && (string) $line === $code)
<x-mail::panel>
{{ $line }}
</x-mail::panel>
@else
{{ $line }}
@endif

@endforeach
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif
</x-mail::message>
