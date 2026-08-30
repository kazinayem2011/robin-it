<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The category tree exactly as startech.com.bd presents it, brands included.
 *
 * ## Brands are categories here, deliberately
 *
 * This mirrors a site whose third level is the brand — Component > Graphics
 * Card > ASUS / MSI / Colorful — so a shopper who thinks "I want a Gigabyte
 * board" navigates rather than filters. It is the client's requirement and it
 * is what this file implements.
 *
 * The cost is real and worth writing down: a product's make now lives in
 * `brand_id` and again in its category, with nothing keeping the two in step.
 * A Gigabyte card filed under the ASUS category is a bug no constraint can
 * catch. If that starts biting, the fix is to keep this tree for navigation and
 * make the leaf a filtered view of `brands` rather than a category of its own.
 *
 * ## This seeder is authoritative, not additive
 *
 * It rebuilds the tree from scratch. `products.category_id` is
 * `cascadeOnDelete`, so **clearing the categories deletes every product with
 * it** — including anything CatalogSeeder made. That is the point (the old
 * slugs describe a different taxonomy and nothing would map cleanly), but it is
 * destructive and only belongs on a shop that has not opened yet.
 *
 * Run TaxonomyDemoProductsSeeder afterwards, or the menu comes back empty:
 * CategoryService hides categories that hold no products.
 */
class StarTechTaxonomySeeder extends Seeder
{
    /**
     * A value that is a list of strings is a row of leaves — nearly always
     * brands. A value that is an associative array nests further.
     *
     * @var array<string, mixed>
     */
    private const TREE = [
        'Desktop' => [
            'AI PC' => [],
            'Star PC' => ['Intel PC', 'Ryzen PC'],
            'Gaming PC' => ['Intel Gaming PC', 'Ryzen Gaming PC'],
            'Brand PC' => ['Acer', 'ASUS', 'Dell', 'HP', 'Lenovo', 'MSI', 'Gigabyte'],
            'All-in-One PC' => ['Dell', 'HP', 'ASUS', 'Lenovo', 'Teclast', 'AOC', 'Value-Top', 'Smart'],
            'Portable Mini PC' => ['Asus'],
            'Apple Mac Mini' => [],
            'Apple iMac' => [],
            'Apple Mac Studio' => [],
            'Apple Mac Pro' => [],
        ],

        'Laptop' => [
            'All Laptop' => ['Lenovo', 'MSI', 'HP', 'Asus', 'Tecno', 'Chuwi', 'MacBook', 'Gigabyte', 'Acer', 'Dell', 'Microsoft', 'Smart', 'Thunderobot', 'Walton'],
            'Gaming Laptop' => ['MSI', 'Asus', 'Lenovo', 'Gigabyte', 'HP', 'Acer'],
            'Premium Ultrabook' => ['HP', 'Acer', 'Lenovo', 'Asus', 'Dell', 'Microsoft', 'MSI'],
            'Laptop Bag' => ['Asus', 'Dell', 'Fantech', 'Lenovo', 'MaxGreen', 'BWOO', 'Targus', 'Tucano', 'UGREEN', 'WiWU', 'Xiaomi', 'Arctic Hunter', 'Honor'],
            'Laptop Accessories' => ['Laptop Cooler', 'Laptop Desk', 'Laptop RAM', 'Laptop Stand', 'Laptop Battery', 'Laptop Charger', 'Laptop Display', 'Laptop Keyboard', 'HDD Caddy'],
        ],

        'Component' => [
            'Processor' => ['AMD', 'Intel'],
            'CPU Cooler' => ['MSI', 'Antec', 'Gamdias', 'ARCTIC', 'Corsair', 'Ocypus', 'DeepCool', 'Asus', '1STPLAYER', 'NZXT', 'Cooler Master', 'Cougar', 'Gigabyte', 'Xigmatek', 'Xtreme', 'TEAM', 'upHere', 'Yeston', 'Value-Top'],
            'Motherboard' => ['MSI Intel', 'MSI AMD', 'ASRock Intel', 'ASRock AMD', 'ASUS Intel', 'ASUS AMD', 'Gigabyte Intel', 'Gigabyte AMD', 'Colorful Intel', 'Colorful AMD'],
            'Graphics Card' => ['Colorful', 'INNO3D', 'MSI', 'ASUS', 'PNY', 'Gigabyte', 'ZOTAC', 'Manli', 'NVIDIA', 'Sapphire', 'PowerColor', 'GUNNIR', 'Yeston', 'ARKTEK', 'AFOX', 'OCPC', 'PELADN', 'MAXSUN', 'Unika'],
            'RAM Desktop' => ['TEAM', 'Colorful', 'Corsair', 'Kingston', 'PNY', 'G.Skill', 'AITC', 'Lexar', 'Netac', 'OCPC', 'OSCOO', 'KingBank', 'V-Color'],
            'RAM Laptop' => ['TEAM', 'Adata', 'G.Skill', 'Lexar', 'Corsair', 'PNY', 'OCPC', 'Netac'],
            'Power Supply' => ['MSI', 'Antec', 'Gamdias', '1STPLAYER', 'MaxGreen', 'Corsair', 'Cooler Master', 'Gigabyte', 'Asus', 'DeepCool', 'NZXT', 'Ocypus', 'Value-Top', 'Xtreme', 'Acer', 'Xigmatek', 'Cougar', 'OCPC', 'T-WOLF', 'Solitine', 'Huntkey'],
            'Hard Disk Drive' => ['Toshiba', 'Western Digital', 'Seagate', 'Hikvision'],
            'Portable Hard Disk Drive' => ['Transcend', 'Western Digital', 'Seagate', 'Toshiba'],
            'SSD' => ['TEAM', 'Colorful', 'MiPhi', 'Corsair', 'Kingston', 'Western Digital', 'Lexar', 'Transcend', 'Seagate', 'AITC', 'Netac', 'OCPC', 'OSCOO', 'Addlink', 'KingBank'],
            'Portable SSD' => ['TEAM', 'SanDisk', 'Transcend', 'Seagate', 'Lexar', 'Corsair', 'OSCOO', 'UGREEN'],
            'Casing' => ['MSI', 'Antec', 'Gamdias', 'MaxGreen', 'Corsair', 'Asus', '1STPLAYER', 'NZXT', 'Gigabyte', 'Xtreme', 'DeepCool', 'Xigmatek', 'Value-Top', 'Cougar', 'PC Power', 'Monarch', 'Acer', 'Carbono', 'T-Wolf', 'Arctic'],
            'Casing Cooler' => ['Antec', 'Xtreme', 'MaxGreen', 'Gamdias', '1STPLAYER', 'Corsair', 'Fantech', 'NZXT', 'Cooler Master', 'DeepCool', 'Redragon', 'Xigmatek', 'ARCTIC', 'upHere'],
            'Optical Disk Drive' => [],
            'Vertical GPU Holder' => [],
            'Water / Liquid Cooling' => [],
        ],

        'Monitor' => [
            'Monitor Brands' => ['MSI', 'AOC', 'Asus', 'Lenovo', 'BenQ', 'LG', 'Acer', 'HP', 'Dell', 'Samsung', 'Gigabyte', 'Philips', 'Viewsonic', 'Corsair', 'ThundeRobot', 'Koorui', 'Dahua', 'PC Power', 'Hikvision', 'Eurovision', 'Walton', 'Arzopa', 'GEESUU', 'Titan Army', 'Value-Top', 'AIWA', 'Xiaomi', 'Fopo', 'Gigasonic', 'TrendSonic', 'FeuVision'],
            'Gaming Monitor' => [],
            'Curved Monitor' => [],
            'Touch Monitor' => [],
            '4K Monitor' => [],
            'Portable Monitor' => [],
            'Monitor Arm' => [],
        ],

        'Power' => [
            'UPS' => ['MaxGreen', 'Digital X', 'Prolink', 'Apollo', 'KSTAR', 'MARSRIVA', 'Power Pac', 'Power Guard', 'Must', 'KENSON', 'Dahua'],
            'Online UPS' => ['MaxGreen', 'SANTAK', 'MARSRIVA', 'Apollo', 'KSTAR', 'Vertiv', 'Power Pac', 'EnSmart', 'APC', 'Zigor', 'Power Guard', 'Kehua', 'Must'],
            'Mini UPS' => ['MaxGreen', 'PC Power', 'MARSRIVA'],
            'Portable Power Station' => ['Anker', 'EcoFlow', 'Marsriva', 'Hithium', 'Bluetti', 'EcoSONIC'],
            'IPS' => [],
            'UPS Battery' => [],
            'Voltage Stabilizer' => [],
            'Inverter' => [],
            'Solar Panel' => [],
        ],

        'Phone' => [
            'iPhone' => [],
            'Samsung' => [],
            'Google' => [],
            'Redmi' => [],
            'Realme' => [],
            'Vivo' => [],
            'OPPO' => [],
            'HONOR' => [],
            'HUAWEI' => [],
            'OnePlus' => [],
            'TECNO' => [],
            'Infinix' => [],
            'Symphony' => [],
            'Helio' => [],
            'Nokia' => [],
            'ZTE' => [],
            'HTC' => [],
            'TCL' => [],
            'Motorola' => [],
            'XTRA' => [],
            'HMD' => [],
            'Feature Phone' => [],
            'Mobile Accessories' => ['Charger Adapter', 'Car Charger', 'Type-C Cable', 'Micro USB Cable', 'Lightning Cable', 'Holder & Stand', 'Case & Cover', 'Mobile Phone Cooler'],
        ],

        'Tablet' => [
            'Graphics Tablet' => ['XP-PEN', 'Huion', 'Wacom', 'VEIKK'],
            'iPad' => [],
            'Lenovo' => [],
            'Samsung' => [],
            'HONOR' => [],
            'Xiaomi' => [],
            'Walton' => [],
            'HUAWEI' => [],
            'Chuwi' => [],
            'Amazon' => [],
            'Google' => [],
            'OnePlus' => [],
            'Teclast' => [],
            'ZTE' => [],
            'Infinix' => [],
            'reMarkable' => [],
            'Tecno' => [],
            'Stylus Pen' => [],
        ],

        'Office Equipment' => [
            'Projector' => ['Optoma', 'Acer', 'BenQ', 'Epson', 'ViewSonic', 'VIVItek', 'Boxlight', 'Xiaomi', 'AUN', 'Philips', 'Anker', 'Havit', 'XINJI', 'Blisbond', 'Cheerlux', 'InFocus', 'Magcubic', 'Projection Screen', 'Projector Mount'],
            'Conference System' => ['Logitech', 'Jabra', 'HTDZ', 'CMX', 'EMEET', 'TEV', 'AVerMedia', 'Rapoo', 'Poly', 'BenQ', 'AVer', 'Grandstream', 'ScreenBeam', 'MAXHUB', 'Ahuja'],
            'PA System' => ['TEV', 'CMX', 'Grandstream', 'JBL'],
            'Interactive Flat Panel' => ['InFocus', 'Optoma', 'BenQ', 'Hikvision', 'ViewSonic', 'LG', 'Dahua', 'BoxLight', 'MAXHUB', 'Lumevax', 'METZ', 'iBoard', 'Newline', 'Epson', 'ZKTeco', 'Horion', 'Hitachi', 'Panasonic', 'ARMOR'],
            'Video Wall' => [],
            'Signage' => ['ViewSonic', 'LG', 'Hikvision', 'MAXHUB', 'Dahua'],
            'Kiosk' => ['ViewSonic', 'ARMOR', 'Hikvision', 'Innovtech'],
            'Printer' => ['HP', 'Epson', 'Brother', 'Canon', 'Pantum', 'Fujifilm', 'Deli'],
            'Laser Printer' => [],
            'Large Format Printer' => [],
            'ID Card Printer' => ['Zebra', 'Evolis', 'HID'],
            'POS Printer' => ['Deli', 'RONGTA', 'Sewoo', 'Epson', 'Xprinter', 'SPRT', 'Sunmi', 'Citizen', 'G-Printer', 'Tigo', 'Bixolon', 'Ciontek'],
            'Label Printer' => ['Deli', 'Brother', 'Xprinter', 'Zebra', 'Sewoo', 'TSC', 'Rongta', 'G-Printer', 'GoDEX', 'Tigo'],
            'Photocopier' => ['Toshiba', 'Canon', 'Sharp', 'HP', 'Ricoh'],
            'Toner' => ['HP', 'Pantum', 'Canon', 'Brother', 'Fujifilm', 'Toshiba', 'Power Print', 'True Trust', 'Starink', 'G&G', 'Print-Rite', 'Sharp', 'LongPrint', 'SafeWay', 'Ricoh', 'Samsung', 'Deli'],
            'Cartridge' => ['Canon', 'Epson', 'HP', 'Brother'],
            'Ink Bottle' => ['Epson', 'Brother', 'HP', 'Canon', 'Deli'],
            'Printer Paper' => [],
            'Ribbon' => [],
            'Printer Drum' => [],
            'Scanner' => ['HP', 'Canon', 'Epson', 'Brother', 'Fujitsu', 'Kodak', 'Avision', 'Plustek'],
            'Barcode Scanner' => ['Deli', 'Winson', 'Yumite', 'ZEBEX', 'Zebra', 'SEWOO', 'Honeywell', 'Sunlux', 'ZKTeco'],
            'Cash Drawer' => [],
            'Telephone Set' => [],
            'IP Phone' => ['Mitel', 'Grandstream', 'Yealink', 'Fanvil', 'Cisco', 'Avaya', 'DINSTAR', 'Snom', 'Flyingvoice'],
            'PABX System' => ['Mitel', 'Grandstream', 'DINSTAR', 'Zycoo', 'Panasonic', 'Synway'],
            'Money Counting Machine' => ['Apollo', 'Kington', 'Domens', 'Yumite', 'Namibind', 'Safescan', 'Chihua', 'Julong', 'Maxsell', 'Tay-Chian', 'Deli', 'Henry', 'Universal'],
            'Paper Shredder' => ['Deli', 'Ofitech', 'Aurora', 'Xtreme'],
            'Laminating Machine' => [],
            'Binding Machine' => [],
        ],

        'Camera' => [
            'Action Camera' => ['GoPro', 'DJI', 'Insta360', 'SJCAM', 'AKASO', 'EKEN', 'AUSEK', 'ORDRO', 'Feiyu', 'Blisbond', 'ACEFAST', 'Action Camera Accessories'],
            'DSLR' => ['Canon'],
            'Mirrorless Camera' => ['Canon', 'Sony', 'Nikon', 'FUJIFILM', 'Panasonic'],
            'Digital Camera' => ['Sony', 'Canon', 'Fujifilm'],
            'Video Camera' => ['Sony', 'Canon', 'JVC', 'Panasonic'],
            'Handycam' => [],
            'Dash Cam' => ['Transcend', 'WiWU', '70mai', 'XO'],
            'Instant Camera' => [],
            'Body Camera' => [],
            'Camera Lenses' => ['7Artisans', 'Canon', 'Nikon', 'Sirui', 'Sony', 'Tamron', 'FUJIFILM', 'Samyang', 'Viltrox', 'Sigma'],
            'Camera Tripod' => ['Manbily', 'K&F Concept', 'Digipod', 'Yunteng', 'Kingjoy', 'Manfrotto', 'Libec', 'Jmary', 'Telesin', 'Fantech'],
            'Camera Accessories' => ['Camera Flash', 'Studio Light', 'Softbox', 'Lens Filter', 'Lens Adapter', 'Battery & Charger', 'Camera Bag', 'Dry Cabinet', 'Camera Flash Trigger'],
            'Gimbal' => [],
        ],

        'Security' => [
            'Portable WiFi Camera' => ['Meari', 'Dahua', 'Imou', 'EZVIZ', 'Jovision', 'TP-Link', 'Tenda', 'ZKTeco', 'Xiaomi', 'Vimtag', 'Havit', 'IMILAB', 'Lenovo', 'Newland'],
            'IP Camera' => ['Dahua', 'Jovision', 'Hikvision', 'Tenda', 'Uniview', 'TP-Link'],
            'CC Camera' => ['Dahua', 'Hikvision', 'Uniview'],
            'PTZ Camera' => ['Dahua', 'Hikvision', 'Jovision', 'Uniview', 'TP-Link', 'Tiandy'],
            'CC Camera Package' => ['Hikvision', 'Dahua'],
            'IP Camera Package' => ['Hikvision', 'Dahua', 'TP-Link'],
            'DVR' => ['Hikvision', 'Jovision'],
            'NVR' => ['Dahua', 'Hikvision', 'Jovision', 'Uniview', 'TP-Link', 'EZVIZ', 'Tiandy', 'Imou'],
            'XVR' => ['Dahua', 'Uniview', 'Jovision'],
            'CC Camera Accessories' => [],
            'Door Lock' => ['ZKTeco', 'STATA', 'SmartX', 'SmartLife', 'Nexakey', 'Ovalin', 'NGTeco'],
            'Smart Door Bell' => [],
            'Access Control' => ['ZKTeco', 'Hikvision', 'Onspot', 'NexaKey', 'Tipsoi', 'Access Control Accessories'],
            'Entrance Control' => [],
            'Digital Locker & Vault' => [],
            'KVM Switch' => [],
        ],

        'Networking' => [
            'Starlink' => [],
            'Router' => ['TP-Link', 'Tenda', 'Cudy', 'Mikrotik', 'D-Link', 'Ruijie', 'ASUS', 'Mercusys', 'Zyxel', 'TOTOLINK', 'BDCOM', 'PROLINK', 'Grandstream', 'Cisco', 'Dahua', 'Hikvision', 'Netis', 'RobiWifi', 'VSOL'],
            'Pocket Router' => ['TP-Link', 'Mercusys'],
            'WiFi Range Extender' => ['Tenda', 'TP-Link', 'D-Link', 'Cudy', 'Xiaomi', 'Mercusys'],
            'Access Point' => ['TP-Link', 'Zyxel', 'Tenda', 'TOTOLINK', 'NETGEAR', 'MikroTik', 'Grandstream', 'Ubiquiti', 'Cambium', 'Cudy', 'Edgecore', 'Ruijie', 'TRENDnet', 'IP-COM', 'Huawei', 'BDCOM', 'Cisco'],
            'WiFi Adapter' => ['TP-Link', 'Tenda', 'Cudy', 'Vention', 'Mercusys', 'TRENDnet', 'Yuanxin', 'D-Link', 'UGREEN', 'Dtech', 'Xtreme'],
            'Network Switch' => ['Zyxel', 'Cisco', 'MikroTik', 'TP-Link', 'Tenda', 'Cudy', 'Ruijie', 'BDCOM', 'NETGEAR', 'D-Link', 'Hikvision', 'Netis', 'TOTOLINK', 'Solitine', 'TRENDnet', 'C-Data', 'Grandstream', 'Huawei', 'Levelone', 'IP-COM', 'VSOL', 'Gigalink', 'Nexakey'],
            'Firewall' => [],
            'ONU' => [],
            'OLT' => [],
            'Media Converter' => ['D-Link', 'TP-Link', 'Cote'],
            'Network Transceivers' => [],
            'Networking Cable' => ['UTP Cable', 'Fiber Optic Cable'],
            'Patch Cord' => [],
            'Connector' => [],
            'Modular Jack' => [],
            'Faceplate' => [],
            'Patch Panel' => [],
            'LAN Card' => [],
            'PoE Injector' => [],
            'Crimping Tool' => [],
            'Splicer Machine' => [],
            'Cable Tester' => [],
        ],

        'Software' => [
            'Operating System' => ['Microsoft Windows', 'Red Hat'],
            'Office Application' => ['Microsoft Office'],
            'Database Server Solution' => [],
            'Mail Server Solution' => [],
            'Cloud Solutions' => [],
            'Antivirus' => ['For Home User', 'For Business Users'],
            'Bangla Typing Software' => ['Bijoy'],
            'Adobe' => [],
            'VMware' => [],
            'AutoDesk' => [],
            'AnyDesk' => [],
        ],

        'Server & Storage' => [
            'Server' => ['Dell', 'HPE', 'Cisco', 'ASUS'],
            'GPU Server' => [],
            'Server Rack' => ['Toten', 'Safenet', 'Cote', 'DateUP', 'Nexakey'],
            'Workstation' => ['HP', 'Dell', 'Lenovo', 'MSI', 'NVIDIA'],
            'NAS Storage' => ['Asustor', 'Synology', 'Orico', 'QNAP'],
            'SAN Storage' => ['DELL'],
            'DAS Storage' => [],
            'Server HDD' => [],
            'Server HDD Bay' => [],
            'Server RAM' => [],
            'Server SSD' => [],
            'Server Power Supply' => [],
        ],

        'Accessories' => [
            'Watch' => [],
            'Keyboard' => ['Logitech', 'Xtrike Me', 'GAMDIAS', 'Fantech', 'Asus', 'Corsair', 'A4Tech', 'SteelSeries', 'Durgod', 'Havit', 'Rapoo', 'T-WOLF', 'Onikuma', 'AULA', 'iMICE', 'ROYAL KLUDGE', 'AJAZZ', 'Keychron', 'Dareu', 'Redragon', 'Microsoft', 'NZXT', 'PC Power', 'Jedel', 'MCHOSE', 'Furycube', 'Magegee', 'XO'],
            'Mouse' => ['Logitech', 'Xtrike Me', 'Asus', 'Corsair', 'A4Tech', 'SteelSeries', 'Fantech', 'Havit', 'iMICE', 'Rapoo', 'Durgod', 'T-WOLF', 'Onikuma', 'AULA', 'ThundeRobot', 'GAMDIAS', 'Apple', 'Redragon', 'MSI', 'AJAZZ', 'PC Power', 'Hoco', 'MCHOSE', 'Furycube', 'Inphic', 'XO'],
            'Headphone' => ['Logitech', 'Xtrike Me', 'Sony', 'Asus', 'Corsair', 'A4Tech', 'SteelSeries', 'Fantech', 'Havit', 'Edifier', 'Rapoo', 'iMICE', 'Onikuma', 'Inbertec', 'JBL', 'MSI', 'EKSA', 'Apple', 'Jabra', 'RODE', 'MeeTion', 'Redragon', 'Microlab', 'Anker', 'Audio Technica', 'Beyerdynamic', 'UGREEN', 'AKG', 'Awei', 'Tribit', 'Hoco', 'Fastrack', 'PC Power', 'OneOdio', 'Acefast', 'Weofly', 'AJAZZ'],
            'Bluetooth Headphone' => [],
            'Mouse Pad' => ['RAZER', 'Xtrike Me', 'Asus', 'Fantech', 'Havit', 'Logitech', 'SteelSeries', 'MSI', 'MeeTion', 'X-Raypad', 'Elgato', 'Onikuma', 'A4Tech', 'Inphic', 'AJAZZ'],
            'Wrist Rest' => [],
            'Headphone Stand' => [],
            'Speaker & Home Theater' => ['JBL', 'Bose', 'Sony', 'Samsung', 'Xtrike Me', 'Edifier', 'Logitech', 'F&D', 'Microlab', 'Havit', 'Fantech', 'Xtreme', 'T-WOLF', 'AULA', 'Rapoo', 'Awei', 'Thonet & Vander', 'Onikuma', 'Solitine'],
            'Bluetooth Speakers' => ['Awei', 'Baseus', 'EarFun', 'Fantech', 'Oraimo', 'HiFuture', 'Hoco', 'JBL', 'Havit', 'JOYROOM', 'Marshall', 'Thunderobot', 'Logitech', 'Edifier', 'F&D', 'HONOR', 'RECCI', 'Sony', 'Tribit', 'LDNIO', 'Yison', 'Ikarao', 'SteelSeries', 'Thonet & Vander', 'QCY', 'Onikuma', 'BWOO', 'TOZO', 'Monster', 'Weofly', 'Jiayou', 'Unikyy'],
            'Soundbar' => [],
            'Webcam' => ['A4TECH', 'Logitech', 'Asus', 'Havit', 'Fantech', 'AVerMedia', 'EMEET', 'Redragon', 'Rapoo', 'Magpie', 'Verbatim'],
            'Cable' => ['Micro USB Cable', 'USB Cable', 'Type-C Cable', 'Audio Cable', 'HDMI Cable', 'VGA Cable', 'DisplayPort Cable', 'Lightning Cable', 'Printer Cable', 'Cable Organizer'],
            'Converter' => ['USB Converter', 'Audio Converter', 'Type-C Converter', 'HDMI Converter', 'VGA Converter', 'DisplayPort Converter', 'DVI Converter'],
            'Card Reader' => [],
            'Hubs & Docks' => [],
            'Microphone' => ['Maono', 'BOYA', 'Fantech', 'Audio Technica', 'RODE', 'Elgato', 'Sennheiser', 'AVerMedia', 'Havit', 'Hollyland', 'Logitech', 'K&F Concept', 'AKG', 'Redragon', 'SYNCO', 'Saramonic', 'MIRFAK', 'Rapoo', 'Neumann', 'FIFINE', 'DJI', 'Asus', 'Hoco', 'Ulanzi', 'A4Tech', 'SteelSeries', 'Onikuma', 'MeeTion', 'BWOO', 'Acefast'],
            'Digital Voice Recorder' => ['Sony', 'ZOOM', 'Boya', 'Philips'],
            'Presenter' => ['Logitech', 'Inphic', 'Rapoo', 'Micropack', 'Baseus', 'XO'],
            'Memory Card' => ['Jovision', 'PNY', 'SanDisk', 'Transcend', 'Apacer', 'Lexar', 'Adata', 'Sony', 'TwinMOS', 'Nikon', 'Dahua', 'Smart', 'Kingston'],
            'Capture Card' => ['AVerMedia', 'UGREEN', 'Elgato', 'Onten'],
            'Pen Drive' => ['TEAM', 'Transcend', 'TWINMOS', 'ADATA', 'SanDisk', 'Kingston', 'Apacer', 'Lexar', 'Dahua', 'Netac', 'Smart', 'Hiksemi', 'OSCOO'],
            'Thermal Paste' => [],
            'HDD-SSD Enclosure' => ['Orico', 'UGREEN', 'Yuanxin', 'Patriot', 'OSCOO', 'OCPC', 'Jeyi', 'Onten'],
            'Power Strip' => ['Deli', 'Huntkey', 'Belkin', 'Baseus', 'Aptech'],
            'Bluetooth Adapter' => [],
            'Monitor Light Bar' => [],
        ],

        'Gadget' => [
            'Daily Lifestyle' => ['Weight Scale', 'Hair Dryer', 'Hair Straightener', 'Electric Toothbrush', 'GPS Tracker', 'Mosquito Bat', 'Torch Light', 'Table Lamp', 'Massage Gun'],
            'Smart Watch' => ['Amazfit', 'Apple', 'Black Shark', 'boAt', 'COLMI', 'DIZO', 'Google', 'Havit', 'Haylou', 'HiFuture', 'HUAWEI', 'IMILAB', 'Kieslect', 'KOSPET', 'OnePlus', 'Oraimo', 'QCY', 'Realme', 'RIVERSONG', 'Samsung', 'Titan', 'WiWU', 'Xiaomi', 'XTRA', 'Yison', 'Zeblaze', 'Remax', 'Joyroom', 'Awei', 'MOVR', 'Tecno', 'BWOO', 'CHARG', 'Unikyy', 'XO'],
            'Smart Band' => [],
            'Analog Watch' => [],
            'Earphone' => ['Acefast', 'Baseus', 'BLON', 'CHARG', 'Edifier', 'FONENG', 'JBL', 'JOYROOM', 'KZ', 'Oraimo', 'RECCI', 'RIVERSONG', 'SteelSeries', 'Thonet & Vander', 'Xiaomi', 'Yison', 'OnePlus', 'Jiayou', 'WiWU'],
            'Earbuds' => ['Anker', 'Apple', 'CHARG', 'BWOO', 'Awei', 'Baseus', 'EMEET', 'EarFun', 'Edifier', 'Fantech', 'FONENG', 'Havit', 'Haylou', 'HiFuture', 'Hoco', 'IMILAB', 'Jabra', 'JBL', 'JOYROOM', 'Mibro', 'OnePlus', 'Oraimo', 'QCY', 'Realme', 'RECCI', 'RIVERSONG', 'Samsung', 'Sony', 'SoundPEATS', 'UGREEN', 'Vyvylabs', 'WiWU', 'XINJI', 'Yison', 'Choetech', 'cmf', 'SteelSeries', 'Onikuma', 'Tecno', 'Vention', 'TOZO', 'Monster', 'Weofly', 'Jiayou', 'Onten', 'Unikyy', 'XO'],
            'Neckband' => ['CHARG', 'DIZO', 'Awei', 'Havit', 'HiFuture', 'Hoco', 'OnePlus', 'Oraimo', 'Realme', 'RIVERSONG', 'Wavefun', 'Yison', 'QCY', 'FONENG', 'cmf', 'Thonet & Vander', 'Acefast', 'Microlab', 'TOZO', 'Onikuma', 'Unikyy'],
            'Trimmer' => ['Panasonic', 'Philips', 'DIZO', 'Kemei', 'ENCHEN', 'VGR', 'Xiaomi', 'Oraimo', 'HTC', 'Hoco'],
            'Smart Ring' => [],
            'Smart Glasses' => [],
            'Power Bank' => ['Anker', 'Awei', 'Baseus', 'Hoco', 'JOYROOM', 'Oraimo', 'RECCI', 'Vyvylabs', 'UGREEN', 'WiWU', 'Yison', 'Vention', 'Fantech', 'FONENG', 'Charg', 'BWOO', 'ACEFAST', 'QCY', 'BYZ', 'Jiayou'],
            'Car Charger' => [],
            'Mini Fan' => [],
            'Health Monitor' => [],
            'TV Box' => [],
            'Studio Equipment' => ['Studio Microphones', 'Studio Monitors', 'Studio Headphones', 'Audio Interfaces', 'Switcher'],
            'Drones' => [],
        ],
    ];

    private int $count = 0;

    public function run(): void
    {
        // cascadeOnDelete on products.category_id means this takes the whole
        // catalogue with it. Announced rather than silent, because a seeder
        // that quietly empties a products table is how a shop loses a morning.
        $products = Product::count();
        $this->command?->warn("Clearing {$products} product(s) and the existing category tree.");

        Schema::withoutForeignKeyConstraints(function () {
            ProductSpecification::query()->delete();
            ProductImage::query()->delete();
            Product::query()->delete();
            Category::query()->delete();
        });

        foreach (self::TREE as $name => $children) {
            $this->plant($name, $children, null);
        }

        $this->command?->info("Star Tech taxonomy seeded: {$this->count} categories.");
        $this->command?->comment('Run TaxonomyDemoProductsSeeder next, or the menu stays empty — CategoryService hides categories holding no products.');
    }

    /**
     * @param  array<mixed>  $children
     */
    private function plant(string $name, array $children, ?Category $parent): void
    {
        $category = Category::create([
            'slug' => $this->slugFor($name, $parent),
            'name' => $name,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ]);

        $this->count++;

        // A list of strings is a row of leaves; an associative array nests.
        foreach ($children as $key => $value) {
            is_int($key)
                ? $this->plant($value, [], $category)
                : $this->plant($key, $value, $category);
        }
    }

    /**
     * Brand names repeat relentlessly — ASUS is a child of eleven different
     * subcategories — so a bare slug collides almost immediately. Prefixing
     * with the parent keeps every one unique and readable:
     * `graphics-card-asus`, `casing-asus`, `laptop-bag-asus`.
     */
    private function slugFor(string $name, ?Category $parent): string
    {
        $base = Str::slug($name) ?: Str::slug(Str::ascii($name));

        if (! $parent) {
            return $base;
        }

        $candidate = "{$parent->slug}-{$base}";

        return Category::where('slug', $candidate)->exists()
            ? "{$candidate}-{$this->count}"
            : $candidate;
    }
}
