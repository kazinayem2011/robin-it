<?php

namespace App\Support;

/**
 * Every message the shop can be made to say, and what each one fills in.
 *
 * One list, because there used to be two that agreed by hand. The seeder
 * declared what a template may name, and the code that sends it separately
 * decided what to supply; nothing compared them, so `payment_due` shipped with
 * a row a shop could edit and a sender that never read it — six of seven wired
 * looked exactly like seven of seven from outside. The shop rewords the one
 * that was missed, the preview agrees, the customer is sent the old words.
 *
 * Drift is the quieter half of the same fault. A key that declares
 * `{amount_due}` and a sender that supplies `amount` leaves a placeholder
 * unfilled, which sends the wording back to the default — so the shop's words
 * are ignored from then on, correctly and silently, with a preview that still
 * shows them. Keeping the declaration in one place is what makes both
 * testable: a key here with nothing supplying it fails, and so does a sender
 * that supplies the wrong names.
 */
class MessageKeys
{
    /**
     * The emails a template actually writes.
     *
     * Only the ones that are genuinely words. The other four carry structure a
     * box meant for prose cannot hold — a receipt's line items, the address it
     * is going to, a button that has to survive Outlook, a reset link whose
     * removal would lock somebody out — and letting a template stand in for
     * one of those sent a customer three sentences where their receipt used to
     * be. They keep their designed views, and the screen says so rather than
     * offering an edit that would not take effect.
     *
     * @var list<string>
     */
    public const EMAIL_WRITTEN = ['welcome', 'back_in_stock', 'contact_reply'];

    /**
     * @var array<string, list<string>>
     */
    public const EMAIL = [
        'welcome' => ['shop_name', 'customer_name', 'shop_url'],
        'order_placed' => ['shop_name', 'customer_name', 'order_number', 'order_total', 'order_items', 'order_url'],
        'order_status' => ['shop_name', 'customer_name', 'order_number', 'order_status', 'order_url'],
        'back_in_stock' => ['shop_name', 'product_name', 'product_url'],
        'contact_reply' => ['shop_name', 'customer_name', 'enquiry_subject', 'reply_body'],
        'password_reset' => ['shop_name', 'customer_name', 'reset_url', 'expires_minutes'],
        'verify_email' => ['shop_name', 'customer_name', 'verify_url'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const SMS = [
        'order_placed' => ['shop_name', 'order_number', 'order_total', 'track_url'],
        'order_updated' => ['shop_name', 'order_number', 'order_total', 'track_url'],
        'processing' => ['shop_name', 'order_number', 'track_url'],
        'payment_due' => ['shop_name', 'order_number', 'amount_due'],
        'shipped' => ['shop_name', 'order_number', 'courier_name', 'track_url'],
        'delivered' => ['shop_name', 'order_number'],
        'cancelled' => ['shop_name', 'order_number'],
        'returned' => ['shop_name', 'order_number'],
        'refund' => ['shop_name', 'order_number', 'amount'],
    ];

    /** @return list<string> */
    public static function email(string $key): array
    {
        return self::EMAIL[$key] ?? [];
    }

    /** Whether editing this template changes what a customer receives. */
    public static function emailIsWritten(string $key): bool
    {
        return in_array($key, self::EMAIL_WRITTEN, true);
    }

    /** @return list<string> */
    public static function sms(string $key): array
    {
        return self::SMS[$key] ?? [];
    }
}
