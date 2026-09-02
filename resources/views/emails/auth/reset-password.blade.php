@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Reset your password',
    'preheader' => 'A link to choose a new password. It expires shortly, and doing nothing leaves your account untouched.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        Reset your password
    </h1>

    <p class="eml-text" style="margin:0 0 10px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        Hi {{ $user->name }},
    </p>
    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        Someone asked to reset the password on this account. Choose a new one
        with the button below.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-btn">
        <tr>
            <td align="center" style="padding:0 0 24px;">
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                             href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="#d12127">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Choose a new password</center>
                </v:roundrect>
                <![endif]-->
                <!--[if !mso]><!-- -->
                <a href="{{ $url }}"
                   style="display:inline-block; background-color:#d12127; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; line-height:46px; text-align:center; text-decoration:none; width:260px; border-radius:8px;">
                    Choose a new password
                </a>
                <!--<![endif]-->
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:0 0 24px;">
        <tr>
            <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#475569; word-break:break-all;">
                Or paste this into your browser:<br>
                <span style="color:#0f172a;">{{ $url }}</span>
            </td>
        </tr>
    </table>

    <p class="eml-muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#64748b; text-align:center;">
        The link expires in {{ $expiresInMinutes }} minutes. If this was not you,
        ignore this message — the password stays as it is until someone opens
        that link.
    </p>
@endsection
