import {
    Cpu,
    Server,
    Layers,
    HardDrive,
    Monitor,
    Zap,
    Box,
    Wind,
    Tv,
    Keyboard,
    Mouse,
    Headphones,
    Wifi,
    ShieldCheck,
    BatteryCharging,
    Speaker,
    Camera,
    Gamepad2,
    Cable,
    Printer,
    Fan,
    Usb,
    MemoryStick,
    Package,
} from 'lucide-react';

/**
 * The icons a PC Builder part can wear, by the name the server stores.
 *
 * One list for the builder and the admin that picks from it. The builder had
 * its own nine, so Keyboard, Mouse, Headphone and Router all fell back to a
 * processor chip. Kept in step with PcBuilderSlot::ICONS.
 */
export const PC_BUILDER_ICONS = {
    Cpu,
    Server,
    Layers,
    HardDrive,
    Monitor,
    Zap,
    Box,
    Wind,
    Tv,
    Keyboard,
    Mouse,
    Headphones,
    Wifi,
    ShieldCheck,
    BatteryCharging,
    Speaker,
    Camera,
    Gamepad2,
    Cable,
    Printer,
    Fan,
    Usb,
    MemoryStick,
    Package,
};

/** The icon for a part, or a plain box for a name it does not know. */
export const pcBuilderIcon = (name) => PC_BUILDER_ICONS[name] ?? Package;
