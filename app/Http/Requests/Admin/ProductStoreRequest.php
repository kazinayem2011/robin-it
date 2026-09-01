<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class ProductStoreRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'name' => 'required|string|max:255',
            /*
             * The number on the box, for scanning at a count or a delivery.
             * Unique because two products sharing one makes every scan of it a
             * coin toss; blank is fine, since plenty of stock has none.
             */
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')],
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            /*
             * No quantity. A product is a description of a thing; what is on
             * the shelf is a separate fact with its own paperwork.
             *
             * Stock already held when a product is first entered is received
             * under Purchasing from the "Opening balance" source — the same
             * screen as a delivery, with a cost against it and a receipt to
             * look up. That leaves one way for stock to enter the shop, which
             * is the only way the ledger can be trusted.
             */
            'short_description' => 'nullable|string|max:500',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',

            ...ProductRules::gallery('images'),
            'reorder_level' => 'nullable|integer|min:0|max:100000',

            ...ProductRules::details(),
            ...ProductRules::preorder(),
            // A product sold in options can be entered in one go. The form
            // always showed the options editor here; nothing read it.
            ...ProductRules::variants(),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            ProductRules::checkBarcodes(
                $validator,
                (array) $this->input('variants', []),
                null
            );
        });
    }
}
