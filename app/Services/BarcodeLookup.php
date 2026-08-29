<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;

/**
 * What was just scanned.
 *
 * A handheld scanner is a keyboard: it types the code and presses Enter. There
 * is no camera to drive and no library to pull in — the whole job is turning
 * the string it typed into a row, which means knowing the three things it
 * could be.
 *
 * A product's own barcode, a variant's — 16GB and 32GB of the same stick are
 * different boxes with different numbers — or a serial number, because on a
 * graphics card the sticker somebody scans is as likely to be the serial as
 * the retail barcode, and answering "not found" to a number we hold is the
 * kind of thing that makes people stop using the scanner.
 */
class BarcodeLookup
{
    /**
     * @return array{
     *     found: bool, matched_on: ?string,
     *     product_id: ?int, product_variant_id: ?int,
     *     name: ?string, barcode: ?string, serial: ?string
     * }
     */
    public function find(string $code): array
    {
        $code = self::normalise($code);

        if ($code === '') {
            return self::nothing();
        }

        if ($variant = ProductVariant::with('product:id,name')->where('barcode', $code)->first()) {
            return self::hit(
                'variant',
                $variant->product_id,
                $variant->id,
                trim(($variant->product?->name ?? 'Unknown product')." ({$variant->name})"),
                $code
            );
        }

        if ($product = Product::where('barcode', $code)->first(['id', 'name'])) {
            return self::hit('product', $product->id, null, $product->name, $code);
        }

        /*
         * A serial identifies one unit, so it also identifies what that unit
         * is. Useful at a stock take, where somebody scanning a shelf of
         * graphics cards will hit serial stickers as often as retail barcodes.
         */
        $serial = ProductSerial::with(['product:id,name', 'variant:id,name'])
            ->where('serial', ProductSerial::normalise($code))
            ->first();

        if ($serial) {
            $name = $serial->variant
                ? "{$serial->product?->name} ({$serial->variant->name})"
                : ($serial->product?->name ?? 'Unknown product');

            return self::hit('serial', $serial->product_id, $serial->product_variant_id, $name, null, $serial->serial);
        }

        return self::nothing();
    }

    /**
     * Scanners send trailing whitespace and sometimes a carriage return; some
     * are configured to add a prefix character. Trim and drop anything that is
     * not printable rather than failing a lookup on invisible characters.
     */
    public static function normalise(?string $code): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '', (string) $code) ?? (string) $code;

        return trim($clean);
    }

    private static function hit(
        string $on,
        ?int $productId,
        ?int $variantId,
        ?string $name,
        ?string $barcode = null,
        ?string $serial = null
    ): array {
        return [
            'found' => true,
            'matched_on' => $on,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'name' => $name,
            'barcode' => $barcode,
            'serial' => $serial,
        ];
    }

    private static function nothing(): array
    {
        return [
            'found' => false, 'matched_on' => null,
            'product_id' => null, 'product_variant_id' => null,
            'name' => null, 'barcode' => null, 'serial' => null,
        ];
    }
}
