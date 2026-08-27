@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Re: '.$contactMessage->subject,
    'preheader' => 'Our reply to the message you sent us.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:22px; line-height:30px; font-weight:bold; color:#0f172a;">
        Re: {{ $contactMessage->subject }}
    </h1>

    <p class="eml-text" style="margin:0 0 18px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        Hi {{ $contactMessage->name }},
    </p>

    <div class="eml-text" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        {!! nl2br(e($reply->body)) !!}
    </div>

    <p class="eml-muted" style="margin:0 0 8px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#475569;">
        — {{ $reply->author_name }}, {{ $brand['name'] }}
    </p>

    {{-- What they wrote, so the reply makes sense on its own. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:22px 0 0;">
        <tr>
            <td style="padding:16px 18px; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:22px; color:#64748b;">
                <strong style="color:#0f172a;">You wrote on {{ $contactMessage->created_at->format('d M Y') }}:</strong><br>
                {!! nl2br(e(\Illuminate\Support\Str::limit($contactMessage->message, 600))) !!}
            </td>
        </tr>
    </table>

    <p class="eml-muted" style="margin:22px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:22px; color:#64748b;">
        Reply to this email if you need anything else, or call {{ $brand['hotline'] ?? '' }}.
    </p>
@endsection
