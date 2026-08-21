<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Order confirmation emails were failing for every order: the Blade template
 * echoed $order->shipping_address, which is cast to an array, raising
 * "htmlspecialchars(): argument must be of type string, array given".
 *
 * The send was wrapped in a try/catch that logged a warning and moved on, so
 * nothing surfaced — the customer simply never received an email. These tests
 * render the templates for real so a repeat cannot pass unnoticed.
 */
class OrderMailTest extends TestCase
{
    use RefreshDatabase;

    private function order(?User $user = null): Order
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Intel Core i9-14900KS',
            'slug' => 'intel-core-i9-'.uniqid(),
            'price' => 85000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $user?->id,
            'order_number' => 'ORD-MAILTEST'.random_int(10, 99),
            'subtotal' => 85000, 'shipping_fee' => 60, 'discount' => 0, 'total' => 85060,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Rahim Chowdhury',
                'phone' => '01712345678',
                'street_address' => 'House 45, Road 7, Gulshan 2',
                'city' => 'Dhaka',
                'zone' => 'Gulshan',
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'price' => 85000, 'quantity' => 1, 'total' => 85000,
        ]);

        return $order->load(['items.product', 'user']);
    }

    public function test_the_confirmation_email_renders_without_error(): void
    {
        $mailable = new OrderConfirmationMail($this->order(User::factory()->create()));

        // render() is what blew up in production; assertions on the output follow.
        $html = $mailable->render();

        $this->assertNotEmpty($html);
    }

    public function test_the_confirmation_email_shows_a_readable_address(): void
    {
        $mailable = new OrderConfirmationMail($this->order(User::factory()->create()));

        $mailable->assertSeeInHtml('Rahim Chowdhury');
        $mailable->assertSeeInHtml('House 45, Road 7, Gulshan 2');
        $mailable->assertSeeInHtml('01712345678');

        // The array must never be dumped into the message.
        $mailable->assertDontSeeInHtml('Array');
        $mailable->assertDontSeeInHtml('street_address');
    }

    public function test_the_confirmation_email_renders_for_a_guest_order(): void
    {
        // A guest order has no user, so anything reading $order->user->… must not blow up.
        $mailable = new OrderConfirmationMail($this->order(null));

        $mailable->assertSeeInHtml('Rahim Chowdhury');
        $mailable->assertSeeInHtml('01712345678');
    }

    public function test_the_status_update_email_renders(): void
    {
        $order = $this->order(User::factory()->create());
        $order->update(['status' => 'shipped']);

        $mailable = new OrderStatusUpdatedMail($order->fresh()->load('items'));

        $mailable->assertSeeInHtml('House 45, Road 7, Gulshan 2');
        $mailable->assertDontSeeInHtml('Array');
    }

    public function test_order_mail_is_queued_so_checkout_does_not_wait_on_smtp(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new OrderConfirmationMail($this->order(User::factory()->create()))
        );
    }

    public function test_placing_an_order_sends_the_confirmation(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU', 'slug' => 'test-cpu-'.uniqid(),
            'price' => 5000, 'stock_quantity' => 10, 'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        Mail::assertQueued(
            OrderConfirmationMail::class,
            fn ($mail) => $mail->hasTo($user->email)
        );
    }
}
