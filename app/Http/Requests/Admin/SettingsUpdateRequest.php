<?php

namespace App\Http\Requests\Admin;

use App\Models\SiteSetting;

/**
 * Save the Settings screen.
 *
 * The keys are checked against SiteSetting::editableKeys(). Previously only the
 * *shape* of each value was validated and the key was accepted as given, so any
 * key at all could be written into the settings table — and since what gets
 * published to the browser was decided by a denylist of name patterns, a key
 * that dodged those patterns ended up in the props of every public page.
 */
class SettingsUpdateRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => 'required|array',
            'settings.*' => [
                'nullable',
                function (string $attribute, $value, $fail) {
                    if (! is_scalar($value)) {
                        $fail('Each setting must be a single text, number or on/off value.');
                    }
                },
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $submitted = array_keys((array) $this->input('settings', []));
            $unknown = array_diff($submitted, SiteSetting::editableKeys());

            foreach ($unknown as $key) {
                $validator->errors()->add(
                    "settings.{$key}",
                    "\"{$key}\" is not a setting this site stores."
                );
            }
        });
    }

    /**
     * The submitted settings, normalised to the strings the table holds.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        $out = [];

        foreach ((array) $this->validated()['settings'] as $key => $value) {
            $out[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $out;
    }
}
