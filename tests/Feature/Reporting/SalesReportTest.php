<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Reports\CustomerReport;
use App\Support\Reports\ProductReport;
use App\Support\Reports\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What sold, when, and whether that is better than before.
 *
 * The shop had a profit-and-loss statement and nothing else, so the question
 * everybody asks first — how did this month go against the last — had no answer.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Product $gpu;

    private Product $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Parts', 'slug' => 'parts', 'is_active' => true]);

        $this->gpu = Product::create([
            'category_id' => $category->id, 'name' => 'RTX 4090', 'slug' => 'gpu-rep',
            'price' => 10000, 'stock_quantity' => 50, 'is_active' => true,
        ]);
        $this->cable = Product::create([
            'category_id' => $category->id, 'name' => 'HDMI cable', 'slug' => 'cable-rep',
            'price' => 500, 'stock_quantity' => 50, 'is_active' => true,
        ]);
    }

    private function sale(
        Product $product,
        int $quantity,
        string $on,
        ?float $unitCost = null,
        string $status = 'delivered',
        ?User $user = null,
        string $phone = '01712345678',
    ): Order {
        $price = (float) $product->price;

        $order = Order::create([
            'order_number' => 'ORD-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
            'user_id' => $user?->id,
            'session_id' => str_repeat('s', 40),
            'status' => $status,
            'subtotal' => $price * $quantity, 'shipping_fee' => 0, 'discount' => 0,
            'total' => $price * $quantity,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => $phone, 'city' => 'Dhaka'],
        ]);

        $order->forceFill(['created_at' => $on, 'updated_at' => $on])->save();

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $price, 'quantity' => $quantity, 'total' => $price * $quantity,
            'unit_cost' => $unitCost,
        ]);

        return $order;
    }

    // --- the totals ---------------------------------------------------------

    public function test_it_totals_what_sold(): void
    {
        $this->sale($this->gpu, 2, '2026-03-05');
        $this->sale($this->cable, 4, '2026-03-06');

        $report = SalesReport::totals('2026-03-01', '2026-03-31');

        $this->assertSame(22000.0, $report['revenue']);
        $this->assertSame(2, $report['orders']);
        $this->assertSame(6, $report['units']);
        $this->assertSame(11000.0, $report['average_order']);
    }

    /** A cancelled order is not a sale, and counting it flatters a bad month. */
    public function test_cancelled_and_returned_orders_are_not_sales(): void
    {
        $this->sale($this->gpu, 1, '2026-03-05');
        $this->sale($this->gpu, 5, '2026-03-06', null, 'cancelled');
        $this->sale($this->gpu, 5, '2026-03-07', null, 'returned');

        $report = SalesReport::totals('2026-03-01', '2026-03-31');

        $this->assertSame(10000.0, $report['revenue']);
        $this->assertSame(1, $report['orders']);
    }

    /**
     * The comparison is against the same length of time immediately before.
     * A month against a fortnight would flatter or damn the period for no
     * reason but its length.
     */
    public function test_it_compares_against_the_same_length_of_time_before(): void
    {
        $this->sale($this->gpu, 1, '2026-02-10');   // the week before
        $this->sale($this->gpu, 3, '2026-02-18');   // the week in question

        $report = SalesReport::for('2026-02-15', '2026-02-21');

        $this->assertSame(30000.0, $report['totals']['revenue']);
        $this->assertSame(10000.0, $report['previous']['revenue']);
        $this->assertSame('2026-02-08', $report['previous_period']['from']);
        $this->assertSame('2026-02-14', $report['previous_period']['to']);
    }

    // --- the series ---------------------------------------------------------

    /** A gap in a chart reads as missing data; a zero reads as a quiet day. */
    public function test_days_with_no_sales_appear_as_zero(): void
    {
        $this->sale($this->gpu, 1, '2026-03-01');
        $this->sale($this->gpu, 1, '2026-03-03');

        $series = SalesReport::for('2026-03-01', '2026-03-03')['series'];

        $this->assertCount(3, $series);
        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($series, 'on'));
        $this->assertSame(0.0, $series[1]['revenue']);
        $this->assertSame(0, $series[1]['orders']);
    }

    /**
     * Joining orders to their lines and summing the order total counts it once
     * per line. SUM(DISTINCT) does not fix that — it collapses two different
     * orders that happen to come to the same amount into one.
     */
    public function test_a_day_with_several_lines_is_not_counted_several_times(): void
    {
        $order = $this->sale($this->gpu, 1, '2026-03-01');

        // A second line on the same order.
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->cable->id,
            'product_name' => 'HDMI cable', 'price' => 500, 'quantity' => 3, 'total' => 1500,
        ]);
        $order->forceFill(['subtotal' => 11500, 'total' => 11500])->save();

        $series = SalesReport::for('2026-03-01', '2026-03-01')['series'];

        $this->assertSame(11500.0, $series[0]['revenue'], 'The order was counted once per line.');
        $this->assertSame(1, $series[0]['orders']);
        $this->assertSame(4, $series[0]['units']);
    }

    /** Two orders of the same value on one day are two orders. */
    public function test_two_identical_orders_on_one_day_are_both_counted(): void
    {
        $this->sale($this->gpu, 1, '2026-03-01');
        $this->sale($this->gpu, 1, '2026-03-01');

        $series = SalesReport::for('2026-03-01', '2026-03-01')['series'];

        $this->assertSame(20000.0, $series[0]['revenue']);
        $this->assertSame(2, $series[0]['orders']);
    }

    // --- products -----------------------------------------------------------

    /**
     * Ranked by what they earned, not by how many left. A cable selling four
     * times is not a better product than one graphics card, and a list by units
     * quietly recommends buying more cables.
     */
    public function test_products_are_ranked_by_profit_not_units(): void
    {
        $this->sale($this->gpu, 1, '2026-03-05', 6000);      // 4,000 profit on 1 unit
        $this->sale($this->cable, 20, '2026-03-06', 450);    // 1,000 profit on 20 units

        $lines = ProductReport::for('2026-03-01', '2026-03-31')['lines'];

        $this->assertSame('RTX 4090', $lines[0]['name']);
        $this->assertSame(4000.0, $lines[0]['profit']);
        $this->assertSame(40.0, $lines[0]['margin_percent']);
        $this->assertSame(20, $lines[1]['units']);
    }

    /** A partial cost reads as profit that is not there, so it is withheld. */
    public function test_a_product_with_no_recorded_cost_shows_no_margin(): void
    {
        $this->sale($this->gpu, 1, '2026-03-05');

        $report = ProductReport::for('2026-03-01', '2026-03-31');

        $this->assertNull($report['lines'][0]['profit']);
        $this->assertNull($report['lines'][0]['margin_percent']);
        $this->assertSame(1, $report['uncosted']);
    }

    /**
     * The other half of the question, and the more expensive mistake: what to
     * stop buying.
     */
    public function test_stock_that_did_not_sell_in_the_period_is_listed(): void
    {
        $this->sale($this->gpu, 1, '2026-03-05');

        $idle = ProductReport::neverSold('2026-03-01', '2026-03-31');

        $this->assertSame(['HDMI cable'], array_column($idle, 'name'));
        $this->assertSame(25000.0, $idle[0]['tied_up']);
    }

    // --- customers ------------------------------------------------------------

    /**
     * A guest who has ordered five times is a repeat customer. Counting them as
     * five strangers is how a shop concludes nobody comes back.
     */
    public function test_guest_orders_are_grouped_by_phone_number(): void
    {
        $this->sale($this->gpu, 1, '2026-03-01', null, 'delivered', null, '01711111111');
        $this->sale($this->gpu, 1, '2026-03-02', null, 'delivered', null, '01711111111');
        $this->sale($this->cable, 1, '2026-03-03', null, 'delivered', null, '01722222222');

        $report = CustomerReport::for('2026-03-01', '2026-03-31');

        $this->assertSame(2, $report['totals']['customers']);
        $this->assertSame(2, $report['top'][0]['orders']);
        $this->assertSame(20000.0, $report['top'][0]['spent']);
    }

    public function test_an_account_holder_is_one_customer_however_they_ordered(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Rahim']);

        $this->sale($this->gpu, 1, '2026-03-01', null, 'delivered', $user, '01711111111');
        $this->sale($this->gpu, 1, '2026-03-02', null, 'delivered', $user, '01799999999');

        $report = CustomerReport::for('2026-03-01', '2026-03-31');

        $this->assertSame(1, $report['totals']['customers']);
        $this->assertTrue($report['top'][0]['has_account']);
    }

    /**
     * New is measured against the whole history, not the period — otherwise
     * every customer looks new whenever the range is short.
     */
    public function test_a_returning_customer_is_not_counted_as_new(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->sale($this->gpu, 1, '2026-01-10', null, 'delivered', $user);
        $this->sale($this->gpu, 1, '2026-03-10', null, 'delivered', $user);

        $report = CustomerReport::for('2026-03-01', '2026-03-31');

        $this->assertSame(0, $report['new_vs_returning']['new']);
        $this->assertSame(1, $report['new_vs_returning']['returning']);
    }

    // --- the pages ------------------------------------------------------------

    public function test_the_sales_page_renders(): void
    {
        $this->sale($this->gpu, 1, now()->toDateString(), 6000);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/reports/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Sales')
                ->has('sales.totals')
                ->has('products.lines')
                ->has('customers.top'));
    }

    public function test_the_index_lists_every_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Reports/Index')->has('reports', 6));
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/reports/sales?from=2026-03-10&to=2026-03-01')
            ->assertSessionHasErrors('to');
    }
}
