<?php

namespace App\Http\Requests\Admin;

use App\Models\Expense;

class ExpenseRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => 'required|string|in:'.implode(',', Expense::categoryKeys()),
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'description' => 'required|string|max:255',
            // Costs can be entered late, but not in advance — a bill that has
            // not happened yet does not belong in a period's accounts.
            'incurred_on' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:1000',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'Choose one of the listed spending categories.',
            'amount.min' => 'Enter what this cost.',
            'description.required' => 'Say what the money went on.',
            'incurred_on.before_or_equal' => 'An expense cannot be dated in the future.',
        ];
    }
}
