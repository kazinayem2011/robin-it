<?php

namespace Tests\Feature\Ops;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * catalogue:remove-samples takes the seeded "Sample …" products out: deleted
 * when nothing ever happened to them, hidden when an order names them, and a
 * real product is never touched.
 */
class RemoveSampleProductsTest extends TestCase
{
    use RefreshDatabase;

    private Product $clean;

    private Product $ordered;

    private Product $real;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $cat = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $make = fn ($name, $slug) => Product::create(['category_id' => $cat->id, 'name' => $name, 'slug' => $slug, 'price' => 1000, 'is_active' => true]);

        $this->clean = $make('Sample Laptop', 'sample-laptop');
        $this->ordered = $make('Sample Mouse', 'sample-mouse');
        $this->real = $make('Lenovo IdeaPad Slim 3', 'lenovo-ideapad');

        Storage::disk('public')->put('uploads/products/sample.jpg', 'x');
        ProductImage::create(['product_id' => $this->clean->id, 'image_path' => '/storage/uploads/products/sample.jpg', 'is_primary' => true]);

        $order = Order::create([
            'order_number' => 'ORD-SAMPLE01', 'session_id' => str_repeat('a', 40), 'status' => 'delivered',
            'subtotal' => 1000, 'shipping_fee' => 0, 'discount' => 0, 'total' => 1000,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01711000000', 'city' => 'Dhaka'],
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $this->ordered->id, 'product_name' => 'Sample Mouse', 'price' => 1000, 'quantity' => 1, 'total' => 1000]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('catalogue:remove-samples')
            ->expectsOutputToContain('2 "Sample …" products: 1 to delete, 1 to hide')
            ->expectsOutputToContain('keep   #'.$this->real->id.' Lenovo IdeaPad Slim 3')
            ->assertSuccessful();

        $this->assertSame(3, Product::count());
        Storage::disk('public')->assertExists('uploads/products/sample.jpg');
    }

    public function test_force_deletes_the_clean_sample_hides_the_ordered_one_and_keeps_the_real(): void
    {
        $this->artisan('catalogue:remove-samples --force')->assertSuccessful();

        $this->assertNull(Product::find($this->clean->id));
        Storage::disk('public')->assertMissing('uploads/products/sample.jpg');

        $this->assertFalse((bool) $this->ordered->fresh()->is_active);
        $this->assertSame(1, OrderItem::where('product_id', $this->ordered->id)->count());

        $this->assertTrue((bool) $this->real->fresh()->is_active);
    }
}
