@php($brand = \App\Support\BrandDetails::all())
{{ $brand['name'] }} — {{ $brand['tagline'] }}
==============================================

YOUR ORDER HAS BEEN UPDATED

Hi {{ $order->recipient_name }},

We've changed the items on order #{{ $order->order_number }}. This is
what it holds now, and what to pay on delivery.

ITEMS NOW
---------
@foreach ($order->items as $item)
- {{ $item->display_name }}
  {{ $item->quantity }} x ৳{{ number_format($item->price, 2) }} = ৳{{ number_format($item->total, 2) }}
@endforeach

Subtotal: ৳{{ number_format($order->subtotal, 2) }}
@if ($order->discount > 0)
@php($couponLabel = $order->coupon_code ? ' ('.$order->coupon_code.')' : '')
Discount{{ $couponLabel }}: -৳{{ number_format($order->discount, 2) }}
@endif
Delivery: ৳{{ number_format($order->shipping_fee, 2) }}
TOTAL DUE ON DELIVERY: ৳{{ number_format($order->total, 2) }}
(was ৳{{ number_format($totalBefore, 2) }})

DELIVERING TO
-------------
{{ $order->recipient_name }}
{{ $order->formatted_shipping_address }}
{{ $order->recipient_phone }}

Track your order: {{ $order->trackUrl() }}

Questions? Reply to this email or call {{ $brand['hotline'] }}.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
{{ $brand['email'] }}
