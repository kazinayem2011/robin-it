<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SiteSetting;
use App\Models\Store;
use Illuminate\Database\Seeder;

class DynamicContentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. SEED BANNERS
        Banner::truncate();

        // Hero Slides (1920x600 / 16:9 Aspect Ratio)
        Banner::create([
            'title' => 'ASUS ROG STRIX SCAR 18',
            'subtitle' => 'Intel Core Ultra 9 + RTX 4090 24GB • 240Hz Nebula HDR OLED Display',
            'badge' => 'FLASH DEAL — SAVE ৳25,000',
            'image_path' => '/images/hero_banner_rog.jpg',
            'link_url' => '/shop/laptops',
            'button_text' => 'Shop ROG Strix',
            'position' => 'hero',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Aurora X9: Custom Watercooled Rig',
            'subtitle' => 'AMD Ryzen 7 7800X3D + RTX 4090 + 64GB DDR5 RGB • Dual Loop Crystal Glass',
            'badge' => 'SIGNATURE BUILD',
            'image_path' => '/images/hero_banner_beast_pc.jpg',
            'link_url' => '/pc-builder',
            'button_text' => 'Configure Rig',
            'position' => 'hero',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'UltraWide OLED & Pro Esports Gear',
            'subtitle' => 'Curved OLED Displays, Mechanical Keyboards & Low-Latency Wireless Audio',
            'badge' => 'NEW ARRIVAL',
            'image_path' => '/images/hero_banner_gear.jpg',
            'link_url' => '/shop/accessories',
            'button_text' => 'Explore Gear',
            'position' => 'hero',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        // Side Promo Cards (600x400 / 4:3 Aspect Ratio)
        Banner::create([
            'title' => 'Build Your Dream PC',
            'subtitle' => 'Instant Compatibility Checker & Free Express Assembly',
            'badge' => 'CUSTOM RIG',
            'image_path' => '/images/promo_banner_pc_builder.jpg',
            'link_url' => '/pc-builder',
            'button_text' => 'Build Now',
            'position' => 'promo_side',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Ultimate PC Upgrade Bundle',
            'subtitle' => 'Samsung 990 PRO NVMe + Corsair Dominator DDR5 + 360mm AIO Cooler',
            'badge' => 'SAVE 35%',
            'image_path' => '/images/promo_banner_special_deals.jpg',
            'link_url' => '/shop/components',
            'button_text' => 'Shop Bundles',
            'position' => 'promo_side',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Official Warranty Claim',
            'subtitle' => 'Doorstep Pickup & Rapid 48H Diagnostic Turnaround',
            'badge' => 'CUSTOMER FIRST',
            'image_path' => '/images/promo_banner_warranty.jpg',
            'link_url' => '/support',
            'button_text' => 'Get Service',
            'position' => 'promo_side',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        // 2. SEED COUPONS
        Coupon::truncate();

        Coupon::create([
            'code' => 'WELCOME10',
            'description' => '10% discount on your first order (Up to ৳2,000)',
            'discount_type' => 'percent',
            'discount_value' => 10.00,
            'min_spend' => 5000.00,
            'max_discount' => 2000.00,
            'usage_limit' => 500,
            'expires_at' => now()->addMonths(6),
            'is_active' => true,
        ]);

        Coupon::create([
            'code' => 'ROBIN500',
            'description' => 'Flat ৳500 off on tech purchases over ৳15,000',
            'discount_type' => 'fixed',
            'discount_value' => 500.00,
            'min_spend' => 15000.00,
            'usage_limit' => 1000,
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        Coupon::create([
            'code' => 'GAMINGRIG',
            'description' => '৳3,000 instant discount on custom PCs and rigs over ৳100,000',
            'discount_type' => 'fixed',
            'discount_value' => 3000.00,
            'min_spend' => 100000.00,
            'usage_limit' => 200,
            'expires_at' => now()->addMonths(3),
            'is_active' => true,
        ]);

        // 3. SEED SHOWROOM STORES
        Store::truncate();

        Store::create([
            'name' => 'IDB Bhaban Flagship Showroom',
            'branch_type' => 'Flagship Showroom',
            'city' => 'Dhaka',
            'address' => 'Shop #SR-402, 4th Floor, BCS Computer City, IDB Bhaban, Agargaon, Dhaka',
            'phone' => '+880 1700-112233',
            'email' => 'idb@robin-it.com',
            'opening_hours' => '10:00 AM – 08:00 PM (Weekly Closed: Sunday)',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Store::create([
            'name' => 'Multiplan Center Mega Branch',
            'branch_type' => 'Showroom & Service Center',
            'city' => 'Dhaka',
            'address' => 'Level 9, Shop #901-904, Multiplan Center, New Elephant Road, Dhaka-1205',
            'phone' => '+880 1700-112244',
            'email' => 'multiplan@robin-it.com',
            'opening_hours' => '10:00 AM – 08:00 PM (Weekly Closed: Tuesday)',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Store::create([
            'name' => 'Uttara Sector 3 Showroom',
            'branch_type' => 'Showroom',
            'city' => 'Dhaka',
            'address' => 'House #14, Road #2, Sector 3, Rabindra Sarani, Uttara, Dhaka-1230',
            'phone' => '+880 1700-112255',
            'email' => 'uttara@robin-it.com',
            'opening_hours' => '10:30 AM – 08:30 PM (Weekly Closed: Wednesday)',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        Store::create([
            'name' => 'Chattogram Agrabad Regional Hub',
            'branch_type' => 'Regional Showroom',
            'city' => 'Chattogram',
            'address' => 'World Trade Center Ground Floor, 102 Agrabad Commercial Area, Chattogram',
            'phone' => '+880 1700-112266',
            'email' => 'ctg@robin-it.com',
            'opening_hours' => '10:00 AM – 08:00 PM (Weekly Closed: Friday)',
            'sort_order' => 4,
            'is_active' => true,
        ]);

        // 4. SEED SITE SETTINGS
        SiteSetting::truncate();

        SiteSetting::set(
            'announcement_ticker',
            '⚡ Flash Deals Live: Save up to 40% OFF on Gaming Laptops & Graphics Cards! Free 64-District Express Delivery on orders over ৳50,000.',
            'announcement',
            'Top Header Announcement'
        );

        SiteSetting::set('announcement_badge', 'LIVE OFFER', 'announcement', 'Announcement Badge Text');
        SiteSetting::set('hotline_number', '16789', 'contact', 'Customer Hotline');
        SiteSetting::set('hotline_hours', '9:00 AM – 9:00 PM (Everyday)', 'contact', 'Hotline Operating Hours');
        SiteSetting::set('free_shipping_threshold', '50000', 'general', 'Free Shipping Threshold (BDT)');
        SiteSetting::set('delivery_charge_dhaka', '70', 'general', 'Inside Dhaka Delivery Charge');
        SiteSetting::set('delivery_charge_outside', '130', 'general', 'Outside Dhaka Delivery Charge');

        // 5. SEED SPOTLIGHT BANNERS ON TOP CATEGORIES
        $laptopCat = Category::where('slug', 'laptops')->first();
        if ($laptopCat) {
            $laptopCat->update([
                'spotlight_title' => 'Laptops Collection',
                'spotlight_subtitle' => 'Official 100% Genuine Tech with Warranty',
                'spotlight_image' => '/images/product_laptop_rog.jpg',
                'spotlight_link' => '/shop/laptops',
            ]);
        }

        $compCat = Category::where('slug', 'components')->first();
        if ($compCat) {
            $compCat->update([
                'spotlight_title' => 'Flagship Hardware',
                'spotlight_subtitle' => 'Unlocked CPUs, RTX 40 GPUs & DDR5 RAM',
                'spotlight_image' => '/images/product_gpu_rtx4090.jpg',
                'spotlight_link' => '/shop/components',
            ]);
        }

        $desktopCat = Category::where('slug', 'desktops')->first();
        if ($desktopCat) {
            $desktopCat->update([
                'spotlight_title' => 'Custom Gaming Rigs',
                'spotlight_subtitle' => 'Built, Tested & Benchmarked by Robin IT Engineers',
                'spotlight_image' => '/images/product_cpu_i9.jpg',
                'spotlight_link' => '/pc-builder',
            ]);
        }

        // 6. SEED PRODUCT REVIEWS
        ProductReview::truncate();

        $products = Product::take(10)->get();
        $sampleReviews = [
            [
                'rating' => 5,
                'author_name' => 'Tanvir Ahmed',
                'title' => 'Absolute Monster Performance!',
                'comment' => 'Bought this for my 4K video editing and Unreal Engine 5 development workstation. Outstanding thermals, pristine packaging, and received delivery within 24 hours in Dhaka.',
            ],
            [
                'rating' => 5,
                'author_name' => 'Mehedi Hasan',
                'title' => '100% Original with Authentic Brand Warranty',
                'comment' => 'Verified the serial number on the manufacturer official portal immediately upon unboxing. Authentic product from Robin IT. Highly satisfied!',
            ],
            [
                'rating' => 4,
                'author_name' => 'Sabbir Rahman',
                'title' => 'Great value for money & fast shipping',
                'comment' => 'Runs super smooth at ultra settings. Customer service answered all my PC compatibility questions before purchase.',
            ],
        ];

        foreach ($products as $p) {
            foreach ($sampleReviews as $rev) {
                ProductReview::create([
                    'product_id' => $p->id,
                    'author_name' => $rev['author_name'],
                    'author_email' => strtolower(str_replace(' ', '', $rev['author_name'])).'@gmail.com',
                    'rating' => $rev['rating'],
                    'title' => $rev['title'],
                    'comment' => $rev['comment'],
                    'is_verified_buyer' => true,
                    'is_approved' => true,
                ]);
            }
        }

        // 7. SEED DYNAMIC BLOG POSTS / TECH JOURNAL
        BlogPost::truncate();

        BlogPost::create([
            'title' => 'Intel Core Ultra 200S vs AMD Ryzen 9000: Desktop Gaming Showdown',
            'slug' => 'intel-core-ultra-200s-vs-amd-ryzen-9000-showdown',
            'category' => 'HARDWARE BENCHMARKS',
            'excerpt' => 'Comprehensive gaming benchmarks, thermals, power efficiency, and IPC comparisons across 20 AAA titles on Arrow Lake and Zen 5 architecture.',
            'content' => 'In-depth review and benchmark results for desktop enthusiasts building high-performance workstations and competitive gaming rigs.',
            'image_path' => '/images/hero_banner_beast_pc.jpg',
            'link_url' => '/shop/components',
            'author_name' => 'Robin IT Hardware Lab',
            'read_time' => '5 min read',
            'is_published' => true,
            'published_at' => now()->subDays(1),
        ]);

        BlogPost::create([
            'title' => 'Top 5 Flagship Gaming Laptops with OLED Displays in Bangladesh',
            'slug' => 'top-5-gaming-laptops-with-oled-displays-bangladesh',
            'category' => 'BUYING GUIDE',
            'excerpt' => 'Comparing ASUS ROG Strix SCAR 18, Lenovo Legion Pro 7i, and MSI Raider GE78 with 240Hz Nebula HDR OLED screens and RTX 4090 mobility.',
            'content' => 'Complete buyer guide highlighting thermal performance, battery life, color accuracy, and official brand replacement warranties in BD.',
            'image_path' => '/images/hero_banner_rog.jpg',
            'link_url' => '/shop/laptops',
            'author_name' => 'System Specialist Team',
            'read_time' => '4 min read',
            'is_published' => true,
            'published_at' => now()->subDays(2),
        ]);

        BlogPost::create([
            'title' => 'Ultrawide Curved OLED vs 4K Fast IPS: Ultimate Esports Guide',
            'slug' => 'ultrawide-curved-oled-vs-4k-fast-ips-esports-guide',
            'category' => 'DISPLAYS & PERIPHERALS',
            'excerpt' => 'An in-depth breakdown of 0.03ms pixel response times, HDR1000 peak luminance, 240Hz refresh rate, and burn-in prevention algorithms.',
            'content' => 'Detailed side-by-side analysis for content creators and competitive FPS esports gamers looking for zero ghosting and true blacks.',
            'image_path' => '/images/hero_banner_gear.jpg',
            'link_url' => '/shop/monitors',
            'author_name' => 'Peripherals Lab',
            'read_time' => '6 min read',
            'is_published' => true,
            'published_at' => now()->subDays(3),
        ]);

        BlogPost::create([
            'title' => 'Custom Hardline Watercooling & Clean PC Cable Routing Blueprint',
            'slug' => 'custom-hardline-watercooling-clean-cable-routing-blueprint',
            'category' => 'PC BUILDING',
            'excerpt' => 'Professional technician guide to tube bending, dual-radiator sizing, airflow pressure optimization, and custom braided wiring.',
            'content' => 'Step-by-step assembly tips from Robin IT certified rig architects for achieving whisper-quiet thermals.',
            'image_path' => '/images/promo_banner_pc_builder.jpg',
            'link_url' => '/pc-builder',
            'author_name' => 'Master PC Architect',
            'read_time' => '7 min read',
            'is_published' => true,
            'published_at' => now()->subDays(4),
        ]);

        BlogPost::create([
            'title' => 'PCIe Gen5 NVMe SSDs & DDR5 7200MHz: Essential Upgrade Guide',
            'slug' => 'pcie-gen5-nvme-ssds-and-ddr5-speed-upgrade-guide',
            'category' => 'STORAGE & MEMORY',
            'excerpt' => 'Evaluating 14,000 MB/s sequential transfer speeds, DirectStorage game loading times, and DDR5 EXPO/XMP stability profiles.',
            'content' => 'Benchmark comparison showing real-world application launch speeds and content render times.',
            'image_path' => '/images/promo_banner_special_deals.jpg',
            'link_url' => '/shop/components',
            'author_name' => 'Robin IT Hardware Lab',
            'read_time' => '5 min read',
            'is_published' => true,
            'published_at' => now()->subDays(5),
        ]);
    }
}
