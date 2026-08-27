<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use App\Support\ProfitAndLoss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The statement, and the two things it is careful about:
 *
 *   Cost of goods sold comes from what was *sold*, not what was *bought*. A
 *   delivery still on the shelf has cost nothing yet — it has turned cash into
 *   stock. Treating purchases as an expense would make every month with a big
 *   delivery look like a loss it was not.
 *
 *   Orders whose cost was never recorded are left out of both sides. Counting
 *   their revenue while skipping their cost would report the whole sale price
 *   as profit.
 */
class ProfitAndLossTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
    }

    private function stocked(string $slug, float $price, ?float $cost, int $qty = 50): Product
    {
        $product = Product::create([
            'category_id' => $this->category->id, 'name' => ucfirst($slug), 'slug' => $slug,
            'price' => $price, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        $this->stock->receive([], [['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]]);

        return $product;
    }

    private function sell(Product $product, int $qty = 1): Order
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $qty]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);

        return Order::latest('id')->first();
    }

    private function spend(string $category, float $amount, ?string $on = null): void
    {
        Expense::create([
            'category' => $category,
            'amount' => $amount,
            'description' => 'test',
            'incurred_on' => $on ?? now()->toDateString(),
        ]);
    }

    public function test_an_empty_period_is_all_zeroes(): void
    {
        $s = ProfitAndLoss::statement();

        $this->assertSame(0.0, $s['income']['total']);
        $this->assertSame(0.0, $s['cost_of_goods']);
        $this->assertSame(0.0, $s['net_profit']);
        $this->assertNull($s['net_margin_percent']);
    }

    public function test_it_adds_up(): void
    {
        $order = $this->sell($this->stocked('ryzen', 20000, 14000), 2);
        $this->spend('rent', 5000);
        $this->spend('salaries', 8000);

        $s = ProfitAndLoss::statement();
        $delivery = (float) $order->shipping_fee;

        $this->assertSame(40000.0, $s['income']['goods']);
        $this->assertSame($delivery, $s['income']['delivery']);
        $this->assertSame(28000.0, $s['cost_of_goods']);
        $this->assertSame(12000.0, $s['gross_profit']);
        $this->assertSame(13000.0, $s['expenses']['total']);

        // 12,000 gross + delivery collected - 13,000 spent
        $this->assertSame(round(12000 + $delivery - 13000, 2), $s['net_profit']);
    }

    public function test_a_loss_is_reported_as_one(): void
    {
        $this->sell($this->stocked('ryzen', 20000, 14000), 1);
        $this->spend('rent', 50000);

        $this->assertLessThan(0, ProfitAndLoss::statement()['net_profit']);
    }

    /**
     * The distinction the whole design turns on: buying stock is not spending.
     */
    public function test_buying_stock_does_not_dent_the_profit(): void
    {
        $this->sell($this->stocked('ryzen', 20000, 14000), 1);
        $before = ProfitAndLoss::statement()['net_profit'];

        // A large delivery arrives and is not sold.
        $this->stock->receive([], [[
            'product_id' => Product::first()->id, 'quantity' => 100, 'unit_cost' => 14000,
        ]]);

        $this->assertSame($before, ProfitAndLoss::statement()['net_profit'],
            'A delivery that is still on the shelf must not read as a loss.');
    }

    public function test_expenses_are_broken_down_by_category(): void
    {
        $this->spend('rent', 5000);
        $this->spend('rent', 2000);
        $this->spend('marketing', 3000);

        $byCategory = collect(ProfitAndLoss::statement()['expenses']['by_category'])->keyBy('key');

        $this->assertSame(7000.0, $byCategory['rent']['amount']);
        $this->assertSame(3000.0, $byCategory['marketing']['amount']);
        // Listed at zero, so "nothing spent" is distinguishable from "not tracked".
        $this->assertSame(0.0, $byCategory['utilities']['amount']);
    }

    public function test_only_the_period_asked_for_is_counted(): void
    {
        $this->sell($this->stocked('ryzen', 20000, 14000), 1);
        $this->spend('rent', 5000);
        $this->spend('rent', 99999, now()->subMonths(2)->toDateString());

        $s = ProfitAndLoss::statement(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(5000.0, $s['expenses']['total'], 'An older bill leaked into this month.');
    }

    /** Cancelled and returned orders never happened as far as the accounts go. */
    public function test_cancelled_orders_are_excluded(): void
    {
        $order = $this->sell($this->stocked('ryzen', 20000, 14000), 1);
        $this->assertSame(6000.0, ProfitAndLoss::statement()['gross_profit']);

        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(0.0, ProfitAndLoss::statement()['gross_profit']);
    }

    /** Counting revenue without its cost would report the sale price as profit. */
    public function test_an_uncosted_order_is_excluded_from_both_sides_and_flagged(): void
    {
        $this->sell($this->stocked('costed', 20000, 14000), 1);
        $this->sell($this->stocked('mystery', 9000, null), 1);

        $s = ProfitAndLoss::statement();

        $this->assertSame(20000.0, $s['income']['goods'], 'Uncosted revenue leaked in.');
        $this->assertSame(6000.0, $s['gross_profit']);
        $this->assertSame(1, $s['excluded']['orders']);
        $this->assertSame(9000.0, $s['excluded']['revenue'], 'The gap must be visible.');
    }

    public function test_the_report_screen_renders_the_statement(): void
    {
        $this->sell($this->stocked('ryzen', 20000, 14000), 1);
        $this->spend('rent', 1000);

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/reports/profit-loss')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame(6000.0, $props['statement']['gross_profit']);
        $this->assertSame(1000.0, $props['statement']['expenses']['total']);
    }

    public function test_a_customer_cannot_read_the_statement(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/reports/profit-loss')
            ->assertRedirect();
    }

    public function test_an_end_before_its_start_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/reports/profit-loss?from=2026-08-01&to=2026-07-01')
            ->assertSessionHasErrors('to');
    }
}
