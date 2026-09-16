<?php

namespace Tests\Feature\Sms;

use App\Models\Campaign;
use App\Models\Order;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\SmsService;
use App\Support\BrandDetails;
use App\Support\SmsTemplates;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two rules the gateway sends notices about, held to by tests.
 *
 * What is at stake is not a badly worded message but the sending account —
 * they enforce these by refusing traffic, and an order confirmation that stops
 * arriving is discovered by a customer rather than by the shop.
 *
 *   1. A one-time code must open with the brand name in brackets:
 *      "ওটিপি এসএমএস প্রেরণ করতে হলে এসএমএস এর শুরুতে ব্রাকেট দিয়ে
 *      প্রতিষ্ঠান/ব্রান্ডের নাম লেখা বাধ্যতামূলক", their example being
 *      `(কোম্পানিনেম) আপনার ওটিপি 12XXX`.
 *
 *   2. Every message must carry Bengali. Bengali mixed with English is fine;
 *      English alone is refused, and so is Banglish.
 */
class SmsGatewayRulesTest extends TestCase
{
    use RefreshDatabase;

    /* Shaped like the one in SmsTest, which is what these messages are built from. */
    private function order(): Order
    {
        return Order::create([
            'order_number' => 'ORD-24081',
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 84500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 84500,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
    }

    /** Rule 1, on the message the shop sends more than any other. */
    public function test_a_one_time_code_opens_with_the_brand_name_in_brackets(): void
    {
        $shop = BrandDetails::name();

        foreach (['verify', 'password_reset'] as $purpose) {
            $message = SmsTemplates::verificationCode('123456', $purpose, $shop);

            $this->assertStringStartsWith("({$shop})", $message);
        }
    }

    /**
     * And still costs one message.
     *
     * A Bengali message gets 70 characters, and this one is at 69. The
     * brackets took two of them. There is no room here for another word, and
     * this is the message the shop sends most: a second part is a doubling of
     * the bill for every sign-up and every forgotten password.
     */
    public function test_the_brackets_did_not_cost_a_second_part(): void
    {
        $shop = BrandDetails::name();

        foreach (['verify', 'password_reset'] as $purpose) {
            $message = SmsTemplates::verificationCode('123456', $purpose, $shop);

            $this->assertSame(1, SmsService::parts($message), $message);
        }
    }

    /** Rule 2, over every message the shop can send from code. */
    public function test_every_message_the_shop_sends_carries_bengali(): void
    {
        $shop = BrandDetails::name();
        $order = $this->order();

        $messages = [
            'order placed' => SmsTemplates::orderPlaced($order, $shop),
            'payment due' => SmsTemplates::paymentDue($order, 84500, $shop),
            'verification' => SmsTemplates::verificationCode('123456', 'verify', $shop),
            'password reset' => SmsTemplates::verificationCode('123456', 'password_reset', $shop),
        ];

        foreach (['shipped', 'delivered', 'cancelled', 'returned'] as $status) {
            $order->status = $status;
            $messages[$status] = SmsTemplates::statusChanged($order, $shop);
        }

        foreach ($messages as $what => $message) {
            $this->assertNotNull($message, $what);
            $this->assertTrue(SmsService::hasBengali($message), "{$what}: {$message}");
        }
    }

    /** And over the seven a shop can reword for itself. */
    public function test_every_stored_template_carries_bengali(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        $templates = SmsTemplate::all();

        $this->assertNotEmpty($templates);

        foreach ($templates as $template) {
            $this->assertTrue(
                SmsService::hasBengali((string) $template->body),
                "{$template->key}: {$template->body}",
            );
        }
    }

    /**
     * Banglish has no Bengali character in it at all, which is what makes it
     * catchable: the notice bars "Amar/Ami/Tumi" spelled in Latin letters, and
     * a message written that way reads as English to this check.
     */
    public function test_banglish_reads_as_english_and_is_refused(): void
    {
        $this->assertFalse(SmsService::hasBengali('Apnar order ti peyechi'));
        $this->assertFalse(SmsService::hasBengali('{shop_name}: order {order_number} received'));
        $this->assertTrue(SmsService::hasBengali('({shop_name}) অর্ডার {order_number} পেয়েছি'));
    }

    /**
     * The screen where somebody would break rule 2 without ever having seen
     * the notice — this is the only place the wording can be changed.
     */
    public function test_the_admin_cannot_reword_a_text_message_into_english(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $template = SmsTemplate::create([
            'key' => 'order_placed',
            'name' => 'Order received',
            'group' => 'Orders',
            'body' => '{shop_name}: অর্ডার {order_number} পেয়েছি।',
            'variables' => ['shop_name', 'order_number'],
        ]);

        $response = $this->patchJson("/api/admin/templates/sms/{$template->id}", [
            'body' => '{shop_name}: we have your order {order_number}.',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Bengali', $response->json('message'));

        /* And nothing was written. */
        $this->assertTrue(SmsService::hasBengali($template->fresh()->body));
    }

    /**
     * The larger exposure by far.
     *
     * A template reaches one customer at a time; a campaign reaches the whole
     * list at once, and the account it is stopped on is the same account the
     * order confirmations go out on.
     */
    public function test_a_text_campaign_written_in_english_is_not_sent(): void
    {
        $campaign = Campaign::create([
            'title' => 'Eid Sale', 'subject' => 'Eid Sale',
            'body' => 'Up to 40% off every laptop this week. Shop now.',
            'channel' => 'sms', 'audience' => 'customers', 'status' => 'draft',
        ]);

        $this->expectExceptionMessage('only accepts messages with Bengali');
        app(CampaignService::class)->send($campaign);
    }

    /** Bengali and English together is what these messages actually are. */
    public function test_bengali_mixed_with_english_is_allowed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $template = SmsTemplate::create([
            'key' => 'shipped',
            'name' => 'Dispatched',
            'group' => 'Orders',
            'body' => '{shop_name}: অর্ডার {order_number} পাঠানো হয়েছে।',
            'variables' => ['shop_name', 'order_number'],
        ]);

        $this->patchJson("/api/admin/templates/sms/{$template->id}", [
            'body' => '{shop_name}: অর্ডার {order_number} পাঠানো হয়েছে। Track: {track_url}',
        ])->assertOk();
    }
}
