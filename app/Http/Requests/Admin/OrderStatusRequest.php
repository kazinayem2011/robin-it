<?php

namespace App\Http\Requests\Admin;

use App\Models\Order;

class OrderStatusRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => 'required|in:'.implode(',', Order::STATUSES),
            'payment_status' => 'nullable|in:'.implode(',', Order::PAYMENT_STATUSES),
        ];
    }
}
