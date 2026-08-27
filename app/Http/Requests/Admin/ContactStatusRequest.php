<?php

namespace App\Http\Requests\Admin;

use App\Models\ContactMessage;
use Illuminate\Validation\Rule;

class ContactStatusRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ContactMessage::STATUSES)],
        ];
    }
}
