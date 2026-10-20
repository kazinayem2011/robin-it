<?php

namespace App\Http\Requests\Admin;

use App\Support\MapLink;
use Illuminate\Validation\Validator;

class ShowroomRequest extends AdminRequest
{
    /** What the pasted map link draws, once it has been read. */
    private ?string $embed = null;

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
            // Pasted from Google Maps; the map is worked out from it below.
            'map_link' => 'nullable|string|max:3000',
        ];
    }

    /**
     * The link is read here, so a link Google Maps cannot place shows under
     * its own field rather than as a failed save.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('map_link')) {
                return;
            }

            try {
                $this->embed = MapLink::embedFor($this->input('map_link'));
            } catch (\InvalidArgumentException $e) {
                $validator->errors()->add('map_link', $e->getMessage());
            }
        }];
    }

    /**
     * The link as pasted and the map it draws, when the form sent one.
     *
     * @return array<string, ?string>
     */
    public function mapFields(): array
    {
        if (! $this->has('map_link')) {
            return [];
        }

        $link = trim((string) $this->input('map_link'));

        return ['map_link' => $link === '' ? null : $link, 'map_embed_url' => $this->embed];
    }
}
