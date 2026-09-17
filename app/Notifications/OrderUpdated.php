<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * The shop changed the lines on the customer's own order, and so its total.
 *
 * Sent to the customer beside the email and the text. This is the one they
 * see without leaving the page they are on — and for an order whose bill has
 * just moved, the one most likely to be seen before the rider arrives.
 */
class OrderUpdated extends ShopNotification
{
    public function __construct(public readonly Order $order) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'order.updated',
            'title' => 'Order '.$this->order->order_number.' was updated',
            'body' => 'It now comes to ৳'.number_format((float) $this->order->total, 2).'. Tap to see what changed.',
            // Unlocked, so it opens the order on any device the customer is on.
            'url' => $this->order->trackPath(),
            'icon' => 'order',
        ];
    }
}
