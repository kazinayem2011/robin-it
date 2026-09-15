<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A template being saved.
 *
 * `subject` is optional here rather than required, because an SMS has none.
 * The placeholder check that matters is not a validation rule — it depends on
 * what the template declares — and lives in the controller.
 */
class MessageTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:20000',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'A template with nothing in it sends nothing. Write something, or switch it off instead.',
            'body.max' => 'That is longer than any message the shop should send.',
        ];
    }
}
