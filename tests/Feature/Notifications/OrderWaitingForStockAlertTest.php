<?php

namespace Tests\Feature\Notifications;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Services\StockService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The customer is not told when part of an order has to wait for the next
 * delivery — to them the product is simply available. The shop is, at once.
 */
class OrderWaitingForStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Product $mouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();
        Notification::fake();

        $category = Category::create(['name' => 'Mice', 'slug' => 'mice', 'is_active' => true]);
        $this->mouse = Product::create([
            'category_id' => $category->id, 'name' => 'Logitech Mouse', 'slug' => 'mouse',
            'price' => 1500, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $this->mouse->id, 'quantity' => 2, 'unit_cost' => 1000]]);
        $this->owner = User::factory()->create(['role' => 'admin']);
    }

    private function buy(int $quantity): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson('/api/cart', ['product_id' => $this->mouse->id, 'quantity' => $quantity])->assertOk();
        $this->actingAs($customer)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678', 'street_address' => 'House 1', 'city' => 'Dhaka',
        ])->assertStatus(201);
    }

    public function test_an_order_beyond_the_shelf_alerts_the_shop_naming_what_is_short(): void
    {
        $this->buy(5);

        Notification::assertSentTo($this->owner, OrderPlaced::class, function (OrderPlaced $n) {
            $payload = $n->payload($this->owner);

            return str_contains($payload['title'], 'waiting for stock')
                && str_contains($payload['body'], 'Needs the next delivery: Logitech Mouse.');
        });
    }

    public function test_an_order_the_shelf_covers_reads_as_before(): void
    {
        $this->buy(2);

        Notification::assertSentTo($this->owner, OrderPlaced::class, function (OrderPlaced $n) {
            $payload = $n->payload($this->owner);

            return ! str_contains($payload['title'], 'waiting')
                && ! str_contains($payload['body'], 'Needs the next delivery');
        });
    }
}
