<?php

namespace App\Http\Requests\Admin;

use App\Models\OrderPayment;
use Illuminate\Validation\Rule;

class OrderPaymentRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            // Signed: a negative amount corrects a payment taken in error.
            'amount' => 'required|numeric|not_in:0|min:-99999999|max:99999999',
            'method' => ['required', Rule::in(array_keys(OrderPayment::METHODS))],
            'reference' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
            'received_on' => 'nullable|date|before_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter how much was received.',
            'amount.not_in' => 'Enter how much was received.',
            'method.in' => 'Choose how the money was received.',
            'received_on.before_or_equal' => 'Money cannot be received in the future.',
        ];
    }
}
