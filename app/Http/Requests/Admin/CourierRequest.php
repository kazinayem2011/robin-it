<?php

namespace App\Http\Requests\Admin;

use App\Models\Courier;
use App\Services\Courier\CourierDriverRegistry;
use Illuminate\Validation\Rule;

class CourierRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('couriers', 'name')->ignore($this->routeId()),
            ],
            /*
             * Where the customer is sent to watch the parcel. Carriers change
             * these, so it is data rather than something needing a deploy.
             *
             * Not the `url` rule: Laravel validates URLs with its own regex
             * rather than filter_var, and that regex rejects the braces in
             * {tracking} — so no template could be saved at all, including the
             * seeded ones if anyone edited them. The shape is checked below
             * instead.
             */
            'tracking_url_template' => 'nullable|string|max:500',
            'driver' => 'nullable|string|in:'.implode(',', app(CourierDriverRegistry::class)->keys()),
            // Each driver names its own fields, so the values are validated as
            // strings and the driver decides what it actually needs.
            'credentials' => 'nullable|array',
            'credentials.*' => 'nullable|string|max:500',
            'is_sandbox' => 'nullable|boolean',
            'phone' => 'nullable|string|max:40',
            'note' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'That courier is already on the list.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $template = trim((string) $this->input('tracking_url_template'));

            if ($template === '') {
                return;
            }

            if (! preg_match('#^https?://#i', $template)) {
                $validator->errors()->add(
                    'tracking_url_template',
                    'The tracking link must start with http:// or https://.'
                );

                return;
            }

            // A link with no placeholder still works — it lands on the
            // carrier's search page — but it is nearly always a mistake worth
            // pointing out rather than silently accepting.
            if (! str_contains($template, Courier::PLACEHOLDER)) {
                $validator->errors()->add(
                    'tracking_url_template',
                    'Put '.Courier::PLACEHOLDER.' where the consignment number goes, '
                        .'or leave this blank if the courier has no per-parcel page.'
                );
            }
        });
    }
}
