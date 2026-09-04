<?php

namespace Tests\Feature\Sms;

use App\Models\Order;
use App\Models\OtpCode;
use App\Models\SiteSetting;
use App\Services\SmsService;
use App\Support\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sending an SMS.
 *
 * The shop had six email templates and no SMS, which in Bangladesh means a
 * large share of customers were told nothing at all: the confirmation, the
 * dispatch note and the tracking link went to an inbox nobody opened.
 *
 * The gateway handling is lifted from a sister project that has been through
 * it in production. These tests pin the parts that were learned the hard way.
 */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private SmsService $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);
        $this->sms = app(SmsService::class);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-SMS1',
            'session_id' => str_repeat('a', 40),
            'status' => 'pending', 'subtotal' => 1000, 'shipping_fee' => 0,
            'discount' => 0, 'total' => 1000,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ], $overrides));
    }

    // --- the number ------------------------------------------------------

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function numbers(): array
    {
        return [
            'local' => ['01712345678', '8801712345678'],
            'as the app prints it' => ['+880 1712-345678', '8801712345678'],
            'without the leading zero' => ['1712345678', '8801712345678'],
            'with the country code' => ['8801712345678', '8801712345678'],
            'too short' => ['0171234567', null],
            'not a number' => ['hello', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_a_number_is_put_in_the_form_a_gateway_wants(string $given, ?string $expected): void
    {
        $this->assertSame($expected, $this->sms->gatewayNumber($given));
    }

    public function test_a_number_that_cannot_be_dialled_is_not_sent_to(): void
    {
        $this->assertFalse($this->sms->send('12345', 'Hello'));
    }

    public function test_nothing_is_sent_when_sms_is_switched_off(): void
    {
        config(['services.sms.enabled' => false]);

        $this->assertFalse(app(SmsService::class)->send('01712345678', 'Hello'));
    }

    // --- reading the gateway's answer ------------------------------------

    /**
     * Gateways answer in a dozen shapes, so a send only counts as failed when
     * the reply actually names a failure.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function replies(): array
    {
        return [
            'plain ok' => ['SMS SUBMITTED', true],
            'json success' => [['status' => 'success'], true],
            'a bare message id' => ['1194938201', true],
            'error zero is not an error' => [['error' => 0, 'msg' => 'sent'], true],
            'invalid token' => ['Invalid Token', false],
            'no balance' => [['status' => 'Insufficient Balance'], false],
            'blocked' => ['Sender ID blocked', false],
        ];
    }

    #[DataProvider('replies')]
    public function test_a_reply_is_read_as_the_gateway_meant_it(mixed $body, bool $expected): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertSame($expected, $this->sms->send('01712345678', 'Hello'));
    }

    /**
     * "Invalid Token" also contains "ok".
     *
     * Failure has to be checked before success or half the rejections read as
     * acceptances.
     */
    public function test_a_rejection_containing_ok_is_still_a_rejection(): void
    {
        Http::fake(['*' => Http::response('Invalid Token', 200)]);

        $this->assertFalse($this->sms->send('01712345678', 'Hello'));
    }

    /**
     * A read timeout is not proof of failure.
     *
     * The gateway takes the message before it answers, so the SMS still lands.
     * Reporting it as failed invites somebody to send it again.
     */
    public function test_a_timeout_counts_as_sent(): void
    {
        Http::fake(fn () => throw new \RuntimeException('cURL error 28: Operation timed out'));

        $this->assertTrue($this->sms->send('01712345678', 'Hello'));
    }

    /** A refused connection means the request never arrived. */
    public function test_a_connection_that_never_landed_counts_as_failed(): void
    {
        Http::fake(fn () => throw new \RuntimeException('cURL error 7: Failed to connect'));

        $this->assertFalse($this->sms->send('01712345678', 'Hello'));
    }

    /**
     * The one that made a working token look broken.
     *
     * `sms_url` is the *other* gateway's address — the Settings screen says so,
     * and the two paths are only ever used one or the other. viaGreenWeb() was
     * preferring it anyway, so a shop that had a generic gateway and then moved
     * to GreenWeb posted its GreenWeb token, GreenWeb-shaped, to the old
     * provider, and got back a complaint about a missing api_key. Found by
     * doing exactly that.
     */
    public function test_a_greenweb_token_always_goes_to_greenweb(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{"status":"SENT"}')]);

        config(['services.sms.greenweb_url' => 'https://greenweb.test/api']);

        SiteSetting::create(['key' => 'sms_enabled', 'value' => '1']);
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('greenweb-token')]);
        // Left behind by a previous provider, as it would be after a switch.
        SiteSetting::create(['key' => 'sms_url', 'value' => 'https://old-provider.test/api/sendsms']);
        SiteSetting::flushCache();

        $this->assertTrue(app(SmsService::class)->send('01712345678', 'Hello'));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://greenweb.test/api'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'old-provider.test'));
    }

    /**
     * A chosen provider is held to, even with the other one's fields filled.
     *
     * Which gateway sends used to be inferred from whichever credential
     * happened to be present. That reads fine until a shop switches: the old
     * provider's fields are still saved, nothing on the screen says which set
     * is live, and the wrong one can win.
     */
    public function test_the_chosen_provider_is_the_one_used(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{"status":"SENT"}')]);

        config(['services.sms.greenweb_url' => 'https://greenweb.test/api']);

        SiteSetting::create(['key' => 'sms_enabled', 'value' => '1']);
        SiteSetting::create(['key' => 'sms_provider', 'value' => SmsService::PROVIDER_CUSTOM]);
        // Both sets configured, as they are mid-switch.
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('greenweb-token')]);
        SiteSetting::create(['key' => 'sms_url', 'value' => 'https://chosen.test/send']);
        SiteSetting::create(['key' => 'sms_api_key', 'value' => SmsService::encryptSecret('chosen-key')]);
        SiteSetting::create(['key' => 'sms_sender_id', 'value' => '8801000000000']);
        SiteSetting::flushCache();

        $this->assertTrue(app(SmsService::class)->send('01712345678', 'Hello'));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://chosen.test/send'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenweb.test'));
    }

    /** A shop that has never seen the setting keeps working exactly as before. */
    public function test_an_unchosen_provider_falls_back_to_whichever_is_configured(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{"status":"SENT"}')]);

        config(['services.sms.greenweb_url' => 'https://greenweb.test/api']);

        SiteSetting::create(['key' => 'sms_enabled', 'value' => '1']);
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('greenweb-token')]);
        SiteSetting::flushCache();

        $this->assertSame(SmsService::PROVIDER_GREENWEB, app(SmsService::class)->provider());
        $this->assertTrue(app(SmsService::class)->send('01712345678', 'Hello'));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://greenweb.test/api'));
    }

    /**
     * Choosing one and configuring the other sends nothing, rather than
     * quietly using whatever happens to be filled in.
     */
    public function test_a_provider_without_its_credentials_sends_nothing(): void
    {
        Http::preventStrayRequests();

        SiteSetting::create(['key' => 'sms_enabled', 'value' => '1']);
        SiteSetting::create(['key' => 'sms_provider', 'value' => SmsService::PROVIDER_CUSTOM]);
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('greenweb-token')]);
        SiteSetting::flushCache();

        config(['services.sms.log_fallback' => false]);

        $this->assertFalse(app(SmsService::class)->send('01712345678', 'Hello'));
    }

    public function test_nothing_is_sent_with_no_gateway_and_no_log_fallback(): void
    {
        config(['services.sms.token' => null, 'services.sms.url' => null]);

        $this->assertFalse(app(SmsService::class)->send('01712345678', 'Hello'));
    }

    public function test_the_log_stands_in_for_a_gateway_locally(): void
    {
        config([
            'services.sms.token' => null,
            'services.sms.url' => null,
            'services.sms.log_fallback' => true,
        ]);

        $this->assertTrue(app(SmsService::class)->send('01712345678', 'Hello'));
    }

    // --- what the shop says ----------------------------------------------

    /**
     * Every message must fit one plain part.
     *
     * A gateway bills per 160 characters of the GSM-7 set; one character
     * outside it — an em dash, a curly quote — switches the whole message to
     * unicode and the part size to 70. Two of these once cost double for a
     * dash.
     */
    /**
     * Every message is in Bengali, and none of them has grown.
     *
     * This used to assert the opposite half: that every message was pure
     * GSM-7 and one part, because that is the cheapest a message can be. The
     * gateway has since told us that is not allowed — all SMS must be in
     * Bengali, Banglish is out, Bengali mixed with English is fine, English
     * alone is not. So the alphabet is settled and what is left to guard is
     * the length, which the alphabet more than doubled the cost of.
     */
    public function test_every_message_is_in_bengali_and_stays_short(): void
    {
        $order = $this->order();
        $shop = 'Robins Computer';

        $messages = ['placed' => SmsTemplates::orderPlaced($order, $shop)];

        foreach (['shipped', 'delivered', 'cancelled', 'returned'] as $status) {
            $order->status = $status;

            if ($message = SmsTemplates::statusChanged($order, $shop)) {
                $messages[$status] = $message;
            }
        }

        $messages['refund'] = SmsTemplates::refundIssued($order, 5000, $shop);
        $messages['due'] = SmsTemplates::paymentDue($order, 5000, $shop);

        foreach (OtpCode::PURPOSES as $purpose) {
            $messages["code:{$purpose}"] = SmsTemplates::verificationCode('048213', $purpose, $shop);
        }

        foreach ($messages as $name => $message) {
            $this->assertMatchesRegularExpression(
                '/\p{Bengali}/u',
                $message,
                "The \"{$name}\" message is English only, which the gateway does not accept."
            );

            /*
             * Two parts is the ceiling. A Bengali message gets 70 characters
             * to a part rather than 160, so a sentence that reads as a small
             * courtesy is a third of the bill again, on every order, forever.
             */
            $this->assertLessThanOrEqual(
                2,
                SmsService::parts($message),
                "The \"{$name}\" message runs to ".SmsService::parts($message).' parts.'
            );
        }
    }

    /**
     * The one sent most often stays a single part.
     *
     * A code goes out on every sign-up, every phone login and every password
     * reset — many times more often than any order message — so this is the
     * one worth counting characters over. It sits at 66 of the 70 a unicode
     * part allows; two more words put it over, and did once already.
     */
    public function test_a_verification_code_is_a_single_part(): void
    {
        foreach (OtpCode::PURPOSES as $purpose) {
            $message = SmsTemplates::verificationCode('048213', $purpose, 'Robins Computer');

            $this->assertSame(
                1,
                SmsService::parts($message),
                "The [{$purpose}] code message costs two parts: \"{$message}\""
            );
        }
    }

    /**
     * A message that says nothing still costs money and still interrupts
     * somebody's evening.
     */
    public function test_statuses_a_customer_cannot_act_on_send_nothing(): void
    {
        $order = $this->order();

        foreach (['pending', 'processing'] as $status) {
            $order->status = $status;
            $this->assertNull(SmsTemplates::statusChanged($order, 'Robins Computer'));
        }
    }

    /**
     * A template nobody calls is a message nobody gets.
     *
     * Two of these were written and left unwired, which reads in the code as a
     * feature the shop has and in practice as silence.
     */
    public function test_every_template_is_actually_sent_from_somewhere(): void
    {
        $app = base_path('app');
        $wired = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && ! str_ends_with($file->getPathname(), 'SmsTemplates.php')) {
                $wired[] = file_get_contents($file->getPathname());
            }
        }

        $source = implode("\n", $wired);

        /*
         * Read off the class rather than listed here.
         *
         * A list has to be remembered, and the two that were already dead code
         * are proof that it will not be: they were written, never called, and
         * only noticed when somebody asked what messages the shop sends.
         */
        $templates = collect((new \ReflectionClass(SmsTemplates::class))->getMethods())
            // getMethods()'s filter argument ORs its flags rather than ANDs
            // them, so the bitmask would drag in the private helpers too.
            ->filter(fn ($method) => $method->isPublic() && $method->isStatic())
            ->pluck('name');

        $this->assertGreaterThanOrEqual(5, $templates->count());

        foreach ($templates as $template) {
            // assertTrue rather than assertStringContainsString: a failure
            // there prints every PHP file in app/ as the diff.
            $this->assertTrue(
                str_contains($source, "SmsTemplates::{$template}"),
                "SmsTemplates::{$template}() is never called, so that message is never sent."
            );
        }
    }

    // --- which messages a shop pays for ----------------------------------

    /**
     * The defaults are a spending decision, not a shrug.
     *
     * Pathao, Steadfast and RedX all text the customer themselves when a
     * parcel is picked up and when it lands. A shop repeating that pays twice
     * for one piece of news, so those two start off. What stays on is what no
     * courier knows: the order exists, money is owed, a refund is coming, the
     * order is off.
     */
    public function test_the_messages_a_courier_already_sends_are_off_by_default(): void
    {
        foreach (['shipped', 'delivered'] as $event) {
            $this->assertFalse(
                $this->sms->sends($event),
                "The {$event} message should be off by default; the courier sends it."
            );
        }

        foreach (['order_placed', 'payment_due', 'refund', 'cancelled'] as $event) {
            $this->assertTrue(
                $this->sms->sends($event),
                "The {$event} message should be on by default; nobody else sends it."
            );
        }
    }

    public function test_a_shop_can_switch_any_message_either_way(): void
    {
        config(['services.sms.on_shipped' => '1', 'services.sms.on_order_placed' => '0']);

        $this->assertTrue($this->sms->sends('shipped'));
        $this->assertFalse($this->sms->sends('order_placed'));
    }

    public function test_switching_sms_off_switches_every_message_off(): void
    {
        config(['services.sms.enabled' => false, 'services.sms.on_order_placed' => '1']);

        $this->assertFalse($this->sms->sends('order_placed'));
    }

    public function test_a_message_that_is_switched_off_is_never_sent(): void
    {
        Http::fake();
        config(['services.sms.on_delivered' => '0']);

        $this->assertFalse(
            $this->sms->sendEvent('delivered', '01712345678', 'Your order has arrived.')
        );

        Http::assertNothingSent();
    }

    /**
     * A new message must reach the Settings screen, or it can never be
     * switched off — which is the whole point of having switches.
     */
    /**
     * Every SMS setting must be one the Settings screen may write.
     *
     * SettingsUpdateRequest rejects any key not in SiteSetting::editableKeys(),
     * so a field added to the form but not to that list renders perfectly and
     * cannot be saved. That is what happened to `sms_provider`: the dropdown
     * looked right and the save came back "not a setting this site stores".
     * Caught by being asked whether the form had actually been tested.
     */
    public function test_every_sms_setting_can_be_saved_from_the_screen(): void
    {
        $editable = SiteSetting::editableKeys();

        foreach (SmsService::KEYS as $key) {
            $this->assertContains(
                $key,
                $editable,
                "[{$key}] is on the SMS form but SettingsUpdateRequest would reject it."
            );
        }
    }

    /** ...and none of them may be published to the browser. */
    public function test_no_sms_setting_is_sent_to_the_storefront(): void
    {
        $public = SiteSetting::publicKeys();

        foreach (SmsService::KEYS as $key) {
            $this->assertNotContains($key, $public, "[{$key}] would be readable by any visitor.");
        }
    }

    public function test_every_message_has_a_switch_on_the_settings_screen(): void
    {
        foreach (array_keys(SmsService::EVENTS) as $event) {
            $key = 'sms_on_'.$event;

            $this->assertContains($key, SmsService::KEYS);
            $this->assertContains(
                $key,
                SiteSetting::GROUPS['sms'],
                "{$key} is missing from the SMS settings group, so the form cannot save it."
            );
            $this->assertContains(
                $key,
                SiteSetting::editableKeys(),
                "{$key} would be rejected as an unknown setting when saved."
            );
        }
    }

    /**
     * A switch nothing checks is a switch that does nothing.
     *
     * sendEvent() takes the order status straight through for the status
     * messages, so a status without a matching event would send unconditionally
     * — or, once the default is consulted, never send at all.
     */
    public function test_every_event_name_used_in_the_app_is_a_real_switch(): void
    {
        foreach (['shipped', 'delivered', 'cancelled', 'returned'] as $status) {
            $this->assertArrayHasKey(
                $status,
                SmsService::EVENTS,
                "Orders reach the {$status} status and send a message, but there is no switch for it."
            );
        }

        $source = file_get_contents(base_path('app/Services/RefundService.php'))
            .file_get_contents(base_path('app/Services/OrderService.php'));

        preg_match_all("/sendEvent\(\s*'([a-z_]+)'/", $source, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $event) {
            $this->assertArrayHasKey($event, SmsService::EVENTS, "sendEvent('{$event}') has no switch.");
        }
    }

    // --- a customer with no phone ----------------------------------------

    /**
     * An order can be placed with an email and nothing else.
     *
     * recipient_phone answers "N/A" so a printed invoice reads properly, which
     * is right for a page and wrong for a gateway: the shop would spend an
     * attempt, and a log line on every such order, dialling two letters.
     */
    public function test_an_order_with_no_phone_has_nothing_to_text(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-NOPHONE',
            'shipping_address' => ['name' => 'Rahim', 'city' => 'Dhaka'],
        ]);

        $this->assertSame('N/A', $order->recipient_phone);
        $this->assertNull($order->notifiablePhone());
    }

    public function test_a_blank_phone_counts_as_no_phone(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-BLANK',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '', 'city' => 'Dhaka'],
        ]);

        $this->assertNull($order->notifiablePhone());
    }

    /**
     * The guard has to sit before the gateway, not inside it.
     *
     * Sending to "N/A" is refused either way; the difference is a warning
     * logged on every order from every customer who never gave a number.
     */
    public function test_nothing_is_dialled_for_an_order_with_no_phone(): void
    {
        Http::fake();

        $order = $this->order([
            'order_number' => 'ORD-NOPHONE2',
            'shipping_address' => ['name' => 'Rahim', 'city' => 'Dhaka'],
        ]);

        $this->assertFalse(
            $this->sms->sendEvent(
                'order_placed',
                $order->notifiablePhone(),
                SmsTemplates::orderPlaced($order, 'Robins Computer')
            )
        );

        Http::assertNothingSent();
    }

    /** The tracking link is the whole reason the dispatch message is worth sending. */
    public function test_the_dispatch_message_carries_a_way_to_track_it(): void
    {
        $order = $this->order(['status' => 'shipped']);

        $this->assertStringContainsString(
            $order->order_number,
            SmsTemplates::statusChanged($order, 'Robins Computer')
        );
        $this->assertStringContainsString(
            'track',
            strtolower(SmsTemplates::statusChanged($order, 'Robins Computer'))
        );
    }
}
