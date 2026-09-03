<?php

namespace App\Http\Requests\Admin;

class OfferRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'excerpt' => 'nullable|string|max:300',
            // Rich text. Cleaned by the model on the way in — this is rendered
            // as raw HTML on the public offer page.
            'content' => 'nullable|string',
            'image_path' => 'nullable|string',
            'starts_at' => 'nullable|date',
            // An offer that ends before it starts is a typo, not a campaign.
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'availability' => 'nullable|string|max:120',
            'link_url' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after_or_equal' => 'The offer cannot end before it starts.',
        ];
    }
}
