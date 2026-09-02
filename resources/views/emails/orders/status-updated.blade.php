@php($brand = \App\Support\BrandDetails::all())
@php($labels = [
    'pending' => ['Order placed', 'We have your order and are confirming the details.'],
    'processing' => ['Being packed', 'Your hardware is being tested and packed for dispatch.'],
    'shipped' => ['Out for delivery', 'Your parcel is with our courier and on its way to you.'],
    'delivered' => ['Delivered', 'Your order has been delivered. We hope you enjoy it.'],
    'cancelled' => ['Cancelled', 'This order has been cancelled. Any reserved stock has been released.'],
])
@php($current = $labels[$order->status] ?? $labels['pending'])
@php($isCancelled = $order->status === 'cancelled')

@extends('emails.layouts.master', [
    'title' => 'Order #'.$order->order_number.' — '.$current[0],
    'preheader' => 'Order #'.$order->order_number.' is now '.strtolower($current[0]).'. '.$current[1],
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        {{ $current[0] }}
    </h1>

    <p class="eml-text" style="margin:0 0 10px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        Hi {{ $order->recipient_name }},
    </p>
    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        {{ $current[1] }}
    </p>

    {{-- Status banner --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 26px;">
        <tr>
            <td align="center" style="background-color:{{ $isCancelled ? '#7f1d1d' : '#0f172a' }}; border-radius:8px; padding:24px 20px;">
                <span style="display:block; margin-bottom:6px; font-family:Arial,Helvetica,sans-serif; font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#94a3b8;">
                    Order #{{ $order->order_number }}
                </span>
                <span style="display:block; font-family:Arial,Helvetica,sans-serif; font-size:22px; font-weight:bold; letter-spacing:0.5px; color:#ffffff;">
                    {{ strtoupper($current[0]) }}
                </span>
            </td>
        </tr>
    </table>

    {{-- Details --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin:0 0 28px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#475569;">
                <span style="display:block; margin-bottom:8px; font-size:12px; font-weight:bold; color:#0f172a; text-transform:uppercase; letter-spacing:0.6px;">Order details</span>
                <strong style="color:#0f172a;">Total:</strong> ৳{{ number_format($order->total, 2) }}
                @unless ($isCancelled) (cash on delivery) @endunless<br>
                <strong style="color:#0f172a;">Delivering to:</strong> {{ $order->formatted_shipping_address }}<br>
                <strong style="color:#0f172a;">Contact:</strong> {{ $order->recipient_phone }}
            </td>
        </tr>
    </table>

    @unless ($isCancelled)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-btn">
            <tr>
                <td align="center" style="padding:0 0 24px;">
                    <!--[if mso]>
                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                                 href="{{ $brand['url'] }}/track/{{ $order->order_number }}" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="#d12127">
                        <w:anchorlock/>
                        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Track your order</center>
                    </v:roundrect>
                    <![endif]-->
                    <!--[if !mso]><!-- -->
                    <a href="{{ $brand['url'] }}/track/{{ $order->order_number }}"
                       style="display:inline-block; background-color:#d12127; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; line-height:46px; text-align:center; text-decoration:none; width:260px; border-radius:8px;">
                        Track your order
                    </a>
                    <!--<![endif]-->
                </td>
            </tr>
        </table>
    @endunless

    <p class="eml-muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#64748b; text-align:center;">
        Questions? Reply to this email or call
        <a href="{{ \App\Support\BrandDetails::hotlineHref() }}" class="eml-accent" style="color:#d12127; text-decoration:none; font-weight:bold;">{{ $brand['hotline'] }}</a>.
    </p>
@endsection
