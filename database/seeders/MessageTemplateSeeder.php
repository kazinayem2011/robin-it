<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * The wording the shop already sends, moved to where it can be changed.
 *
 * Seeded from what the Blade templates and SmsTemplates say today, so the first
 * customer after this migration receives exactly what the last one did. The
 * point of the screen is that the shop can change its own words, not that
 * somebody has to rewrite them before it works.
 *
 * `updateOrCreate` on the key, so running this again restores a template
 * somebody has edited into a corner without disturbing the others.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->emails() as $row) {
            EmailTemplate::updateOrCreate(['key' => $row['key']], $row);
        }

        foreach ($this->texts() as $row) {
            SmsTemplate::updateOrCreate(['key' => $row['key']], $row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emails(): array
    {
        return [
            [
                'key' => 'welcome',
                'name' => 'Welcome',
                'group' => 'Account',
                'subject' => 'Welcome to {shop_name}',
                'hint' => 'Sent once, when somebody first creates an account.',
                'variables' => ['shop_name', 'customer_name', 'shop_url'],
                'body' => '<h1>Welcome to {shop_name}</h1>'
                    .'<p>Hi {customer_name},</p>'
                    .'<p>Your account is ready. You can track orders, save PC builds '
                    .'and manage warranties in one place.</p>'
                    .'<p><a href="{shop_url}">Start shopping</a></p>',
            ],
            [
                'key' => 'order_placed',
                'name' => 'Order confirmation',
                'group' => 'Orders',
                'subject' => 'Order {order_number} received — {shop_name}',
                'hint' => 'The receipt. {order_items} draws the line-item table and cannot be edited, only moved.',
                'variables' => ['shop_name', 'customer_name', 'order_number', 'order_total', 'order_items', 'order_url'],
                'body' => '<h1>Thanks for your order</h1>'
                    .'<p>Hi {customer_name},</p>'
                    .'<p>We have your order <strong>{order_number}</strong>. '
                    .'Here is what is on it.</p>'
                    .'{order_items}'
                    .'<p>Total: <strong>{order_total}</strong></p>'
                    .'<p><a href="{order_url}">Track this order</a></p>',
            ],
            [
                'key' => 'order_status',
                'name' => 'Order status changed',
                'group' => 'Orders',
                'subject' => 'Order {order_number} is now {order_status}',
                'hint' => 'Sent whenever an order moves — dispatched, delivered, cancelled.',
                'variables' => ['shop_name', 'customer_name', 'order_number', 'order_status', 'order_url'],
                'body' => '<h1>Your order has moved</h1>'
                    .'<p>Hi {customer_name},</p>'
                    .'<p>Order <strong>{order_number}</strong> is now '
                    .'<strong>{order_status}</strong>.</p>'
                    .'<p><a href="{order_url}">See the details</a></p>',
            ],
            [
                'key' => 'back_in_stock',
                'name' => 'Back in stock',
                'group' => 'Catalogue',
                'subject' => '{product_name} is back in stock',
                'hint' => 'Sent to everybody who asked to be told, the moment stock arrives.',
                'variables' => ['shop_name', 'product_name', 'product_url'],
                'body' => '<h1>{product_name} is back</h1>'
                    .'<p>You asked us to let you know when this came back into stock.</p>'
                    .'<p><a href="{product_url}">View it now</a></p>'
                    .'<p>Stock goes quickly — this is not reserved for you.</p>',
            ],
            [
                'key' => 'contact_reply',
                'name' => 'Reply to an enquiry',
                'group' => 'Support',
                'subject' => 'Re: {enquiry_subject}',
                'hint' => 'What a customer receives when staff answer their message.',
                'variables' => ['shop_name', 'customer_name', 'enquiry_subject', 'reply_body'],
                'body' => '<p>Hi {customer_name},</p>'
                    .'{reply_body}'
                    .'<p>If this did not answer it, reply to this email and we will pick it up.</p>'
                    .'<p>— {shop_name}</p>',
            ],
            [
                'key' => 'password_reset',
                'name' => 'Password reset',
                'group' => 'Account',
                'subject' => 'Reset your {shop_name} password',
                'hint' => 'The link expires; the wording should say so.',
                'variables' => ['shop_name', 'customer_name', 'reset_url', 'expires_minutes'],
                'body' => '<h1>Reset your password</h1>'
                    .'<p>Hi {customer_name},</p>'
                    .'<p>Somebody asked to reset the password on this account. '
                    .'If it was not you, nothing has changed and you can ignore this.</p>'
                    .'<p><a href="{reset_url}">Choose a new password</a></p>'
                    .'<p>The link works for {expires_minutes} minutes.</p>',
            ],
            [
                'key' => 'verify_email',
                'name' => 'Verify email address',
                'group' => 'Account',
                'subject' => 'Confirm your email address',
                'hint' => 'Sent when an address needs confirming before the account is usable.',
                'variables' => ['shop_name', 'customer_name', 'verify_url'],
                'body' => '<h1>Confirm your email</h1>'
                    .'<p>Hi {customer_name},</p>'
                    .'<p>Tap below to confirm this address belongs to you.</p>'
                    .'<p><a href="{verify_url}">Confirm my email</a></p>',
            ],
        ];
    }

    /**
     * Bengali, because the gateway requires it: every message must be Bengali,
     * Banglish is not allowed, and Bengali mixed with English is fine, but
     * English alone is not. Order numbers, amounts and links stay in ASCII —
     * a tracking code is read back to a person or typed into a box, and in
     * Bengali digits it is neither.
     *
     * @return list<array<string, mixed>>
     */
    private function texts(): array
    {
        return [
            [
                'key' => 'order_placed',
                'name' => 'Order received',
                'group' => 'Orders',
                'hint' => 'The confirmation, with a tracking link. Nobody else sends this.',
                'variables' => ['shop_name', 'order_number', 'order_total', 'track_url'],
                'body' => '{shop_name}: অর্ডার {order_number} পেয়েছি, Tk {order_total}। ট্র্যাক: {track_url}',
            ],
            [
                'key' => 'payment_due',
                'name' => 'Amount due on delivery',
                'group' => 'Orders',
                'hint' => 'Sent with the dispatch note when money is still owed, so the cash is ready when the rider knocks.',
                'variables' => ['shop_name', 'order_number', 'amount_due'],
                'body' => '{shop_name}: অর্ডার {order_number} ডেলিভারিতে Tk {amount_due} দিতে হবে। টাকা প্রস্তুত রাখুন।',
            ],
            [
                'key' => 'shipped',
                'name' => 'Dispatched',
                'group' => 'Orders',
                'hint' => 'Your courier already texts this, with their own tracking link.',
                'variables' => ['shop_name', 'order_number', 'courier_name', 'track_url'],
                'body' => '{shop_name}: অর্ডার {order_number} পাঠানো হয়েছে ({courier_name})। ট্র্যাক: {track_url}',
            ],
            [
                'key' => 'delivered',
                'name' => 'Delivered',
                'group' => 'Orders',
                'hint' => 'Your courier already texts this too.',
                'variables' => ['shop_name', 'order_number'],
                'body' => '{shop_name}: অর্ডার {order_number} ডেলিভারি হয়েছে। ধন্যবাদ। ওয়ারেন্টির জন্য মেসেজটি রাখুন।',
            ],
            [
                'key' => 'cancelled',
                'name' => 'Order cancelled',
                'group' => 'Orders',
                'hint' => 'The courier never knows about a cancellation.',
                'variables' => ['shop_name', 'order_number'],
                'body' => '{shop_name}: অর্ডার {order_number} বাতিল হয়েছে। প্রশ্ন থাকলে আমাদের কল করুন।',
            ],
            [
                'key' => 'returned',
                'name' => 'Return received',
                'group' => 'Orders',
                'hint' => 'Rarely worth the cost; the refund message covers what the customer cares about.',
                'variables' => ['shop_name', 'order_number'],
                'body' => '{shop_name}: অর্ডার {order_number}-এর রিটার্ন পেয়েছি। রিফান্ড কয়েক কর্মদিবসের মধ্যে।',
            ],
            [
                'key' => 'refund',
                'name' => 'Refund issued',
                'group' => 'Money',
                'hint' => 'A bank transfer takes days to appear; without this the customer chases it.',
                'variables' => ['shop_name', 'order_number', 'amount'],
                'body' => '{shop_name}: অর্ডার {order_number}-এর Tk {amount} রিফান্ড হয়েছে। ব্যাংকে আসতে কয়েক দিন লাগতে পারে।',
            ],
        ];
    }
}
