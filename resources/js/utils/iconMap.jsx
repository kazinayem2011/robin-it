import React from 'react';
import AppleLogo from '../Components/Icons/AppleLogo';
import {
    BrainCircuit,
    Briefcase,
    Building2,
    Cpu,
    Laptop,
    Monitor,
    MonitorSpeaker,
    HardDrive,
    MemoryStick,
    Zap,
    Wind,
    Box,
    CircuitBoard,
    Server,
    Wifi,
    Headphones,
    Mouse,
    Keyboard,
    Gamepad2,
    Camera,
    Printer,
    Speaker,
    Armchair,
    Shield,
    Tv,
    Smartphone,
    Layers,
    Sparkles,
    Package,
    Folder,
    Tablet,
    Watch,
    BatteryCharging,
    Plug,
    Cable,
    Lock,
    Video,
    ScanLine,
    Projector,
    FileText,
    AppWindow,
    Usb,
    Disc,
    Mic,
    Sun,
    Wrench,
    Presentation,
    Droplets,
    HardDriveDownload,
    Bluetooth,
    Aperture,
    Car,
    Focus,
    Hand,
    PcCase,
    PenTool,
    Star,
} from 'lucide-react';

/**
 * Universal Icon Registry for Categories & Subcategories (SSOT)
 */
export const ICON_REGISTRY = {
    /*
     * Added when the catalogue grew from nine top-level categories to fifteen.
     * Before this, 62% of subcategories and a third of top-level entries fell
     * through to the generic folder — a menu where most rows carry the same
     * glyph is a menu where the glyph means nothing.
     */
    tablet: Tablet,
    ipad: AppleLogo,
    watch: Watch,
    smartwatch: Watch,
    smartband: Watch,
    gadget: Watch,
    ups: BatteryCharging,
    battery: BatteryCharging,
    inverter: Plug,
    stabilizer: Plug,
    solar: Sun,
    cable: Cable,
    converter: Cable,
    adapter: Cable,
    lock: Lock,
    dvr: Video,
    nvr: Video,
    barcode: ScanLine,
    projector: Projector,
    software: AppWindow,
    antivirus: Shield,
    os: AppWindow,
    office: FileText,
    printer2: Printer,
    toner: Printer,
    cartridge: Printer,
    usb: Usb,
    pendrive: Usb,
    casing: Box,
    odd: Disc,
    microphone: Mic,
    tool: Wrench,
    signage: Presentation,
    liquid: Droplets,
    enclosure: HardDriveDownload,
    bluetooth: Bluetooth,
    stylus: PenTool,
    accessories: Package,
    earbuds: Headphones,
    earbud: Headphones,
    drone: Camera,
    drones: Camera,
    iphone: AppleLogo,
    // Processors & Components
    cpu: Cpu,
    processor: Cpu,
    processors: Cpu,
    components: Cpu,
    component: Cpu,
    intel: Cpu,
    amd: Cpu,
    ryzen: Cpu,
    core: Cpu,

    // Laptops & Portables
    laptop: Laptop,
    laptops: Laptop,
    notebook: Laptop,
    notebooks: Laptop,
    ultrabook: Laptop,
    ultrabooks: Laptop,
    macbook: AppleLogo,
    thinkpad: Laptop,

    // Monitors & Displays
    monitor: Monitor,
    monitors: Monitor,
    display: Monitor,
    displays: Monitor,
    screen: Monitor,
    oled: Monitor,
    ips: Monitor,
    tv: Tv,

    // Graphics Cards (GPU)
    gpu: Monitor,
    graphics: Monitor,
    graphicscard: Monitor,
    graphicscards: Monitor,
    rtx: Monitor,
    radeon: Monitor,

    // Motherboards
    motherboard: CircuitBoard,
    motherboards: CircuitBoard,
    circuitboard: CircuitBoard,
    mainboard: CircuitBoard,

    // Memory (RAM)
    ram: MemoryStick,
    memory: MemoryStick,
    memorystick: MemoryStick,
    ddr: MemoryStick,
    ddr4: MemoryStick,
    ddr5: MemoryStick,

    // Storage
    storage: HardDrive,
    ssd: HardDrive,
    hdd: HardDrive,
    harddrive: HardDrive,
    nvme: HardDrive,
    drive: HardDrive,

    // Power & Cooling
    powersupply: Zap,
    psu: Zap,
    power: Zap,
    zap: Zap,
    cooler: Wind,
    cpucooler: Wind,
    cooling: Wind,
    aio: Wind,
    fan: Wind,
    wind: Wind,

    // Cases & Chassis
    pccase: Box,
    case: Box,
    chassis: Box,
    tower: Box,
    box: Box,

    /*
     * Desktops & Workstations.
     *
     * A tower, not a rack. `Server` is a stack of rack units and belongs to
     * the Server & Storage aisle; on a desktop it read as networking kit —
     * and because every machine shape below pointed at this one key, all ten
     * rows of the Desktop menu wore it, which is the failure this file's own
     * header warns about.
     */
    /*
     * A screen beside a tower — the two things a desktop is, and the only
     * glyph here that says "desktop" rather than "a box". `Server` was a rack
     * stack, `PcCase` the tower alone and `Computer` two stacked boxes; all
     * three read as something else at 16px next to the plain screen the
     * Monitor aisle wears.
     */
    desktop: MonitorSpeaker,
    desktops: MonitorSpeaker,
    pc: MonitorSpeaker,
    server: Server,
    workstation: MonitorSpeaker,
    workstations: MonitorSpeaker,
    rig: PcCase,

    // The shapes a desktop actually comes in, each its own thing.
    gamingpc: Gamepad2,
    aipc: BrainCircuit,
    starpc: Star,
    brandpc: Building2,
    allinone: Monitor,
    minipc: Box,
    // The Apple machines are one family and wear the maker's own mark. ⌘ was
    // standing in for it, which is a key on the keyboard, not the maker.
    mac: AppleLogo,
    apple: AppleLogo,
    imac: AppleLogo,

    // Networking
    networking: Wifi,
    router: Wifi,
    routers: Wifi,
    wifi: Wifi,
    network: Wifi,
    switch: Wifi,
    accesspoint: Wifi,

    // Audio & Sound
    audio: Headphones,
    sound: Headphones,
    headset: Headphones,
    headsets: Headphones,
    headphones: Headphones,
    earphone: Headphones,
    earphones: Headphones,
    speaker: Speaker,
    speakers: Speaker,
    soundbar: Speaker,

    // Peripherals & Gaming
    peripherals: Mouse,
    gaminggear: Gamepad2,
    gaming: Gamepad2,
    gamepad: Gamepad2,
    console: Gamepad2,
    mouse: Mouse,
    mice: Mouse,
    keyboard: Keyboard,
    keyboards: Keyboard,

    // Office, Security & Furniture
    camera: Camera,
    cctv: Camera,
    webcam: Camera,
    security: Shield,
    shield: Shield,
    printer: Printer,
    printers: Printer,
    scanner: Printer,
    chair: Armchair,
    chairs: Armchair,
    armchair: Armchair,
    furniture: Armchair,

    // General & Fallbacks
    mobile: Smartphone,
    phone: Smartphone,
    smartphone: Smartphone,
    layers: Layers,
    sparkles: Sparkles,
    offer: Sparkles,
    offers: Sparkles,
    deal: Sparkles,
    deals: Sparkles,
    package: Package,
    default: Folder,
};

/**
 * Resolves the most accurate Lucide Icon Component for any Category or Subcategory.
 * @param {string|object} input - Category object, icon name string, or slug
 * @param {object} props - Props passed directly to the Icon (e.g. size, className, color)
 */
/**
 * Keyword heuristics, most specific first — the loop takes the first hit, so
 * 'laptop bag' has to be tried before 'laptop'.
 *
 * Module scope rather than inside getCategoryIcon, because hasCategoryIcon
 * has to consult exactly the same list. Two copies would drift, and the drift
 * would show as a category drawing an icon the predicate said it did not have.
 */
const KEYWORD_LIST_WITH_ICONS = [
    /*
     * Apple first, and above every shape below it.
     *
     * A MacBook is a laptop and an iPad is a tablet, so the shape rules had
     * them — but in the Laptop menu every row is already a laptop, and the
     * glyph that tells a shopper something there is whose it is. Whole words
     * throughout, so this cannot reach into another maker's name.
     */
    ['apple', ICON_REGISTRY.mac],
    ['macbook', ICON_REGISTRY.mac],
    ['imac', ICON_REGISTRY.mac],
    ['ipad', ICON_REGISTRY.mac],
    ['iphone', ICON_REGISTRY.mac],
    ['ipod', ICON_REGISTRY.mac],
    ['airpods', ICON_REGISTRY.mac],
    ['mac studio', ICON_REGISTRY.mac],
    ['mac mini', ICON_REGISTRY.mac],
    ['mac pro', ICON_REGISTRY.mac],

    /*
     * The specific kind before the generic word, because the first match wins.
     * Without these, every row under Laptop, Monitor and Camera matched the
     * bare category name and the whole menu came out wearing one glyph.
     */
    ['laptop bag', Briefcase],
    ['laptop accessor', ICON_REGISTRY.accessories],
    ['gaming laptop', ICON_REGISTRY.gaming],
    ['monitor arm', Wrench],
    ['monitor brand', Building2],
    ['gaming monitor', ICON_REGISTRY.gaming],
    ['touch monitor', Hand],
    ['portable monitor', Tablet],
    ['curved monitor', Tv],
    ['4k monitor', ICON_REGISTRY.monitor],
    ['camera lens', Aperture],
    ['camera accessor', ICON_REGISTRY.accessories],
    ['camera tripod', Focus],
    ['action camera', Focus],
    ['video camera', Video],
    ['handycam', Video],
    ['dash cam', Car],
    ['body camera', Video],
    ['gimbal', Focus],
    ['instant camera', ICON_REGISTRY.printer],
    ['mirrorless', Aperture],

    ['laptop', ICON_REGISTRY.laptop],
    ['notebook', ICON_REGISTRY.laptop],
    ['thinkpad', ICON_REGISTRY.laptop],
    ['ultrabook', Sparkles],
    ['processor', ICON_REGISTRY.cpu],
    ['cpu', ICON_REGISTRY.cpu],
    ['intel', ICON_REGISTRY.cpu],
    ['ryzen', ICON_REGISTRY.cpu],
    ['gpu', ICON_REGISTRY.gpu],
    ['graphic', ICON_REGISTRY.gpu],
    ['rtx', ICON_REGISTRY.gpu],
    ['radeon', ICON_REGISTRY.gpu],
    ['motherboard', ICON_REGISTRY.motherboard],
    ['mobo', ICON_REGISTRY.motherboard],
    ['z790', ICON_REGISTRY.motherboard],
    ['z890', ICON_REGISTRY.motherboard],
    ['b650', ICON_REGISTRY.motherboard],
    ['ram', ICON_REGISTRY.ram],
    ['ddr', ICON_REGISTRY.ram],
    ['memory', ICON_REGISTRY.ram],
    ['ssd', ICON_REGISTRY.storage],
    ['hdd', ICON_REGISTRY.storage],
    ['storage', ICON_REGISTRY.storage],
    ['nvme', ICON_REGISTRY.storage],
    ['power', ICON_REGISTRY.powersupply],
    ['psu', ICON_REGISTRY.powersupply],
    ['cooler', ICON_REGISTRY.cooler],
    ['cooling', ICON_REGISTRY.cooler],
    ['aio', ICON_REGISTRY.cooler],
    ['fan', ICON_REGISTRY.cooler],
    ['case', ICON_REGISTRY.pccase],
    ['chassis', ICON_REGISTRY.pccase],
    ['tower', ICON_REGISTRY.pccase],
    ['monitor', ICON_REGISTRY.monitor],
    ['display', ICON_REGISTRY.monitor],
    ['oled', ICON_REGISTRY.monitor],
    ['ips', ICON_REGISTRY.monitor],
    ['desktop', ICON_REGISTRY.desktop],
    ['workstation', ICON_REGISTRY.desktop],
    ['gaming', ICON_REGISTRY.gaming],
    ['keyboard', ICON_REGISTRY.keyboard],
    ['mouse', ICON_REGISTRY.mouse],
    ['headphone', ICON_REGISTRY.audio],
    ['headset', ICON_REGISTRY.audio],
    ['earphone', ICON_REGISTRY.audio],
    ['sound', ICON_REGISTRY.audio],
    ['speaker', ICON_REGISTRY.speaker],
    ['router', ICON_REGISTRY.networking],
    ['wifi', ICON_REGISTRY.networking],
    ['network', ICON_REGISTRY.networking],
    ['printer', ICON_REGISTRY.printer],
    ['camera', ICON_REGISTRY.camera],
    ['cctv', ICON_REGISTRY.camera],
    ['chair', ICON_REGISTRY.chair],
    ['component', ICON_REGISTRY.components],
    ['offer', ICON_REGISTRY.offer],
    ['deal', ICON_REGISTRY.offer],

    /*
     * The fifteen-category taxonomy. Ordered most specific first, because
     * the loop takes the first hit: "laptop bag" must not be caught by
     * "laptop", and "graphics tablet" must not be caught by "graphics".
     */
    ['laptop bag', ICON_REGISTRY.accessories],
    ['graphics tablet', ICON_REGISTRY.stylus],
    ['stylus', ICON_REGISTRY.stylus],
    ['tablet', ICON_REGISTRY.tablet],

    ['smart watch', ICON_REGISTRY.watch],
    ['smartwatch', ICON_REGISTRY.watch],
    ['smart band', ICON_REGISTRY.watch],
    ['watch', ICON_REGISTRY.watch],

    ['power bank', ICON_REGISTRY.battery],
    ['power supply', ICON_REGISTRY.powersupply],
    ['power station', ICON_REGISTRY.battery],
    ['power strip', ICON_REGISTRY.adapter],
    ['ups', ICON_REGISTRY.ups],
    ['ips', ICON_REGISTRY.ups],
    ['inverter', ICON_REGISTRY.inverter],
    ['stabilizer', ICON_REGISTRY.inverter],
    ['solar', ICON_REGISTRY.solar],
    ['charger', ICON_REGISTRY.adapter],

    ['type-c', ICON_REGISTRY.cable],
    ['hdmi', ICON_REGISTRY.cable],
    ['cable', ICON_REGISTRY.cable],
    ['converter', ICON_REGISTRY.converter],
    ['patch', ICON_REGISTRY.cable],
    ['connector', ICON_REGISTRY.cable],

    ['pen drive', ICON_REGISTRY.usb],
    ['pendrive', ICON_REGISTRY.usb],
    ['usb', ICON_REGISTRY.usb],
    ['enclosure', ICON_REGISTRY.enclosure],
    ['card reader', ICON_REGISTRY.usb],
    ['memory card', ICON_REGISTRY.storage],
    ['hard disk', ICON_REGISTRY.storage],
    ['optical disk', ICON_REGISTRY.odd],

    ['router', ICON_REGISTRY.router],
    ['switch', ICON_REGISTRY.networking],
    ['access point', ICON_REGISTRY.networking],
    ['onu', ICON_REGISTRY.networking],
    ['olt', ICON_REGISTRY.networking],
    ['starlink', ICON_REGISTRY.networking],
    ['lan', ICON_REGISTRY.networking],

    ['door lock', ICON_REGISTRY.lock],
    ['access control', ICON_REGISTRY.lock],
    ['locker', ICON_REGISTRY.lock],
    ['dvr', ICON_REGISTRY.cctv],
    ['nvr', ICON_REGISTRY.cctv],
    ['xvr', ICON_REGISTRY.cctv],
    ['security', ICON_REGISTRY.security],
    ['surveillance', ICON_REGISTRY.cctv],

    ['barcode', ICON_REGISTRY.barcode],
    ['scanner', ICON_REGISTRY.scanner],
    ['projector', ICON_REGISTRY.projector],
    ['signage', ICON_REGISTRY.signage],
    ['kiosk', ICON_REGISTRY.signage],
    ['flat panel', ICON_REGISTRY.signage],
    ['video wall', ICON_REGISTRY.signage],
    ['toner', ICON_REGISTRY.toner],
    ['cartridge', ICON_REGISTRY.cartridge],
    ['ink', ICON_REGISTRY.cartridge],
    ['ribbon', ICON_REGISTRY.cartridge],
    ['photocopier', ICON_REGISTRY.printer2],
    ['shredder', ICON_REGISTRY.tool],
    ['laminating', ICON_REGISTRY.tool],
    ['binding', ICON_REGISTRY.tool],
    ['counting machine', ICON_REGISTRY.tool],
    ['cash drawer', ICON_REGISTRY.tool],
    ['telephone', ICON_REGISTRY.phone],
    ['pabx', ICON_REGISTRY.phone],
    ['ip phone', ICON_REGISTRY.phone],
    ['conference', ICON_REGISTRY.signage],
    ['pa system', ICON_REGISTRY.speaker],
    ['office', ICON_REGISTRY.office],

    ['operating system', ICON_REGISTRY.os],
    ['antivirus', ICON_REGISTRY.antivirus],
    ['software', ICON_REGISTRY.software],
    ['cloud', ICON_REGISTRY.software],
    ['typing', ICON_REGISTRY.software],

    ['microphone', ICON_REGISTRY.microphone],
    ['mic', ICON_REGISTRY.microphone],
    ['webcam', ICON_REGISTRY.webcam],
    ['soundbar', ICON_REGISTRY.speaker],
    ['earbud', ICON_REGISTRY.audio],
    ['neckband', ICON_REGISTRY.audio],
    ['bluetooth', ICON_REGISTRY.bluetooth],

    ['casing', ICON_REGISTRY.casing],
    ['liquid cooling', ICON_REGISTRY.liquid],
    ['water', ICON_REGISTRY.liquid],
    ['thermal', ICON_REGISTRY.liquid],
    ['gpu holder', ICON_REGISTRY.pccase],

    ['drone', ICON_REGISTRY.camera],
    ['gimbal', ICON_REGISTRY.camera],
    ['tripod', ICON_REGISTRY.camera],
    ['dslr', ICON_REGISTRY.camera],
    ['lens', ICON_REGISTRY.camera],

    ['trimmer', ICON_REGISTRY.gadget],
    ['hair', ICON_REGISTRY.gadget],
    ['toothbrush', ICON_REGISTRY.gadget],
    ['massage', ICON_REGISTRY.gadget],
    ['weight scale', ICON_REGISTRY.gadget],
    ['gps', ICON_REGISTRY.gadget],
    ['torch', ICON_REGISTRY.gadget],
    ['lamp', ICON_REGISTRY.gadget],
    ['lifestyle', ICON_REGISTRY.gadget],
    ['mini fan', ICON_REGISTRY.fan],
    ['tv box', ICON_REGISTRY.tv],

    ['phone', ICON_REGISTRY.phone],
    ['mobile', ICON_REGISTRY.phone],
    ['server', ICON_REGISTRY.server],
    ['nas', ICON_REGISTRY.server],
    ['rack', ICON_REGISTRY.server],
    ['accessor', ICON_REGISTRY.accessories],

    // Machine shapes, last: 'pc' is two letters and would otherwise swallow
    // anything containing them.
    ['all-in-one', ICON_REGISTRY.allinone],
    ['mini pc', ICON_REGISTRY.minipc],
    ['imac', ICON_REGISTRY.mac],
    ['mac studio', ICON_REGISTRY.mac],
    ['mac mini', ICON_REGISTRY.mac],
    ['mac pro', ICON_REGISTRY.mac],
    ['ai pc', ICON_REGISTRY.aipc],
    ['brand pc', ICON_REGISTRY.brandpc],
    ['star pc', ICON_REGISTRY.starpc],
    [' pc', ICON_REGISTRY.desktop],
];

const KEYWORD_LIST = KEYWORD_LIST_WITH_ICONS.map(([keyword]) => keyword);

/**
 * Whole words only.
 *
 * Substring matching read "PowerColor" as power, "Microsoft" as micro,
 * "Fantech" as fan and "Philips" as ip — handing seventy-seven brands an icon
 * belonging to something they merely contain the letters of. A brand that gets
 * a wrong icon is worse than one that gets a lettermark, because the wrong icon
 * looks deliberate.
 *
 * Costs three categories that were only matching by accident — iPhone, Earbuds
 * and Drones — each of which now has a key of its own above.
 */
const matchesKeyword = (haystack, keyword) =>
    new RegExp(`\\b${keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`).test(
        haystack,
    );

export const getCategoryIcon = (input, props = {}) => {
    if (!input) {
        const DefaultIcon = ICON_REGISTRY.default;
        return <DefaultIcon {...props} />;
    }

    let searchKey = '';

    if (typeof input === 'object') {
        searchKey =
            `${input.icon || ''} ${input.slug || ''} ${input.name || ''}`.toLowerCase();
    } else {
        searchKey = String(input).toLowerCase();
    }

    // Clean search string
    const normalized = searchKey.replace(/[^a-z0-9]/g, '');

    // 1. Direct key lookup
    if (ICON_REGISTRY[normalized]) {
        const MatchedIcon = ICON_REGISTRY[normalized];
        return <MatchedIcon {...props} />;
    }

    // 2. Keyword heuristic search in priority order

    for (const [keyword, IconComp] of KEYWORD_LIST_WITH_ICONS) {
        if (matchesKeyword(searchKey, keyword)) {
            return <IconComp {...props} />;
        }
    }

    const DefaultIcon = ICON_REGISTRY.default;
    return <DefaultIcon {...props} />;
};

/**
 * Whether this name resolves to a real icon rather than the generic fallback.
 *
 * The menu asks before drawing: a name with no icon of its own is nearly always
 * a brand, and a brand is better served by its own lettermark than by the same
 * folder glyph a thousand other rows are wearing.
 *
 * Depth cannot answer this. Under Phone the brands sit at the second level
 * (Samsung, Redmi); under Component they sit at the third. The name is the only
 * thing that knows.
 */
export const hasCategoryIcon = (input) => {
    const name =
        typeof input === 'object' && input !== null
            ? `${input.icon || ''} ${input.slug || ''} ${input.name || ''}`
            : String(input ?? '');

    const key = name.toLowerCase().trim();
    const normalized = key.replace(/[^a-z0-9]/g, '');

    if (ICON_REGISTRY[normalized]) {
        return true;
    }

    return KEYWORD_LIST.some((keyword) => matchesKeyword(key, keyword));
};

export default getCategoryIcon;
