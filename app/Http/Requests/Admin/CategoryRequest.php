<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * Create or edit a category. The only difference between the two is which row
 * the slug is allowed to collide with, so they share one request.
 */
class CategoryRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('categories', 'slug')->ignore($this->routeId()),
            ],
            'parent_id' => 'nullable|exists:categories,id',
            /*
             * The brand this shelf stands for, if it stands for one. Most
             * shelves are product lines, not makers, so this is normally null.
             */
            'brand_id' => 'nullable|exists:brands,id',
            /*
             * "This shelf is a maker, and there is no row for it yet." Saves a
             * trip to the brands screen, which is the trip nobody made: 386
             * shelves are named after makers that `brands` has never heard of.
             */
            'create_brand' => 'nullable|boolean',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:20',
            'is_offer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
