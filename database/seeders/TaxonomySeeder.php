<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The shop's category tree, modelled on the one Bangladeshi customers already
 * know from startech.com.bd.
 *
 * Three levels, which the schema, CategoryService and Header.jsx have all
 * supported since the beginning — this is the data to fill them.
 *
 * ## Brands are not categories
 *
 * The site this is modelled on puts brands at the third level: Laptop → Gaming
 * Laptop → MSI / Asus / Lenovo. Copied literally that is about fifteen hundred
 * rows, most of them a brand name repeated under every category that stocks it,
 * and a product's make then lives both in `brand_id` and in its category — two
 * places to disagree.
 *
 * So the third level here is only ever a real subdivision: Accessories → Cable →
 * HDMI Cable, Camera → Camera Accessories → Softbox. Brand belongs to the
 * `brands` table and is a filter, which is what it actually is.
 *
 * ## Additive, never destructive
 *
 * Existing categories are matched by slug and updated in place, so the demo
 * catalogue stays attached to them. Nothing is deleted: a category this file
 * does not mention is left exactly as it is.
 *
 * Note that a category with no products beneath it does not appear in the mega
 * menu at all — CategoryService filters the tree to categories that hold stock,
 * so that navigation never leads to "No products found". Seeding this alone
 * changes nothing visible. See TaxonomyDemoProductsSeeder.
 */
class TaxonomySeeder extends Seeder
{
    /**
     * Roots the shop already has, whose slugs predate this file. Keyed by the
     * name used in TREE so an existing branch is extended rather than a second
     * one created beside it — `desktops` already holds products, `desktop`
     * would hold none.
     */
    private const ROOT_SLUGS = [
        'Desktop' => 'desktops',
        'Laptop' => 'laptops',
        'Component' => 'components',
        'Monitor' => 'monitors',
        'Networking' => 'networking',
        'Server & Storage' => 'server-storage',
        'Accessories' => 'accessories',
    ];

    /**
     * A node is either `'Name' => []` for a leaf, or `'Name' => [...children]`.
     *
     * @var array<string, array<string, mixed>>
     */
    private const TREE = [
        'Desktop' => [
            'Gaming PC' => ['Intel Gaming PC' => [], 'Ryzen Gaming PC' => []],
            'Custom Built PC' => ['Intel PC' => [], 'Ryzen PC' => []],
            'Brand PC' => [],
            'All-in-One PC' => [],
            'Portable Mini PC' => [],
            'Apple Mac' => ['Mac Mini' => [], 'iMac' => [], 'Mac Studio' => [], 'Mac Pro' => []],
            'AI PC' => [],
        ],

        'Laptop' => [
            'Gaming Laptop' => [],
            'Premium Ultrabook' => [],
            'Business Laptop' => [],
            'Laptop Bag' => [],
            'Laptop Accessories' => [
                'Laptop Cooler' => [],
                'Laptop Stand' => [],
                'Laptop Desk' => [],
                'Laptop Battery' => [],
                'Laptop Charger' => [],
                'Laptop Keyboard' => [],
                'HDD Caddy' => [],
            ],
        ],

        'Component' => [
            'Processor' => [],
            'CPU Cooler' => [],
            'Motherboard' => [],
            'Graphics Card' => [],
            'RAM Desktop' => [],
            'RAM Laptop' => [],
            'Power Supply' => [],
            'Hard Disk Drive' => [],
            'Portable Hard Disk Drive' => [],
            'SSD' => [],
            'Portable SSD' => [],
            'Casing' => [],
            'Casing Cooler' => [],
            'Liquid Cooling' => [],
            'Optical Disk Drive' => [],
            'Vertical GPU Holder' => [],
        ],

        'Monitor' => [
            'Gaming Monitor' => [],
            'Curved Monitor' => [],
            'Touch Monitor' => [],
            '4K Monitor' => [],
            'Portable Monitor' => [],
            'Monitor Arm' => [],
        ],

        'Power' => [
            'UPS' => [],
            'Online UPS' => [],
            'Mini UPS' => [],
            'Portable Power Station' => [],
            'IPS' => [],
            'UPS Battery' => [],
            'Voltage Stabilizer' => [],
            'Inverter' => [],
            'Solar Panel' => [],
        ],

        'Phone' => [
            'Smartphone' => [],
            'Feature Phone' => [],
            'Mobile Accessories' => [
                'Charger Adapter' => [],
                'Car Charger' => [],
                'Type-C Cable' => [],
                'Micro USB Cable' => [],
                'Lightning Cable' => [],
                'Holder & Stand' => [],
                'Case & Cover' => [],
                'Mobile Phone Cooler' => [],
            ],
        ],

        'Tablet' => [
            'Android Tablet' => [],
            'iPad' => [],
            'Graphics Tablet' => [],
            'Stylus Pen' => [],
        ],

        'Office Equipment' => [
            'Projector' => ['Projection Screen' => [], 'Projector Mount' => []],
            'Conference System' => [],
            'PA System' => [],
            'Interactive Flat Panel' => [],
            'Video Wall' => [],
            'Signage' => [],
            'Kiosk' => [],
            'Printer' => [],
            'Laser Printer' => [],
            'Large Format Printer' => [],
            'ID Card Printer' => [],
            'POS Printer' => [],
            'Label Printer' => [],
            'Photocopier' => [],
            'Printer Consumables' => [
                'Toner' => [],
                'Cartridge' => [],
                'Ink Bottle' => [],
                'Printer Paper' => [],
                'Ribbon' => [],
                'Printer Drum' => [],
            ],
            'Scanner' => [],
            'Barcode Scanner' => [],
            'Cash Drawer' => [],
            'Telephone Set' => [],
            'IP Phone' => [],
            'PABX System' => [],
            'Money Counting Machine' => [],
            'Paper Shredder' => [],
            'Laminating Machine' => [],
            'Binding Machine' => [],
        ],

        'Camera' => [
            'Action Camera' => [],
            'DSLR Camera' => [],
            'Mirrorless Camera' => [],
            'Digital Camera' => [],
            'Video Camera' => [],
            'Dash Cam' => [],
            'Instant Camera' => [],
            'Body Camera' => [],
            'Camera Lens' => [],
            'Camera Tripod' => [],
            'Gimbal' => [],
            'Camera Accessories' => [
                'Camera Flash' => [],
                'Studio Light' => [],
                'Softbox' => [],
                'Lens Filter' => [],
                'Lens Adapter' => [],
                'Camera Battery & Charger' => [],
                'Camera Bag' => [],
                'Dry Cabinet' => [],
                'Flash Trigger' => [],
            ],
        ],

        'Security' => [
            'Portable WiFi Camera' => [],
            'IP Camera' => [],
            'CC Camera' => [],
            'PTZ Camera' => [],
            'CC Camera Package' => [],
            'IP Camera Package' => [],
            'DVR' => [],
            'NVR' => [],
            'XVR' => [],
            'CC Camera Accessories' => [],
            'Door Lock' => [],
            'Smart Door Bell' => [],
            'Access Control' => ['Access Control Accessories' => []],
            'Entrance Control' => [],
            'Digital Locker & Vault' => [],
            'KVM Switch' => [],
        ],

        'Networking' => [
            'Router' => [],
            'Pocket Router' => [],
            'WiFi Range Extender' => [],
            'Access Point' => [],
            'WiFi Adapter' => [],
            'Network Switch' => [],
            'Firewall' => [],
            'ONU' => [],
            'OLT' => [],
            'Media Converter' => [],
            'Network Transceiver' => [],
            'Networking Cable' => ['UTP Cable' => [], 'Fiber Optic Cable' => []],
            'Patch Cord' => [],
            'Patch Panel' => [],
            'LAN Card' => [],
            'PoE Injector' => [],
            'Crimping Tool' => [],
            'Cable Tester' => [],
        ],

        'Software' => [
            'Operating System' => [],
            'Office Application' => [],
            'Antivirus' => ['Antivirus for Home' => [], 'Antivirus for Business' => []],
            'Database Server Solution' => [],
            'Mail Server Solution' => [],
            'Cloud Solution' => [],
            'Bangla Typing Software' => [],
            'Design Software' => [],
            'Virtualization' => [],
        ],

        'Server & Storage' => [
            'Server' => [],
            'GPU Server' => [],
            'Server Rack' => [],
            'Workstation' => [],
            'NAS Storage' => [],
            'SAN Storage' => [],
            'DAS Storage' => [],
            'Server HDD' => [],
            'Server SSD' => [],
            'Server RAM' => [],
            'Server Power Supply' => [],
        ],

        'Accessories' => [
            'Keyboard' => [],
            'Mouse' => [],
            'Headphone' => [],
            'Bluetooth Headphone' => [],
            'Mouse Pad' => [],
            'Wrist Rest' => [],
            'Headphone Stand' => [],
            'Speaker & Home Theater' => [],
            'Bluetooth Speaker' => [],
            'Soundbar' => [],
            'Webcam' => [],
            'Microphone' => [],
            'Cable' => [
                'USB Cable' => [],
                'HDMI Cable' => [],
                'DisplayPort Cable' => [],
                'VGA Cable' => [],
                'Audio Cable' => [],
                'Printer Cable' => [],
                'Cable Organizer' => [],
            ],
            'Converter' => [
                'USB Converter' => [],
                'HDMI Converter' => [],
                'VGA Converter' => [],
                'DisplayPort Converter' => [],
                'Type-C Converter' => [],
                'Audio Converter' => [],
            ],
            'Card Reader' => [],
            'Hubs & Docks' => [],
            'Memory Card' => [],
            'Pen Drive' => [],
            'Capture Card' => [],
            'HDD-SSD Enclosure' => [],
            'Digital Voice Recorder' => [],
            'Presenter' => [],
            'Thermal Paste' => [],
            'Power Strip' => [],
            'Bluetooth Adapter' => [],
            'Monitor Light Bar' => [],
        ],

        'Gadget' => [
            'Smart Watch' => [],
            'Smart Band' => [],
            'Analog Watch' => [],
            'Earphone' => [],
            'Earbuds' => [],
            'Neckband' => [],
            'Power Bank' => [],
            'Trimmer' => [],
            'Smart Ring' => [],
            'Smart Glasses' => [],
            'Mini Fan' => [],
            'Health Monitor' => [],
            'TV Box' => [],
            'Drone' => [],
            'Daily Lifestyle' => [
                'Weight Scale' => [],
                'Hair Dryer' => [],
                'Hair Straightener' => [],
                'Electric Toothbrush' => [],
                'GPS Tracker' => [],
                'Torch Light' => [],
                'Table Lamp' => [],
                'Massage Gun' => [],
            ],
            'Studio Equipment' => [
                'Studio Microphone' => [],
                'Studio Monitor' => [],
                'Studio Headphone' => [],
                'Audio Interface' => [],
                'Video Switcher' => [],
            ],
        ],
    ];

    private int $created = 0;

    private int $matched = 0;

    public function run(): void
    {
        foreach (self::TREE as $name => $children) {
            $this->plant($name, $children, null, self::ROOT_SLUGS[$name] ?? null);
        }

        $this->command?->info(
            "Taxonomy seeded: {$this->created} categories created, {$this->matched} already existed."
        );

        // The cached menu is dropped by CategoryService::flush(), which
        // AppServiceProvider wires to Category's model events precisely so
        // seeders are covered without having to remember this.
    }

    /**
     * @param  array<string, mixed>  $children
     */
    private function plant(string $name, array $children, ?Category $parent, ?string $forcedSlug = null): void
    {
        $slug = $forcedSlug ?? $this->slugFor($name, $parent);

        $existing = Category::where('slug', $slug)->first();

        $category = Category::updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'parent_id' => $parent?->id, 'is_active' => true],
        );

        $existing ? $this->matched++ : $this->created++;

        foreach ($children as $childName => $grandChildren) {
            $this->plant($childName, $grandChildren, $category);
        }
    }

    /**
     * Slugs are unique across the whole table, but names are not: "Type-C
     * Cable" is a real subdivision of both Mobile Accessories and Cable, and
     * "Car Charger" of both Mobile Accessories and Gadget.
     *
     * Where the plain slug is already taken by a node under a different parent,
     * the parent's slug goes in front — `mobile-accessories-type-c-cable`. On a
     * second run the node owns the slug already, so the same name resolves to
     * the same slug and nothing is duplicated.
     */
    private function slugFor(string $name, ?Category $parent): string
    {
        $base = Str::slug($name);

        $owner = Category::where('slug', $base)->first();

        if (! $owner || $owner->parent_id === $parent?->id) {
            return $base;
        }

        return $parent ? "{$parent->slug}-{$base}" : $base;
    }
}
