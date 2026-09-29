<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\PreorderLedger;

/** An order has been placed. Told to whoever handles orders. */
class OrderPlaced extends ShopNotification
{
    public function __construct(public readonly Order $order) {}

    public function payload(object $notifiable): array
    {
        /*
         * The customer is never told when part of an order has to wait for the
         * next delivery — to them the product is simply available. So the shop
         * is told instead, at once, naming what is short, rather than finding
         * out at the packing bench.
         */
        $ledger = app(PreorderLedger::class);
        $short = $this->order->items()->get()
            ->filter(fn (OrderItem $item) => $ledger->stillOwed(
                $this->order->id,
                (int) $item->product_id,
                $item->product_variant_id ? (int) $item->product_variant_id : null,
            ))
            ->map(fn (OrderItem $item) => $item->product_name.($item->variant_name ? " ({$item->variant_name})" : ''))
            ->values();

        return [
            'kind' => 'order.placed',
            'title' => 'New order '.$this->order->order_number
                .($short->isNotEmpty() ? ' — waiting for stock' : ''),
            /*
             * The name is on the shipping address, not a column: an order can
             * be placed without an account, so the person who placed it is
             * whoever the delivery is addressed to.
             */
            'body' => trim(($this->order->shipping_address['name'] ?? 'A customer')
                .' · ৳'.number_format((float) $this->order->total))
                .($short->isNotEmpty() ? '. Needs the next delivery: '.$short->implode(', ').'.' : ''),
            'url' => '/admin/orders?search='.$this->order->order_number,
            'icon' => 'order',
        ];
    }
}
