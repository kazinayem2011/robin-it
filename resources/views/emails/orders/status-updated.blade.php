@extends('emails.layouts.master', ['title' => 'Order #' . $order->order_number . ' Status Update — Robin IT'])

@section('content')
    <h2 class="email-heading">Order Status Update</h2>
    <p class="email-lead">
        Hi <strong>{{ $order->user->name ?? 'Valued Customer' }}</strong>,<br>
        Your order <strong>#{{ $order->order_number }}</strong> has been updated to:
    </p>

    <!-- Status Highlight Box -->
    <div style="background-color: #0f172a; color: #ffffff; padding: 24px; border-radius: 8px; text-align: center; margin: 24px 0;">
        <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; display: block; margin-bottom: 6px;">CURRENT STAGE</span>
        <span style="font-size: 22px; font-weight: 900; color: #ea484f; letter-spacing: 1px;">
            {{ strtoupper($order->status) }}
        </span>
    </div>

    <div class="email-info-box">
        <div class="email-info-box-title">Order Details</div>
        <p class="email-info-box-text">
            <strong>Order Number:</strong> #{{ $order->order_number }}<br>
            <strong>Total Amount:</strong> ৳{{ number_format($order->total, 2) }} (Cash on Delivery)<br>
            <strong>Shipping Destination:</strong> {{ $order->formatted_shipping_address }}
        </p>
    </div>

    <!-- Track Order CTA Button -->
    <div class="email-button-wrap">
        <a href="{{ url('/track-order?order=' . $order->order_number) }}" class="email-button" target="_blank">
            View Live Tracking &rarr;
        </a>
    </div>

    <p style="font-size: 13px; color: #64748b; line-height: 1.5; text-align: center;">
        Thank you for choosing Robin IT — The Store of Technology.
    </p>
@endsection
