<?php

namespace Tests\Feature\Orders;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Times the customer reads are Dhaka times.
 *
 * Stored in UTC and printed as stored, an order placed at 4:05 in the
 * afternoon read "10:05 AM" on its invoice and in the confirmation email.
 */
class TimesOnTheShopsClockTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:05:00', 'UTC'));

        $order = Order::create([
            'order_number' => 'ORD-CLOCK', 'user_id' => User::factory()->create()->id,
            'subtotal' => 4000, 'shipping_fee' => 70, 'discount' => 0, 'total' => 4070,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
        $order->items()->create(['product_name' => 'AJAZZ Mouse', 'price' => 4000, 'quantity' => 1, 'total' => 4000]);

        return $order->fresh()->load('items');
    }

    public function test_the_invoice_shows_the_time_in_dhaka(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/orders/{$order->id}/invoice")
            ->assertOk()
            ->assertSee('30 Sep 2026, 4:05 PM')
            ->assertDontSee('10:05 AM');
    }

    public function test_the_confirmation_email_shows_the_time_in_dhaka(): void
    {
        $html = (new OrderConfirmationMail($this->order()))->render();

        $this->assertStringContainsString('30 Sep 2026, 4:05 PM', $html);
        $this->assertStringNotContainsString('10:05 AM', $html);
    }
}
