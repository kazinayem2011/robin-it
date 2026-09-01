<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The questions the Router shelf asks.
 *
 * Modelled on what StarTech's TP-Link router page actually offers, read off
 * the rendered page rather than guessed: Type, Wi-Fi Standard, WiFi Speed,
 * Number of Bands, Number of LAN Ports, Additional Features. Price and
 * availability are already facets here and are not attributes.
 *
 * Safe to run more than once: everything is matched on its slug and updated in
 * place, so re-running after adding a value does not duplicate the rest.
 *
 *   php artisan db:seed --class=RouterAttributeSeeder
 */
class RouterAttributeSeeder extends Seeder
{
    /**
     * The bands are curated rather than computed from whatever happens to be
     * in stock. "301 Mbps to 750 Mbps" is a judgement about how shoppers think
     * about speed; a quartile of the current catalogue is an accident of it,
     * and would move every time a product was added.
     */
    private const DEFINITIONS = [
        [
            'name' => 'Type',
            'input_type' => Attribute::ENUM,
            'values' => [
                'Standard Router', 'Gaming Router', 'Mesh Router', 'SIM Router',
                'Pocket Router', 'Load Balancer Router', 'Outdoor Router', 'Core Router',
            ],
        ],
        [
            'name' => 'Wi-Fi Standard',
            'input_type' => Attribute::ENUM,
            'values' => ['Wi-Fi 7', 'Wi-Fi 6E', 'Wi-Fi 6', 'Wi-Fi 5', 'Wi-Fi 4'],
        ],
        [
            'name' => 'WiFi Speed',
            'input_type' => Attribute::NUMBER,
            'unit' => 'Mbps',
            'bands' => [
                ['Up to 300 Mbps', null, 300],
                ['301 Mbps to 750 Mbps', 301, 750],
                ['751 Mbps to 1200 Mbps', 751, 1200],
                ['1201 Mbps to 1800 Mbps', 1201, 1800],
                ['1801 Mbps and Above', 1801, null],
            ],
        ],
        [
            'name' => 'Number of Bands',
            'input_type' => Attribute::ENUM,
            'values' => ['Single Band', 'Dual Band', 'Tri-Band', 'Quad-Band'],
        ],
        [
            'name' => 'Number of LAN Ports',
            'input_type' => Attribute::ENUM,
            'values' => ['1', '2', '3', '4', '5 and Above'],
        ],
        [
            'name' => 'Additional Features',
            'input_type' => Attribute::FLAGS,
            'values' => [
                'USB Port', 'Repeater Mode', 'Access Point Mode', 'Parental Controls',
                'Mesh Support', 'Dedicated Backhaul', 'VPN Support', 'Guest Network',
                'Beamforming', 'MU-MIMO', 'QoS', 'IPv6 Support',
                'Removable Antenna', 'PoE Support', 'Cloud Management',
            ],
        ],
    ];

    public function run(): void
    {
        $category = Category::where('slug', 'networking-router')->first();

        if (! $category) {
            $this->command?->warn('No "networking-router" category; nothing to attach the questions to.');

            return;
        }

        foreach (self::DEFINITIONS as $order => $definition) {
            $attribute = Attribute::updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'unit' => $definition['unit'] ?? null,
                    'input_type' => $definition['input_type'],
                    'sort_order' => $order,
                ]
            );

            $rows = $definition['bands']
                ?? array_map(fn ($label) => [$label, null, null], $definition['values']);

            foreach ($rows as $index => [$label, $from, $to]) {
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

            // Declared once, on the aisle. Router > TP-Link inherits it.
            $attribute->categories()->syncWithoutDetaching([
                $category->id => ['sort_order' => $order],
            ]);
        }

        $this->command?->info(
            count(self::DEFINITIONS).' router questions defined against '.$category->name.'.'
        );
    }
}
