<?php

namespace App\Http\Requests\Admin;

class BannerRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'badge' => 'nullable|string|max:50',
            'image_path' => 'required|string',
            'link_url' => 'nullable|string|max:255',
            'button_text' => 'nullable|string|max:50',
            'position' => 'required|in:hero,promo_top,promo_side,popup',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
