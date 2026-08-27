<?php

namespace App\Http\Requests\Admin;

use App\Models\WarrantyClaim;

class WarrantyStatusRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => 'required|string|in:'.implode(',', WarrantyClaim::STATUSES),
            'diagnostic_notes' => 'nullable|string|max:2000',
        ];
    }
}
