<?php

namespace App\Http\Requests\Admin;

use App\Models\Refund;
use App\Support\ShopDate;

class RefundRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0|max:99999999',
            /*
             * "Cash never collected" is no longer recorded as a refund. Only
             * money that came in can go back, and an order nobody paid for
             * that is cancelled or comes back already owes nothing. A payment
             * recorded by mistake is undone with a negative payment. Old rows
             * keep their label.
             */
            'method' => 'required|string|not_in:cod_not_collected|in:'.implode(',', array_keys(Refund::METHODS)),
            'reason' => 'required|string|in:'.implode(',', array_keys(Refund::REASONS)),
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'refunded_on' => 'required|date|'.ShopDate::notInFuture(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Choose how the money went back.',
            'method.not_in' => 'Nothing was collected, so nothing goes back. If a payment was recorded by mistake, record it again as a negative payment.',
            'amount.gt' => 'Enter how much was given back.',
            'reason.in' => 'Choose why this was refunded.',
            'refunded_on.before_or_equal' => 'A refund cannot be dated in the future.',
        ];
    }
}
