<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Rule fragments shared by the create and edit product forms.
 *
 * They were copied between the two, and had already drifted: the edit form
 * accepted a `preorder_limit` the create form did not.
 */
class ProductRules
{
    /**
     * Barcodes have to be unique across both tables, and the rule builder
     * cannot say that for a `variants.*` array: each row needs to ignore its
     * own id, and rows within the one submission have to be checked against
     * each other as well as against the table.
     *
     * Without this a repeated barcode reaches the unique index and comes back
     * as a 500, which tells the person typing it nothing.
     */
    public static function checkBarcodes($validator, array $variants, ?int $productId = null): void
    {
        $seen = [];

        foreach ($variants as $i => $variant) {
            $code = trim((string) ($variant['barcode'] ?? ''));

            if ($code === '') {
                continue;
            }

            if (isset($seen[$code])) {
                $validator->errors()->add(
                    "variants.{$i}.barcode",
                    "Barcode {$code} is on more than one option here. Each box has its own number."
                );

                continue;
            }

            $seen[$code] = true;

            $takenByVariant = ProductVariant::where('barcode', $code)
                ->when($variant['id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();

            $takenByProduct = Product::where('barcode', $code)
                ->when($productId, fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();

            if ($takenByVariant || $takenByProduct) {
                $validator->errors()->add(
                    "variants.{$i}.barcode",
                    "Barcode {$code} is already on something else."
                );
            }
        }
    }

    /**
     * Selling ahead of a delivery, decided per product. The limit is how far the
     * balance may go below zero: without one a single scripted buyer can commit
     * the shop to any number of units, so it is worth setting even though it is
     * optional.
     *
     * @return array<string, mixed>
     */
    public static function preorder(): array
    {
        return [
            'allow_preorder' => 'nullable|boolean',
            'preorder_limit' => 'nullable|integer|min:1|max:100000',
            'preorder_release_at' => 'nullable|date',
        ];
    }

    /**
     * The spec sheet, and the two fields that belong beside it.
     *
     * `specifications` is sent whole and replaces what is stored, so an absent
     * key means "not editing these" while an empty array means "remove them
     * all" — see ProductController::syncSpecifications.
     *
     * A blank `group` is allowed: a mouse has six specs and needs no headings.
     *
     * @return array<string, mixed>
     */
    public static function details(): array
    {
        return [
            'description' => 'nullable|string',

            // Months rather than a date, because the clock starts when the
            // customer buys it, not when the shop lists it. 600 is fifty years:
            // high enough for a "lifetime" claim, low enough to catch a typo
            // where someone meant days.
            'warranty_months' => 'nullable|integer|min:0|max:600',

            /*
             * The sentence, where the number is not the whole story — a laptop
             * is routinely "2 Years warranty (Battery & Adapter 1 Year)". Shown
             * to the customer in preference to the months when both are set.
             */
            'warranty_text' => 'nullable|string|max:255',

            /*
             * Written for a search result, not for the page. Lengths match what
             * Google will actually render before truncating — a longer one is
             * not wrong, it just will not be seen.
             */
            /*
             * Not `barcode`. That is the number on this shop's box, scanned at
             * a delivery. The MPN is the manufacturer's own part number — what
             * a customer pastes into Google to check the revision — and the
             * model is what they say out loud at the counter. Neither is
             * unique: two sellers list the same MPN, and an 8GB and a 16GB
             * build share a model string.
             */
            'model' => 'nullable|string|max:255',
            'mpn' => 'nullable|string|max:120',

            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'meta_keyword' => 'nullable|string|max:500',

            /*
             * Extra categories to list the product under, beyond its primary
             * one. The primary is added back regardless, so a caller cannot
             * take a product out of its own breadcrumb by omitting it.
             */
            'category_ids' => 'nullable|array|max:30',
            'category_ids.*' => 'integer|exists:categories,id',

            /*
             * The bullet list above the fold. HTML, and cleaned through
             * RichText like the description — it is written by staff, but
             * "written by staff" has never been a reason to trust markup.
             */
            'key_features' => 'nullable|string|max:4000',

            /*
             * A discount that only applies to paying at once. Cannot exceed the
             * price it is taken off, or the shop pays the customer.
             */
            'checkout_discount' => 'nullable|numeric|min:0|lte:price',

            'emi_available' => 'nullable|boolean',
            // Twelve, twenty-four, thirty-six are the terms banks here offer;
            // the cap allows for longer without allowing nonsense.
            'emi_max_months' => 'nullable|integer|min:3|max:60',

            /*
             * Said only when the shelf is empty. Free text rather than an enum,
             * because "Pre-Order", "2-3 Days" and "Call for Price" are the
             * shop's words, not the developer's.
             */
            'out_of_stock_status' => 'nullable|string|max:60',

            /*
             * A sale that ends by itself. Both optional: no dates means "until
             * somebody changes it", which is what every discount was before
             * scheduling existed.
             */
            'discount_starts_at' => 'nullable|date',
            'discount_ends_at' => 'nullable|date|after:discount_starts_at',

            // Cable by the metre, paste by the sachet. One is the normal case.
            'min_order_quantity' => 'nullable|integer|min:1|max:10000',

            /*
             * Buy-more-pay-less tiers. Sent whole and replacing what is stored,
             * like specifications — a tier the admin deleted is one that is
             * simply absent.
             */
            'quantity_discounts' => 'nullable|array|max:10',
            'quantity_discounts.*.min_quantity' => 'required_with:quantity_discounts|integer|min:2|max:100000',
            'quantity_discounts.*.price' => 'required_with:quantity_discounts|numeric|min:0',

            'related_product_ids' => 'nullable|array|max:12',
            'related_product_ids.*' => 'integer|exists:products,id',

            'specifications' => 'nullable|array|max:200',
            'specifications.*.group' => 'nullable|string|max:80',
            'specifications.*.name' => 'required_with:specifications.*.value|nullable|string|max:120',
            'specifications.*.value' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Options. Stock is deliberately absent from every one of these: editing a
     * product must never move a unit.
     *
     * @return array<string, mixed>
     */
    public static function variants(): array
    {
        return [
            'has_variants' => 'nullable|boolean',
            'variant_attributes' => 'nullable|array',
            'variant_attributes.*' => 'string|max:60',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.options' => 'nullable|array',
            'variants.*.name' => 'nullable|string|max:180',
            'variants.*.sku' => 'nullable|string|max:80',
            // 16GB and 32GB of the same stick are different boxes with
            // different numbers, so a variant carries its own.
            'variants.*.barcode' => 'nullable|string|max:64',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.discount_price' => 'nullable|numeric|min:0',
            'variants.*.image_url' => 'nullable|string|max:2048',
            'variants.*.reorder_level' => 'nullable|integer|min:0|max:100000',
            'variants.*.is_active' => 'nullable|boolean',
            // Only read when switching a single product over to options, where it
            // says how the existing shelf is split. It never adds stock.
            'variants.*.opening_stock' => 'nullable|integer|min:0',
        ];
    }
}
