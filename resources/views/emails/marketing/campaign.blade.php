@extends('emails.layouts.master', [
    'title' => $campaign->subject ?: $campaign->title,
    'preheader' => Str::limit(strip_tags($body), 120),
])

@section('content')
    {{-- The writer's own words, already purified when the campaign was saved.
         Newlines are turned into breaks so somebody typing into a plain box
         gets the paragraphs they typed. --}}
    <div class="eml-text" style="font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        {!! nl2br($body) !!}
    </div>

    {{-- Every marketing email carries the way out, one click, no form. Without
         it the way to stop a shop's email is the spam button, which costs the
         shop its deliverability for everybody else on the list. --}}
    <p class="eml-muted" style="margin:34px 0 0; padding-top:18px; border-top:1px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:20px; color:#94a3b8;">
        You are receiving this because you shop with {{ $brand['name'] }} or joined our mailing list.
        <a href="{{ $unsubscribeUrl }}" style="color:#64748b;">Unsubscribe</a> — it takes one click and we will not ask why.
    </p>
@endsection
