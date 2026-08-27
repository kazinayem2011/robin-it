<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every admin form request.
 *
 * The `admin` middleware already gates these routes; repeating the check here
 * means a request object can never be reused on a route that forgot it.
 */
abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * The `{id}` segment of the route being handled, or null when creating.
     */
    protected function routeId(): ?int
    {
        $id = $this->route('id');

        return $id === null ? null : (int) $id;
    }
}
