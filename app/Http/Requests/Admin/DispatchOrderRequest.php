<?php

namespace App\Http\Requests\Admin;

class DispatchOrderRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier_id' => 'required|exists:couriers,id',
            // Some carriers issue no number at all — own-delivery, or a
            // shop's own rider — so it is optional rather than invented.
            'tracking_number' => 'nullable|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courier_id.required' => 'Choose who is carrying this parcel.',
            'courier_id.exists' => 'That courier is no longer on the list.',
        ];
    }
}
