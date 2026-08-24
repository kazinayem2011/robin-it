<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * Cases and CPU coolers.
 *
 * Without these two slots no PC Builder build can be completed, and the
 * form-factor and cooler-socket compatibility rules never run — they have
 * nothing to check against.
 *
 * The specs matter as much as the products. `Motherboard Support` on a case and
 * `Socket` on a cooler are the exact fields PcCompatibilityService reads; a
 * product without them is treated as "unknown" and silently skips the check.
 *
 * PRICES AND STOCK ARE PLACEHOLDERS. The models and their specifications are
 * real and correct, but every price here is an estimate and every quantity is
 * invented. Both need replacing with your own before this goes anywhere near a
 * customer — the admin can edit prices directly, and stock should be corrected
 * by recording a real delivery.
 *
 * Safe to re-run: existing rows are matched by slug and updated in place.
 */
class CaseAndCoolerSeeder extends Seeder
{
    public function run(): void
    {
        $brands = $this->brands();
        $categories = Category::whereIn('slug', [
            'mid-tower-atx', 'mini-itx-case', '360mm-aio', '240mm-aio', 'air-cooler',
        ])->get()->keyBy('slug');

        if ($categories->isEmpty()) {
            $this->command?->warn('Case and cooler categories are missing; run the catalogue seeder first.');

            return;
        }

        foreach ($this->cases() + $this->coolers() as $slug => $data) {
            $category = $categories->get($data['category']);

            if (! $category) {
                continue;
            }

            $this->upsert($category, $brands[$data['brand']], $slug, $data);
        }

        $this->command?->info('Cases and coolers seeded. Prices and stock are placeholders — correct them before going live.');
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $wanted = [
            'lianli' => 'Lian Li',
            'nzxt' => 'NZXT',
            'corsair' => 'Corsair',
            'cooler' => 'Cooler Master',
            'noctua' => 'Noctua',
            'thermalt' => 'Thermaltake',
        ];

        $brands = [];

        foreach ($wanted as $slug => $name) {
            $brands[$slug] = Brand::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        return $brands;
    }

    /**
     * `Motherboard Support` is what the case check reads. Without it the engine
     * cannot tell whether a board fits and says nothing at all.
     */
    private function cases(): array
    {
        return [
            'lian-li-lancool-216' => [
                'category' => 'mid-tower-atx', 'brand' => 'lianli',
                'name' => 'Lian Li Lancool 216 Mid-Tower ATX Case (Black)',
                'price' => 12500, 'discount' => 11200, 'stock' => 14,
                'short' => 'ATX Mid-Tower, 2x 160mm front fans, mesh front, up to 392mm GPU',
                'description' => 'An airflow-first mid tower with two 160mm intake fans included. Takes a full ATX board, a 392mm graphics card and a 180mm tower cooler without complaint.',
                'specs' => [
                    'Motherboard Support' => 'ATX, Micro-ATX, Mini-ITX',
                    'Form Factor' => 'Mid-Tower ATX',
                    'Max GPU Length' => '392mm',
                    'Max CPU Cooler Height' => '180mm',
                    'Included Fans' => '2x 160mm front, 1x 140mm rear',
                    'Warranty' => '1 Year Official',
                ],
            ],
            'nzxt-h7-flow' => [
                'category' => 'mid-tower-atx', 'brand' => 'nzxt',
                'name' => 'NZXT H7 Flow Mid-Tower ATX Case (White)',
                'price' => 15500, 'discount' => null, 'stock' => 9,
                'short' => 'ATX Mid-Tower, perforated panels, cable management channels, 400mm GPU',
                'description' => 'A clean-looking case that does not trade airflow for it. Perforated front and top, a proper cable bar behind the tray, and room for a 360mm radiator up top.',
                'specs' => [
                    'Motherboard Support' => 'ATX, Micro-ATX, Mini-ITX',
                    'Form Factor' => 'Mid-Tower ATX',
                    'Max GPU Length' => '400mm',
                    'Max CPU Cooler Height' => '185mm',
                    'Radiator Support' => 'Up to 360mm top, 360mm front',
                    'Warranty' => '2 Years Official',
                ],
            ],
            'cooler-master-nr200p' => [
                'category' => 'mini-itx-case', 'brand' => 'cooler',
                'name' => 'Cooler Master NR200P Mini-ITX Small Form Factor Case',
                'price' => 11000, 'discount' => 9800, 'stock' => 7,
                'short' => 'Mini-ITX SFF, 18.25L, vented side panels, up to 330mm GPU',
                'description' => 'An 18-litre box that still swallows a full-length graphics card and a 280mm radiator. The reference point for small-form-factor builds.',
                'specs' => [
                    // Deliberately narrow: an ATX board must not pass this check.
                    'Motherboard Support' => 'Mini-ITX, Mini-DTX',
                    'Form Factor' => 'Mini-ITX',
                    'Max GPU Length' => '330mm',
                    'Max CPU Cooler Height' => '155mm',
                    'Volume' => '18.25 Litres',
                    'Warranty' => '1 Year Official',
                ],
            ],
        ];
    }

    /**
     * `Socket` is what the cooler check reads, and it must list every socket the
     * mount supports — the check looks for the CPU's socket inside this string.
     */
    private function coolers(): array
    {
        return [
            'noctua-nh-d15-g2' => [
                'category' => 'air-cooler', 'brand' => 'noctua',
                'name' => 'Noctua NH-D15 G2 Dual-Tower CPU Air Cooler',
                'price' => 16500, 'discount' => null, 'stock' => 11,
                'short' => 'Dual-tower air cooler, 2x NF-A14 fans, LGA1851/1700 and AM5 support',
                'description' => 'The successor to the cooler that defined air cooling. Handles a 250W processor without a pump, and comes with a mount for every current socket.',
                'specs' => [
                    'Socket' => 'LGA1851, LGA1700, AM5, AM4',
                    'Type' => 'Dual-Tower Air Cooler',
                    'Height' => '168mm',
                    'TDP' => 'Up to 250W',
                    'Fans' => '2x NF-A14 140mm PWM',
                    'Warranty' => '6 Years Official',
                ],
            ],
            'corsair-h150i-elite' => [
                'category' => '360mm-aio', 'brand' => 'corsair',
                'name' => 'Corsair iCUE H150i Elite Capellix XT 360mm Liquid Cooler',
                'price' => 24500, 'discount' => 22000, 'stock' => 8,
                'short' => '360mm AIO, 3x AF120 RGB fans, LGA1700 and AM5 brackets included',
                'description' => 'A 360mm radiator with enough headroom for an overclocked flagship. Ships with brackets for current Intel and AMD sockets.',
                'specs' => [
                    'Socket' => 'LGA1851, LGA1700, LGA1200, AM5, AM4',
                    'Type' => '360mm AIO Liquid Cooler',
                    'Radiator Size' => '360mm',
                    'TDP' => 'Up to 300W',
                    'Fans' => '3x AF120 RGB Elite',
                    'Warranty' => '5 Years Official',
                ],
            ],
            'thermaltake-tough-240' => [
                'category' => '240mm-aio', 'brand' => 'thermalt',
                'name' => 'Thermaltake TOUGHLIQUID Ultra 240 ARGB Liquid Cooler',
                'price' => 15000, 'discount' => 13500, 'stock' => 10,
                'short' => '240mm AIO, LCD pump head, LGA1700 and AM5 support',
                'description' => 'A 240mm all-in-one with a display on the pump head. Enough cooling for a mid-range processor in a case that cannot take a 360.',
                'specs' => [
                    'Socket' => 'LGA1700, LGA1200, AM5, AM4',
                    'Type' => '240mm AIO Liquid Cooler',
                    'Radiator Size' => '240mm',
                    'TDP' => 'Up to 220W',
                    'Fans' => '2x 120mm ARGB PWM',
                    'Warranty' => '3 Years Official',
                ],
            ],
        ];
    }

    private function upsert(Category $category, Brand $brand, string $slug, array $data): void
    {
        $product = Product::updateOrCreate(
            ['slug' => $slug],
            [
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'name' => $data['name'],
                'price' => $data['price'],
                'discount_price' => $data['discount'],
                'short_description' => $data['short'],
                'description' => $data['description'],
                'is_active' => true,
            ]
        );

        ProductImage::firstOrCreate(
            ['product_id' => $product->id, 'is_primary' => true],
            ['image_path' => '/images/product-placeholder.svg']
        );

        foreach ($data['specs'] as $name => $value) {
            ProductSpecification::updateOrCreate(
                ['product_id' => $product->id, 'name' => $name],
                ['value' => $value]
            );
        }

        $this->seedOpeningStock($product, $data['stock']);
    }

    /**
     * Give the product a starting balance through the ledger rather than
     * writing stock_quantity directly, so the number is explained by a movement
     * like every other unit in the shop.
     */
    private function seedOpeningStock(Product $product, int $quantity): void
    {
        if (StockMovement::where('product_id', $product->id)->exists()) {
            return;
        }

        $store = Store::onlineFulfilment();

        StockMovement::create([
            'product_id' => $product->id,
            'store_id' => $store?->id,
            'quantity' => $quantity,
            'type' => StockMovement::OPENING,
            'balance_after' => $quantity,
            'note' => 'Seeded placeholder stock — replace with a real delivery',
        ]);

        $product->update(['stock_quantity' => $quantity]);

        if ($store) {
            ProductStock::updateOrCreate(
                ['product_id' => $product->id, 'product_variant_id' => null, 'store_id' => $store->id],
                ['quantity' => $quantity]
            );
        }
    }
}
