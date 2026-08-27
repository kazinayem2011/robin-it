<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('expense_categories', 'name')->ignore($this->routeId()),
            ],
            'note' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the category a name.',
            'name.unique' => 'There is already a category with that name.',
        ];
    }
}
