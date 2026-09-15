<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where to send a test.
 *
 * An address or a number, depending on the template — validated loosely on
 * purpose, because a Bangladeshi mobile is written half a dozen ways and a
 * staff member testing their own wording should not have to guess which one
 * this box wants.
 */
class TemplateTestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Enter an address or a mobile number to send the test to.',
        ];
    }
}
