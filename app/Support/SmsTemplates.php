<?php

namespace App\Support;

use App\Models\Order;
use App\Models\SmsTemplate;
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
 * Every one of them opens with the shop's name in brackets, which is the shape
 * the gateway's notice asks for — `(কোম্পানিনেম) আপনার ওটিপি 12XXX`. They
 * require it of a one-time code; the rest are written the same way so that
 * what a customer sees from this shop is one thing rather than two, and
 * because it costs a single character.
 *
 * The wording below is the default, not the last word. Where the shop has
 * written its own in Message Templates, that row is used instead — see
 * `stored()`, which is also where the rules for refusing to use one live.
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
        $track = self::trackUrl($order);

        return self::stored('order_placed', [
            'shop_name' => $shop,
            'order_number' => $order->order_number,
            'order_total' => $total,
            'track_url' => $track,
        ], "({$shop}) অর্ডার {$order->order_number} পেয়েছি, Tk {$total}। ট্র্যাক: {$track}");
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
        $plain = ['shop_name' => $shop, 'order_number' => $order->order_number];

        return match ($order->status) {
            'shipped' => self::shipped($order, $shop),
            'delivered' => self::stored('delivered', $plain,
                "({$shop}) অর্ডার {$order->order_number} ডেলিভারি হয়েছে। "
                    .'ধন্যবাদ। ওয়ারেন্টির জন্য মেসেজটি রাখুন।'),
            'cancelled' => self::stored('cancelled', $plain,
                "({$shop}) অর্ডার {$order->order_number} বাতিল হয়েছে। "
                    .'প্রশ্ন থাকলে আমাদের কল করুন।'),
            'returned' => self::stored('returned', $plain,
                "({$shop}) অর্ডার {$order->order_number}-এর রিটার্ন পেয়েছি। "
                    .'রিফান্ড কয়েক কর্মদিবসের মধ্যে।'),
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

        /*
         * One way to follow the parcel, not two.
         *
         * A Bengali message gets 70 characters to a part, and a courier plus a
         * consignment number plus a link ran to 146 — three parts, on a message
         * that is off by default because the courier has already sent its own.
         *
         * So: the carrier's own tracking page where they have one, since it
         * opens on the consignment and is the thing worth tapping. Failing
         * that, the consignment number itself, which is what a customer reads
         * out to a courier's hotline. Only with neither do we fall back to our
         * own page — the order confirmation already carried that link.
         */
        $follow = match (true) {
            filled($order->tracking_url) => "ট্র্যাক: {$order->tracking_url}",
            filled($order->tracking_number) => "কনসাইনমেন্ট {$order->tracking_number}।",
            default => 'ট্র্যাক: '.self::trackUrl($order),
        };

        return self::stored('shipped', [
            'shop_name' => $shop,
            'order_number' => $order->order_number,
            /*
             * Left unsupplied when there is no courier on the order, which
             * sends this back to the default below rather than texting
             * somebody "পাঠানো হয়েছে ()।" — see the brace check in stored().
             */
            'courier_name' => $courier,
            'track_url' => $order->tracking_url ?: self::trackUrl($order),
        ], "({$shop}) অর্ডার {$order->order_number} পাঠানো হয়েছে{$carrier}। {$follow}");
    }

    public static function refundIssued(Order $order, float $amount, string $shop): string
    {
        $sum = number_format($amount, 0);

        return self::stored('refund', [
            'shop_name' => $shop,
            'order_number' => $order->order_number,
            'amount' => $sum,
        ], "({$shop}) অর্ডার {$order->order_number}-এ Tk {$sum} রিফান্ড হয়েছে। "
            .'অ্যাকাউন্টে আসতে কয়েক দিন লাগতে পারে।');
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

        /*
         * The shop's name first and in brackets, because the gateway requires
         * it of a one-time code specifically: "ওটিপি এসএমএস প্রেরণ করতে হলে
         * এসএমএস এর শুরুতে ব্রাকেট দিয়ে প্রতিষ্ঠান/ব্রান্ডের নাম লেখা
         * বাধ্যতামূলক", their example being `(কোম্পানিনেম) আপনার ওটিপি 12XXX`.
         *
         * Two characters and no extra part: this was 66, and 70 is where a
         * Bengali message stops being one message. There is no room left for
         * another word — a test holds it to the one part.
         */
        return "({$shop}) {$code} {$what} কোড। {$minutes} মিনিট বৈধ। কাউকে দেবেন না।";
    }

    /** What the shop is owed, when a delivery is going out unpaid. */
    public static function paymentDue(Order $order, float $due, string $shop): string
    {
        return "({$shop}) অর্ডার {$order->order_number}, ডেলিভারিতে Tk "
            .number_format($due, 0).' দিতে হবে। টাকা প্রস্তুত রাখুন।';
    }

    /**
     * The wording the shop wrote for itself, or the default written above.
     *
     * Until now these rows were edited, previewed and test-sent in the admin
     * and read by nothing else: a shop could reword its order confirmation,
     * see the new words in the preview, and watch customers keep receiving the
     * old ones. This is the join that was missing.
     *
     * The default wins in three cases, and each of them is a message going out
     * rather than a message going wrong:
     *
     *   - no row, because the templates have never been seeded;
     *   - a row emptied out, which is not a decision anybody makes on purpose;
     *   - a row still holding braces after it has been filled in, which means
     *     it names a placeholder this message does not supply. Braces reaching
     *     a customer are worse than wording the shop did not choose, and this
     *     is also what keeps `{courier_name}` from rendering as "()" on an
     *     order that went out without a courier.
     *
     * Wrapped in rescue() because the words are a nicety and the message is
     * not: a missing table mid-deploy should not stop an order confirmation.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    private static function stored(string $key, array $values, string $default): string
    {
        $body = rescue(
            fn () => (string) SmsTemplate::where('key', $key)->value('body'),
            '',
            report: false,
        );

        if (trim($body) === '') {
            return $default;
        }

        $filled = MessageTemplate::fill($body, $values);

        return preg_match('/\{[a-z_]+\}/', $filled) ? $default : $filled;
    }

    private static function trackUrl(Order $order): string
    {
        return url('/track/'.$order->order_number);
    }
}
