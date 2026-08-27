<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each job covers, decided by the shop.
 *
 * The five roles and their abilities were a constant, so a shop that wanted
 * its storekeepers to see the customer directory, or a role of its own for the
 * person who only answers the phone, needed a developer.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $counts = User::selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return Inertia::render('Admin/Roles', [
            'roles' => Role::ordered()->get()->map(fn (Role $r) => [
                ...$r->only(['id', 'key', 'label', 'description', 'is_system']),
                'abilities' => array_values((array) $r->abilities),
                'people' => (int) ($counts[$r->key] ?? 0),
            ]),
            // What each ability actually covers, so the checkboxes are not a
            // list of words to guess at.
            'abilities' => collect(Roles::ABILITIES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'ownerKey' => Roles::OWNER,
            'customerKey' => Roles::CUSTOMER,
        ]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            ...$validated,
            'is_system' => false,
            'sort_order' => (int) Role::max('sort_order') + 10,
        ]);

        return $this->successResponse(
            $role->only(['id', 'key', 'label']),
            "\"{$role->label}\" added. Staff can be given it from the Staff screen.",
            201
        );
    }

    public function update(RoleRequest $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $validated = $request->validated();

        /*
         * A system role keeps its key, because users.role stores that string —
         * moving it would orphan every account holding it — and the owner
         * keeps every ability, because a shop that could take them away could
         * lock itself out of this very screen.
         */
        if ($role->is_system) {
            unset($validated['key']);

            if ($role->key === Roles::OWNER) {
                $validated['abilities'] = array_keys(Roles::ABILITIES);
            }

            if ($role->key === Roles::CUSTOMER) {
                $validated['abilities'] = [];
            }
        }

        $role->update($validated);

        return $this->successResponse(
            $role->only(['id', 'key', 'label']),
            "\"{$role->label}\" saved. Anyone holding it sees the change on their next page."
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->is_system) {
            return $this->errorResponse(
                "\"{$role->label}\" is built in and cannot be removed.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // Deleting a role people hold would leave them with a role string
        // nothing recognises — signed in, and able to do nothing.
        $holders = User::where('role', $role->key)->count();

        if ($holders > 0) {
            return $this->errorResponse(
                "{$holders} ".($holders === 1 ? 'person holds' : 'people hold')
                    ." \"{$role->label}\". Move them to another role first.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $role->delete();

        return $this->successResponse([], "\"{$role->label}\" removed.");
    }
}
