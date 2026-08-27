<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,

            ContentPageSeeder::class,
        ]);

        // 1. Seed Official Admin User
        $admin = User::updateOrCreate(
            ['email' => 'admin@robinit.com'],
            [
                'name' => 'Robin IT Executive Admin',
                'phone' => '01711000000',
                'password' => Hash::make('password'),
                'avatar' => null,
            ]
        );
        $admin->assignRole(User::ROLE_ADMIN)->save();

        // 2. Seed Default Verified Customer User
        $customer = User::updateOrCreate(
            ['email' => 'customer@robinit.com'],
            [
                'name' => 'Kazi Nayem',
                'phone' => '01722000000',
                'password' => Hash::make('password'),
                'avatar' => null,
            ]
        );
        $customer->assignRole(User::ROLE_CUSTOMER)->save();

        // 3. Seed Sample Orders for Customer & Admin Dashboard
        $pGpu = Product::where('slug', 'asus-rog-strix-rtx-4090-oc')->first();
        $pCpu = Product::where('slug', 'intel-core-i9-14900k')->first();
        $pRam = Product::where('slug', 'corsair-vengeance-rgb-32gb-ddr5-6000mhz')->first();
        $pLaptop = Product::where('slug', 'asus-rog-strix-scar-16-2026')->first();

        // Sample Shipping Address snapshot
        $addressSnapshot = [
            'name' => 'Kazi Nayem',
            'phone' => '01722000000',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'city' => 'Dhanmondi',
            'address' => 'House 42, Road 9/A, Dhanmondi R/A, Dhaka - 1209',
        ];

        // Order 1: Processing Flagship GPU Order
        if ($pGpu && ! Order::where('order_number', 'ROBIN-2026-8849')->exists()) {
            $order1 = Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ROBIN-2026-8849',
                'subtotal' => 245000.00,
                'shipping_fee' => 0.00,
                'total' => 245000.00,
                'status' => 'processing',
                'payment_method' => 'COD',
                'payment_status' => 'pending',
                'shipping_address' => $addressSnapshot,
                'created_at' => now()->subHours(5),
            ]);
            OrderItem::create([
                'order_id' => $order1->id,
                'product_id' => $pGpu->id,
                'product_name' => $pGpu->name,
                'price' => 245000.00,
                'quantity' => 1,
                'total' => 245000.00,
            ]);
        }

        // Order 2: Delivered Flagship CPU Order
        if ($pCpu && ! Order::where('order_number', 'ROBIN-2026-6120')->exists()) {
            $order2 = Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ROBIN-2026-6120',
                'subtotal' => 72500.00,
                'shipping_fee' => 0.00,
                'total' => 72500.00,
                'status' => 'delivered',
                'payment_method' => 'bKash Online',
                'payment_status' => 'paid',
                'shipping_address' => $addressSnapshot,
                'created_at' => now()->subDays(3),
            ]);
            OrderItem::create([
                'order_id' => $order2->id,
                'product_id' => $pCpu->id,
                'product_name' => $pCpu->name,
                'price' => 72500.00,
                'quantity' => 1,
                'total' => 72500.00,
            ]);
        }

        // Order 3: Shipped Laptop Order (for Admin Feed)
        if ($pLaptop && ! Order::where('order_number', 'ROBIN-2026-9921')->exists()) {
            $order3 = Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ROBIN-2026-9921',
                'subtotal' => 285000.00,
                'shipping_fee' => 0.00,
                'total' => 285000.00,
                'status' => 'shipped',
                'payment_method' => 'COD',
                'payment_status' => 'pending',
                'shipping_address' => [
                    'name' => 'Tanvir Ahmed',
                    'phone' => '01833445566',
                    'division' => 'Chattogram',
                    'district' => 'Chattogram',
                    'city' => 'Agrabad',
                    'address' => 'Agrabad Commercial Area, Chattogram',
                ],
                'created_at' => now()->subDays(1),
            ]);
            OrderItem::create([
                'order_id' => $order3->id,
                'product_id' => $pLaptop->id,
                'product_name' => $pLaptop->name,
                'price' => 285000.00,
                'quantity' => 1,
                'total' => 285000.00,
            ]);
        }
    }
}
