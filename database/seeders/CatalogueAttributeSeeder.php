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
     *
     * A fifth element is the attribute's slug, for where two shelves ask what a
     * shopper would call the same thing. Slugs are global, so a phone's RAM and
     * a tablet's cannot both be `ram` — but both should read "RAM" in the
     * sidebar, because the shelf is the context and "Tablet RAM" on the tablet
     * page is noise. The name is shown; the slug is what must be unique.
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

        'power-ups' => [
            ['Volt Ampere', 'number', 'VA', [
                ['Up to 800 VA', null, 800],
                ['801 to 1200 VA', 801, 1200],
                ['1201 to 2000 VA', 1201, 2000],
                ['2001 to 3000 VA', 2001, 3000],
                ['3001 VA and Above', 3001, null],
            ]],
            ['Load Capacity', 'number', 'W', [
                ['Up to 400 W', null, 400],
                ['401 to 700 W', 401, 700],
                ['701 to 1200 W', 701, 1200],
                ['1201 W and Above', 1201, null],
            ]],
            ['Body Material', 'enum', null, ['Metal', 'Plastic']],
        ],

        'phone' => [
            ['Display Size', 'number', 'inch', [
                ['Below 6.0 inch', null, 5.9],
                ['6.0 to 6.4 inch', 6.0, 6.4],
                ['6.5 to 6.9 inch', 6.5, 6.9],
                ['7.0 inch and Above', 7.0, null],
            ], 'phone-display-size'],
            ['Display Type', 'enum', null,
                ['TFT', 'IPS', 'AMOLED', 'Super AMOLED', 'OLED'], 'phone-display-type'],
            ['Chipset', 'enum', null,
                ['Snapdragon', 'MediaTek', 'Exynos', 'UNISOC', 'Bionic', 'Tensor', 'Kirin']],
            ['RAM', 'number', 'GB', [
                ['2GB', 2, 2], ['3GB', 3, 3], ['4GB', 4, 4], ['6GB', 6, 6],
                ['8GB', 8, 8], ['12GB', 12, 12], ['16GB', 16, 16],
            ], 'phone-ram'],
            ['Internal Storage', 'number', 'GB', [
                ['64GB', 64, 64], ['128GB', 128, 128], ['256GB', 256, 256],
                ['512GB', 512, 512], ['1TB', 1024, 1024],
            ]],
            ['Battery', 'number', 'mAh', [
                ['Up to 2999 mAh', null, 2999],
                ['3000 to 3999 mAh', 3000, 3999],
                ['4000 to 4999 mAh', 4000, 4999],
                ['5000 to 5999 mAh', 5000, 5999],
                ['6000 mAh and Above', 6000, null],
            ]],
            ['Features', 'flags', null, [
                'Dual SIM', 'eSIM Support', '5G Network', 'Fast Charging',
                'Water Resistant', 'Foldable', 'AI Integrated',
            ], 'phone-features'],
        ],

        'tablet' => [
            ['Screen Size', 'number', 'inch', [
                ['Up to 7.4 inch', null, 7.4],
                ['7.5 to 8.4 inch', 7.5, 8.4],
                ['8.5 to 10.4 inch', 8.5, 10.4],
                ['10.5 to 11.4 inch', 10.5, 11.4],
                ['11.5 inch and Above', 11.5, null],
            ], 'tablet-screen-size'],
            ['Storage', 'number', 'GB', [
                ['32GB', 32, 32], ['64GB', 64, 64], ['128GB', 128, 128],
                ['256GB', 256, 256], ['512GB', 512, 512], ['1TB', 1024, 1024],
            ], 'tablet-storage'],
            ['RAM', 'number', 'GB', [
                ['2 GB', 2, 2], ['3 GB', 3, 3], ['4 GB', 4, 4],
                ['6 GB', 6, 6], ['8 GB', 8, 8], ['12 GB', 12, 12],
            ], 'tablet-ram'],
            ['Operating System', 'enum', null,
                ['Android', 'Windows', 'iPadOS'], 'tablet-operating-system'],
            ['Connectivity', 'enum', null, ['Wi-Fi only', 'Wi-Fi + Cellular']],
        ],

        'office-equipment-printer' => [
            ['Printer Type', 'enum', null, ['Inkjet', 'Ink Tank', 'Laser', 'Dotmatrix']],
            ['Functionality', 'enum', null, [
                'Only Print', 'Print, Scan & Copy', 'Print, Scan, Copy & Fax', 'Professional Photo',
            ]],
            ['Colour Output', 'enum', null, ['Black & White', 'Colour', 'Black & White + Colour']],
            ['Interface', 'enum', null,
                ['USB', 'USB & Wi-Fi', 'USB & LAN', 'USB, Wi-Fi & LAN'], 'printer-interface'],
            ['Print Speed', 'number', 'ppm', [
                ['Less than 15 ppm', null, 14.9],
                ['15 to 18.9 ppm', 15, 18.9],
                ['19 to 23.9 ppm', 19, 23.9],
                ['24 ppm and Above', 24, null],
            ]],
            ['Paper Size', 'enum', null, ['A4', 'A3, A3+', 'Legal']],
            ['Features', 'flags', null,
                ['Duplex (Auto)', 'Borderless', 'ADF'], 'printer-features'],
        ],

        'component-ssd' => [
            ['Capacity', 'number', 'GB', [
                ['Up to 128GB', null, 128],
                ['129GB to 256GB', 129, 256],
                ['257GB to 512GB', 257, 512],
                ['513GB to 1TB', 513, 1024],
                ['Over 1TB', 1025, null],
            ], 'ssd-capacity'],
            ['Interface', 'enum', null, ['SATA', 'PCI-E'], 'ssd-interface'],
            ['Form Factor', 'enum', null, ['2.5 inches', 'M.2']],
            ['PCIe/NVMe Generation', 'enum', null, ['Gen3', 'Gen4', 'Gen5']],
            ['Read Speed', 'number', 'MB/s', [
                ['Up to 500 MB/s', null, 500],
                ['501 to 800 MB/s', 501, 800],
                ['801 to 1000 MB/s', 801, 1000],
                ['1001 MB/s and Above', 1001, null],
            ]],
        ],

        'accessories-pen-drive' => [
            ['Connectivity', 'enum', null,
                ['USB 2.0', 'USB 3.0', 'USB 3.1', 'USB 3.2'], 'pen-drive-connectivity'],
            ['Capacity', 'number', 'GB', [
                ['16GB', 16, 16], ['32GB', 32, 32], ['64GB', 64, 64],
                ['128GB', 128, 128], ['256GB', 256, 256], ['512GB', 512, 512],
            ], 'pen-drive-capacity'],
            ['Features', 'flags', null, [
                'OTG Pendrive', 'Metallic', 'Type-C', 'Hook Attache', 'Key Ring Attache',
            ], 'pen-drive-features'],
        ],

        'accessories-memory-card' => [
            ['Type', 'enum', null, ['SD', 'MicroSD', 'CompactFlash'], 'memory-card-type'],
            ['Capacity', 'number', 'GB', [
                ['32GB', 32, 32], ['64GB', 64, 64], ['128GB', 128, 128],
                ['256GB', 256, 256], ['512GB', 512, 512], ['1TB', 1024, 1024],
            ], 'memory-card-capacity'],
        ],

        'accessories-keyboard' => [
            ['Type', 'enum', null, ['Standard', 'Combo', 'Gaming'], 'keyboard-type'],
            ['Switch Type', 'enum', null, [
                'Blue', 'Brown', 'Red', 'Silver', 'Green', 'Yellow', 'Magnetic Switch', 'Membrane',
            ]],
            ['Interface', 'enum', null,
                ['Wired', 'Wireless', 'Bluetooth Wireless', 'Type-C'], 'keyboard-interface'],
            ['Features', 'flags', null, [
                'Mechanical', 'RGB', 'Programmable', 'Backlit', 'Bangla Keyboard', 'Waterproof',
            ], 'keyboard-features'],
        ],

        'accessories-mouse' => [
            ['Type', 'enum', null, ['Standard', 'Gaming', 'RGB', 'Programmable'], 'mouse-type'],
            ['Number of Keys', 'enum', null, ['Up to 3', '4 to 6', '7 to 10', '11 & Above']],
            ['Interface', 'enum', null,
                ['Wired', 'Wireless', 'Bluetooth Wireless', 'Type-C'], 'mouse-interface'],
            ['Max DPI', 'number', 'dpi', [
                ['Up to 3000', null, 3000],
                ['3001 to 8000', 3001, 8000],
                ['8001 to 20000', 8001, 20000],
                ['20001 and Above', 20001, null],
            ]],
        ],

        'accessories-headphone' => [
            ['Connector', 'enum', null, ['3.5mm', 'Type-C', 'Lightning', 'Wireless']],
            ['Features', 'flags', null, [
                'Stereo Bass', 'Gaming', 'Noise Cancelling', 'Splash-Proof',
                'Detachable Microphone', 'Magnetic Design',
            ], 'headphone-features'],
        ],
    ];

    public function run(): void
    {
        $attached = 0;
        $missing = [];

        foreach (self::CATALOGUE as $categorySlug => $definitions) {
            $category = Category::where('slug', $categorySlug)->first();

            /*
             * Shelves are matched by slug, so a catalogue whose tree differs
             * would define the questions and hang them on nothing. Skipping is
             * right — a shop without a Printer shelf should not grow one — but
             * it has to be said out loud, or a seed that did nothing looks
             * exactly like a seed that worked.
             */
            if (! $category) {
                $missing[] = $categorySlug;

                continue;
            }

            $attached++;

            foreach ($definitions as $order => $definition) {
                [$name, $inputType, $unit, $rows] = $definition;

                $attribute = Attribute::updateOrCreate(
                    ['slug' => $definition[4] ?? Str::slug($name)],
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

        $this->command?->newLine();
        $this->command?->info("{$attached} of ".count(self::CATALOGUE).' shelves now ask questions.');

        if ($missing !== []) {
            $this->command?->warn(
                'No category matched these slugs, so their questions were not attached to anything: '
                .implode(', ', $missing).'.'
            );
        }
    }
}
