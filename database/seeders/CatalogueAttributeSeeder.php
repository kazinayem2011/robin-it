<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The questions each shelf asks about its products.
 *
 * Modelled on what StarTech actually offers, read off their rendered router,
 * monitor and laptop pages rather than guessed. Price, availability and brand
 * are already facets here and are not attributes.
 *
 * Definitions are data, not code: a new category is another entry below, and
 * the storefront and the product form both draw whatever it declares.
 *
 * Attribute names are global, so two shelves asking a differently-worded
 * version of the same question must not share a name. Monitor's "Screen Size"
 * is in inch bands of its own and Laptop's is "Display Size" — StarTech
 * distinguishes them for the same reason, and the three feature lists are
 * "Additional", "Special" and plain "Features" for the same one.
 *
 * Safe to run repeatedly: everything is matched on its slug and updated in
 * place, so re-running after adding a value leaves the rest alone.
 *
 *   php artisan db:seed --class=CatalogueAttributeSeeder
 */
class CatalogueAttributeSeeder extends Seeder
{
    /**
     * Bands are curated rather than computed from whatever is in stock.
     * "301 Mbps to 750 Mbps" is a judgement about how shoppers think; a
     * quartile of the current catalogue is an accident of it, and would move
     * every time a product was added.
     *
     * A band with both bounds null is a plain choice rather than a measurement.
     */
    private const CATALOGUE = [
        'networking-router' => [
            ['Type', 'enum', null, [
                'Standard Router', 'Gaming Router', 'Mesh Router', 'SIM Router',
                'Pocket Router', 'Load Balancer Router', 'Outdoor Router', 'Core Router',
            ]],
            ['Wi-Fi Standard', 'enum', null, ['Wi-Fi 7', 'Wi-Fi 6E', 'Wi-Fi 6', 'Wi-Fi 5', 'Wi-Fi 4']],
            ['WiFi Speed', 'number', 'Mbps', [
                ['Up to 300 Mbps', null, 300],
                ['301 Mbps to 750 Mbps', 301, 750],
                ['751 Mbps to 1200 Mbps', 751, 1200],
                ['1201 Mbps to 1800 Mbps', 1201, 1800],
                ['1801 Mbps and Above', 1801, null],
            ]],
            ['Number of Bands', 'enum', null, ['Single Band', 'Dual Band', 'Tri-Band', 'Quad-Band']],
            ['Number of LAN Ports', 'enum', null, ['1', '2', '3', '4', '5 and Above']],
            ['Additional Features', 'flags', null, [
                'USB Port', 'Repeater Mode', 'Access Point Mode', 'Parental Controls',
                'Mesh Support', 'Dedicated Backhaul', 'VPN Support', 'Guest Network',
                'Beamforming', 'MU-MIMO', 'QoS', 'IPv6 Support',
                'Removable Antenna', 'PoE Support', 'Cloud Management',
            ]],
        ],

        'monitor' => [
            ['Screen Size', 'number', 'inch', [
                ['15-17 inch', 15, 17],
                ['18-20 inch', 18, 20],
                ['21-22 inch', 21, 22],
                ['23-25 inch', 23, 25],
                ['26-30 inch', 26, 30],
                ['31-40 inch', 31, 40],
                ['41 inch & Above', 41, null],
            ]],
            ['Resolution', 'enum', null, ['HD or Below', 'FHD', '2K QHD', '4K UHD', '5K']],
            ['Panel Type', 'enum', null, ['TN', 'VA', 'IPS', 'OLED', 'Mini LED']],
            ['Refresh Rate', 'number', 'Hz', [
                ['Up to 75 Hz', null, 75],
                ['100 Hz', 76, 100],
                ['120 Hz', 101, 120],
                ['144 Hz', 121, 144],
                ['165 Hz', 145, 165],
                ['180 Hz', 166, 180],
                ['240 Hz', 181, 240],
                ['300 Hz', 241, 300],
                ['360 Hz', 301, 360],
                ['480 Hz and Above', 361, null],
            ]],
            ['Response Time', 'number', 'ms', [
                ['0.5 ms and below', null, 0.5],
                ['1-4 ms', 1, 4],
                ['5-7 ms', 5, 7],
                ['8-12 ms', 8, 12],
                ['13-14 ms', 13, 14],
                ['15 ms and Above', 15, null],
            ]],
            ['Input Type', 'flags', null, [
                'VGA', 'HDMI', 'DisplayPort', 'DVI', 'D-SUB', 'USB',
                'USB Type-C', 'Thunderbolt', 'Audio Jack',
            ]],
            ['Features', 'flags', null, [
                'Height Adjustable Stand', 'Touch Screen', 'Built-in Speaker',
                'Curved Screen', 'Built-in Webcam', 'AMD FreeSync', 'NVIDIA G-Sync',
                'Gaming', 'Ultra-Wide', 'Wall Mountable',
            ]],
        ],

        'laptop' => [
            ['Series', 'enum', null, [
                'Consumer Laptops', 'Business Laptops', 'Gaming Laptops', 'Premium Ultrabook Laptops',
            ]],
            ['Processor Type', 'enum', null, ['Intel', 'AMD', 'Apple', 'Snapdragon']],
            ['Processor Model', 'enum', null, [
                'Intel Core i3', 'Intel Core i5', 'Intel Core i7', 'Intel Core i9',
                'Intel Core Ultra 5', 'Intel Core Ultra 7', 'Intel Core Ultra 9',
                'AMD Ryzen 3', 'AMD Ryzen 5', 'AMD Ryzen 7', 'AMD Ryzen 9',
                'Apple M2', 'Apple M4', 'Apple M4 Max', 'Snapdragon X Elite', 'Snapdragon X Plus',
            ]],
            ['Display Size', 'number', 'inch', [
                ['Below 13-inch', null, 12.9],
                ['13-Inch to 13.9-Inch', 13, 13.9],
                ['14-Inch to 14.9-Inch', 14, 14.9],
                ['15-Inch to 15.9-Inch', 15, 15.9],
                ['16-Inch to 16.9-Inch', 16, 16.9],
                ['17-Inch to 17.9-Inch', 17, 17.9],
            ]],
            ['Display Type', 'enum', null, ['LED', 'OLED']],
            ['RAM Size', 'number', 'GB', [
                ['8 GB', 8, 8], ['12 GB', 12, 12], ['16 GB', 16, 16], ['24 GB', 24, 24],
                ['32 GB', 32, 32], ['48 GB', 48, 48], ['64 GB', 64, 64],
            ]],
            ['RAM Type', 'enum', null, ['DDR4', 'DDR5']],
            ['SSD', 'number', 'GB', [
                ['256 GB', 256, 256], ['512 GB', 512, 512],
                ['1 TB', 1024, 1024], ['2 TB', 2048, 2048],
            ]],
            ['Graphics', 'enum', null, [
                'Shared / Integrated', 'Dedicated 4GB', 'Dedicated 6GB', 'Dedicated 8GB',
                'Dedicated 12GB', 'Dedicated 16GB', 'Dedicated 24GB',
            ]],
            ['Operating System', 'enum', null, ['Free Dos', 'Windows', 'macOS']],
            ['Special Features', 'flags', null, [
                'Backlit Keyboard', 'Finger Print', '360°', 'Touch Screen',
                'Dual Display', 'Type-C Port', 'RJ45 LAN Port',
            ]],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $categorySlug => $definitions) {
            $category = Category::where('slug', $categorySlug)->first();

            if (! $category) {
                $this->command?->warn("No \"{$categorySlug}\" category; its questions were skipped.");

                continue;
            }

            foreach ($definitions as $order => [$name, $inputType, $unit, $rows]) {
                $attribute = Attribute::updateOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'unit' => $unit,
                        'input_type' => $inputType,
                        'sort_order' => $order,
                    ]
                );

                foreach ($rows as $index => $row) {
                    [$label, $from, $to] = is_array($row) ? $row : [$row, null, null];

                    AttributeValue::updateOrCreate(
                        ['attribute_id' => $attribute->id, 'slug' => Str::slug($label)],
                        [
                            'label' => $label,
                            'range_from' => $from,
                            'range_to' => $to,
                            'sort_order' => $index,
                        ]
                    );
                }

                // Declared once, on the aisle. Every shelf beneath inherits it.
                $attribute->categories()->syncWithoutDetaching([
                    $category->id => ['sort_order' => $order],
                ]);
            }

            $this->command?->info(
                count($definitions)." questions defined against {$category->name}."
            );
        }
    }
}
