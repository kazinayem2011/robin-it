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
            'stock_quantity' => 'required|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',
            'reorder_level' => 'nullable|integer|min:0|max:100000',

            ...ProductRules::preorder(),
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
