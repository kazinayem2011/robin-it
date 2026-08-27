<?php

namespace App\Http\Requests\Admin;

use App\Support\Roles;
use Illuminate\Validation\Rule;

class RoleRequest extends AdminRequest
{
    public function rules(): array
    {
        $id = $this->routeId();

        return [
            'key' => [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'key')->ignore($id),
            ],
            'label' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'abilities' => 'present|array',
            'abilities.*' => ['string', Rule::in(array_keys(Roles::ABILITIES))],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'Use lowercase letters, numbers and underscores, starting with a letter — "shift_lead".',
            'key.unique' => 'There is already a role with that key.',
            'abilities.*.in' => 'That is not a part of the admin we know about.',
        ];
    }
}
