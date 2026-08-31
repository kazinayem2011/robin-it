<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Database\Seeder;

/**
 * One real product, entered exactly as the shop being modelled presents it.
 *
 * Every other product in the local database is a "Sample X" placeholder, which
 * proves the tree renders and nothing else. This one proves the schema: a
 * genuine laptop with fifteen specification groups, forty-five rows, a real
 * description and real warranty terms, so it is possible to see whether the
 * product page holds up under actual content rather than three-word stubs.
 *
 * It is also the answer to "did we model this right?". Every field below came
 * off a live product page. If something here needed a column that does not
 * exist, that is a gap in the design, not a gap in the data — which is exactly
 * how `warranty_text` was found.
 */
class ReferenceProductSeeder extends Seeder
{
    /**
     * Group => [[name, value], ...], in the order the shop lists them. Order is
     * information: a spec sheet opens with the processor and closes with the
     * warranty, never alphabetically.
     */
    private const SPECIFICATIONS = [
        'Processor' => [
            ['Processor Brand', 'Intel'],
            ['Processor Model', 'Core i5-13420H'],
            ['Generation', '13th Gen'],
            ['Processor Frequency', '3.4GHz to 4.6GHz'],
            ['Processor Core', '8 (4 Performance cores, 4 Efficient cores)'],
            ['Processor Thread', '12'],
            ['CPU Cache', '12MB'],
        ],
        'Chipset' => [
            ['Chipset Model', 'Intel SoC Platform'],
        ],
        'Display' => [
            ['Display Size', '15.6 Inch'],
            ['Display Type', 'IPS'],
            ['Display Resolution', 'FHD (1920*1080)'],
            ['Touch Screen', 'No'],
            ['Refresh Rate', '144Hz'],
            ['Display Features', '100% SRGB'],
        ],
        'Memory' => [
            ['RAM', '16GB'],
            ['RAM Type', 'DDR5'],
            ['Bus Speed', '5200MHz'],
            ['Total RAM Slot', '2'],
            ['Max RAM Capacity', '64GB'],
        ],
        'Storage' => [
            ['Storage Type', 'NVMe PCIe Gen4 SSD'],
            ['Storage Capacity', '512GB'],
            ['Extra M.2 Slot', 'N/A'],
        ],
        'Graphics' => [
            ['Graphics Model', 'NVIDIA GeForce RTX 3050'],
            ['Graphics Memory', '4GB'],
            ['Graphics Type', 'GDDR6'],
        ],
        'Keyboard & TouchPad' => [
            ['Keyboard Type', 'Single Backlit Keyboard (Blue)'],
            ['TouchPad', 'Yes'],
        ],
        'Camera & Audio' => [
            ['WebCam', 'HD type (30fps@720p)'],
            ['Speaker', '2x 2W Speaker'],
            ['Microphone', 'Yes'],
        ],
        'Ports & Slots' => [
            ['Card Reader', 'N/A'],
            ['HDMI Port', '1x HDMI 2.1 (4K @ 60Hz)'],
            ['USB 3 Port', '2x Type-A USB3.2 Gen1'],
            ['USB Type-C / Thunderbolt Port', '1x Type-C (USB3.2 Gen1 / DisplayPort)'],
            ['Headphone & Microphone Port', '1x Mic-in/Headphone-out Combo Jack'],
        ],
        'Network & Connectivity' => [
            ['LAN', '1x RJ45'],
            ['WiFi', 'Wi-Fi 6E AX211'],
            ['Bluetooth', 'Bluetooth v5.2'],
        ],
        'Security' => [
            ['Fingerprint Sensor', 'N/A'],
            ['Security Locker Slot', 'Kensington Lock'],
        ],
        'Software' => [
            ['Operating System', 'FreeDOS'],
        ],
        'Power' => [
            ['Battery Type', '3 cell'],
            ['Battery Capacity', '53.5Whr'],
            ['Adapter Type', '120W Adapter'],
        ],
        'Physical Specification' => [
            ['Color', 'Translucent Black'],
            ['Dimensions', '359.36 x 250.34 x 21.95~22.9 mm'],
            ['Weight', '1.98 kg'],
            ['Part Number / MPN', '9S7-15K112-2423'],
        ],
        'Warranty' => [
            ['Warranty Details', '2 Years warranty (Battery & Adapter 1 Year)'],
        ],
    ];

    public function run(): void
    {
        $category = Category::where('slug', 'laptop-gaming-laptop-msi')->first()
            ?? Category::where('slug', 'like', '%gaming-laptop%')->first();

        if (! $category) {
            $this->command?->error('No gaming laptop category found. Run StarTechTaxonomySeeder first.');

            return;
        }

        $product = Product::updateOrCreate(
            ['slug' => 'msi-cyborg-15-black-edition-a13uc-core-i5-laptop'],
            [
                'category_id' => $category->id,
                'name' => 'MSI Cyborg 15 Black Edition A13UC Core i5 13th Gen RTX 3050 15.6" FHD Gaming Laptop',
                'model' => 'Cyborg 15 Black Edition A13UC',
                'mpn' => '9S7-15K112-2423',

                // Regular price and the price actually charged. Their page shows
                // both, the second struck through.
                'price' => 132000,
                'discount_price' => 125000,

                // Posted through the ledger after creation, not written here:
                // stock the shop never bought and cannot explain is exactly
                // what the stock screen should never show.
                'stock_quantity' => 0,

                // Their "Key Features" list, which is a curated summary rather
                // than a truncated description.
                'short_description' => 'Intel Core i5-13420H (12M Cache, 3.4 GHz up to 4.6 GHz) · 16GB DDR5 5200MHz · 512GB NVMe PCIe Gen4x4 SSD · NVIDIA GeForce RTX 3050 4GB GDDR6 · Single Backlit Keyboard, Type-C, Wi-Fi 6E, 144Hz',

                'description' => '<h2>MSI Cyborg 15 Black Edition A13UC Core i5 13th Gen RTX 3050 15.6" FHD Gaming Laptop</h2>'
                    .'<p>The <strong>MSI Cyborg 15 Black Edition A13UC</strong> delivers strong performance with its Intel Core i5-13420H processor. Featuring 8 cores and 12 threads, it ensures smooth multitasking and efficient gameplay. The 15.6-inch FHD IPS display with 144Hz refresh rate enhances visuals, offering fluid motion for competitive gaming.</p>'
                    .'<p>Equipped with 16GB DDR5 RAM running at 5200MHz, it supports demanding applications with ease. Its 512GB NVMe PCIe Gen4 SSD ensures rapid boot times and quick data access. Connectivity includes Wi-Fi 6E, Bluetooth 5.2, HDMI 2.1 and multiple USB ports.</p>'
                    .'<p>Weighing only 1.98 kg, its translucent black design offers a sleek look. A 53.5Whr battery paired with a 120W adapter ensures consistent performance during extended use.</p>',

                // 24 for the claims system to count; the sentence for the
                // customer, because the battery runs to a different clock.
                'warranty_months' => 24,
                'warranty_text' => '2 Years warranty (Battery & Adapter 1 Year)',

                'meta_title' => 'MSI Cyborg 15 Black Edition A13UC Laptop Price in Bangladesh',
                'meta_description' => 'Buy MSI Cyborg 15 Black Edition A13UC Laptop at best price in Bangladesh. Latest MSI Gaming Laptop available at Robin\'s Computer. Order online for delivery in BD.',
                'meta_keyword' => 'MSI Cyborg 15 Black Edition A13UC Core i5 13th Gen RTX 3050 15.6" FHD Gaming Laptop',

                'is_active' => true,
                'is_featured' => true,
            ]
        );

        $product->specifications()->delete();

        $position = 0;

        foreach (self::SPECIFICATIONS as $group => $rows) {
            foreach ($rows as [$name, $value]) {
                $product->specifications()->create([
                    'group' => $group,
                    'name' => $name,
                    'value' => $value,
                    'sort_order' => $position++,
                ]);
            }
        }

        if ($product->images()->count() === 0) {
            $product->images()->create([
                'image_path' => '/images/product-placeholder.svg',
                'alt_text' => 'Front open view of MSI Cyborg 15 Black Edition A13UC gaming laptop showing display and backlit keyboard',
                'is_primary' => true,
            ]);
        }

        // Idempotent: the seeder is re-runnable, and a second opening balance
        // on the same product would be a quantity nobody delivered.
        if (! $product->stockMovements()->exists()) {
            app(StockService::class)->recordOpeningBalance(
                $product,
                null,
                5,
                null,
                'Seeded opening balance — replace with a real delivery',
            );
            $product->update(['stock_quantity' => 5]);
        }

        $this->command?->info(sprintf(
            'Reference product seeded: %d specifications across %d groups.',
            $position,
            count(self::SPECIFICATIONS)
        ));
    }
}
