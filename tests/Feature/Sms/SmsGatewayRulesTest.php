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
 * The two rules the gateway sends notices about, held to by the wording itself.
 *
 * Nothing here blocks a send. The rules are met by how the messages are
 * written, which is where they belong: a shop that cannot send what it meant
 * to send has a worse problem than a gateway that rejects one message, and the
 * screens that can change this wording say what the rules are.
 *
 *   1. A one-time code must open with the brand name in brackets — and every
 *      message here is written that way, so what a customer sees from this
 *      shop is one shape rather than two:
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

    /** Rule 1, over every message the shop sends — required only of the code. */
    public function test_every_message_opens_with_the_brand_name_in_brackets(): void
    {
        $shop = BrandDetails::name();

        foreach ($this->everyMessage() as $what => $message) {
            $this->assertStringStartsWith("({$shop})", $message, "{$what}: {$message}");
        }
    }

    /** And so is every template a shop can reword for itself. */
    public function test_every_stored_template_opens_the_same_way(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        foreach (SmsTemplate::all() as $template) {
            $this->assertStringStartsWith(
                '({shop_name})',
                (string) $template->body,
                "{$template->key}: {$template->body}",
            );
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
        foreach ($this->everyMessage() as $what => $message) {
            $this->assertTrue(SmsService::hasBengali($message), "{$what}: {$message}");
        }
    }

    /**
     * Every text the shop sends from code, as a customer would receive it.
     *
     * @return array<string, string>
     */
    private function everyMessage(): array
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
            $message = SmsTemplates::statusChanged($order, $shop);

            $this->assertNotNull($message, $status);
            $messages[$status] = $message;
        }

        return $messages;
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
     * visible: the notice bars "Amar/Ami/Tumi" spelled in Latin letters, and a
     * message written that way reads as English to this check.
     */
    public function test_banglish_reads_as_english(): void
    {
        $this->assertFalse(SmsService::hasBengali('Apnar order ti peyechi'));
        $this->assertFalse(SmsService::hasBengali('{shop_name}: order {order_number} received'));
        $this->assertTrue(SmsService::hasBengali('({shop_name}) অর্ডার {order_number} পেয়েছি'));
    }

    /**
     * The composer is told, and nothing is stopped.
     *
     * A shop that cannot send what it meant to send has a worse problem than a
     * gateway that rejects one message — so the estimate carries the answer to
     * the screen, where somebody who can read the wording decides.
     */
    public function test_a_campaign_reports_whether_it_carries_bengali_without_refusing_it(): void
    {
        $english = Campaign::create([
            'title' => 'Eid Sale', 'subject' => 'Eid Sale',
            'body' => 'Up to 40% off every laptop this week.',
            'channel' => 'sms', 'audience' => 'customers', 'status' => 'draft',
        ]);

        $this->assertFalse(app(CampaignService::class)->estimate($english)['bengali']);

        $bengali = Campaign::create([
            'title' => 'ঈদ সেল', 'subject' => 'ঈদ সেল',
            'body' => 'সব ল্যাপটপে ৪০% পর্যন্ত ছাড়।',
            'channel' => 'sms', 'audience' => 'customers', 'status' => 'draft',
        ]);

        $this->assertTrue(app(CampaignService::class)->estimate($bengali)['bengali']);

        /* And the English one still goes out, because that is the shop's call. */
        $this->customerWithPhone();

        $sent = app(CampaignService::class)->send($english);

        $this->assertNotSame(Campaign::DRAFT, $sent->status);
        $this->assertGreaterThan(0, $sent->recipient_count);
    }

    private function customerWithPhone(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'accepts_marketing' => true,
            'phone' => '01710000001',
        ]);
    }
}
