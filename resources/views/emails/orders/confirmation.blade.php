@php($brand = \App\Support\BrandDetails::all())
@extends('emails.layouts.master', [
    'title' => 'Order Confirmation #'.$order->order_number,
    'preheader' => 'Order #'.$order->order_number.' confirmed — '.$order->items->count().' item(s), ৳'.number_format($order->total, 2).' cash on delivery.',
])

@section('content')
    <h1 class="eml-h1 eml-text" style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:32px; font-weight:bold; color:#0f172a;">
        Thank you for your order
    </h1>

    <p class="eml-text" style="margin:0 0 10px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#334155;">
        Hi {{ $order->recipient_name }},
    </p>
    <p class="eml-muted" style="margin:0 0 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:24px; color:#475569;">
        We've received your order and are getting it ready. You'll get another email as soon as it ships.
    </p>

    {{-- Order summary --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin:0 0 26px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#334155;">
                <span style="display:block; margin-bottom:8px; font-size:12px; font-weight:bold; color:#0f172a; text-transform:uppercase; letter-spacing:0.6px;">Order Summary</span>
                <strong style="color:#0f172a;">Order number:</strong> #{{ $order->order_number }}<br>
                <strong style="color:#0f172a;">Placed:</strong> {{ $order->created_at->format('d M Y, g:i A') }}<br>
                <strong style="color:#0f172a;">Payment:</strong> Cash on Delivery
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 6px;">
        <tr>
            <th align="left" style="padding:10px 0; border-bottom:2px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:11px; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:0.6px;">Item</th>
            <th align="center" width="50" style="padding:10px 0; border-bottom:2px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:11px; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:0.6px;">Qty</th>
            <th align="right" width="110" style="padding:10px 0; border-bottom:2px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:11px; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:0.6px;">Total</th>
        </tr>

        @foreach ($order->items as $item)
            <tr>
                <td style="padding:14px 8px 14px 0; border-bottom:1px solid #f1f5f9; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:20px; color:#0f172a;">
                    {{ $item->product_name }}
                    {{-- The option is part of what was bought: without it a
                         customer who chose the 32GB cannot tell from this
                         email which one is on its way. --}}
                    @if ($item->variant_name)
                        <span class="eml-muted" style="display:block; margin-top:3px; font-size:12px; color:#64748b;">{{ $item->variant_name }}</span>
                    @endif
                    <span class="eml-muted" style="display:block; margin-top:3px; font-size:12px; color:#64748b;">৳{{ number_format($item->price, 2) }} each</span>
                </td>
                <td align="center" style="padding:14px 0; border-bottom:1px solid #f1f5f9; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#334155;">{{ $item->quantity }}</td>
                <td align="right" style="padding:14px 0; border-bottom:1px solid #f1f5f9; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; white-space:nowrap;">৳{{ number_format($item->total, 2) }}</td>
            </tr>
        @endforeach
    </table>

    {{-- Totals --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 26px;">
        <tr>
            <td align="right" style="padding:8px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#475569;">Subtotal</td>
            <td align="right" width="110" style="padding:8px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#334155; white-space:nowrap;">৳{{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if ($order->discount > 0)
            <tr>
                <td align="right" style="padding:6px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#475569;">
                    Discount @if ($order->coupon_code)<span style="color:#64748b;">({{ $order->coupon_code }})</span>@endif
                </td>
                <td align="right" style="padding:6px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#16a34a; white-space:nowrap;">&minus; ৳{{ number_format($order->discount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td align="right" style="padding:6px 0 12px; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#475569;">Delivery</td>
            <td align="right" style="padding:6px 0 12px; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#334155; white-space:nowrap;">৳{{ number_format($order->shipping_fee, 2) }}</td>
        </tr>
        <tr class="eml-rule">
            <td align="right" style="padding:12px 0 0; border-top:2px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:16px; font-weight:bold; color:#0f172a;">Total due on delivery</td>
            <td align="right" class="eml-accent" style="padding:12px 0 0; border-top:2px solid #e2e8f0; font-family:Arial,Helvetica,sans-serif; font-size:18px; font-weight:bold; color:#d12127; white-space:nowrap;">৳{{ number_format($order->total, 2) }}</td>
        </tr>
    </table>

    {{-- Delivery address --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-panel"
           style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin:0 0 28px;">
        <tr>
            <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; color:#475569;">
                <span style="display:block; margin-bottom:8px; font-size:12px; font-weight:bold; color:#0f172a; text-transform:uppercase; letter-spacing:0.6px;">Delivering to</span>
                <strong style="color:#0f172a;">{{ $order->recipient_name }}</strong><br>
                {{ $order->formatted_shipping_address }}<br>
                {{ $order->recipient_phone }}
            </td>
        </tr>
    </table>

    {{-- Bulletproof CTA: VML for Outlook, a normal anchor everywhere else. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-btn">
        <tr>
            <td align="center" style="padding:0 0 24px;">
                <!--[if mso]>
                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                             href="{{ $brand['url'] }}/track" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="#d12127">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">Track your order</center>
                </v:roundrect>
                <![endif]-->
                <!--[if !mso]><!-- -->
                <a href="{{ $brand['url'] }}/track"
                   style="display:inline-block; background-color:#d12127; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; line-height:46px; text-align:center; text-decoration:none; width:260px; border-radius:6px;">
                    Track your order
                </a>
                <!--<![endif]-->
            </td>
        </tr>
    </table>

    <p class="eml-muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:21px; color:#64748b; text-align:center;">
        Questions about this order? Reply to this email or call
        <a href="{{ \App\Support\BrandDetails::hotlineHref() }}" class="eml-accent" style="color:#d12127; text-decoration:none; font-weight:bold;">{{ $brand['hotline'] }}</a>.
    </p>
@endsection
