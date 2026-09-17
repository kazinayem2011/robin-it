<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Services\SmsService;
use App\Support\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The link in an order's own messages opens the order.
 *
 * It filled in the order number and then asked for the mobile number — the
 * number the text had just been sent to. The link now carries a key made from
 * the order, which stands in for the number and says nothing about the customer.
 */
class TrackingLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function order(string $number = 'ORD-LSFCIBTEIG', array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => $number,
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 125000, 'shipping_fee' => 0, 'discount' => 0, 'total' => 125000,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Karim Uddin',
                'phone' => '01712345678',
                'street_address' => 'House 12, Road 4',
                'email' => 'karim@example.com',
            ],
        ], $overrides));
    }

    private function track(string $number, array $with = [])
    {
        return $this->postJson('/api/orders/track', ['order_number' => $number] + $with);
    }

    /** What a link's `k` says, read off the link rather than asked of the model. */
    private function keyIn(string $text): string
    {
        $this->assertMatchesRegularExpression('#/track/[A-Z0-9-]+\?k=([a-f0-9]+)#', $text);
        preg_match('#/track/[A-Z0-9-]+\?k=([a-f0-9]+)#', $text, $m);

        return $m[1];
    }

    public function test_the_order_placed_text_opens_the_order_without_a_phone(): void
    {
        $order = $this->order();

        $key = $this->keyIn(SmsTemplates::orderPlaced($order, 'Robins Computer'));

        $this->track($order->order_number, ['key' => $key])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number);
    }

    public function test_the_confirmation_email_opens_the_order_without_a_phone(): void
    {
        $order = $this->order();

        $html = (new OrderConfirmationMail($order))->render();

        $this->track($order->order_number, ['key' => $this->keyIn(html_entity_decode($html))])->assertOk();
    }

    /** One order's key is not another's, and a made-up one opens nothing. */
    public function test_a_key_opens_only_its_own_order(): void
    {
        $mine = $this->order('ORD-MINE000001');
        $theirs = $this->order('ORD-THEIRS0001');

        $this->track($theirs->order_number, ['key' => $mine->trackingKey()])->assertNotFound();
        $this->track($theirs->order_number, ['key' => '0123456789'])->assertNotFound();
        $this->track($theirs->order_number, ['key' => ''])->assertStatus(422);
    }

    /** The phone still works, for anyone who has it and not the link. */
    public function test_the_phone_still_opens_the_order(): void
    {
        $order = $this->order();

        $this->track($order->order_number, ['phone' => '01712345678'])->assertOk();
        $this->track($order->order_number, ['phone' => '01712345678', 'key' => 'ffffffffff'])->assertOk();
    }

    public function test_the_track_page_is_handed_the_key_and_nothing_else(): void
    {
        $this->get('/track/ORD-LSFCIBTEIG?k=0a1b2c3d4e')
            ->assertInertia(fn ($page) => $page
                ->where('orderNumber', 'ORD-LSFCIBTEIG')
                ->where('accessKey', '0a1b2c3d4e'));

        $this->get('/track/ORD-LSFCIBTEIG?k=<script>')
            ->assertInertia(fn ($page) => $page->where('accessKey', null));
    }

    /**
     * The confirmation page's "Track Order" opens the order for whoever placed
     * it — the same test that lets them print its invoice — and nobody else.
     */
    public function test_only_the_customer_gets_the_unlocked_link_on_the_confirmation_page(): void
    {
        $owner = User::factory()->create();
        $order = $this->order(overrides: ['user_id' => $owner->id, 'session_id' => null]);

        $this->actingAs($owner)
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('trackUrl', $order->trackPath()));

        $this->actingAs(User::factory()->create())
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('trackUrl', null));
    }

    /**
     * Measured against the shop's real address, not the test's.
     *
     * The key makes the link ten characters longer, and the test suite's
     * http://localhost is ten characters shorter than the live domain — so a
     * message that fits here could be a third part on every real order.
     */
    public function test_the_texts_stay_two_parts_at_the_live_address(): void
    {
        URL::forceRootUrl('https://robinscomputer.com');
        URL::forceScheme('https');

        $order = $this->order('ORD-LSFCIBTEIG');
        $shop = 'Robins Computer';

        $this->assertStringContainsString('https://robinscomputer.com/track/', SmsTemplates::orderPlaced($order, $shop));

        $order->status = 'shipped';
        $order->setRelation('courier', new Courier(['name' => 'Steadfast', 'tracking_url_template' => null]));

        $messages = [
            'placed' => SmsTemplates::orderPlaced($order, $shop),
            'changed' => SmsTemplates::orderUpdated($order->forceFill(['total' => 1250000]), $shop),
            'confirmed' => SmsTemplates::statusChanged((clone $order)->forceFill(['status' => 'processing']), $shop),
            'shipped, our link' => SmsTemplates::statusChanged($order, $shop),
        ];

        foreach ($messages as $what => $message) {
            $this->assertLessThanOrEqual(2, SmsService::parts($message), "{$what}: {$message}");
        }

        // The fallback still opens the order, which is where the courier is named.
        $this->assertSame($order->trackingKey(), $this->keyIn($messages['shipped, our link']));
    }
}
