<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $order->order_number }} — {{ $brand['name'] }}</title>

    {{-- An invoice must never end up in a search index: it carries a
         customer's name, address and phone number. --}}
    <meta name="robots" content="noindex, nofollow">

    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #d12127; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 32px 20px;
            background: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.5;
        }

        .sheet {
            max-width: 760px;
            margin: 0 auto;
            padding: 40px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        }

        .toolbar { max-width: 760px; margin: 0 auto 16px; display: flex; gap: 10px; justify-content: flex-end; }

        .btn {
            padding: 9px 18px; border: 1px solid var(--line); border-radius: 8px;
            background: #fff; color: var(--ink); font: inherit; font-size: 13px;
            font-weight: 600; text-decoration: none; cursor: pointer;
        }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }

        .head { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start;
                padding-bottom: 22px; border-bottom: 2px solid var(--ink); }
        .head img { max-height: 44px; }
        .wordmark { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
        .head-meta { text-align: right; font-size: 12px; color: var(--muted); }
        .doc-title { font-size: 22px; font-weight: 800; letter-spacing: 0.08em;
                     text-transform: uppercase; color: var(--ink); margin-bottom: 4px; }

        .parties { display: flex; gap: 32px; margin: 24px 0 28px; }
        .party { flex: 1; min-width: 0; }
        .party h3 { margin: 0 0 6px; font-size: 11px; letter-spacing: 0.08em;
                    text-transform: uppercase; color: var(--muted); }
        .party p { margin: 0; font-size: 13px; line-height: 1.6; }
        .party strong { display: block; font-size: 14px; }

        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 9px 8px; border-bottom: 1px solid var(--ink);
                   font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase;
                   color: var(--muted); text-align: left; }
        tbody td { padding: 12px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .option { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); }
        .option.preorder { color: #b45309; font-weight: 600; }

        .totals { margin-left: auto; margin-top: 18px; width: 280px; }
        .totals td { padding: 6px 8px; }
        .totals tr:last-child td { border-top: 2px solid var(--ink); padding-top: 10px;
                                   font-size: 17px; font-weight: 800; }
        .discount { color: #0d7a3f; }
        .totals tr.vat-note td { border: 0; padding-top: 2px; font-size: 11px; color: #6b7280; }
        .vat-reg { margin-top: 6px; font-size: 11px; color: #6b7280; }

        .payment { margin-top: 26px; padding: 14px 16px; border: 1px solid var(--line);
                   border-radius: 6px; background: #f8fafc; font-size: 13px; }
        .payment strong { color: var(--ink); }

        .foot { margin-top: 30px; padding-top: 18px; border-top: 1px solid var(--line);
                font-size: 12px; color: var(--muted); text-align: center; }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px;
                 font-size: 11px; font-weight: 700; text-transform: uppercase;
                 letter-spacing: 0.04em; background: #f1f5f9; color: var(--muted); }

        /* Printing: drop the page furniture and the buttons, and let the sheet
           fill the paper rather than sitting on a grey background. */
        @media print {
            /* Zero, so there is nowhere for the browser to print its own header
               and footer — the date, the tab title and the page number would
               otherwise run across the top and bottom of a customer's invoice.
               The paper margin comes from .sheet instead. */
            @page { margin: 0; }
            body { background: #fff; padding: 0; font-size: 12px; }
            .sheet { max-width: none; padding: 14mm; border-radius: 0; box-shadow: none; }
            .toolbar { display: none; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ url()->previous() }}" class="btn">Back</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div>
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $brand['name'] }}">
                @else
                    <div class="wordmark">{{ $brand['name'] }}</div>
                @endif
                <div class="head-meta" style="text-align:left; margin-top:8px;">
                    {{ $brand['hotline'] }}<br>
                    {{ $brand['url'] }}
                    {{-- A VAT invoice has to carry the registration it was issued under. --}}
                    @if ($vatNumber)
                        <div class="vat-reg">BIN: {{ $vatNumber }}</div>
                    @endif
                </div>
            </div>

            <div class="head-meta">
                <div class="doc-title">Invoice</div>
                <strong style="color:var(--ink); font-size:14px;">{{ $order->order_number }}</strong><br>
                {{ $order->created_at->format('d M Y, g:i A') }}<br>
                <span class="badge">{{ ucfirst($order->status) }}</span>
            </div>
        </div>

        <div class="parties">
            <div class="party">
                <h3>Billed to</h3>
                <p>
                    <strong>{{ $order->recipient_name }}</strong>
                    {{ $order->formatted_shipping_address }}<br>
                    {{ $order->recipient_phone }}
                    @if ($order->user?->email)
                        <br>{{ $order->user->email }}
                    @endif
                </p>
            </div>

            <div class="party">
                <h3>Payment</h3>
                <p>
                    <strong>{{ $order->payment_method === 'COD' ? 'Cash on delivery' : $order->payment_method }}</strong>
                    {{ ucfirst($order->payment_status) }}
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Unit price</th>
                    <th class="num">Qty</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            {{ $item->product_name }}
                            {{-- The option is part of what was bought; without
                                 it the invoice does not say which one. --}}
                            @if ($item->variant_name)
                                <span class="option">{{ $item->variant_name }}</span>
                            @endif
                            @if ($item->returned_quantity > 0)
                                <span class="option">{{ $item->returned_quantity }} returned</span>
                            @endif
                            {{-- An order mixing stock and pre-order lines is not
                                 one shipment, and the paperwork has to say which
                                 line is waiting on a delivery. --}}
                            @if ($item->wasPreordered())
                                <span class="option preorder">pre-order — ships when stock arrives</span>
                            @endif
                        </td>
                        <td class="num">৳{{ number_format($item->price, 2) }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">৳{{ number_format($item->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Subtotal</td>
                <td class="num">৳{{ number_format($order->subtotal, 2) }}</td>
            </tr>
            @if ($order->discount > 0)
                <tr class="discount">
                    <td>Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</td>
                    <td class="num">−৳{{ number_format($order->discount, 2) }}</td>
                </tr>
            @endif
            {{--
                VAT sits above Delivery because it is charged on the goods, not
                on the courier's fee. An inclusive order shows it as a note —
                the amount is already inside the subtotal, so adding it to the
                column would make the invoice fail to add up.
            --}}
            @if ($order->vat_amount > 0 && ! $order->vat_inclusive)
                <tr>
                    <td>{{ $order->vat_label }}</td>
                    <td class="num">৳{{ number_format($order->vat_amount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>Delivery</td>
                <td class="num">
                    {{ $order->shipping_fee > 0 ? '৳'.number_format($order->shipping_fee, 2) : 'Free' }}
                </td>
            </tr>
            <tr>
                <td>Total</td>
                <td class="num">৳{{ number_format($order->total, 2) }}</td>
            </tr>
            @if ($order->vat_amount > 0 && $order->vat_inclusive)
                <tr class="vat-note">
                    <td colspan="2" class="num">
                        {{ $order->vat_label }}: ৳{{ number_format($order->vat_amount, 2) }}
                    </td>
                </tr>
            @endif
        </table>

        @if ($order->payment_method === 'COD' && $order->payment_status !== 'paid')
            <div class="payment">
                <strong>Please have ৳{{ number_format($order->total, 2) }} ready for the delivery rider.</strong>
                Payment is collected on delivery.
            </div>
        @endif

        <div class="foot">
            Thank you for shopping with {{ $brand['name'] }}.<br>
            Questions about this order? Call {{ $brand['hotline'] }} quoting {{ $order->order_number }}.
        </div>
    </div>

    @if ($autoPrint)
        <script>
            // Arrived at from an explicit "print invoice" action, so go
            // straight to the dialog rather than making them click again.
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
