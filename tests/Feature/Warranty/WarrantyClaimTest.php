<?php

namespace Tests\Feature\Warranty;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\SerialService;
use App\Services\SmsService;
use App\Services\StockService;
use App\Services\WarrantyService;
use App\Support\ProfitAndLoss;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A warranty claim, from the form to the counter.
 *
 * A claim was a typed serial with a status anyone could set to anything. It
 * never looked at the shop's own units, told the customer nothing, could go
 * backwards, and a replacement moved no stock.
 */
class WarrantyClaimTest extends TestCase
{
    use RefreshDatabase;

    private Product $laptop;

    private Store $store;

    private $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = $this->spy(SmsService::class);
        $this->store = Store::create([
            'name' => 'Uttara', 'city' => 'Dhaka', 'address' => 'Test',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
        ]);
        $this->laptop = Product::create([
            'category_id' => Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true])->id,
            'name' => 'ASUS Vivobook', 'slug' => 'asus-vivobook', 'price' => 60000,
            'stock_quantity' => 0, 'is_active' => true, 'warranty_months' => 24,
        ]);

        app(StockService::class)->record($this->laptop, null, 3, 'purchase', ['store_id' => $this->store->id, 'unit_cost' => 50000]);
        app(SerialService::class)->receive($this->laptop, null, ['SN-1', 'SN-2', 'SN-3'], $this->store->id);
    }

    /** SN-1, sold to a customer. */
    private function sold(): ProductSerial
    {
        $order = Order::create([
            'order_number' => 'ORD-WARRANTY', 'session_id' => str_repeat('w', 40),
            'status' => 'delivered', 'subtotal' => 60000, 'shipping_fee' => 0, 'discount' => 0, 'total' => 60000,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->laptop->id, 'product_name' => $this->laptop->name,
            'price' => 60000, 'quantity' => 1, 'total' => 60000, 'unit_cost' => 50000,
        ]);
        app(SerialService::class)->assignToOrder($order->load('items.product'), $this->store->id);

        return ProductSerial::where('serial', 'SN-1')->sole();
    }

    private function claim(string $serial = 'sn-1'): WarrantyClaim
    {
        $this->postJson('/api/warranty/claim', [
            'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'product_name' => 'ASUS Vivobook', 'serial_number' => $serial,
            'issue_type' => 'Hardware Malfunction', 'issue_description' => 'It does not turn on any more.',
        ])->assertStatus(201);

        return WarrantyClaim::latest('id')->first();
    }

    private function staffView(WarrantyClaim $claim): array
    {
        return app(WarrantyService::class)->check($claim->fresh());
    }

    public function test_a_claim_is_linked_to_the_unit_the_shop_sold(): void
    {
        $unit = $this->sold();
        $claim = $this->claim();

        $this->assertSame($unit->id, $claim->product_serial_id);
        $this->assertSame('SN-1', $claim->serial_number, 'stored in one form');

        $check = $this->staffView($claim);
        $this->assertSame('ok', $check['tone']);
        $this->assertStringContainsString('under warranty until', $check['label']);
        $this->assertSame('ORD-WARRANTY', $check['order_number']);
    }

    public function test_staff_are_warned_about_units_the_shop_never_sold(): void
    {
        $this->assertSame('Not a unit we sold', $this->staffView($this->claim('SN-NOT-OURS'))['label']);
        $this->assertStringContainsString('never sold', $this->staffView($this->claim('SN-2'))['label']);
    }

    public function test_staff_are_warned_when_the_warranty_has_ended(): void
    {
        $this->sold()->update(['warranty_until' => now()->subDay()->toDateString()]);

        $check = $this->staffView($this->claim());
        $this->assertSame('bad', $check['tone']);
        $this->assertStringStartsWith('Warranty ended', $check['label']);
    }

    public function test_a_second_open_claim_on_the_same_unit_is_refused(): void
    {
        $this->sold();
        $first = $this->claim();

        $this->postJson('/api/warranty/claim', [
            'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'product_name' => 'ASUS Vivobook', 'serial_number' => ' sn-1 ',
            'issue_type' => 'Hardware Malfunction', 'issue_description' => 'Still does not turn on.',
        ])->assertStatus(422)->assertJsonFragment(['message' => "This serial already has a claim in progress: {$first->claim_number}. Track it with that number on this page."]);
    }

    public function test_looking_up_the_serial_shows_the_claim(): void
    {
        $this->sold();
        $claim = $this->claim();

        $this->getJson('/api/warranty/check?query=sn-1')
            ->assertOk()
            ->assertJsonPath('data.existing_claim.claim_number', $claim->claim_number)
            ->assertJsonPath('data.existing_claim.status_label', 'Received');
    }

    public function test_an_invoice_lookup_uses_the_products_own_warranty(): void
    {
        $this->sold();

        $this->getJson('/api/warranty/check?query=ORD-WARRANTY')
            ->assertOk()
            ->assertJsonPath('data.warranty_period', '24 months from the date of purchase');
    }

    public function test_stages_only_move_forward(): void
    {
        $this->sold();
        $claim = $this->claim();
        $warranty = app(WarrantyService::class);

        $warranty->move($claim, 'repairing', 'Screen cable reseated.');

        try {
            $warranty->move($claim->fresh(), 'diagnosing', null);
            $this->fail('A claim went back from Repairing to Checking.');
        } catch (StorefrontException $e) {
            $this->assertSame('A claim cannot go back from Repairing to Checking.', $e->getMessage());
        }

        $warranty->move($claim->fresh(), 'completed', 'Collected.');
        $this->expectException(StorefrontException::class);
        $warranty->move($claim->fresh(), 'rejected', null);
    }

    public function test_the_customer_is_texted_at_the_three_moments_that_matter(): void
    {
        $this->sold();
        $claim = $this->claim();
        $warranty = app(WarrantyService::class);

        $warranty->move($claim, 'diagnosing', null);
        $warranty->move($claim->fresh(), 'repairing', null);
        $warranty->move($claim->fresh(), 'ready_for_pickup', null);

        $this->sms->shouldHaveReceived('sendEvent')->with('warranty_received', '01712345678', Mockery::type('string'))->once();
        $this->sms->shouldHaveReceived('sendEvent')->with('warranty_ready', '01712345678', Mockery::on(fn ($t) => str_contains($t, $claim->claim_number)))->once();
        $this->sms->shouldNotHaveReceived('sendEvent', ['warranty_rejected', Mockery::any(), Mockery::any()]);
    }

    public function test_a_rejection_is_texted(): void
    {
        $claim = $this->claim('SN-NOT-OURS');
        app(WarrantyService::class)->move($claim, 'rejected', 'Not a unit sold by this shop.');

        $this->sms->shouldHaveReceived('sendEvent')->with('warranty_rejected', '01712345678', Mockery::type('string'))->once();
    }

    public function test_a_replacement_moves_the_new_unit_to_the_customer_and_marks_the_old_one_faulty(): void
    {
        $old = $this->sold();
        $claim = $this->claim();
        $new = ProductSerial::where('serial', 'SN-2')->sole();
        $stockBefore = (int) $this->laptop->fresh()->stock_quantity;

        app(WarrantyService::class)->replace($claim, $new->id, null);

        $new = $new->fresh();
        $this->assertSame(ProductSerial::SOLD, $new->status);
        $this->assertSame($old->order_id, $new->order_id);
        $this->assertSame($old->warranty_until->toDateString(), $new->warranty_until->toDateString(), 'the cover continues, it does not restart');
        $this->assertSame(ProductSerial::FAULTY, $old->fresh()->status);

        $this->assertSame($stockBefore - 1, (int) $this->laptop->fresh()->stock_quantity, 'the new unit left the shelf');
        $this->assertSame(1, ProductSerial::available()->count(), 'SN-3 is the only one left');

        $claim = $claim->fresh();
        $this->assertSame('ready_for_pickup', $claim->status);
        $this->assertSame($new->id, $claim->replacement_serial_id);

        // What the new unit cost the shop is a cost, under Stock lost.
        $this->assertSame(50000.0, ProfitAndLoss::statement(now()->toDateString(), now()->toDateString())['stock_lost']['amount']);
    }

    public function test_a_replacement_must_be_the_same_product_and_on_the_shelf(): void
    {
        $this->sold();
        $claim = $this->claim();
        $warranty = app(WarrantyService::class);

        $other = Product::create([
            'category_id' => $this->laptop->category_id, 'name' => 'Dell', 'slug' => 'dell',
            'price' => 50000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(SerialService::class)->receive($other, null, ['DELL-1'], $this->store->id);

        try {
            $warranty->replace($claim, ProductSerial::where('serial', 'DELL-1')->value('id'), null);
            $this->fail('A Dell replaced an ASUS.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('same product', $e->getMessage());
        }

        $this->expectException(StorefrontException::class);
        $warranty->replace($claim, ProductSerial::where('serial', 'SN-1')->value('id'), null); // the customer's own
    }

    public function test_the_admin_screen_shows_the_check_and_the_way_forward(): void
    {
        $this->seed(RoleSeeder::class);
        Roles::forget();
        $this->sold();
        $this->claim();

        $claims = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/warranty')
            ->assertOk()
            ->viewData('page')['props']['claims'];

        $this->assertSame('ok', $claims[0]['check']['tone']);
        $this->assertTrue($claims[0]['can_replace']);
        $this->assertSame(['received', 'diagnosing', 'repairing', 'ready_for_pickup', 'completed', 'rejected'], $claims[0]['next_statuses']);
        $this->assertCount(2, $claims[0]['replacement_options'], 'SN-2 and SN-3 are on the shelf');
    }

    public function test_the_replace_endpoint_works_for_staff(): void
    {
        $this->seed(RoleSeeder::class);
        Roles::forget();
        $this->sold();
        $claim = $this->claim();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson("/api/admin/warranty/{$claim->id}/replace", ['serial_id' => ProductSerial::where('serial', 'SN-2')->value('id')])
            ->assertOk()
            ->assertJsonPath('message', "{$claim->claim_number}: replacement recorded. It is ready for pickup.");
    }
}
