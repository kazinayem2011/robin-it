<?php

namespace Tests\Feature\Stock;

use App\Mail\OrderConfirmationMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Everywhere the customer is told what they bought.
 *
 * A line that names only the product is ambiguous once the product is sold in
 * options: someone who chose the 32GB cannot tell from "Kingston Fury Beast"
 * which one is on its way.
 */
class VariantOrderPaperworkTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'kingston-fury-beast',
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [['product_id' => $this->product->id, 'quantity' => 10]]);

        app(ProductVariantService::class)->convertToVariants($this->product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 6],
            ['options' => ['Capacity' => '32GB'], 'price' => 8200, 'opening_stock' => 4],
        ]);

        $this->user = User::factory()->create(['email' => 'rahim@example.com']);
    }

    private function buy32GB(): Order
    {
        $variant = $this->product->fresh('variants')->variants->firstWhere('name', '32GB');

        $this->actingAs($this->user)->postJson('/cart-api', [
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($this->user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::where('user_id', $this->user->id)->latest()->first();
    }

    public function test_the_confirmation_email_names_the_chosen_option(): void
    {
        Mail::fake();
        $order = $this->buy32GB();

        // The mailable is queued (ShouldQueue), so the fake records it as
        // queued rather than sent.
        Mail::assertQueued(OrderConfirmationMail::class, function ($mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Kingston Fury Beast', $html);
            $this->assertStringContainsString('32GB', $html, 'the email did not say which option');

            return true;
        });

        $this->assertSame('32GB', $order->items->first()->variant_name);
    }

    public function test_the_plain_text_email_names_the_option_too(): void
    {
        $this->buy32GB();

        $text = Mail::mailer('array')->getSymfonyTransport()->messages()->last()
            ?->getOriginalMessage()?->getTextBody();

        $this->assertNotNull($text, 'no plain-text alternative was sent');
        $this->assertStringContainsString('Kingston Fury Beast (32GB)', $text);
    }

    public function test_order_tracking_names_the_option(): void
    {
        $order = $this->buy32GB();

        $response = $this->postJson('/api/orders/track', [
            'order_number' => $order->order_number,
            'phone' => '01712345678',
        ]);
        $response->assertStatus(200);

        $item = $response->json('data.items.0');

        $this->assertSame('Kingston Fury Beast', $item['product_name']);
        $this->assertSame('32GB', $item['variant_name'], 'tracking did not say which option');
    }

    /** The invoice must survive the option being renamed or retired later. */
    public function test_the_line_keeps_its_option_name_after_the_option_changes(): void
    {
        $order = $this->buy32GB();
        $variant = $this->product->fresh('variants')->variants->firstWhere('name', '32GB');

        $variant->update(['name' => 'Renamed Later', 'is_active' => false]);

        $this->assertSame('32GB', $order->fresh('items')->items->first()->variant_name);
        $this->assertSame(
            'Kingston Fury Beast (32GB)',
            $order->fresh('items')->items->first()->display_name
        );
    }
}
