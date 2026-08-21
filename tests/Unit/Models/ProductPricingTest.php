<?php

namespace Tests\Unit\Models;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The test suite runs on SQLite while production runs on MySQL, and the two
 * return decimals differently: MySQL/PDO hands back the string "0.00" (truthy in
 * PHP), SQLite hands back the float 0.0 (falsy). That divergence is exactly how
 * the "100% off" pricing bug stayed invisible to CI.
 *
 * These cases feed the raw driver values in directly, so the pricing rules are
 * pinned regardless of which database the suite happens to run against.
 */
class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    private function productWithRawAttributes(array $raw): Product
    {
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Core i9',
            'slug' => 'core-i9',
            'price' => 50000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $product->setRawAttributes(array_merge($product->getAttributes(), $raw), true);

        return $product;
    }

    public static function decimalRepresentationProvider(): array
    {
        return [
            // [price, discount_price, expected hasDiscount, expected effective price]
            'mysql string zero discount' => ['50000.00', '0.00', false, 50000.0],
            'sqlite float zero discount' => [50000.0, 0.0, false, 50000.0],
            'mysql string null discount' => ['50000.00', null, false, 50000.0],
            'mysql string real discount' => ['50000.00', '40000.00', true, 40000.0],
            'sqlite float real discount' => [50000.0, 40000.0, true, 40000.0],
            'discount above list price' => ['50000.00', '60000.00', false, 50000.0],
            'discount equal to list price' => ['50000.00', '50000.00', false, 50000.0],
        ];
    }

    #[DataProvider('decimalRepresentationProvider')]
    public function test_discount_detection_is_driver_independent(
        mixed $price,
        mixed $discountPrice,
        bool $expectedHasDiscount,
        float $expectedEffectivePrice
    ): void {
        $product = $this->productWithRawAttributes([
            'price' => $price,
            'discount_price' => $discountPrice,
        ]);

        $this->assertSame($expectedHasDiscount, $product->hasDiscount());
        $this->assertSame($expectedEffectivePrice, $product->effective_price);
    }

    public function test_a_zero_discount_from_mysql_produces_no_saving(): void
    {
        $product = $this->productWithRawAttributes([
            'price' => '50000.00',
            'discount_price' => '0.00',
        ]);

        $this->assertSame(0.0, $product->saving);
    }

    public function test_can_fulfil_respects_stock_and_active_flag(): void
    {
        $product = $this->productWithRawAttributes(['stock_quantity' => 3]);

        $this->assertTrue($product->canFulfil(3));
        $this->assertFalse($product->canFulfil(4));

        $product->is_active = false;
        $this->assertFalse($product->canFulfil(1));
        $this->assertFalse($product->isInStock());
    }
}
