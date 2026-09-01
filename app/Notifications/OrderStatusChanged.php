<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * The customer's own order has moved. Sent to them, not to the shop.
 *
 * Alongside the email rather than instead of it: the email is the record they
 * keep, this is the one they see without leaving the page they are on.
 */
class OrderStatusChanged extends ShopNotification
{
    /** What each status means to the person waiting, in their words. */
    private const WORDING = [
        'processing' => 'We are getting it ready.',
        'shipped' => 'It is on its way.',
        'delivered' => 'It has been delivered.',
        'cancelled' => 'The order has been cancelled.',
        'refunded' => 'A refund has been issued.',
    ];

    public function __construct(
        public readonly Order $order,
        public readonly string $status,
    ) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'order.status',
            'title' => 'Order '.$this->order->order_number.' is '.$this->status,
            'body' => self::WORDING[$this->status] ?? 'The status of your order has changed.',
            /*
             * The tracking page, which is the customer-facing view of one
             * order. /orders/{number} is not a route they have — it 404'd, so
             * every one of these notifications led nowhere.
             */
            'url' => '/track/'.$this->order->order_number,
            'icon' => 'order',
        ];
    }
}
