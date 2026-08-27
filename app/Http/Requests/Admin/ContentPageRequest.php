<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class ContentPageRequest extends AdminRequest
{
    public function rules(): array
    {
        $id = $this->routeId();

        return [
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('content_pages', 'slug')->ignore($id),
            ],
            'title' => 'required|string|max:160',
            'subtitle' => 'nullable|string|max:200',
            'body' => 'required|string|max:120000',
            'meta_title' => 'nullable|string|max:160',
            'meta_description' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The address may use lowercase letters, numbers and hyphens only — "return-policy", not "Return Policy".',
            'slug.unique' => 'Another page already lives at that address.',
            'body.required' => 'A page with nothing on it is not worth publishing.',
        ];
    }
}
