<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

/**
 * Writing a product's photos, and each option's.
 *
 * A gallery arrives from the form as a plain ordered list. Turning that into
 * rows has a few rules that are easy to get subtly wrong, and getting them
 * wrong is invisible until a customer opens the product page:
 *
 *  - exactly one photo leads. Zero means the page falls back to a placeholder
 *    while real photos sit behind it; two means the lead is whichever the
 *    database happens to return first, which can change between requests.
 *  - order is the order given, renumbered from zero, so removing the second of
 *    five does not leave a hole that later sorts by id instead.
 *  - a row already in the table is updated rather than deleted and recreated,
 *    so its id survives and nothing referencing it breaks.
 *
 * The same three rules apply to an option's photos, so both go through here.
 */
class ProductGalleryService
{
    /**
     * Replace a product's own gallery.
     *
     * @param  array<int, array<string, mixed>>  $images
     */
    public function syncProduct(Product $product, array $images): void
    {
        $this->sync($product->id, null, $images);
    }

    /**
     * Replace one option's gallery, and keep its lead shot on the variant row.
     *
     * @param  array<int, array<string, mixed>>  $images
     */
    public function syncVariant(ProductVariant $variant, array $images): void
    {
        $this->sync($variant->product_id, $variant->id, $images);

        /*
         * `image_url` stays the column everything else reads — the cart line,
         * the order line, the storefront listings, the admin table. Keeping it
         * in step with the first photo means options gain a gallery without
         * every one of those readers having to learn about a new table.
         */
        $lead = $this->normalise($images)[0]['image_path'] ?? null;

        if ($variant->image_url !== $lead) {
            $variant->forceFill(['image_url' => $lead])->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     */
    private function sync(int $productId, ?int $variantId, array $images): void
    {
        $rows = $this->normalise($images);

        $existing = ProductImage::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->get()
            ->keyBy('id');

        $kept = [];

        foreach ($rows as $position => $row) {
            $id = $row['id'] ?? null;
            $attributes = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'image_path' => $row['image_path'],
                'alt_text' => $row['alt_text'] ?? null,
                'is_primary' => $position === 0,
                'position' => $position,
            ];

            // Updated in place where the row is already ours, so ids survive an
            // edit that only reorders the gallery.
            if ($id && $existing->has($id)) {
                $existing->get($id)->forceFill($attributes)->save();
                $kept[] = $id;

                continue;
            }

            $kept[] = ProductImage::create($attributes)->id;
        }

        ProductImage::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->whereNotIn('id', $kept ?: [0])
            ->delete();
    }

    /**
     * Drop the blanks and the duplicates, and put the lead shot first.
     *
     * A gallery arriving with two rows flagged primary, or none, is not a
     * validation error worth refusing a save over — it is a form that lost
     * track. The first flagged photo leads; failing that, the first photo does.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function normalise(array $images): array
    {
        $rows = [];
        $seen = [];

        foreach ($images as $image) {
            $path = trim((string) ($image['image_path'] ?? ''));

            // The same file twice is a gallery with a repeated thumbnail, and
            // a customer clicking between two identical shots.
            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $rows[] = [
                'id' => isset($image['id']) ? (int) $image['id'] : null,
                'image_path' => $path,
                'alt_text' => isset($image['alt_text']) && trim((string) $image['alt_text']) !== ''
                    ? trim((string) $image['alt_text'])
                    : null,
                'is_primary' => (bool) ($image['is_primary'] ?? false),
            ];
        }

        $leadIndex = null;

        foreach ($rows as $index => $row) {
            if ($row['is_primary']) {
                $leadIndex = $index;
                break;
            }
        }

        if ($leadIndex !== null && $leadIndex > 0) {
            array_unshift($rows, array_splice($rows, $leadIndex, 1)[0]);
        }

        return array_values($rows);
    }
}
