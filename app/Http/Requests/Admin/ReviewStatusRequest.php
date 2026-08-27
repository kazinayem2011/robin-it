<?php

namespace App\Http\Requests\Admin;

class ReviewStatusRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_approved' => 'required|boolean',
        ];
    }
}
