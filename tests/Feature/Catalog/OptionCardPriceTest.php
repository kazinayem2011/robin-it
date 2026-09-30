<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An option product's card shows the price of the option its page opens on —
 * the first that can be bought — not the product's own. A phone at ৳90,000
 * and ৳1,00,000 read ৳1,00,000 on the card, and the page then said ৳90,000.
 */
class OptionCardPriceTest extends TestCase
{
    use RefreshDatabase;

    private function phone(): Product
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Phone', 'slug' => 'phone', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Pixel Test', 'category_id' => $category->id, 'price' => 100000, 'is_active' => true,
            'has_variants' => true, 'variant_attributes' => ['Storage'],
            'variants' => [
                ['options' => ['Storage' => '128GB'], 'price' => 95000, 'discount_price' => 90000, 'is_active' => true],
                ['options' => ['Storage' => '256GB'], 'price' => 100000, 'is_active' => true],
            ],
        ])->assertCreated();

        auth()->forgetGuards();

        return Product::firstWhere('name', 'Pixel Test');
    }

    private function card(): array
    {
        return collect($this->getJson('/api/products?search=Pixel')->assertOk()->json('data'))
            ->firstWhere('name', 'Pixel Test');
    }

    public function test_the_card_shows_the_first_option_in_stock(): void
    {
        $product = $this->phone();
        [$small, $large] = $product->variants()->orderBy('position')->get()->all();

        app(StockService::class)->receive([], [['product_id' => $product->id, 'product_variant_id' => $large->id, 'quantity' => 1]]);
        $this->assertEquals(100000, $this->card()['raw_price']);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'product_variant_id' => $small->id, 'quantity' => 1]]);
        $card = $this->card();
        $this->assertEquals(90000, $card['raw_price']);
        // Its own offer: ৳95,000 down to ৳90,000.
        $this->assertEquals(95000, $card['raw_old_price']);
        $this->assertSame('SAVE ৳5,000', $card['save']);
    }

    /** Nothing in stock: the first option, as the page opens on it. */
    public function test_sold_out_it_shows_the_first_option(): void
    {
        $this->phone();

        $this->assertEquals(90000, $this->card()['raw_price']);
    }
}
