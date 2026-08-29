<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\PurchaseOrderService;
use App\Services\StockService;
use App\Support\Reports\DeliveryReport;
use App\Support\Reports\MoneyReport;
use App\Support\Reports\StockReport;
use App\Support\Reports\SupplierReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock, money, couriers and suppliers.
 *
 * The four that answer questions the shop could not ask before: what is sitting
 * still, what is owed, who actually delivers, and who sends what they promised.
 */
class OperationsReportTest extends TestCase
{
    use RefreshDatabase;

    private Product $gpu;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);

        $this->gpu = Product::create([
            'category_id' => Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true])->id,
            'name' => 'RTX 4090', 'slug' => 'gpu-ops',
            'price' => 10000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    // --- stock ---------------------------------------------------------------

    public function test_the_shelves_are_valued_at_cost(): void
    {
        $this->stock->receive([], [[
            'product_id' => $this->gpu->id, 'quantity' => 4, 'unit_cost' => 6000,
        ]]);

        $valuation = StockReport::valuation();

        $this->assertSame(4, $valuation['total_units']);
        $this->assertSame(24000.0, $valuation['total_value']);
        $this->assertSame('GPU', $valuation['by_category'][0]['name']);
    }

    /**
     * Valuing an uncosted line at zero would understate the shelf; guessing
     * would be worse. It is counted in the units and named separately.
     */
    public function test_stock_with_no_recorded_cost_is_counted_but_not_valued(): void
    {
        $this->stock->receive([], [['product_id' => $this->gpu->id, 'quantity' => 3]]);

        $valuation = StockReport::valuation();

        $this->assertSame(3, $valuation['total_units']);
        $this->assertSame(0.0, $valuation['total_value']);
        $this->assertSame(1, $valuation['uncosted_lines']);
    }

    /**
     * The report a computer shop lives on: parts depreciate weekly, and money
     * sitting in stock that has not moved is the least visible cost it carries.
     */
    public function test_stock_is_aged_by_how_long_since_it_last_moved(): void
    {
        $this->travelTo(now()->subDays(200));
        $this->stock->receive([], [[
            'product_id' => $this->gpu->id, 'quantity' => 5, 'unit_cost' => 6000,
        ]]);
        $this->travelBack();

        $ageing = StockReport::ageing();

        $this->assertSame(1, count($ageing['lines']));
        $this->assertGreaterThanOrEqual(199, $ageing['lines'][0]['days_idle']);
        $this->assertFalse($ageing['lines'][0]['ever_sold']);
        $this->assertSame(30000.0, $ageing['total_value']);

        $overSix = collect($ageing['buckets'])->firstWhere('label', 'Over 6 months');
        $this->assertSame(30000.0, $overSix['value']);
    }

    /**
     * A part that came in yesterday and has not sold is not dead stock.
     * Treating "never sold" as infinitely old would put every new arrival at
     * the top of the list nobody would then trust.
     */
    public function test_a_new_arrival_is_not_dead_stock(): void
    {
        $this->stock->receive([], [[
            'product_id' => $this->gpu->id, 'quantity' => 5, 'unit_cost' => 6000,
        ]]);

        $ageing = StockReport::ageing();

        $this->assertSame(0, $ageing['lines'][0]['days_idle']);
        $this->assertSame(0.0, $ageing['slow_value']);
    }

    // --- money ----------------------------------------------------------------

    public function test_what_customers_still_owe_is_added_up(): void
    {
        $this->owing(5000, 'processing');
        $this->owing(3000, 'shipped');

        $owed = MoneyReport::owed();

        $this->assertSame(8000.0, $owed['total']);
        $this->assertSame(2, $owed['orders']);
    }

    /**
     * Dispatched and unpaid is the riskiest kind: the goods have left and the
     * money is with a third party until they settle.
     */
    public function test_money_out_with_a_courier_is_called_out_separately(): void
    {
        $this->owing(5000, 'processing');
        $this->owing(3000, 'shipped');

        $owed = MoneyReport::owed();

        $this->assertSame(1, $owed['with_courier']['orders']);
        $this->assertSame(3000.0, $owed['with_courier']['amount']);
    }

    public function test_a_paid_order_owes_nothing(): void
    {
        $order = $this->owing(5000, 'delivered');
        $order->forceFill(['payment_status' => 'paid'])->save();

        app(OrderPaymentService::class)
            ->record($order->fresh(), User::factory()->create(['role' => 'admin']), 5000, 'cash');

        $this->assertSame(0.0, MoneyReport::owed()['total']);
    }

    public function test_refunds_are_grouped_by_reason(): void
    {
        $order = $this->owing(5000, 'delivered');

        foreach ([['Arrived damaged', 800], ['Arrived damaged', 700], ['Changed mind', 500]] as [$why, $amount]) {
            Refund::create([
                'order_id' => $order->id, 'amount' => $amount, 'method' => 'bkash',
                'reason' => $why, 'refunded_on' => now()->toDateString(),
            ]);
        }

        $report = MoneyReport::refunds(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(2000.0, $report['total']);
        // Ordered by money, so the expensive problem is the first thing read.
        $this->assertSame('Arrived damaged', $report['by_reason'][0]['label']);
        $this->assertSame(1500.0, $report['by_reason'][0]['amount']);
    }

    /**
     * VAT is reclaimed only where it was actually charged.
     *
     * Applying the current rate to the refunded amount claims tax back on
     * orders that never carried any — which produced a bill of minus fifty-
     * eight thousand taka against nothing collected, a figure that would have
     * gone onto a return.
     */
    public function test_no_vat_is_reclaimed_on_a_sale_that_never_charged_any(): void
    {
        $order = $this->owing(5000, 'delivered');
        $order->forceFill(['vat_amount' => 0])->save();

        Refund::create([
            'order_id' => $order->id, 'amount' => 5000, 'method' => 'cash',
            'reason' => 'Changed mind', 'refunded_on' => now()->toDateString(),
        ]);

        $vat = MoneyReport::vat(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(0.0, $vat['collected']);
        $this->assertSame(0.0, $vat['refunded']);
        $this->assertSame(0.0, $vat['net'], 'A refund cannot make the shop owe negative tax.');
    }

    /** A partial refund reclaims its share: half back is half the VAT back. */
    public function test_a_partial_refund_reclaims_its_share_of_the_vat(): void
    {
        $order = $this->owing(1150, 'delivered');
        $order->forceFill(['vat_amount' => 150, 'vat_inclusive' => true])->save();

        Refund::create([
            'order_id' => $order->id, 'amount' => 575, 'method' => 'cash',
            'reason' => 'Part returned', 'refunded_on' => now()->toDateString(),
        ]);

        $vat = MoneyReport::vat(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(150.0, $vat['collected']);
        $this->assertSame(75.0, $vat['refunded']);
        $this->assertSame(75.0, $vat['net']);
    }

    // --- couriers --------------------------------------------------------------

    /**
     * Only finished journeys count towards a rate. Including parcels still in
     * transit makes a courier look worse the busier the shop has been, which
     * measures the shop rather than them.
     */
    public function test_a_couriers_rate_ignores_parcels_still_in_transit(): void
    {
        $pathao = Courier::where('slug', 'pathao')->first();

        $this->carried($pathao, 'delivered');
        $this->carried($pathao, 'delivered');
        $this->carried($pathao, 'returned');
        $this->carried($pathao, 'shipped');

        $report = DeliveryReport::for(now()->startOfMonth()->toDateString(), now()->toDateString());
        $row = $report['couriers'][0];

        $this->assertSame(4, $row['parcels']);
        $this->assertSame(1, $row['in_transit']);
        // Two of the three that finished.
        $this->assertSame(66.7, $row['delivery_rate']);
        $this->assertSame(33.3, $row['return_rate']);
    }

    public function test_a_courier_with_nothing_settled_has_no_rate_rather_than_zero(): void
    {
        $this->carried(Courier::where('slug', 'redx')->first(), 'shipped');

        $report = DeliveryReport::for(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertNull($report['couriers'][0]['delivery_rate']);
    }

    // --- suppliers ---------------------------------------------------------------

    /**
     * The number that could not be answered before purchase orders existed: a
     * supplier who habitually ships eighteen against twenty looked exactly like
     * one who ships twenty.
     */
    public function test_a_suppliers_fill_rate_shows_short_shipments(): void
    {
        $buyer = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Smart Tech', 'phone' => '01711000000', 'is_active' => true]);
        $orders = app(PurchaseOrderService::class);

        $po = $orders->send($orders->save(null, $supplier, $buyer, [
            ['product_id' => $this->gpu->id, 'quantity' => 20, 'unit_cost' => 6000],
        ]));

        $orders->receive($po, $buyer, [
            ['purchase_order_item_id' => $po->items()->first()->id, 'quantity' => 15],
        ]);

        $report = SupplierReport::for(now()->startOfMonth()->toDateString(), now()->toDateString());
        $row = $report['suppliers'][0];

        $this->assertSame('Smart Tech', $row['name']);
        $this->assertSame(20, $row['units_ordered']);
        $this->assertSame(15, $row['units_received']);
        $this->assertSame(5, $row['still_owed']);
        $this->assertSame(75.0, $row['fill_rate']);
    }

    public function test_an_overdue_order_is_listed_with_how_late_it_is(): void
    {
        $buyer = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Smart Tech', 'phone' => '01711000000', 'is_active' => true]);
        $orders = app(PurchaseOrderService::class);

        $po = $orders->save(null, $supplier, $buyer, [
            ['product_id' => $this->gpu->id, 'quantity' => 10],
        ], ['expected_on' => now()->subDays(9)->toDateString()]);

        $orders->send($po);

        $outstanding = SupplierReport::for(now()->startOfMonth()->toDateString(), now()->toDateString())['outstanding'];

        $this->assertSame(1, count($outstanding));
        $this->assertSame(9, $outstanding[0]['days_overdue']);
        $this->assertSame(10, $outstanding[0]['outstanding']);
    }

    // --- helpers -----------------------------------------------------------------

    private function owing(float $total, string $status): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
            'session_id' => str_repeat('o', 40),
            'status' => $status,
            'subtotal' => $total, 'shipping_fee' => 0, 'discount' => 0, 'total' => $total,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
    }

    private function carried(Courier $courier, string $status): Order
    {
        $order = $this->owing(1000, $status);

        $order->forceFill([
            'courier_id' => $courier->id,
            'dispatched_at' => now()->subDays(3),
        ])->save();

        return $order;
    }
}
