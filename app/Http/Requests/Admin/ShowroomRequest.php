<?php

namespace App\Http\Requests\Admin;

class ShowroomRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'branch_type' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:100',
            'opening_hours' => 'required|string|max:150',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            // The primary branch for online sales: orders take stock from it
            // first. One at a time.
            'fulfils_online' => 'boolean',
            // The branch's pin on the Showrooms map, both or neither; and the
            // Google Maps link it may have been dropped from, kept for staff.
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'map_link' => 'nullable|string|max:3000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required_with' => 'Place the pin on the map, or clear it.',
            'longitude.required_with' => 'Place the pin on the map, or clear it.',
        ];
    }
}
