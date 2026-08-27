<?php

namespace App\Http\Requests\Admin;

use App\Support\Roles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends AdminRequest
{
    /** Only the owner may create or change staff accounts. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can_('staff');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->routeId();

        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20',
                'regex:/^(?:\+?88|88)?01[3-9]\d{8}$/',
                Rule::unique('users', 'phone')->ignore($id)],
            'role' => ['required', Rule::in(Roles::staffRoles())],
            // A member of staff tied to a branch works that branch's stock.
            // Null is the whole shop, which is what head office needs.
            'store_id' => 'nullable|exists:stores,id',
            'is_active' => 'nullable|boolean',
            // Required on create, optional on edit — a blank box means "leave
            // the password alone".
            'password' => [$id ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Choose one of the staff roles.',
            'email.unique' => 'Someone already has an account with that email.',
            'phone.regex' => 'Enter a valid 11-digit Bangladeshi mobile number.',
        ];
    }
}
