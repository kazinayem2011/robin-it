<?php

namespace App\Notifications;

use App\Models\Order;

/** An order has been placed. Told to whoever handles orders. */
class OrderPlaced extends ShopNotification
{
    public function __construct(public readonly Order $order) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'order.placed',
            'title' => 'New order '.$this->order->order_number,
            /*
             * The name is on the shipping address, not a column: an order can
             * be placed without an account, so the person who placed it is
             * whoever the delivery is addressed to.
             */
            'body' => trim(($this->order->shipping_address['name'] ?? 'A customer')
                .' · ৳'.number_format((float) $this->order->total)),
            'url' => '/admin/orders?search='.$this->order->order_number,
            'icon' => 'order',
        ];
    }
}
