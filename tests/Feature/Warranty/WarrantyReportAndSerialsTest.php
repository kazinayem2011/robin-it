<?php

namespace Tests\Feature\Warranty;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\SerialService;
use App\Services\SmsService;
use App\Services\StockService;
use App\Services\WarrantyService;
use App\Support\Reports\WarrantyReport;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Serial numbers where a warranty needs them, the warranty report, and who
 * may open which report.
 */
class WarrantyReportAndSerialsTest extends TestCase
{
    use RefreshDatabase;

    private Product $laptop;

    private Product $cable;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();
        $this->spy(SmsService::class);

        $this->store = Store::create([
            'name' => 'Uttara', 'city' => 'Dhaka', 'address' => 'Test',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
        ]);
        $category = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true]);
        $this->laptop = Product::create([
            'category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'asus',
            'price' => 60000, 'stock_quantity' => 0, 'is_active' => true, 'warranty_months' => 24,
        ]);
        $this->cable = Product::create([
            'category_id' => $category->id, 'name' => 'HDMI cable', 'slug' => 'cable',
            'price' => 500, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function receive(Product $product, int $quantity, ?string $serials): TestResponse
    {
        return $this->actingAs($this->staff('admin'))->postJson('/api/admin/stock/receipts', [
            'store_id' => $this->store->id,
            'lines' => [[
                'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => 100,
                'serials' => $serials,
            ]],
        ]);
    }

    // --- serials at the door -----------------------------------------------

    public function test_a_product_with_a_warranty_needs_a_serial_for_every_unit(): void
    {
        $this->receive($this->laptop, 2, 'SN-1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'ASUS Vivobook has a warranty, so each unit needs its serial number: 2 arrived, 1 typed. They are on the label of each box.');

        $this->assertSame(0, (int) $this->laptop->fresh()->stock_quantity, 'nothing was received');

        $this->receive($this->laptop, 2, "SN-1\nSN-2")->assertSuccessful();
        $this->assertSame(2, ProductSerial::count());
    }

    public function test_a_product_without_a_warranty_can_arrive_without_serials(): void
    {
        $this->receive($this->cable, 20, null)->assertSuccessful();
        $this->assertSame(20, (int) $this->cable->fresh()->stock_quantity);
    }

    public function test_a_purchase_order_delivery_needs_them_too(): void
    {
        $buyer = $this->staff('admin');
        $supplier = Supplier::create(['name' => 'Smart Tech', 'phone' => '01711000000', 'is_active' => true]);
        $orders = app(PurchaseOrderService::class);
        $po = $orders->save(null, $supplier, $buyer, [['product_id' => $this->laptop->id, 'quantity' => 2, 'unit_cost' => 50000]]);
        $item = $po->items()->first();

        $this->assertTrue($item->needs_serials, 'the window is told to ask');

        $this->expectExceptionMessage('ASUS Vivobook has a warranty');
        $orders->receive($po, $buyer, [['purchase_order_item_id' => $item->id, 'quantity' => 2]]);
    }

    // --- the report ----------------------------------------------------------

    public function test_the_report_counts_claims_times_repairs_and_prices_replacements(): void
    {
        app(StockService::class)->record($this->laptop, null, 3, 'purchase', ['store_id' => $this->store->id, 'unit_cost' => 50000]);
        app(SerialService::class)->receive($this->laptop, null, ['SN-1', 'SN-2', 'SN-3'], $this->store->id);
        ProductSerial::where('serial', 'SN-1')->update(['status' => ProductSerial::SOLD, 'sold_at' => now(), 'warranty_until' => now()->addYear()]);

        $warranty = app(WarrantyService::class);
        $details = ['customer_name' => 'Rahim', 'customer_phone' => '01712345678', 'product_name' => 'ASUS Vivobook',
            'issue_type' => 'Hardware Malfunction', 'issue_description' => 'It does not turn on.'];

        $replaced = $warranty->submit($details + ['serial_number' => 'SN-1'], null);
        $this->travel(2)->days();
        $warranty->replace($replaced, ProductSerial::where('serial', 'SN-2')->value('id'), null);

        $rejected = $warranty->submit($details + ['serial_number' => 'NOT-OURS'], null);
        $warranty->move($rejected, 'rejected', 'Not bought from this shop.');

        $report = WarrantyReport::for(now()->subWeek()->toDateString(), now()->toDateString());

        $this->assertSame(2, $report['totals']['opened']);
        $this->assertSame(1, $report['totals']['open_now'], 'the replaced one waits for pickup');
        $this->assertSame(1, $report['totals']['rejected']);
        $this->assertSame(1, $report['totals']['replaced']);
        $this->assertSame(2.0, $report['totals']['average_days']);
        $this->assertSame(50000.0, $report['totals']['replacement_cost']);
        $this->assertSame(1, $report['totals']['unknown_units']);
        $this->assertSame('Not bought from this shop.', $report['rejected'][0]['why']);
        $this->assertSame('ASUS Vivobook', $report['by_product'][0]['name']);
        $this->assertSame(2, $report['by_product'][0]['claims']);

        $this->assertNotNull($replaced->fresh()->ready_at);
        $this->assertNotNull($rejected->fresh()->closed_at);
    }

    // --- who may open what ----------------------------------------------------

    public function test_support_sees_the_warranty_report_without_money(): void
    {
        $props = $this->actingAs($this->staff('support'))
            ->get('/admin/reports/warranty')->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['seesMoney']);
        $this->actingAs($this->staff('accountant'))->get('/admin/reports/warranty')->assertOk();
        $this->actingAs($this->staff('storekeeper'))->get('/admin/reports/warranty')->assertRedirect('/admin/dashboard');
    }

    /* The list was finance-only, so a storekeeper allowed the stock report had no way to it. */
    public function test_the_reports_list_shows_each_role_the_reports_it_may_open(): void
    {
        $keys = fn (string $role) => collect($this->actingAs($this->staff($role))
            ->get('/admin/reports')->assertOk()
            ->viewData('page')['props']['reports'])->pluck('key')->all();

        $this->assertSame(['stock', 'delivery', 'suppliers'], $keys('storekeeper'));
        $this->assertSame(['delivery', 'warranty'], $keys('support'));
        $this->assertSame(['sales', 'money', 'delivery', 'warranty', 'profit'], $keys('accountant'));
        $this->assertCount(7, $keys('admin'));
    }

    /* Taking a phone order means finding the product; support could not. */
    public function test_anyone_who_takes_orders_can_search_products(): void
    {
        $this->actingAs($this->staff('support'))->getJson('/api/admin/stock/units?search=ASUS')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ASUS Vivobook');

        $this->actingAs($this->staff('customer'))->getJson('/api/admin/stock/units')->assertForbidden();
    }
}
