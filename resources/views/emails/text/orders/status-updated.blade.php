@php($brand = \App\Support\BrandDetails::all())
@php($labels = [
    'pending' => ['Order placed', 'We have your order and are confirming the details.'],
    'processing' => ['Being packed', 'Your hardware is being tested and packed for dispatch.'],
    'shipped' => ['Out for delivery', 'Your parcel is with our courier and on its way to you.'],
    'delivered' => ['Delivered', 'Your order has been delivered. We hope you enjoy it.'],
    'cancelled' => ['Cancelled', 'This order has been cancelled. Any reserved stock has been released.'],
])
@php($current = $labels[$order->status] ?? $labels['pending'])
{{ $brand['name'] }} — {{ $brand['tagline'] }}
==============================================

{{ strtoupper($current[0]) }}

Hi {{ $order->recipient_name }},

{{ $current[1] }}

Order number: #{{ $order->order_number }}
Total:        ৳{{ number_format($order->total, 2) }}
Delivering to: {{ $order->formatted_shipping_address }}
Contact:      {{ $order->recipient_phone }}
@if ($order->status !== 'cancelled')

Track your order: {{ $brand['url'] }}/track
@endif

Questions? Reply to this email or call {{ $brand['hotline'] }}.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
