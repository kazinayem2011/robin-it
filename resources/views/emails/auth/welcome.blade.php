@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Welcome to '.$brand['name'],
    'preheader' => 'Your account is ready — track orders, save PC builds and manage warranties in one place.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        Welcome to {{ $brand['name'] }}
    </h1>

    <p class="eml-text" style="margin:0 0 10px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        Hi {{ $user->name }},
    </p>
    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        Your account is ready. Here's what you can do with it.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:0 0 28px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:24px; color:#475569;">
                <strong style="color:#0f172a;">Build a PC</strong> — pick parts with live compatibility checks, then save and share the build.<br>
                <strong style="color:#0f172a;">Track deliveries</strong> — follow your order from packing to your door.<br>
                <strong style="color:#0f172a;">Manage warranties</strong> — raise and follow RMA claims without a phone call.<br>
                <strong style="color:#0f172a;">Save addresses</strong> — check out faster next time.
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-btn">
        <tr>
            <td align="center" style="padding:0 0 24px;">
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                             href="{{ $brand['url'] }}/shop" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="#d12127">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Start shopping</center>
                </v:roundrect>
                <![endif]-->
                <!--[if !mso]><!-- -->
                <a href="{{ $brand['url'] }}/shop"
                   style="display:inline-block; background-color:#d12127; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; line-height:46px; text-align:center; text-decoration:none; width:260px; border-radius:8px;">
                    Start shopping
                </a>
                <!--<![endif]-->
            </td>
        </tr>
    </table>

    <p class="eml-muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#64748b; text-align:center;">
        Need help choosing parts? Call our team on
        <a href="{{ \App\Support\BrandDetails::hotlineHref() }}" class="eml-accent" style="color:#d12127; text-decoration:none; font-weight:bold;">{{ $brand['hotline'] }}</a>.
    </p>
@endsection
