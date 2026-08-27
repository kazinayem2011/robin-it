<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who works in the admin, and what their job covers.
 *
 * There were two roles — admin and customer — so anyone let in could do
 * everything: a storekeeper recording a delivery could also read the accounts
 * or change the SMTP password.
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Staff', [
            'staff' => User::staff()
                ->with('store:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'role', 'store_id', 'is_active', 'last_login_at'])
                ->map(fn (User $u) => [
                    ...$u->only(['id', 'name', 'email', 'phone', 'role', 'store_id', 'is_active']),
                    'role_label' => Roles::label($u->role),
                    'store' => $u->store?->only(['id', 'name']),
                    'last_login_at' => $u->last_login_at?->diffForHumans(),
                    // The owner cannot lock themselves out of their own shop.
                    'is_self' => $u->id === $request->user()->id,
                ]),
            'roles' => Roles::options(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = new User($validated);
        $user->password = Hash::make($validated['password']);
        // Never mass-assigned: the role is what stands between a storekeeper
        // and the accounts.
        $user->assignRole($validated['role']);
        $user->forceFill([
            'store_id' => $validated['store_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
        ])->save();

        return $this->successResponse(
            $user->only(['id', 'name', 'email', 'role']),
            "{$user->name} can now sign in as ".Roles::label($user->role).'.',
            201
        );
    }

    public function update(StaffRequest $request, int $id): JsonResponse
    {
        $user = User::staff()->findOrFail($id);
        $validated = $request->validated();

        if ($guard = $this->refuseSelfLockout($request, $user, $validated)) {
            return $guard;
        }

        $user->fill(collect($validated)->only(['name', 'email', 'phone'])->all());
        $user->assignRole($validated['role']);
        $user->store_id = $validated['store_id'] ?? null;
        $user->is_active = $validated['is_active'] ?? true;

        // Blank means "leave it as it is": the form never receives the current
        // password back, so an empty box is the normal state of the field.
        if (filled($validated['password'] ?? null)) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return $this->successResponse($user->only(['id', 'name', 'role']), 'Staff account updated.');
    }

    /**
     * Suspend an account rather than delete it.
     *
     * Their name is on deliveries, stock adjustments and refunds going back
     * years; removing the row would leave that history unattributed.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::staff()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'You cannot suspend your own account.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if ($this->isLastOwner($user)) {
            return $this->errorResponse(
                'This is the only owner account. Promote someone else first, '
                    .'or the shop would have nobody who can manage staff and settings.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $user->forceFill(['is_active' => false])->save();

        return $this->successResponse([], "{$user->name}'s access has been suspended.");
    }

    /**
     * Stop the last owner demoting or suspending themselves.
     *
     * A shop with no owner has nobody who can appoint one, which needs a
     * developer and a database console to undo.
     */
    private function refuseSelfLockout(Request $request, User $user, array $validated): ?JsonResponse
    {
        $isSelf = $user->id === $request->user()->id;
        $losingOwnership = $validated['role'] !== User::ROLE_ADMIN
            || ($validated['is_active'] ?? true) === false;

        if ($isSelf && $losingOwnership && $this->isLastOwner($user)) {
            return $this->errorResponse(
                'You are the only owner. Appoint another owner before changing your own role.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return null;
    }

    private function isLastOwner(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1;
    }
}
