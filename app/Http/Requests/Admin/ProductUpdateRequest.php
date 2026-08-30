<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class ProductUpdateRequest extends AdminRequest
{
    /**
     * stock_quantity is not accepted here at all. An admin who could type an
     * absolute quantity could save a form opened before a sale and put the sold
     * units back on the shelf; stock moves only through the ledger.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'name' => 'nullable|string|max:255',
            /*
             * The number on the box, for scanning at a count or a delivery.
             * Unique because two products sharing one makes every scan of it a
             * coin toss; blank is fine, since plenty of stock has none.
             */
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($this->route('id'))],
            'price' => 'nullable|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',
            // Not stock: the level at which to buy more. Safe to edit here
            // because it moves nothing.
            'reorder_level' => 'nullable|integer|min:0|max:100000',

            ...ProductRules::details(),
            ...ProductRules::preorder(),
            ...ProductRules::variants(),
        ];
    }

    /**
     * The scalar columns, ready to hand to Product::update().
     *
     * A posted `null` must not blank out a NOT NULL column such as name or
     * price, so nulls are dropped — except on the two columns where "clear it"
     * is a real instruction.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        // `specifications` is a table of its own, not a column on products, and
        // is applied separately — leaving it here would reach Product::update()
        // as an unknown attribute.
        $scalar = collect($this->validated())
            ->except(['has_variants', 'variant_attributes', 'variants', 'specifications', 'category_ids', 'related_product_ids'])
            ->all();

        return array_filter(
            $scalar,
            // barcode joins these: clearing one is a real instruction, and a
            // product whose barcode is wrong has to be able to lose it.
            fn ($value, $key) => $value !== null || in_array($key, ['brand_id', 'discount_price', 'barcode'], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            ProductRules::checkBarcodes(
                $validator,
                (array) $this->input('variants', []),
                (int) $this->route('id')
            );
        });
    }
}
