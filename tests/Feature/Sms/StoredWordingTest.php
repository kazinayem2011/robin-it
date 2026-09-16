<?php

namespace Tests\Feature\Sms;

use App\Models\Courier;
use App\Models\Order;
use App\Models\SmsTemplate;
use App\Support\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * That rewording a template actually changes what a customer receives.
 *
 * It did not. The rows were edited, previewed and test-sent on the Message
 * Templates screen and read by nothing else — every real message came from the
 * hard-coded wording in SmsTemplates, so a shop could change its order
 * confirmation, watch the preview show the new words, and go on sending the
 * old ones indefinitely with nothing to suggest otherwise.
 *
 * Which makes the preview the dangerous part rather than the useful one: it
 * showed a change that had not happened.
 */
class StoredWordingTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-24081',
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 84500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 84500,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ], $overrides));
    }

    private function template(string $key, string $body): SmsTemplate
    {
        return SmsTemplate::create([
            'key' => $key,
            'name' => $key,
            'group' => 'Orders',
            'body' => $body,
            'variables' => [],
        ]);
    }

    public function test_rewording_a_template_changes_what_the_customer_receives(): void
    {
        $this->template(
            'order_placed',
            '({shop_name}) ধন্যবাদ! {order_number} নিশ্চিত হয়েছে, Tk {order_total}।',
        );

        $message = SmsTemplates::orderPlaced($this->order(), 'Robins Computer');

        $this->assertSame(
            '(Robins Computer) ধন্যবাদ! ORD-24081 নিশ্চিত হয়েছে, Tk 84,500।',
            $message,
        );
    }

    /** With no row at all — the templates never seeded — the shop still sends. */
    public function test_the_default_is_used_when_nothing_has_been_written(): void
    {
        $message = SmsTemplates::orderPlaced($this->order(), 'Robins Computer');

        $this->assertStringContainsString('পেয়েছি', $message);
        $this->assertStringContainsString('ORD-24081', $message);
    }

    /** Emptying a template is not a decision anybody makes on purpose. */
    public function test_an_emptied_template_falls_back_rather_than_sending_nothing(): void
    {
        $this->template('cancelled', '   ');

        $message = SmsTemplates::statusChanged(
            $this->order(['status' => 'cancelled']),
            'Robins Computer',
        );

        $this->assertStringContainsString('বাতিল হয়েছে', (string) $message);
    }

    /**
     * A placeholder this message cannot supply survives being filled in, and
     * braces arriving on somebody's phone are worse than wording the shop did
     * not pick.
     */
    public function test_a_template_naming_something_unknown_falls_back(): void
    {
        $this->template('cancelled', '({shop_name}) {order_number} বাতিল। {courier_name} জানানো হয়েছে।');

        $message = SmsTemplates::statusChanged(
            $this->order(['status' => 'cancelled']),
            'Robins Computer',
        );

        $this->assertStringNotContainsString('{', (string) $message);
        $this->assertStringContainsString('বাতিল হয়েছে', (string) $message);
    }

    /**
     * The same rule doing real work: the dispatch template names the courier,
     * and an order can go out without one.
     */
    public function test_dispatch_without_a_courier_does_not_send_empty_brackets(): void
    {
        $this->template('shipped', '({shop_name}) {order_number} পাঠানো হয়েছে ({courier_name})। {track_url}');

        $message = SmsTemplates::statusChanged(
            $this->order(['status' => 'shipped']),
            'Robins Computer',
        );

        $this->assertStringNotContainsString('()', (string) $message);
        $this->assertStringNotContainsString('{', (string) $message);
    }

    /** And with a courier on it, the shop's own wording is what goes out. */
    public function test_dispatch_with_a_courier_uses_the_written_wording(): void
    {
        /* The couriers this shop uses ship with it, so take the one that is there. */
        $courier = Courier::firstOrCreate(['slug' => 'pathao'], ['name' => 'Pathao', 'is_active' => true]);

        $this->template('shipped', '({shop_name}) {order_number} পাঠানো হয়েছে ({courier_name})।');

        $message = SmsTemplates::statusChanged(
            $this->order(['status' => 'shipped', 'courier_id' => $courier->id]),
            'Robins Computer',
        );

        $this->assertSame('(Robins Computer) ORD-24081 পাঠানো হয়েছে (Pathao Courier)।', $message);
    }

    /** A refund names a sum, and the sum has to arrive formatted. */
    public function test_a_refund_fills_in_the_amount(): void
    {
        $this->template('refund', '({shop_name}) {order_number}: Tk {amount} ফেরত পাঠানো হয়েছে।');

        $message = SmsTemplates::refundIssued($this->order(), 12000, 'Robins Computer');

        $this->assertSame('(Robins Computer) ORD-24081: Tk 12,000 ফেরত পাঠানো হয়েছে।', $message);
    }
}
