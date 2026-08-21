import React from 'react';
import {
    Cpu,
    Laptop,
    Monitor,
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
} from 'lucide-react';

/**
 * Universal Icon Registry for Categories & Subcategories (SSOT)
 */
export const ICON_REGISTRY = {
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
    macbook: Laptop,
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

    // Desktops & Workstations
    desktop: Server,
    desktops: Server,
    pc: Server,
    server: Server,
    workstation: Server,
    workstations: Server,
    gamingpc: Server,
    rig: Server,

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
    const priorityKeywords = [
        ['laptop', ICON_REGISTRY.laptop],
        ['notebook', ICON_REGISTRY.laptop],
        ['macbook', ICON_REGISTRY.laptop],
        ['thinkpad', ICON_REGISTRY.laptop],
        ['ultrabook', ICON_REGISTRY.laptop],
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
    ];

    for (const [keyword, IconComp] of priorityKeywords) {
        if (searchKey.includes(keyword) || normalized.includes(keyword)) {
            return <IconComp {...props} />;
        }
    }

    const DefaultIcon = ICON_REGISTRY.default;
    return <DefaultIcon {...props} />;
};

export default getCategoryIcon;
