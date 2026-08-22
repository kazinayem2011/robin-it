@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Test email',
    'preheader' => 'Your SMTP settings are working — this message was sent from the admin.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        Your email settings work
    </h1>

    <p class="eml-muted" style="margin:0 0 20px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        This test was sent from {{ $brand['name'] }} using the SMTP settings saved
        in the admin. If you can read this, order confirmations and status updates
        will reach your customers too.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#475569;">
                <strong style="color:#0f172a;">Sent:</strong> {{ now()->format('d M Y, g:i A') }}<br>
                <strong style="color:#0f172a;">Host:</strong> {{ config('mail.mailers.smtp.host') }}:{{ config('mail.mailers.smtp.port') }}<br>
                <strong style="color:#0f172a;">From:</strong> {{ config('mail.from.address') }}
            </td>
        </tr>
    </table>
@endsection
