<?php

namespace App\Http\Requests\Admin;

class BlogRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'category' => 'required|string|max:50',
            'excerpt' => 'required|string|max:300',
            // Rich text. Sanitised in the controller before it is stored —
            // this is rendered as raw HTML on the public article page.
            'content' => 'required|string',
            'image_path' => 'required|string',
            'author_name' => 'required|string|max:100',
            'author_role' => 'nullable|string|max:100',
            'read_time' => 'required|string|max:20',
            'is_published' => 'boolean',
        ];
    }
}
