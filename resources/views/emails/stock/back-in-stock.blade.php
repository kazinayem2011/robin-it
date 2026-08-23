@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Back in stock: '.$displayName,
    'preheader' => $displayName.' is available again — '.$available.' in stock at ৳'.number_format($price, 2).'.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        It's back in stock
    </h1>

    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        You asked to be told when this came back. It has just arrived.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin:0 0 26px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
                <strong class="eml-text" style="color:#0f172a; font-size:16px;">{{ $displayName }}</strong><br>
                <span class="eml-accent" style="color:#d12127; font-weight:bold; font-size:18px;">৳{{ number_format($price, 2) }}</span>
                <span class="eml-muted" style="color:#64748b;">&nbsp;·&nbsp; {{ $available }} available</span>
            </td>
        </tr>
    </table>

    {{-- Stock is not held for anyone, so say so plainly rather than implying
         it will still be there tomorrow. --}}
    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#475569;">
        We haven't reserved one — everyone waiting was told at the same time, so
        it is worth ordering soon if you still want it.
    </p>

    {{-- Bulletproof CTA: VML for Outlook, a normal anchor everywhere else. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-btn">
        <tr>
            <td align="center" style="padding:0 0 24px;">
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                             href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="#d12127">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">View the product</center>
                </v:roundrect>
                <![endif]-->
                <!--[if !mso]><!-- -->
                <a href="{{ $url }}"
                   style="display:inline-block; background-color:#d12127; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; line-height:46px; text-align:center; text-decoration:none; width:260px; border-radius:6px;">
                    View the product
                </a>
                <!--<![endif]-->
            </td>
        </tr>
    </table>

    <p class="eml-muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#64748b; text-align:center;">
        You received this because you asked to be notified about this item. We
        won't email you about it again unless you ask.
    </p>
@endsection
