<?php

namespace App\Http\Requests\Admin;

use App\Models\Refund;

class RefundRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Zero is allowed on purpose: a cash-on-delivery parcel that came
            // back before the rider collected anything is refunded on paper
            // only, and the order still has to say so.
            'amount' => 'required|numeric|min:0|max:99999999',
            'method' => 'required|string|in:'.implode(',', array_keys(Refund::METHODS)),
            'reason' => 'required|string|in:'.implode(',', array_keys(Refund::REASONS)),
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'refunded_on' => 'required|date|before_or_equal:today',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Choose how the money went back.',
            'reason.in' => 'Choose why this was refunded.',
            'refunded_on.before_or_equal' => 'A refund cannot be dated in the future.',
        ];
    }
}
