{{--
    A template's words, inside the layout every other email uses.

    The shop edits the words; this supplies the table markup and inline styles
    that Outlook needs around them. Keeping the two apart is what lets the
    wording be editable at all — a rich text editor handed the real markup
    would destroy it the first time anybody pressed a key.
--}}
@extends('emails.layouts.master', [
    'title' => $title,
    'preheader' => $preheader ?? null,
])

@section('content')
    {{--
        Unescaped, because this IS the markup: headings, paragraphs and links
        the editor produced. It is written by staff with the `settings`
        ability, never by a customer, and it is never rendered in the admin —
        only mailed.
    --}}
    {!! $bodyHtml !!}
@endsection
