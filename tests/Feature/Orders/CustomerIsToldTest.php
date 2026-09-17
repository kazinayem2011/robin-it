<?php

namespace Tests\Feature\Orders;

use App\Exceptions\StorefrontException;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\OrderUpdatedMail;
use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\OrderEditService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * When the shop changes an order, the customer hears about it.
 *
 * An admin changed the lines on a live order and the customer was told
 * nothing — no bell, no text, no email — and a status change sent the email
 * but never the text, so even a cancellation, whose text is on by default,
 * went unannounced.
 */
class CustomerIsToldTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '01712345678';

    private User $staff;

    private Product $gpu;

    private Product $ram;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);

        $this->staff = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Parts', 'slug' => 'parts', 'is_active' => true]);
        $this->gpu = Product::create([
            'category_id' => $category->id, 'name' => 'RTX 4090', 'slug' => 'rtx-4090-told',
            'price' => 10000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $this->ram = Product::create([
            'category_id' => $category->id, 'name' => 'Vengeance 32GB', 'slug' => 'ram-told',
            'price' => 5000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        foreach ([$this->gpu, $this->ram] as $product) {
            app(StockService::class)->receive([], [[
                'product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 1000,
            ]]);
        }
    }

    /** A customer's order for one graphics card, with nothing sent yet. */
    private function order(?User $customer = null): Order
    {
        $customer ??= User::factory()->create(['phone' => self::PHONE, 'email' => 'karim@example.com']);

        $this->actingAs($customer)->postJson('/api/cart', ['product_id' => $this->gpu->id, 'quantity' => 1])->assertOk();
        $this->actingAs($customer)->postJson('/api/checkout', [
            'name' => 'Karim', 'phone' => self::PHONE,
            'address' => 'House 12, Road 4, Dhanmondi', 'delivery_zone' => 'inside_dhaka',
        ])->assertCreated();

        $order = Order::latest('id')->firstOrFail()->load('items');

        // What checkout itself sent is not what these tests are about.
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);
        Mail::fake();
        $customer->notifications()->delete();

        return $order;
    }

    /** @return list<string> */
    private function textsSent(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function addRam(Order $order): Order
    {
        return app(OrderEditService::class)->apply($order, $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
            ['product_id' => $this->ram->id, 'quantity' => 1],
        ]);
    }

    public function test_an_edit_rings_the_customers_bell(): void
    {
        $order = $this->order();

        $this->addRam($order);

        $bell = $order->user->notifications()->sole();
        $this->assertSame('order.updated', $bell->data['kind']);
        $this->assertStringContainsString('15,060.00', $bell->data['body']);
        $this->assertSame($order->trackPath(), $bell->data['url']);
    }

    public function test_an_edit_emails_what_the_order_holds_now(): void
    {
        $order = $this->order();

        $this->addRam($order);

        Mail::assertQueued(OrderUpdatedMail::class, function (OrderUpdatedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('karim@example.com')
                && str_contains($html, 'Vengeance 32GB')
                && str_contains($html, '15,060.00')     // the new total, delivery included
                && str_contains($html, '10,060.00');    // and what it was
        });
    }

    public function test_an_edit_texts_the_new_total_with_a_link_that_opens_the_order(): void
    {
        $order = $this->order();

        $this->addRam($order);

        $texts = $this->textsSent();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('15,060', $texts[0]);
        $this->assertStringContainsString($order->trackPath(), $texts[0]);
    }

    /** A guest has no bell, but still has a phone and, now, an email. */
    public function test_a_guest_order_is_still_texted_and_emailed(): void
    {
        $order = $this->order();
        $order->forceFill([
            'user_id' => null,
            'shipping_address' => ['email' => 'guest@example.com'] + $order->shipping_address,
        ])->save();

        $this->addRam($order->fresh('items'));

        $this->assertCount(1, $this->textsSent());
        Mail::assertQueued(OrderUpdatedMail::class, fn ($mail) => $mail->hasTo('guest@example.com'));
    }

    public function test_the_text_can_be_switched_off(): void
    {
        $order = $this->order();
        SiteSetting::set('sms_on_order_updated', '0', 'sms');

        $this->addRam($order);

        $this->assertSame([], $this->textsSent());
        Mail::assertQueued(OrderUpdatedMail::class);
    }

    /** A refused edit changed nothing, so there is nothing to tell. */
    public function test_a_refused_edit_tells_nobody(): void
    {
        $order = $this->order();

        try {
            app(OrderEditService::class)->apply($order, $this->staff, [
                ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
            ]);
            $this->fail('An edit that changes nothing should be refused.');
        } catch (StorefrontException) {
        }

        $this->assertSame(0, $order->user->notifications()->count());
        $this->assertSame([], $this->textsSent());
        Mail::assertNothingQueued();
    }

    /** No courier ever tells a customer their order was cancelled. */
    public function test_cancelling_from_the_admin_texts_emails_and_rings(): void
    {
        $order = $this->order();

        $this->actingAs($this->staff)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        $texts = $this->textsSent();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('বাতিল', $texts[0]);
        Mail::assertQueued(OrderStatusUpdatedMail::class, fn ($mail) => $mail->hasTo('karim@example.com'));
        $this->assertSame('order.status', $order->user->notifications()->sole()->data['kind']);
    }

    /** Saving a payment status is not news about the order. */
    public function test_a_status_that_did_not_move_sends_nothing(): void
    {
        $order = $this->order();

        $this->actingAs($this->staff)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'pending', 'payment_status' => 'paid'])
            ->assertOk();

        $this->assertSame([], $this->textsSent());
        Mail::assertNothingQueued();
        $this->assertSame(0, $order->user->notifications()->count());
    }

    /** Dispatch moved the order without ringing the bell. */
    public function test_dispatch_rings_the_bell_too(): void
    {
        $order = $this->order();
        $courier = Courier::create(['name' => 'Steadfast', 'slug' => 'steadfast-told', 'is_active' => true]);

        $this->actingAs($this->staff)
            ->patchJson("/api/admin/orders/{$order->id}/dispatch", [
                'courier_id' => $courier->id,
                'tracking_number' => 'SF123',
            ])
            ->assertOk();

        $bell = $order->user->notifications()->sole();
        $this->assertSame('order.status', $bell->data['kind']);
        $this->assertStringContainsString('shipped', $bell->data['title']);
        Mail::assertQueued(OrderStatusUpdatedMail::class);
    }
}
