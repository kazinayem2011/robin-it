<?php

namespace App\Http\Requests\Admin;

class ContactReplyRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'body' => 'required|string|min:2|max:4000',
            // Answer and close in one go, for the ones that need no follow-up.
            'close' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write something before sending.',
        ];
    }
}
