<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four figures and labels a product page shows above the fold.
 *
 * Each of these was a boolean or a missing column until a real competitor page
 * showed what the shop actually has to be able to say. They are tested together
 * because they are read together — a shopper compares the cash price, the
 * instalment, and whether the thing is even in stock, in one glance.
 */
class ProductMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $category = Category::create([
            'name' => 'Gaming Laptop',
            'slug' => 'gaming-laptop',
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Laptop',
            'slug' => 'test-laptop-'.uniqid(),
            'price' => 132000,
            'stock_quantity' => 5,
            'is_active' => true,
        ], $attributes));
    }

    // ---------------------------------------------------------------- status

    public function test_a_stocked_product_reads_in_stock(): void
    {
        $this->assertSame('In Stock', $this->product()->stock_status_label);
    }

    /**
     * The whole point of the column: an empty shelf is not one situation. A
     * pre-order is a sale deferred, a discontinued line is a sale lost, and a
     * boolean cannot tell a shopper which one they are looking at.
     */
    public function test_an_empty_shelf_uses_the_shop_s_own_wording(): void
    {
        $product = $this->product([
            'stock_quantity' => 0,
            'out_of_stock_status' => 'Call for Price',
        ]);

        $this->assertSame('Call for Price', $product->stock_status_label);
    }

    public function test_an_empty_shelf_without_wording_falls_back(): void
    {
        $this->assertSame(
            'Out of Stock',
            $this->product(['stock_quantity' => 0])->stock_status_label
        );
    }

    public function test_an_empty_shelf_on_a_preorder_product_says_so(): void
    {
        $product = $this->product([
            'stock_quantity' => 0,
            'allow_preorder' => true,
        ]);

        $this->assertSame('Pre-Order', $product->stock_status_label);
    }

    /**
     * A deactivated product that still has stock must not advertise it. This
     * ordering matters: the stock check comes second precisely so that pulling
     * a product offline is enough to stop it claiming availability.
     */
    public function test_a_deactivated_product_never_claims_to_be_in_stock(): void
    {
        $product = $this->product(['is_active' => false, 'stock_quantity' => 99]);

        $this->assertSame('Unavailable', $product->stock_status_label);
    }

    // ------------------------------------------------------- checkout price

    public function test_the_checkout_discount_comes_off_the_price_actually_charged(): void
    {
        $product = $this->product([
            'price' => 132000,
            'discount_price' => 125000,
            'checkout_discount' => 1500,
        ]);

        $this->assertSame(125000.0, $product->effective_price);
        $this->assertSame(123500.0, $product->checkout_price);
    }

    public function test_without_a_checkout_discount_the_two_prices_agree(): void
    {
        $product = $this->product(['price' => 132000, 'discount_price' => 125000]);

        $this->assertSame($product->effective_price, $product->checkout_price);
    }

    /**
     * A discount larger than the price would otherwise produce a negative
     * total, which downstream reads as the shop paying the customer.
     * Validation rejects it on the way in; this is the second line of defence
     * for rows that arrive some other way.
     */
    public function test_the_checkout_price_never_goes_below_zero(): void
    {
        $product = $this->product(['price' => 1000, 'checkout_discount' => 5000]);

        $this->assertSame(0.0, $product->checkout_price);
    }

    // ------------------------------------------------------------------ EMI

    /**
     * The figure this asserts is taken from a real listing: 132,000৳ over
     * twelve months is advertised as 11,000৳/month.
     *
     * Note which price it divides. The discount rewards paying at once, so an
     * instalment buyer does not get it — dividing 125,000 instead would hand
     * every EMI customer a discount the shop never offered.
     */
    public function test_the_instalment_is_the_regular_price_not_the_discounted_one(): void
    {
        $product = $this->product([
            'price' => 132000,
            'discount_price' => 125000,
            'emi_available' => true,
            'emi_max_months' => 12,
        ]);

        $this->assertSame(11000.0, $product->emi_monthly);
    }

    /**
     * Rounded up. Twelve instalments of a rounded-down figure collect less than
     * the machine costs, every time, on every product.
     */
    public function test_the_instalment_rounds_up(): void
    {
        $product = $this->product([
            'price' => 10001,
            'emi_available' => true,
            'emi_max_months' => 3,
        ]);

        // 10001 / 3 = 3333.67
        $this->assertSame(3334.0, $product->emi_monthly);
        $this->assertGreaterThanOrEqual(
            10001,
            $product->emi_monthly * 3,
            'Instalments must not collect less than the price.'
        );
    }

    #[DataProvider('emiUnavailableCases')]
    public function test_no_instalment_figure_when_emi_is_not_offered(array $attributes): void
    {
        $this->assertNull($this->product($attributes)->emi_monthly);
    }

    public static function emiUnavailableCases(): array
    {
        return [
            'flag off' => [['emi_available' => false, 'emi_max_months' => 12]],
            'no term set' => [['emi_available' => true, 'emi_max_months' => null]],
            'neither' => [[]],
        ];
    }

    // -------------------------------------------------------- serialisation

    /**
     * These are computed, so the frontend can only use them if they are
     * appended. They were invisible to the product page until they were.
     */
    public function test_the_derived_figures_reach_the_frontend(): void
    {
        $product = $this->product([
            'price' => 132000,
            'checkout_discount' => 1500,
            'emi_available' => true,
            'emi_max_months' => 12,
        ])->toArray();

        $this->assertArrayHasKey('stock_status_label', $product);
        $this->assertArrayHasKey('checkout_price', $product);
        $this->assertArrayHasKey('emi_monthly', $product);
    }
}
