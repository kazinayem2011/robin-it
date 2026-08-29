<?php

namespace Tests\Feature\Barcode;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BarcodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning what a scanner typed into a row.
 *
 * A handheld scanner is a keyboard: it types the code and presses Enter. There
 * is no camera to drive and no library to pull in, so the whole feature is the
 * lookup — and the lookup has to know the three things a scanned sticker could
 * be, or people go back to finding products in a list by name.
 */
class BarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    private BarcodeLookup $lookup;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = app(BarcodeLookup::class);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true])->id,
            'name' => 'Corsair Vengeance', 'slug' => 'corsair-vengeance',
            'barcode' => '840006600305',
            'price' => 12000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    public function test_a_products_own_barcode_finds_it(): void
    {
        $hit = $this->lookup->find('840006600305');

        $this->assertTrue($hit['found']);
        $this->assertSame('product', $hit['matched_on']);
        $this->assertSame($this->product->id, $hit['product_id']);
        $this->assertNull($hit['product_variant_id']);
    }

    /**
     * 16GB and 32GB of the same stick are different boxes with different
     * numbers, and counting them as one product is the mistake a stock take is
     * meant to catch.
     */
    public function test_a_variant_barcode_finds_the_variant_not_just_the_product(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id, 'name' => '32GB',
            'barcode' => '840006600312', 'price' => 22000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);

        $hit = $this->lookup->find('840006600312');

        $this->assertSame('variant', $hit['matched_on']);
        $this->assertSame($variant->id, $hit['product_variant_id']);
        $this->assertStringContainsString('32GB', $hit['name']);
    }

    /**
     * On a graphics card the sticker somebody scans is as likely to be the
     * serial as the retail barcode. Answering "not found" to a number we hold
     * is how people stop trusting the scanner.
     */
    public function test_a_serial_number_finds_what_it_belongs_to(): void
    {
        ProductSerial::create([
            'product_id' => $this->product->id,
            'serial' => 'SN-123456',
            'status' => ProductSerial::IN_STOCK,
        ]);

        $hit = $this->lookup->find('sn-123456');

        $this->assertTrue($hit['found']);
        $this->assertSame('serial', $hit['matched_on']);
        $this->assertSame($this->product->id, $hit['product_id']);
        $this->assertSame('SN-123456', $hit['serial']);
    }

    /**
     * Scanners append a return, and some are configured to send a prefix
     * character. Failing a lookup on something invisible is the worst kind of
     * bug to be standing in a stockroom with.
     */
    public function test_the_invisible_characters_a_scanner_sends_are_ignored(): void
    {
        foreach (["840006600305\r\n", '  840006600305  ', "\x02840006600305\x03"] as $raw) {
            $this->assertTrue(
                $this->lookup->find($raw)['found'],
                'A scan of '.json_encode($raw).' should have matched.'
            );
        }
    }

    public function test_an_unknown_code_is_not_a_match(): void
    {
        $this->assertFalse($this->lookup->find('000000000000')['found']);
        $this->assertFalse($this->lookup->find('')['found']);
    }

    // --- through the endpoint ---------------------------------------------

    public function test_the_endpoint_answers_a_scan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/stock/barcode?code=840006600305')
            ->assertOk()
            ->assertJsonPath('data.product_id', $this->product->id)
            ->assertJsonPath('data.matched_on', 'product');
    }

    /** A 404 rather than an empty success, so the page can say so out loud. */
    public function test_an_unknown_code_comes_back_as_not_found(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/stock/barcode?code=999999999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_scanning_needs_the_stock_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->getJson('/api/admin/stock/barcode?code=840006600305')
            ->assertStatus(403);
    }

    // --- keeping them unique ----------------------------------------------

    /**
     * Two products sharing a barcode makes every scan of it a coin toss, which
     * is worse than having no barcode on either.
     */
    public function test_a_barcode_already_in_use_is_refused_with_a_message(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);

        $other = Product::create([
            'category_id' => $this->product->category_id,
            'name' => 'Other stick', 'slug' => 'other-stick',
            'price' => 9000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/admin/products/{$other->id}", ['barcode' => '840006600305'])
            ->assertStatus(422);

        $this->assertNull($other->fresh()->barcode);
    }

    public function test_a_product_keeps_its_own_barcode_when_edited(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/products/{$this->product->id}", [
                'barcode' => '840006600305',
                'name' => 'Corsair Vengeance RGB',
            ])
            ->assertOk();

        $this->assertSame('840006600305', $this->product->fresh()->barcode);
    }

    /** A barcode typed onto the wrong product has to be removable. */
    public function test_a_barcode_can_be_cleared(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/products/{$this->product->id}", ['barcode' => null])
            ->assertOk();

        $this->assertNull($this->product->fresh()->barcode);
    }

    /**
     * The same number on two options of one product would otherwise reach the
     * unique index and come back as a 500, which tells nobody anything.
     */
    public function test_the_same_barcode_twice_in_one_submission_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson("/api/admin/products/{$this->product->id}", [
                'has_variants' => true,
                'variants' => [
                    ['name' => '16GB', 'barcode' => '840006600999', 'price' => 12000],
                    ['name' => '32GB', 'barcode' => '840006600999', 'price' => 22000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }
}
