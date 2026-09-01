<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\Supplier;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class CatalogSeeder extends Seeder
{
    /**
     * Run the comprehensive catalog database seeds.
     */
    public function run(): void
    {
        // Clear existing tables
        // Schema:: rather than raw SQL — `SET FOREIGN_KEY_CHECKS` is MySQL-only
        // and made this seeder unrunnable on SQLite, so no test could use the
        // real catalogue as a fixture.
        Schema::disableForeignKeyConstraints();
        ProductSpecification::truncate();
        ProductImage::truncate();
        Product::truncate();
        Category::truncate();
        Brand::truncate();
        Schema::enableForeignKeyConstraints();

        // ─────────────────────────────────────────────────────────────────
        // 1. BRANDS
        // ─────────────────────────────────────────────────────────────────
        $b = [];
        foreach ([
            'intel' => 'Intel',
            'amd' => 'AMD',
            'nvidia' => 'NVIDIA',
            'asus' => 'ASUS',
            'msi' => 'MSI',
            'gigabyte' => 'Gigabyte',
            'corsair' => 'Corsair',
            'samsung' => 'Samsung',
            'kingston' => 'Kingston',
            'gskill' => 'G.Skill',
            'crucial' => 'Crucial',
            'western' => 'Western Digital',
            'seagate' => 'Seagate',
            'razer' => 'Razer',
            'logitech' => 'Logitech',
            'apple' => 'Apple',
            'dell' => 'Dell',
            'hp' => 'HP',
            'lenovo' => 'Lenovo',
            'lg' => 'LG',
            'asus_net' => 'ASUS (Network)',
            'tp_link' => 'TP-Link',
            'cooler' => 'Cooler Master',
            'nzxt' => 'NZXT',
            'thermalt' => 'Thermaltake',
            'robinit' => 'Robin IT Signature',
        ] as $key => $name) {
            $b[$key] = Brand::create(['name' => $name, 'slug' => str_replace('_', '-', $key)]);
        }

        // ─────────────────────────────────────────────────────────────────
        // 2. CATEGORY HIERARCHY (3-LEVEL)
        // ─────────────────────────────────────────────────────────────────

        // ── Root 1: Components ───────────────────────────────────────────
        $components = Category::create(['name' => 'Components', 'slug' => 'components', 'icon' => 'Cpu', 'badge' => 'HOT']);

        // L2: Processor / CPU
        $cpuCat = Category::create(['name' => 'Processor / CPU', 'slug' => 'cpu', 'parent_id' => $components->id, 'icon' => 'Cpu']);
        $catIntelUltra = Category::create(['name' => 'Intel Core Ultra 200 (Arrow Lake)', 'slug' => 'intel-core-ultra-200', 'parent_id' => $cpuCat->id]);
        $catIntelI9 = Category::create(['name' => 'Intel Core i9-14th Gen (Raptor Lake)', 'slug' => 'intel-core-i9-14th', 'parent_id' => $cpuCat->id]);
        $catIntelI7 = Category::create(['name' => 'Intel Core i7-14th Gen', 'slug' => 'intel-core-i7-14th', 'parent_id' => $cpuCat->id]);
        $catIntelI5 = Category::create(['name' => 'Intel Core i5-14th Gen (Budget King)', 'slug' => 'intel-core-i5-14th', 'parent_id' => $cpuCat->id]);
        $catAmdRyzen9 = Category::create(['name' => 'AMD Ryzen 9 9000 / 7000 Series', 'slug' => 'amd-ryzen-9', 'parent_id' => $cpuCat->id]);
        $catAmdRyzen7 = Category::create(['name' => 'AMD Ryzen 7 7800X3D (Gaming King)', 'slug' => 'amd-ryzen-7-x3d', 'parent_id' => $cpuCat->id]);
        $catAmdRyzen5 = Category::create(['name' => 'AMD Ryzen 5 7600X / 9600X', 'slug' => 'amd-ryzen-5', 'parent_id' => $cpuCat->id]);

        // L2: Graphics Card (GPU)
        $gpuCat = Category::create(['name' => 'Graphics Card (GPU)', 'slug' => 'graphics-card', 'parent_id' => $components->id, 'icon' => 'Monitor']);
        $catRtx5090 = Category::create(['name' => 'NVIDIA GeForce RTX 5090 (Blackwell)', 'slug' => 'rtx-5090', 'parent_id' => $gpuCat->id]);
        $catRtx4090 = Category::create(['name' => 'NVIDIA GeForce RTX 4090 24GB', 'slug' => 'rtx-4090', 'parent_id' => $gpuCat->id]);
        $catRtx4080S = Category::create(['name' => 'NVIDIA GeForce RTX 4080 Super 16GB', 'slug' => 'rtx-4080-super', 'parent_id' => $gpuCat->id]);
        $catRtx4070Ti = Category::create(['name' => 'NVIDIA GeForce RTX 4070 Ti Super', 'slug' => 'rtx-4070-ti-super', 'parent_id' => $gpuCat->id]);
        $catRtx4070S = Category::create(['name' => 'NVIDIA GeForce RTX 4070 Super 12GB', 'slug' => 'rtx-4070-super', 'parent_id' => $gpuCat->id]);
        $catRtx4060 = Category::create(['name' => 'NVIDIA GeForce RTX 4060 / 4060 Ti', 'slug' => 'rtx-4060-series', 'parent_id' => $gpuCat->id]);
        $catRx9070 = Category::create(['name' => 'AMD Radeon RX 9070 XT (RDNA 4)', 'slug' => 'rx-9070-xt', 'parent_id' => $gpuCat->id]);
        $catRx7900 = Category::create(['name' => 'AMD Radeon RX 7900 XTX / XT', 'slug' => 'rx-7900-series', 'parent_id' => $gpuCat->id]);

        // L2: Motherboard
        $moboCat = Category::create(['name' => 'Motherboard', 'slug' => 'motherboard', 'parent_id' => $components->id, 'icon' => 'CircuitBoard']);
        $catZ890 = Category::create(['name' => 'Intel Z890 (Arrow Lake — LGA1851)', 'slug' => 'intel-z890', 'parent_id' => $moboCat->id]);
        $catZ790 = Category::create(['name' => 'Intel Z790 (Raptor Lake — LGA1700)', 'slug' => 'intel-z790', 'parent_id' => $moboCat->id]);
        $catB760 = Category::create(['name' => 'Intel B760 (Budget / Mid-range)', 'slug' => 'intel-b760', 'parent_id' => $moboCat->id]);
        $catX870E = Category::create(['name' => 'AMD X870E / X670E (AM5 Flagship)', 'slug' => 'amd-x870e', 'parent_id' => $moboCat->id]);
        $catB650 = Category::create(['name' => 'AMD B650 / B650E (AM5 Mid-range)', 'slug' => 'amd-b650', 'parent_id' => $moboCat->id]);

        // L2: RAM (Memory)
        $ramCat = Category::create(['name' => 'RAM (Memory)', 'slug' => 'ram', 'parent_id' => $components->id, 'icon' => 'MemoryStick']);
        $catDdr5High = Category::create(['name' => 'DDR5 7200MHz+ (Extreme OC)', 'slug' => 'ddr5-7200-oc', 'parent_id' => $ramCat->id]);
        $catDdr5 = Category::create(['name' => 'DDR5 6000–6400MHz (Sweet Spot)', 'slug' => 'ddr5-6000', 'parent_id' => $ramCat->id]);
        $catDdr4 = Category::create(['name' => 'DDR4 3200–3600MHz (Value Pick)', 'slug' => 'ddr4-3200', 'parent_id' => $ramCat->id]);

        // L2: Storage
        $storageCat = Category::create(['name' => 'Storage (SSD & HDD)', 'slug' => 'storage', 'parent_id' => $components->id, 'icon' => 'HardDrive']);
        $catGen5Ssd = Category::create(['name' => 'PCIe Gen5 NVMe M.2 SSD (14,000 MB/s)', 'slug' => 'gen5-nvme-ssd', 'parent_id' => $storageCat->id]);
        $catGen4Ssd = Category::create(['name' => 'PCIe Gen4 NVMe M.2 SSD (7,400 MB/s)', 'slug' => 'gen4-nvme-ssd', 'parent_id' => $storageCat->id]);
        $catSata = Category::create(['name' => '2.5" SATA SSD (Budget Storage)', 'slug' => 'sata-ssd', 'parent_id' => $storageCat->id]);
        $catHdd = Category::create(['name' => '3.5" Desktop HDD (4TB–20TB)', 'slug' => 'desktop-hdd', 'parent_id' => $storageCat->id]);

        // L2: Power Supply (PSU)
        $psuCat = Category::create(['name' => 'Power Supply (PSU)', 'slug' => 'power-supply', 'parent_id' => $components->id, 'icon' => 'Zap']);
        $catPsuPlatinum = Category::create(['name' => '80+ Platinum / Titanium Modular', 'slug' => 'platinum-psu', 'parent_id' => $psuCat->id]);
        $catPsuGold = Category::create(['name' => '80+ Gold Fully Modular ATX 3.0', 'slug' => 'gold-psu', 'parent_id' => $psuCat->id]);
        $catPsuBronze = Category::create(['name' => '80+ Bronze Semi-Modular (Budget)', 'slug' => 'bronze-psu', 'parent_id' => $psuCat->id]);

        // L2: CPU Cooler
        $coolerCat = Category::create(['name' => 'CPU Cooler', 'slug' => 'cpu-cooler', 'parent_id' => $components->id, 'icon' => 'Wind']);
        $catAio360 = Category::create(['name' => '360mm AIO Liquid Cooler', 'slug' => '360mm-aio', 'parent_id' => $coolerCat->id]);
        $catAio240 = Category::create(['name' => '240mm AIO Liquid Cooler', 'slug' => '240mm-aio', 'parent_id' => $coolerCat->id]);
        $catAirCooler = Category::create(['name' => 'Tower Air Cooler (NH-D15 / D15S)', 'slug' => 'air-cooler', 'parent_id' => $coolerCat->id]);

        // L2: PC Case
        $caseCat = Category::create(['name' => 'PC Case / Chassis', 'slug' => 'pc-case', 'parent_id' => $components->id, 'icon' => 'Box']);
        $catCaseMidAtx = Category::create(['name' => 'Mid-Tower ATX (Airflow Optimised)', 'slug' => 'mid-tower-atx', 'parent_id' => $caseCat->id]);
        $catCaseItx = Category::create(['name' => 'Mini-ITX Compact Cases', 'slug' => 'mini-itx-case', 'parent_id' => $caseCat->id]);

        // ── Root 2: Laptops ──────────────────────────────────────────────
        $laptops = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'icon' => 'Laptop', 'badge' => 'POPULAR']);

        $gamingLaptops = Category::create(['name' => 'Gaming Laptops', 'slug' => 'gaming-laptops', 'parent_id' => $laptops->id, 'icon' => 'Laptop']);
        $catRogScar = Category::create(['name' => 'ASUS ROG Strix SCAR / G16', 'slug' => 'asus-rog-scar', 'parent_id' => $gamingLaptops->id]);
        $catRogZeph = Category::create(['name' => 'ASUS ROG Zephyrus G14 / G16', 'slug' => 'asus-rog-zephyrus', 'parent_id' => $gamingLaptops->id]);
        $catLegion = Category::create(['name' => 'Lenovo Legion Pro 7 / 5i Gen 9', 'slug' => 'lenovo-legion-pro', 'parent_id' => $gamingLaptops->id]);
        $catRazerBlade = Category::create(['name' => 'Razer Blade 14 / 16 (RTX 40)', 'slug' => 'razer-blade', 'parent_id' => $gamingLaptops->id]);
        $catMsiRaider = Category::create(['name' => 'MSI Raider / Titan GT77', 'slug' => 'msi-raider', 'parent_id' => $gamingLaptops->id]);

        $ultrabooks = Category::create(['name' => 'Premium Ultrabooks', 'slug' => 'ultrabooks', 'parent_id' => $laptops->id, 'icon' => 'Laptop']);
        $catMacbookPro = Category::create(['name' => 'Apple MacBook Pro M3 / M4', 'slug' => 'macbook-pro-m3', 'parent_id' => $ultrabooks->id]);
        $catMacbookAir = Category::create(['name' => 'Apple MacBook Air M3 / M4', 'slug' => 'macbook-air-m3', 'parent_id' => $ultrabooks->id]);
        $catDellXps = Category::create(['name' => 'Dell XPS 13 / 15 / 16 OLED', 'slug' => 'dell-xps-series', 'parent_id' => $ultrabooks->id]);
        $catLgGram = Category::create(['name' => 'LG Gram 16 / 17 (Lightest)', 'slug' => 'lg-gram', 'parent_id' => $ultrabooks->id]);

        $businessLaptops = Category::create(['name' => 'Business Laptops', 'slug' => 'business-laptops', 'parent_id' => $laptops->id, 'icon' => 'Laptop']);
        $catThinkpad = Category::create(['name' => 'Lenovo ThinkPad X1 Carbon Gen 12', 'slug' => 'thinkpad-x1-carbon', 'parent_id' => $businessLaptops->id]);
        $catHpElitebook = Category::create(['name' => 'HP EliteBook 840 / 860 G11', 'slug' => 'hp-elitebook-g11', 'parent_id' => $businessLaptops->id]);
        $catDellLatitude = Category::create(['name' => 'Dell Latitude 7450 Ultralight', 'slug' => 'dell-latitude-7450', 'parent_id' => $businessLaptops->id]);

        // ── Root 3: Desktop PC ───────────────────────────────────────────
        $desktops = Category::create(['name' => 'Desktop PC', 'slug' => 'desktops', 'icon' => 'Monitor']);

        $gamingPc = Category::create(['name' => 'Custom Gaming Rigs', 'slug' => 'gaming-pc', 'parent_id' => $desktops->id]);
        $catEsports = Category::create(['name' => 'Robin IT Esports Arena PC (1080p Max)', 'slug' => 'esports-pc', 'parent_id' => $gamingPc->id]);
        $cat1440p = Category::create(['name' => 'Robin IT QHD 1440p Powerhouse', 'slug' => '1440p-rig', 'parent_id' => $gamingPc->id]);
        $cat4kPc = Category::create(['name' => 'Robin IT 4K Ultra Beast (RTX 4090)', 'slug' => '4k-ultra-rig', 'parent_id' => $gamingPc->id]);

        $workstationPc = Category::create(['name' => 'Creator & Workstations', 'slug' => 'workstation-pc', 'parent_id' => $desktops->id]);
        $catRenderPc = Category::create(['name' => 'Robin IT 3D Render Workstation', 'slug' => '3d-render-workstation', 'parent_id' => $workstationPc->id]);
        $catVideoEdit = Category::create(['name' => 'Video Editing Studio PC (4K)', 'slug' => 'video-edit-pc', 'parent_id' => $workstationPc->id]);

        // ── Root 4: Monitors ─────────────────────────────────────────────
        $monitors = Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'icon' => 'Monitor', 'badge' => 'NEW']);

        $oledMonitors = Category::create(['name' => 'OLED Gaming Monitors', 'slug' => 'oled-gaming-monitors', 'parent_id' => $monitors->id]);
        $catOled27 = Category::create(['name' => '27" QHD OLED 240Hz (G-Sync)', 'slug' => 'oled-27-qhd', 'parent_id' => $oledMonitors->id]);
        $catOled32 = Category::create(['name' => '32" 4K OLED 240Hz (HDR2000)', 'slug' => 'oled-32-4k', 'parent_id' => $oledMonitors->id]);
        $catOled45Ultra = Category::create(['name' => '45" Curved Ultrawide OLED 240Hz', 'slug' => 'oled-45-ultrawide', 'parent_id' => $oledMonitors->id]);

        $ipsMonitors = Category::create(['name' => 'IPS / Mini-LED Monitors', 'slug' => 'ips-miniled-monitors', 'parent_id' => $monitors->id]);
        $catIps4k144 = Category::create(['name' => '4K 144Hz IPS (Content Creation)', 'slug' => 'ips-4k-144hz', 'parent_id' => $ipsMonitors->id]);
        $catIps1440p165 = Category::create(['name' => '1440p 165Hz IPS (Best Value)', 'slug' => 'ips-1440p-165hz', 'parent_id' => $ipsMonitors->id]);

        // ── Root 5: Gaming Gear ──────────────────────────────────────────
        $gamingGear = Category::create(['name' => 'Gaming Gear', 'slug' => 'gaming-gear', 'icon' => 'Mouse']);

        $keyboards = Category::create(['name' => 'Mechanical Keyboards', 'slug' => 'keyboards', 'parent_id' => $gamingGear->id]);
        $catKbTkl = Category::create(['name' => 'TKL 75% Wireless (Hot-Swap)', 'slug' => 'tkl-75-wireless', 'parent_id' => $keyboards->id]);
        $catKbFull = Category::create(['name' => 'Full-Size RGB Gaming Keyboards', 'slug' => 'full-size-keyboard', 'parent_id' => $keyboards->id]);

        $mice = Category::create(['name' => 'Gaming Mice', 'slug' => 'mice', 'parent_id' => $gamingGear->id]);
        $catMiceWireless = Category::create(['name' => 'Wireless Ultralight Gaming Mice', 'slug' => 'wireless-mice', 'parent_id' => $mice->id]);
        $catMiceWired = Category::create(['name' => 'Wired High-DPI FPS Mice', 'slug' => 'wired-fps-mice', 'parent_id' => $mice->id]);

        $headsets = Category::create(['name' => 'Gaming Headsets', 'slug' => 'headsets', 'parent_id' => $gamingGear->id]);
        $catHs7Point1 = Category::create(['name' => 'Surround Sound 7.1 Wireless', 'slug' => '7point1-wireless-headset', 'parent_id' => $headsets->id]);

        // ── Root 6: Accessories ──────────────────────────────────────────
        $accessories = Category::create(['name' => 'Accessories', 'slug' => 'accessories', 'icon' => 'Package']);

        $webcams = Category::create(['name' => 'Webcams & Streaming Gear', 'slug' => 'webcams-streaming', 'parent_id' => $accessories->id]);
        $catWebcam4k = Category::create(['name' => '4K 60fps Stream Webcams', 'slug' => '4k-webcam', 'parent_id' => $webcams->id]);

        $cables = Category::create(['name' => 'Cables & Adapters', 'slug' => 'cables-adapters', 'parent_id' => $accessories->id]);
        $catHdmi21 = Category::create(['name' => 'HDMI 2.1 / 2.0 Certified Cables', 'slug' => 'hdmi-2-1', 'parent_id' => $cables->id]);

        // ── Root 7: Networking ───────────────────────────────────────────
        $networking = Category::create(['name' => 'Networking', 'slug' => 'networking', 'icon' => 'Wifi']);

        $routers = Category::create(['name' => 'Wi-Fi Routers', 'slug' => 'wifi-routers', 'parent_id' => $networking->id]);
        $catWifi7 = Category::create(['name' => 'Wi-Fi 7 Tri-Band Routers (BE)', 'slug' => 'wifi-7-router', 'parent_id' => $routers->id]);
        $catWifi6E = Category::create(['name' => 'Wi-Fi 6E Gaming Routers (AXE)', 'slug' => 'wifi-6e-router', 'parent_id' => $routers->id]);

        $switches = Category::create(['name' => 'Network Switches', 'slug' => 'switches', 'parent_id' => $networking->id]);
        $catGigSwitch = Category::create(['name' => '2.5G / 10G Managed Switches', 'slug' => '2-5g-switch', 'parent_id' => $switches->id]);

        // ── Root 8: Server & Storage ─────────────────────────────────────
        $server = Category::create(['name' => 'Server & Storage', 'slug' => 'server-storage', 'icon' => 'Server']);

        $nas = Category::create(['name' => 'NAS & Network Storage', 'slug' => 'nas-storage', 'parent_id' => $server->id]);
        $catSynology = Category::create(['name' => 'Synology DiskStation DS / RS', 'slug' => 'synology-nas', 'parent_id' => $nas->id]);

        // ── Root 9: Offers & Deals ───────────────────────────────────────
        $offers = Category::create(['name' => 'Offers & Deals', 'slug' => 'offers-deals', 'icon' => 'Tag', 'badge' => 'SALE', 'is_offer' => true]);

        // ─────────────────────────────────────────────────────────────────
        // 3. PRODUCTS (30 realistic flagship items)
        // ─────────────────────────────────────────────────────────────────

        // ── CPUs ─────────────────────────────────────────────────────────

        $this->product($catIntelI9, $b['intel'], [
            'name' => 'Intel Core i9-14900KS 24-Core 6.2GHz Flagship CPU (Special Edition)',
            'slug' => 'intel-core-i9-14900ks',
            'price' => 88000,
            'discount' => 81500,
            'stock' => 18,
            'featured' => true,
            'short' => '24 Cores (8P+16E), 6.2 GHz Max Boost, 36MB Smart Cache, LGA1700, 150W PBP',
            'description' => 'The Intel Core i9-14900KS Special Edition pushes the limits with an unprecedented 6.2 GHz single-core Thermal Velocity Boost clock — the fastest consumer desktop CPU Intel has ever shipped.',
            'image' => '/images/products/cpu-i9-14900ks.jpg',
            'specs' => ['Socket' => 'LGA1700', 'Cores / Threads' => '24 (8P + 16E) / 32', 'Max Boost' => '6.2 GHz (TVB)', 'Cache' => '36MB Intel Smart Cache', 'TDP' => '150W PBP / 253W MTP', 'Warranty' => '3 Years Official'],
        ]);

        $this->product($catAmdRyzen7, $b['amd'], [
            'name' => 'AMD Ryzen 7 7800X3D 8-Core AM5 3D V-Cache Gaming Processor',
            'slug' => 'amd-ryzen-7-7800x3d',
            'price' => 52000,
            'discount' => 47500,
            'stock' => 32,
            'featured' => true,
            'short' => '8 Cores / 16 Threads, 96MB 3D V-Cache, Up to 5.0 GHz, AM5, 120W TDP',
            'description' => 'The Ryzen 7 7800X3D holds the title of world\'s best gaming CPU. With 96MB of stacked V-Cache, frame rates in AAA games soar past anything Intel can offer at any price.',
            'image' => '/images/products/cpu-ryzen-7800x3d.jpg',
            'specs' => ['Socket' => 'AM5', 'Cores / Threads' => '8 / 16', 'L3 Cache' => '96MB (3D V-Cache)', 'Boost Clock' => 'Up to 5.0 GHz', 'TDP' => '120W', 'Warranty' => '3 Years Official'],
        ]);

        $this->product($catAmdRyzen9, $b['amd'], [
            'name' => 'AMD Ryzen 9 9900X 12-Core Zen 5 Desktop Processor',
            'slug' => 'amd-ryzen-9-9900x',
            'price' => 68000,
            'discount' => 62500,
            'stock' => 20,
            'featured' => true,
            'short' => '12 Cores / 24 Threads, Zen 5 Architecture, 5.6 GHz Boost, AM5, 120W TDP',
            'description' => 'Built on the all-new Zen 5 architecture, the Ryzen 9 9900X delivers up to 16% IPC uplift over Zen 4. A content creator\'s dream with exceptional single-threaded performance.',
            'image' => '/images/products/cpu-ryzen-9900x.jpg',
            'specs' => ['Socket' => 'AM5', 'Cores / Threads' => '12 / 24', 'Architecture' => 'Zen 5', 'Boost Clock' => 'Up to 5.6 GHz', 'Cache' => '64MB L3', 'Warranty' => '3 Years Official'],
        ]);

        $this->product($catIntelI5, $b['intel'], [
            'name' => 'Intel Core i5-14600K 14-Core 5.3GHz Unlocked Desktop Processor',
            'slug' => 'intel-core-i5-14600k',
            'price' => 38500,
            'discount' => 35000,
            'stock' => 45,
            'featured' => false,
            'short' => '14 Cores (6P+8E), 5.3 GHz Max, 24MB Smart Cache, LGA1700, Best Value Gaming CPU',
            'description' => 'The i5-14600K is the undisputed value champion for gaming builds in 2025. It pairs perfectly with RTX 4070 / RX 7800 XT for 1440p gaming without breaking the bank.',
            'image' => '/images/products/cpu-i5-14600k.jpg',
            'specs' => ['Socket' => 'LGA1700', 'Cores / Threads' => '14 (6P + 8E) / 20', 'Max Boost' => '5.3 GHz', 'Cache' => '24MB Intel Smart Cache', 'TDP' => '125W', 'Warranty' => '3 Years Official'],
        ]);

        // ── GPUs ─────────────────────────────────────────────────────────

        $this->product($catRtx4090, $b['asus'], [
            'name' => 'ASUS ROG Strix GeForce RTX 4090 24GB GDDR6X OC Edition',
            'slug' => 'asus-rog-strix-rtx-4090-oc',
            'price' => 265000,
            'discount' => 245000,
            'stock' => 12,
            'featured' => true,
            'short' => '24GB GDDR6X 384-bit, 2640 MHz Boost, DLSS 3.5, Triple Axial-tech Fans',
            'description' => 'The ROG Strix GeForce RTX 4090 OC Edition is a powerhouse built for 8K gaming and professional rendering. Featuring triple Axial-tech fans and an aggressive heatsink that keeps the GPU cool even at full load.',
            'image' => '/images/products/gpu-rtx4090-rog.jpg',
            'specs' => ['CUDA Cores' => '16,384', 'Memory' => '24GB GDDR6X 384-bit', 'Boost Clock' => '2640 MHz', 'TDP' => '450W', 'Power Connector' => '16-pin ATX 3.0', 'Warranty' => '3 Years'],
        ]);

        $this->product($catRtx4070S, $b['msi'], [
            'name' => 'MSI GeForce RTX 4070 Super 12GB Gaming X Slim White',
            'slug' => 'msi-rtx-4070-super-gaming-x-slim-white',
            'price' => 89000,
            'discount' => 82500,
            'stock' => 22,
            'featured' => true,
            'short' => '12GB GDDR6X 192-bit, 2655 MHz Boost, Dual Fan, Silent Mode BIOS',
            'description' => 'The MSI RTX 4070 Super Gaming X Slim White is a slim yet powerful card that delivers exceptional 1440p and competent 4K gaming performance with excellent ray tracing.',
            'image' => '/images/products/gpu-rtx4070s-msi.jpg',
            'specs' => ['CUDA Cores' => '7,168', 'Memory' => '12GB GDDR6X 192-bit', 'Boost Clock' => '2655 MHz', 'TDP' => '220W', 'Power Connector' => '1x 16-pin', 'Warranty' => '3 Years'],
        ]);

        $this->product($catRx9070, $b['gigabyte'], [
            'name' => 'Gigabyte Radeon RX 9070 XT Gaming OC 16GB RDNA 4',
            'slug' => 'gigabyte-rx-9070-xt-gaming-oc',
            'price' => 92000,
            'discount' => 86000,
            'stock' => 15,
            'featured' => true,
            'short' => '16GB GDDR6 256-bit, RDNA 4, AV1 Encode, Hardware Ray Tracing Gen 3',
            'description' => 'AMD RDNA 4 architecture brings incredible rasterization and improved ray tracing to the mainstream segment. The RX 9070 XT trades blows with RTX 4070 Super at a lower price.',
            'image' => '/images/products/gpu-rx9070xt.jpg',
            'specs' => ['Stream Processors' => '4,096', 'Memory' => '16GB GDDR6 256-bit', 'Game Clock' => '2,970 MHz', 'TDP' => '304W', 'Architecture' => 'RDNA 4', 'Warranty' => '3 Years'],
        ]);

        $this->product($catRtx4060, $b['gigabyte'], [
            'name' => 'Gigabyte GeForce RTX 4060 Ti 16GB Gaming OC Edition',
            'slug' => 'gigabyte-rtx-4060-ti-16gb-gaming-oc',
            'price' => 65000,
            'discount' => 59500,
            'stock' => 28,
            'featured' => false,
            'short' => '16GB GDDR6 128-bit, DLSS 3.5, 2640 MHz Boost, Triple Fan WindForce 3X',
            'description' => 'The RTX 4060 Ti 16GB variant offers double the memory over the base model — ideal for content creation and future-proofing 1080p gaming rigs on a budget.',
            'image' => '/images/products/gpu-rtx4060ti.jpg',
            'specs' => ['CUDA Cores' => '4,352', 'Memory' => '16GB GDDR6 128-bit', 'Boost Clock' => '2640 MHz', 'TDP' => '165W', 'Power Connector' => '1x 16-pin', 'Warranty' => '3 Years'],
        ]);

        // ── Motherboards ─────────────────────────────────────────────────

        $this->product($catX870E, $b['asus'], [
            'name' => 'ASUS ROG Crosshair X870E Hero Wi-Fi 7 ATX Motherboard (AM5)',
            'slug' => 'asus-rog-crosshair-x870e-hero',
            'price' => 72000,
            'discount' => 68000,
            'stock' => 14,
            'featured' => true,
            'short' => 'AM5, DDR5, PCIe 5.0 x16, Thunderbolt 4, Wi-Fi 7, 5Gbps LAN, Aura Sync',
            'description' => 'The ROG Crosshair X870E Hero is built for AMD Ryzen 9000/7000 series processors. With PCIe 5.0 support, Wi-Fi 7, and Thunderbolt 4, it\'s future-proof to the core.',
            'image' => '/images/products/mobo-x870e-crosshair.jpg',
            'specs' => ['Socket' => 'AM5', 'Chipset' => 'AMD X870E', 'Memory' => '4x DDR5 slots (Up to 256GB)', 'PCIe' => 'PCIe 5.0 x16 + PCIe 4.0 x4', 'Wireless' => 'Wi-Fi 7 (802.11be) + BT 5.4', 'Warranty' => '3 Years'],
        ]);

        $this->product($catZ790, $b['msi'], [
            'name' => 'MSI MEG Z790 Ace Max Wi-Fi 6E LGA1700 ATX Motherboard',
            'slug' => 'msi-meg-z790-ace-max',
            'price' => 58000,
            'discount' => 54000,
            'stock' => 18,
            'featured' => false,
            'short' => 'LGA1700, DDR5, PCIe 5.0, 10Gbps LAN, Wi-Fi 6E, 20+1+1 Power Stages',
            'description' => 'The MSI MEG Z790 Ace Max is built for overclockers and power users who demand the absolute maximum from Intel 12th/13th/14th Gen platforms.',
            'image' => '/images/products/mobo-z790-msi.jpg',
            'specs' => ['Socket' => 'LGA1700', 'Chipset' => 'Intel Z790', 'Memory' => '4x DDR5 slots', 'PCIe' => 'PCIe 5.0 x16', 'LAN' => '10Gbps (Marvell) + 2.5Gbps', 'Warranty' => '3 Years'],
        ]);

        // ── RAM ──────────────────────────────────────────────────────────

        $this->product($catDdr5, $b['corsair'], [
            'name' => 'Corsair Vengeance RGB 32GB (2×16GB) DDR5 6000MHz CL30 Black',
            'slug' => 'corsair-vengeance-rgb-ddr5-6000-32gb',
            'price' => 17500,
            'discount' => 15500,
            'stock' => 55,
            'featured' => true,
            'short' => '32GB DDR5-6000 CL30, Intel XMP 3.0 & AMD EXPO, 10-Zone Dynamic RGB',
            'description' => 'Corsair Vengeance DDR5 is tuned for high bandwidth with class-leading DDR5-6000 speeds and tight CL30 timings — compatible with both Intel XMP 3.0 and AMD EXPO platforms.',
            'image' => '/images/products/ram-corsair-ddr5.jpg',
            'specs' => ['Capacity' => '32GB (2×16GB)', 'Speed' => 'DDR5-6000MHz CL30', 'Voltage' => '1.4V', 'XMP/EXPO' => 'Intel XMP 3.0 + AMD EXPO', 'RGB' => '10-Zone Dynamic RGB', 'Warranty' => 'Lifetime'],
        ]);

        $this->product($catDdr5High, $b['gskill'], [
            'name' => 'G.Skill Trident Z5 RGB 64GB (2×32GB) DDR5 7200MHz CL34',
            'slug' => 'gskill-trident-z5-rgb-ddr5-7200-64gb',
            'price' => 42000,
            'discount' => 38500,
            'stock' => 20,
            'featured' => false,
            'short' => '64GB DDR5-7200 CL34, Ultra-Speed OC Memory, Intel XMP 3.0 Certified',
            'description' => 'For enthusiasts who refuse to compromise. G.Skill Trident Z5 RGB at DDR5-7200 delivers extreme bandwidth for AI workloads, content creation, and world-record-chasing overclocking.',
            'image' => '/images/products/ram-gskill-ddr5.jpg',
            'specs' => ['Capacity' => '64GB (2×32GB)', 'Speed' => 'DDR5-7200 CL34', 'Voltage' => '1.45V', 'XMP' => 'Intel XMP 3.0', 'Heat Spreader' => 'Aluminum with RGB Diffuser', 'Warranty' => 'Lifetime'],
        ]);

        // ── SSDs ─────────────────────────────────────────────────────────

        $this->product($catGen4Ssd, $b['samsung'], [
            'name' => 'Samsung 990 Pro 2TB PCIe 4.0 NVMe M.2 SSD with Heatsink',
            'slug' => 'samsung-990-pro-2tb-nvme-heatsink',
            'price' => 24000,
            'discount' => 20500,
            'stock' => 60,
            'featured' => false,
            'short' => '7,450 MB/s Read, 6,900 MB/s Write, V-NAND TLC, Integrated Heatsink Version',
            'description' => 'The Samsung 990 Pro is the top-tier NVMe SSD from Samsung. PCIe 4.0 maximised performance with a sleek integrated heatsink perfect for PS5 and motherboards without M.2 covers.',
            'image' => '/images/products/ssd-samsung-990pro.jpg',
            'specs' => ['Interface' => 'PCIe Gen 4.0 x4, NVMe 2.0', 'Seq. Read' => '7,450 MB/s', 'Seq. Write' => '6,900 MB/s', 'NAND' => 'Samsung V-NAND TLC', 'Endurance' => '1,200 TBW', 'Warranty' => '5 Years'],
        ]);

        $this->product($catGen5Ssd, $b['crucial'], [
            'name' => 'Crucial T705 2TB PCIe Gen5 NVMe M.2 SSD (14,500 MB/s)',
            'slug' => 'crucial-t705-2tb-gen5-nvme',
            'price' => 38000,
            'discount' => 34000,
            'stock' => 25,
            'featured' => false,
            'short' => '14,500 MB/s Read, PCIe 5.0 x4, Phison E26 Controller, 2280 Form Factor',
            'description' => 'The Crucial T705 is one of the fastest consumer SSDs available. PCIe Gen5 enables near-DRAM-speed sequential throughput — ideal for video editors and AI training datasets.',
            'image' => '/images/products/ssd-crucial-t705.jpg',
            'specs' => ['Interface' => 'PCIe Gen 5.0 x4, NVMe 2.0', 'Seq. Read' => '14,500 MB/s', 'Seq. Write' => '12,700 MB/s', 'Controller' => 'Phison E26', 'Endurance' => '1,200 TBW', 'Warranty' => '5 Years'],
        ]);

        $this->product($catHdd, $b['western'], [
            'name' => 'Western Digital WD Red Pro 8TB 3.5" NAS HDD 7200RPM SATA 6Gb/s',
            'slug' => 'wd-red-pro-8tb-nas-hdd',
            'price' => 22000,
            'discount' => null,
            'stock' => 35,
            'featured' => false,
            'short' => '8TB, 7200RPM, 256MB Cache, NAS Optimised, CMR Technology, 24×7 Operation',
            'description' => 'Built for always-on NAS environments with CMR recording technology. The WD Red Pro handles heavy workloads with up to 8 drive bays at up to 300 MB/s sustained transfer.',
            'image' => '/images/products/hdd-wd-red-pro.jpg',
            'specs' => ['Capacity' => '8TB', 'RPM' => '7200 RPM', 'Interface' => 'SATA 6Gb/s', 'Cache' => '256MB', 'Recording' => 'CMR (Conventional Magnetic)', 'Warranty' => '5 Years'],
        ]);

        // ── PSU ──────────────────────────────────────────────────────────

        $this->product($catPsuGold, $b['corsair'], [
            'name' => 'Corsair RM1000e 1000W 80+ Gold Fully Modular ATX 3.0 PSU',
            'slug' => 'corsair-rm1000e-1000w-gold',
            'price' => 19500,
            'discount' => 17800,
            'stock' => 30,
            'featured' => false,
            'short' => '1000W, 80+ Gold, Fully Modular, Zero RPM Fan Mode, ATX 3.0 (PCIe 5.0)',
            'description' => 'With 1000W of 80 Plus Gold certified power and a native PCIe 5.0 16-pin connector, the RM1000e is ideal for RTX 4080/4090 builds that demand both efficiency and reliability.',
            'image' => '/images/products/psu-corsair-rm1000e.jpg',
            'specs' => ['Wattage' => '1000W', 'Efficiency' => '80+ Gold', 'Modularity' => 'Fully Modular', 'Standard' => 'ATX 3.0', 'Fan' => 'Zero RPM Mode (135mm)', 'Warranty' => '10 Years'],
        ]);

        // ── Laptops ──────────────────────────────────────────────────────

        $this->product($catRogScar, $b['asus'], [
            'name' => 'ASUS ROG Strix SCAR 16 (2024) G634 RTX 4080 QHD 240Hz Laptop',
            'slug' => 'asus-rog-strix-scar-16-2024-rtx4080',
            'price' => 330000,
            'discount' => 305000,
            'stock' => 8,
            'featured' => true,
            'short' => 'i9-14900HX, RTX 4080 175W, 32GB DDR5, 2TB NVMe, 2.5K 240Hz Nebula HDR',
            'description' => 'The SCAR 16 is the ultimate gaming laptop built to outperform everything else. With an RTX 4080 at full 175W TGP and a stunning Nebula HDR Mini-LED display, it blurs the line between desktop and laptop gaming.',
            'image' => '/images/products/laptop-rog-scar16.jpg',
            'specs' => ['CPU' => 'Intel Core i9-14900HX (24 Cores)', 'GPU' => 'RTX 4080 Laptop 12GB (175W)', 'RAM' => '32GB DDR5-4800 (2x16GB)', 'Display' => '16" 2.5K 240Hz ROG Nebula HDR', 'Storage' => '2TB PCIe4 NVMe SSD', 'Warranty' => '2 Years'],
        ]);

        $this->product($catRogZeph, $b['asus'], [
            'name' => 'ASUS ROG Zephyrus G14 (2024) AMD Advantage RTX 4060 OLED Laptop',
            'slug' => 'asus-rog-zephyrus-g14-2024-rtx4060',
            'price' => 215000,
            'discount' => 198000,
            'stock' => 12,
            'featured' => true,
            'short' => 'Ryzen 9 8945HS, RTX 4060 100W, 16GB DDR5, 1TB, 14" 3K 120Hz OLED',
            'description' => 'The 2024 Zephyrus G14 features a breathtaking 3K OLED display, AMD Ryzen 9 8945HS with 35MB cache, and an RTX 4060 Laptop GPU — all in a slim 14-inch chassis under 1.65kg.',
            'image' => '/images/products/laptop-zephyrus-g14.jpg',
            'specs' => ['CPU' => 'AMD Ryzen 9 8945HS (8 Cores)', 'GPU' => 'RTX 4060 Laptop 8GB (100W)', 'RAM' => '16GB DDR5-7500 LPDDR5X', 'Display' => '14" 3K (2880×1800) OLED 120Hz', 'Storage' => '1TB PCIe4 NVMe SSD', 'Warranty' => '2 Years'],
        ]);

        $this->product($catLegion, $b['lenovo'], [
            'name' => 'Lenovo Legion Pro 7i Gen 9 Intel RTX 4080 16" 2.5K 240Hz Laptop',
            'slug' => 'lenovo-legion-pro-7i-gen9-rtx4080',
            'price' => 285000,
            'discount' => 262000,
            'stock' => 10,
            'featured' => true,
            'short' => 'i9-14900HX, RTX 4080 175W, 32GB DDR5, 1TB Gen4, 16" 2.5K 240Hz IPS',
            'description' => 'The Legion Pro 7i Gen 9 is Lenovo\'s apex gaming laptop featuring the top-tier i9-14900HX processor and RTX 4080 Laptop GPU at full 175W with LA Coldfront 5.0 cooling.',
            'image' => '/images/products/laptop-legion-pro-7i.jpg',
            'specs' => ['CPU' => 'Intel Core i9-14900HX', 'GPU' => 'RTX 4080 Laptop 12GB (175W TGP)', 'RAM' => '32GB DDR5-5600 (Upgradeable)', 'Display' => '16" 2.5K 240Hz IPS (500 nit)', 'Storage' => '1TB PCIe 4.0 NVMe', 'Warranty' => '2 Years'],
        ]);

        $this->product($catMacbookPro, $b['apple'], [
            'name' => 'Apple MacBook Pro 16" M3 Pro Chip (18GB RAM, 512GB SSD) — Space Black',
            'slug' => 'apple-macbook-pro-16-m3-pro-512',
            'price' => 278000,
            'discount' => 265000,
            'stock' => 10,
            'featured' => true,
            'short' => 'M3 Pro 12-Core CPU, 18-Core GPU, 18GB Unified RAM, 512GB SSD, Liquid Retina XDR',
            'description' => 'The MacBook Pro 16" with M3 Pro delivers extraordinary performance for video production, 3D rendering, and audio mastering. Up to 22 hours battery life in the world\'s best laptop display.',
            'image' => '/images/products/laptop-macbook-pro-m3.jpg',
            'specs' => ['Chip' => 'Apple M3 Pro (12-Core CPU, 18-Core GPU)', 'Memory' => '18GB Unified Memory', 'Storage' => '512GB SSD (Up to 8TB)', 'Display' => '16.2" Liquid Retina XDR (3456×2234)', 'Battery' => 'Up to 22 Hours', 'Warranty' => '1 Year Apple'],
        ]);

        $this->product($catMacbookAir, $b['apple'], [
            'name' => 'Apple MacBook Air 15" M3 Chip (16GB RAM, 512GB SSD) — Midnight',
            'slug' => 'apple-macbook-air-15-m3-512',
            'price' => 188000,
            'discount' => 175000,
            'stock' => 15,
            'featured' => true,
            'short' => 'M3 8-Core CPU, 10-Core GPU, 16GB Unified RAM, 15.3" Liquid Retina, 18-hour Battery',
            'description' => 'The MacBook Air 15" is the world\'s best-selling laptop redesigned with M3. Fanless design runs cool and silent with extraordinary battery life — the perfect daily driver.',
            'image' => '/images/products/laptop-macbook-air-m3.jpg',
            'specs' => ['Chip' => 'Apple M3 (8-Core CPU, 10-Core GPU)', 'Memory' => '16GB Unified Memory', 'Storage' => '512GB SSD', 'Display' => '15.3" Liquid Retina (2880×1864)', 'Battery' => 'Up to 18 Hours', 'Warranty' => '1 Year Apple'],
        ]);

        // ── Desktop PCs (Pre-built) ───────────────────────────────────────

        $this->product($cat4kPc, $b['robinit'], [
            'name' => 'Robin IT 4K Ultra Beast — i9-14900K + RTX 4090 Custom Gaming PC',
            'slug' => 'robin-it-4k-ultra-beast-i9-rtx4090',
            'price' => 485000,
            'discount' => 455000,
            'stock' => 5,
            'featured' => true,
            'short' => 'i9-14900K, RTX 4090 24GB, 64GB DDR5 6000MHz, 2TB Gen4 + 4TB HDD, 360mm AIO',
            'description' => 'Robin IT\'s flagship gaming rig — hand-assembled by certified engineers and stress-tested for 72 hours. Runs any game at 4K Ultra settings with uncapped frame rates. Includes cable management, RGB synchronisation, and 3 years full hardware warranty.',
            'image' => '/images/products/pc-4k-ultra-beast.jpg',
            'specs' => ['CPU' => 'Intel Core i9-14900K (24C/32T)', 'GPU' => 'RTX 4090 24GB GDDR6X', 'RAM' => '64GB Corsair Vengeance DDR5-6000', 'Storage' => '2TB Samsung Gen4 NVMe + 4TB HDD', 'Cooling' => '360mm AIO + 6x ARGB Fans', 'Warranty' => '3 Years Full Hardware'],
        ]);

        $this->product($cat1440p, $b['robinit'], [
            'name' => 'Robin IT QHD Powerhouse — Ryzen 7 7800X3D + RTX 4070 Ti Super Gaming PC',
            'slug' => 'robin-it-qhd-powerhouse-7800x3d-4070ti',
            'price' => 210000,
            'discount' => 192000,
            'stock' => 10,
            'featured' => true,
            'short' => 'Ryzen 7 7800X3D, RTX 4070 Ti Super 16GB, 32GB DDR5 6000MHz, 1TB Gen4 NVMe',
            'description' => 'The sweet spot for competitive and AAA gaming at 1440p/165Hz. The 7800X3D\'s 3D V-Cache combined with the RTX 4070 Ti Super produces incredible frame rates in every title.',
            'image' => '/images/products/pc-qhd-powerhouse.jpg',
            'specs' => ['CPU' => 'AMD Ryzen 7 7800X3D', 'GPU' => 'RTX 4070 Ti Super 16GB GDDR6X', 'RAM' => '32GB G.Skill DDR5-6000', 'Storage' => '1TB Crucial Gen4 NVMe SSD', 'Cooling' => '240mm AIO Liquid', 'Warranty' => '3 Years Full Hardware'],
        ]);

        // ── Monitors ─────────────────────────────────────────────────────

        $this->product($catOled27, $b['lg'], [
            'name' => 'LG UltraGear 27GS95QE 27" QHD OLED 240Hz 0.03ms Gaming Monitor',
            'slug' => 'lg-ultragear-27gs95qe-oled-240hz',
            'price' => 115000,
            'discount' => 105000,
            'stock' => 14,
            'featured' => true,
            'short' => '27" QHD (2560×1440) WOLED, 240Hz, 0.03ms, DCI-P3 98.5%, G-Sync & FreeSync',
            'description' => 'Experience breathtaking visuals with per-pixel OLED luminance control. Every frame rendered by your RTX 4080 is displayed with infinite contrast and true black OLED — no blooming.',
            'image' => '/images/products/monitor-lg-oled-27.jpg',
            'specs' => ['Panel' => 'WOLED (White OLED)', 'Resolution' => '2560×1440 (QHD)', 'Refresh Rate' => '240Hz', 'Response Time' => '0.03ms (GtG)', 'Color' => 'DCI-P3 98.5%', 'Warranty' => '3 Years'],
        ]);

        $this->product($catOled45Ultra, $b['asus'], [
            'name' => 'ASUS ROG Swift OLED PG45UQ 45" 4K 240Hz Ultrawide Curved Monitor',
            'slug' => 'asus-rog-swift-pg45uq-45-oled-240hz',
            'price' => 215000,
            'discount' => 198000,
            'stock' => 7,
            'featured' => true,
            'short' => '45" 4K (3840×2160) OLED 240Hz, 800R Curve, HDR2000, DisplayHDR True Black',
            'description' => 'The ROG Swift OLED PG45UQ redefines large-format gaming. A massive 45" curved OLED canvas at native 4K and 240Hz — paired with HDR2000 certification for cinematic visual fidelity.',
            'image' => '/images/products/monitor-rog-pg45uq.jpg',
            'specs' => ['Panel' => 'OLED', 'Resolution' => '3840×2160 (4K)', 'Refresh Rate' => '240Hz', 'Curvature' => '800R', 'HDR' => 'HDR2000 / DisplayHDR True Black', 'Warranty' => '3 Years'],
        ]);

        $this->product($catIps1440p165, $b['dell'], [
            'name' => 'Dell Alienware AW2725DF 27" QHD 360Hz Fast IPS Gaming Monitor',
            'slug' => 'dell-alienware-aw2725df-360hz-qhd',
            'price' => 68000,
            'discount' => 62000,
            'stock' => 20,
            'featured' => false,
            'short' => '27" QHD 360Hz Fast IPS, 1ms GTG, G-Sync Ultimate, DCI-P3 95%, 3-Year Warranty',
            'description' => 'The Alienware 360Hz QHD monitor is the weapon of choice for CS2, Valorant, and Apex pros. Fast IPS brings OLED-like blur reduction at a fraction of the price.',
            'image' => '/images/products/monitor-aw2725df.jpg',
            'specs' => ['Panel' => 'Fast IPS', 'Resolution' => '2560×1440 (QHD)', 'Refresh Rate' => '360Hz', 'Response Time' => '1ms (GtG)', 'Sync' => 'G-Sync Ultimate + FreeSync Premium Pro', 'Warranty' => '3 Years Premium'],
        ]);

        // ── Gaming Gear ───────────────────────────────────────────────────

        $this->product($catMiceWireless, $b['logitech'], [
            'name' => 'Logitech G Pro X Superlight 2 DEX Wireless Gaming Mouse',
            'slug' => 'logitech-gpx-superlight-2-dex',
            'price' => 16500,
            'discount' => 14900,
            'stock' => 40,
            'featured' => true,
            'short' => '44g Weight, HERO 2 Sensor 35,000 DPI, LIGHTSPEED 2 Wireless, Magnesium Shell',
            'description' => 'The G Pro X Superlight 2 DEX is Logitech\'s most precise and lightest gaming mouse ever. Used by over 50% of professional esports athletes on the world circuit.',
            'image' => '/images/products/mouse-gpx-superlight2.jpg',
            'specs' => ['Weight' => '44g (without cable)', 'Sensor' => 'HERO 2 (35,000 DPI Max)', 'Wireless' => 'LIGHTSPEED 2 (<1ms)', 'Battery' => '70 Hours', 'Buttons' => '5 Programmable', 'Warranty' => '2 Years'],
        ]);

        $this->product($catKbTkl, $b['razer'], [
            'name' => 'Razer BlackWidow V4 Pro 75% Wireless Mechanical Keyboard',
            'slug' => 'razer-blackwidow-v4-pro-75-wireless',
            'price' => 22000,
            'discount' => 19500,
            'stock' => 25,
            'featured' => false,
            'short' => 'Razer Yellow Linear Switches, 75% Layout, Hot-Swap, Tri-Mode (USB/2.4G/BT)',
            'description' => 'The BlackWidow V4 Pro 75% is Razer\'s most versatile gaming keyboard with tri-mode connectivity, a gasket mount for premium typing feel, and fully hot-swappable switches.',
            'image' => '/images/products/keyboard-blackwidow-v4.jpg',
            'specs' => ['Switches' => 'Razer Yellow (Linear, Hot-Swap)', 'Layout' => '75% Compact TKL', 'Connectivity' => 'USB-C, 2.4GHz, Bluetooth 5.0', 'Battery' => '200 Hours (No Backlight)', 'Keycaps' => 'Double-Shot PBT', 'Warranty' => '2 Years'],
        ]);

        // ── Networking ────────────────────────────────────────────────────

        $this->product($catWifi7, $b['asus'], [
            'name' => 'ASUS ROG Rapture GT-BE98 Quad-Band Wi-Fi 7 Gaming Router',
            'slug' => 'asus-rog-rapture-gt-be98-wifi7',
            'price' => 68000,
            'discount' => 62000,
            'stock' => 12,
            'featured' => false,
            'short' => 'Wi-Fi 7 (802.11be) 19.4 Gbps, Quad-Band, 10GbE Port, Dual 2.5GbE, OLED Display',
            'description' => 'The ROG Rapture GT-BE98 is the world\'s first quad-band Wi-Fi 7 gaming router. With 10GbE WAN/LAN and dedicated gaming acceleration, latency drops to near-zero.',
            'image' => '/images/products/router-gt-be98.jpg',
            'specs' => ['Standard' => 'Wi-Fi 7 (802.11be)', 'Max Speed' => '19.4 Gbps Combined', 'Bands' => 'Quad-Band (2.4G + 5G + 5G + 6G)', 'Ports' => '1x 10GbE + 2x 2.5GbE + 2x GbE', 'CPU' => '2.0GHz Quad-Core', 'Warranty' => '3 Years'],
        ]);

        $this->product($catWifi6E, $b['tp_link'], [
            'name' => 'TP-Link Archer BE800 Wi-Fi 7 Tri-Band Router (BE19000)',
            'slug' => 'tp-link-archer-be800-wifi7',
            'price' => 38000,
            'discount' => 34500,
            'stock' => 20,
            'featured' => false,
            'short' => 'Wi-Fi 7 Tri-Band 19Gbps, 4x 2.5GbE Ports, 1x 10GbE, 320MHz Channel, 12 Antennas',
            'description' => 'The Archer BE800 brings Wi-Fi 7 speeds to your home network without the premium price of gaming-branded routers. Ideal for households with 20+ devices.',
            'image' => '/images/products/router-be800.jpg',
            'specs' => ['Standard' => 'Wi-Fi 7 (802.11be)', 'Max Speed' => '19Gbps', 'Bands' => 'Tri-Band (2.4G + 5G + 6GHz)', 'Ports' => '1x 10GbE + 4x 2.5GbE', 'Antennas' => '12 High-Gain', 'Warranty' => '3 Years'],
        ]);
    }

    /**
     * Helper to create a product with image and specs in one call.
     */
    private function product(
        Category $category,
        Brand $brand,
        array $data,
    ): Product {
        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'price' => $data['price'],
            'discount_price' => $data['discount'] ?? null,
            // Zero here, then posted through the ledger below: a quantity
            // written straight onto the row is stock the shop cannot account
            // for, with an empty History behind it.
            'stock_quantity' => 0,
            'short_description' => $data['short'],
            'description' => $data['description'],
            'is_featured' => $data['featured'] ?? false,
            'is_active' => true,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => $data['image'],
            'is_primary' => true,
        ]);

        // Received from the opening-balance source, the same way a real
        // shop enters what it already holds — one path in, and a receipt
        // behind every unit.
        if ((int) $data['stock'] > 0) {
            app(StockService::class)->receive(
                ['supplier_id' => Supplier::openingBalance()->id, 'note' => 'Seeded shelf — replace with a real delivery'],
                [['product_id' => $product->id, 'quantity' => (int) $data['stock']]],
            );
        }

        foreach ($data['specs'] as $name => $value) {
            ProductSpecification::create([
                'product_id' => $product->id,
                'name' => $name,
                'value' => $value,
            ]);
        }

        return $product;
    }
}
