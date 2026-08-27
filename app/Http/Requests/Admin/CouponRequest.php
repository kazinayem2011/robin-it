<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use Illuminate\Validation\Rule;

class CouponRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('coupons', 'code')->ignore($this->routeId()),
            ],
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',

            // Restrict the promo to part of the catalogue. Category scope covers
            // everything beneath the categories named.
            'scope' => 'nullable|in:'.implode(',', Coupon::SCOPES),
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ];
    }

    /**
     * The coupon's own columns, without the scope pivots.
     *
     * @return array<string, mixed>
     */
    public function couponAttributes(): array
    {
        return collect($this->validated())
            ->except(['product_ids', 'category_ids'])
            ->all();
    }
}
