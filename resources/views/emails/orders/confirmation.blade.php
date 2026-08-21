@extends('emails.layouts.master', ['title' => 'Order Confirmation #' . $order->order_number . ' — Robin IT'])

@section('content')
    <h2 class="email-heading">Thank You for Your Order!</h2>
    <p class="email-lead">
        Hi <strong>{{ $order->user->name ?? 'Valued Customer' }}</strong>,<br>
        Your genuine tech hardware order has been successfully placed and is now being processed for delivery.
    </p>

    <!-- Order Summary Badge -->
    <div class="email-info-box">
        <div class="email-info-box-title">Order Overview</div>
        <p class="email-info-box-text">
            <strong>Order Number:</strong> #{{ $order->order_number }}<br>
            <strong>Date:</strong> {{ $order->created_at->format('F d, Y - h:i A') }}<br>
            <strong>Payment Method:</strong> Cash on Delivery (COD)<br>
            <strong>Status:</strong> <span class="text-primary">{{ strtoupper($order->status) }}</span>
        </p>
    </div>

    <!-- Items Table -->
    <table class="email-table">
        <thead>
            <tr>
                <th>Item Details</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product->name ?? 'Genuine Product' }}</strong>
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">৳{{ number_format(($item->unit_price ?? $item->price) * $item->quantity, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="text-right"><strong>Subtotal:</strong></td>
                <td class="text-right">৳{{ number_format($order->subtotal ?? $order->total - 60, 2) }}</td>
            </tr>
            @if(isset($order->discount) && $order->discount > 0)
                <tr>
                    <td colspan="2" class="text-right" style="color: #10b981;"><strong>Discount:</strong></td>
                    <td class="text-right" style="color: #10b981;">- ৳{{ number_format($order->discount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="2" class="text-right"><strong>Express Delivery:</strong></td>
                <td class="text-right">৳{{ number_format($order->shipping_cost ?? 60, 2) }}</td>
            </tr>
            <tr style="background-color: #f8fafc;">
                <td colspan="2" class="text-right" style="font-size: 16px;"><strong>Grand Total:</strong></td>
                <td class="text-right" style="font-size: 16px; color: #ea484f;"><strong>৳{{ number_format($order->total, 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <!-- Shipping Address -->
    <div class="email-info-box">
        <div class="email-info-box-title">Shipping &amp; Delivery Address</div>
        <p class="email-info-box-text">
            {{ $order->recipient_name }}<br>
            {{ $order->formatted_shipping_address }}<br>
            Phone: {{ $order->recipient_phone }}
        </p>
    </div>

    <!-- Track Order CTA Button -->
    <div class="email-button-wrap">
        <a href="{{ url('/track-order?order=' . $order->order_number) }}" class="email-button" target="_blank">
            Track Live Shipment Status &rarr;
        </a>
    </div>

    <p style="font-size: 13px; color: #64748b; line-height: 1.5; text-align: center;">
        If you have any questions regarding your shipment or warranty, simply reply to this email or call our hotline at 
        <strong style="color: #0f172a;">{{ \App\Models\SiteSetting::get('site_hotline', '09600-ROBIN-IT') }}</strong>.
    </p>
@endsection
