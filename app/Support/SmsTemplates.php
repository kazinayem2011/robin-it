<?php

namespace App\Support;

use App\Models\Order;
use App\Services\OtpService;

/**
 * What the shop actually says in a text message.
 *
 * In Bengali, because the gateway requires it. Their notice: every SMS must be
 * sent in Bengali, Banglish is not allowed, and Bengali mixed with English is
 * fine — but English alone is not. Every message here used to be English only,
 * so all nine were breaking that rule and the account was at risk.
 *
 * Mixed is what this uses. Order numbers, amounts and links stay in ASCII: a
 * tracking code is meant to be read back to a person or typed into a box, and
 * in Bengali digits it is neither.
 *
 * Kept short on purpose, and shorter than before. A gateway charges per 160
 * characters of plain text but only 70 once there is a single Bengali letter
 * in the message, so the alphabet alone more than doubled what these cost.
 * Every word here has been counted — see SmsService::parts().
 */
class SmsTemplates
{
    /**
     * The message for an order that has just been placed.
     *
     * Two parts, because of the tracking link. It is kept anyway: this is the
     * message nobody else sends, and the link is the reason to send it.
     */
    public static function orderPlaced(Order $order, string $shop): string
    {
        $total = number_format((float) $order->total, 0);

        return "{$shop}: অর্ডার {$order->order_number} পেয়েছি, Tk {$total}। "
            .'ট্র্যাক: '.self::trackUrl($order);
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
            'delivered' => "{$shop}: অর্ডার {$order->order_number} ডেলিভারি হয়েছে। "
                .'ধন্যবাদ। ওয়ারেন্টির জন্য মেসেজটি রাখুন।',
            'cancelled' => "{$shop}: অর্ডার {$order->order_number} বাতিল হয়েছে। "
                .'প্রশ্ন থাকলে আমাদের কল করুন।',
            'returned' => "{$shop}: অর্ডার {$order->order_number}-এর রিটার্ন পেয়েছি। "
                .'রিফান্ড কয়েক কর্মদিবসের মধ্যে।',
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
        $carrier = $courier ? " {$courier} দিয়ে" : '';

        $link = $order->tracking_url ?: self::trackUrl($order);

        $number = $order->tracking_number
            ? " কনসাইনমেন্ট {$order->tracking_number}।"
            : '';

        return "{$shop}: অর্ডার {$order->order_number} পাঠানো হয়েছে{$carrier}।{$number} ট্র্যাক: {$link}";
    }

    public static function refundIssued(Order $order, float $amount, string $shop): string
    {
        return "{$shop}: অর্ডার {$order->order_number}-এ Tk ".number_format($amount, 0)
            .' রিফান্ড হয়েছে। অ্যাকাউন্টে আসতে কয়েক দিন লাগতে পারে।';
    }

    /**
     * A one-time code.
     *
     * Says what the code is for, because a code arriving with no context is
     * exactly what a phishing message looks like — and a customer who has been
     * taught to read those carefully should be able to tell the difference.
     *
     * The warning at the end is the only part that stops the common attack
     * here, which is not technical: somebody rings the customer, claims to be
     * the shop, and asks them to read the code out.
     *
     * The shop's name is kept even though it costs a fifth of the message. It
     * is what tells the customer this is not the phishing text, and with a
     * numeric sender ID there is nothing else on the phone that says who sent
     * it. It still fits in one part; the rest was cut until it did.
     */
    public static function verificationCode(string $code, string $purpose, string $shop): string
    {
        /*
         * Two words, not four. "পাসওয়ার্ড রিসেটের" and "নম্বর যাচাইয়ের" both
         * pushed this to 72 characters — two characters past the 70 a unicode
         * message gets — and doubled the cost of the message the shop sends
         * most often. These still say what the code is for.
         */
        $what = $purpose === 'password_reset'
            ? 'পাসওয়ার্ড'
            : 'যাচাইয়ের';

        // Read from the service rather than passed in, so the message cannot
        // promise five minutes while the code is good for two.
        $minutes = (int) round(OtpService::TTL_SECONDS / 60);

        return "{$code} {$shop} {$what} কোড। {$minutes} মিনিট বৈধ। কাউকে দেবেন না।";
    }

    /** What the shop is owed, when a delivery is going out unpaid. */
    public static function paymentDue(Order $order, float $due, string $shop): string
    {
        return "{$shop}: অর্ডার {$order->order_number}, ডেলিভারিতে Tk "
            .number_format($due, 0).' দিতে হবে। টাকা প্রস্তুত রাখুন।';
    }

    private static function trackUrl(Order $order): string
    {
        return url('/track/'.$order->order_number);
    }
}
