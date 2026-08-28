<?php

namespace App\Support;

use App\Models\Order;

/**
 * What the shop actually says in a text message.
 *
 * Kept short on purpose. A gateway charges per 160 characters of plain text —
 * 70 if there is any Bengali in it — so a friendly extra sentence is a bill,
 * paid on every order, forever.
 */
class SmsTemplates
{
    /**
     * The message for an order that has just been placed.
     */
    public static function orderPlaced(Order $order, string $shop): string
    {
        $total = number_format((float) $order->total, 0);

        return "{$shop}: order {$order->order_number} received, Tk {$total}. "
            .'Track it at '.self::trackUrl($order);
    }

    /**
     * The message for an order that has moved.
     *
     * Null where a status is not worth a text. "Processing" tells a customer
     * nothing they can act on, and a message that says nothing still costs
     * money and still interrupts somebody's evening.
     */
    public static function statusChanged(Order $order, string $shop): ?string
    {
        return match ($order->status) {
            'shipped' => self::shipped($order, $shop),
            'delivered' => "{$shop}: order {$order->order_number} delivered. "
                .'Thank you. Keep this message for your warranty.',
            'cancelled' => "{$shop}: order {$order->order_number} has been cancelled. "
                .'Call us if that is not what you expected.',
            'returned' => "{$shop}: we have your return for order {$order->order_number}. "
                .'Any refund follows within a few working days.',
            default => null,
        };
    }

    /**
     * Dispatch, with the carrier's own tracking link where there is one.
     *
     * That link is the whole reason this message is worth sending: it is the
     * one thing the customer cannot find out for themselves.
     */
    private static function shipped(Order $order, string $shop): string
    {
        $courier = $order->courier?->name;
        $carrier = $courier ? " with {$courier}" : '';

        $link = $order->tracking_url ?: self::trackUrl($order);

        $number = $order->tracking_number
            ? " Consignment {$order->tracking_number}."
            : '';

        return "{$shop}: order {$order->order_number} is on its way{$carrier}.{$number} Track: {$link}";
    }

    public static function refundIssued(Order $order, float $amount, string $shop): string
    {
        return "{$shop}: Tk ".number_format($amount, 0)
            ." refunded against order {$order->order_number}. "
            .'It can take a few days to reach your account.';
    }

    /** What the shop is owed, when a delivery is going out unpaid. */
    public static function paymentDue(Order $order, float $due, string $shop): string
    {
        return "{$shop}: order {$order->order_number}, Tk ".number_format($due, 0)
            .' to pay on delivery. Please keep it ready.';
    }

    private static function trackUrl(Order $order): string
    {
        return url('/track/'.$order->order_number);
    }
}
