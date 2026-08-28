<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which branch a member of staff is standing in.
 *
 * Staff accounts have carried a branch since roles were introduced and nothing
 * read it, so a storekeeper assigned to Uttara saw every branch's shelves and
 * could adjust, receive into and transfer out of any of them.
 *
 * The rule is one line: an account with a branch may only see and touch that
 * branch. An account without one — the owner, a manager — sees the shop whole,
 * which is what "no branch" has always meant on the staff form.
 *
 * Kept in one place rather than repeated across the eight endpoints stock is
 * reachable through, because a rule copied eight times is a rule that will be
 * enforced seven times.
 */
class BranchScope
{
    /**
     * The branch this person is confined to, or null when they are not.
     */
    public static function for(?User $user): ?int
    {
        if (! $user || ! $user->store_id) {
            return null;
        }

        /*
         * Whoever manages branches is not confined by one. Otherwise assigning
         * yourself a branch — which the staff form allows, and which is
         * reasonable for an owner who works out of one shop — would quietly
         * hide the other branches from the only person who can fix it.
         */
        if ($user->can_('settings') || $user->can_('staff')) {
            return null;
        }

        return (int) $user->store_id;
    }

    public static function applies(?User $user): bool
    {
        return self::for($user) !== null;
    }

    /**
     * Force a requested branch to the one this person is allowed.
     *
     * Used where a branch is a filter rather than an instruction: asking for
     * another branch's shelves simply shows you your own.
     */
    public static function narrow(?User $user, ?int $requested): ?int
    {
        return self::for($user) ?? $requested;
    }

    /**
     * Whether this person may act on this branch.
     *
     * A null branch means "wherever the shop keeps it", which only somebody
     * unconfined may say — a storekeeper's adjustment has to land somewhere in
     * particular, and that somewhere is their own shop.
     */
    public static function allows(?User $user, ?int $storeId): bool
    {
        $scope = self::for($user);

        if ($scope === null) {
            return true;
        }

        return $storeId !== null && (int) $storeId === $scope;
    }

    /**
     * The branches this person may choose between.
     *
     * @return Collection<int, Store>
     */
    public static function storesFor(?User $user, ?Collection $all = null): Collection
    {
        $stores = $all ?? Store::holdsStock()->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'city', 'fulfils_online']);

        $scope = self::for($user);

        return $scope === null
            ? $stores
            : $stores->where('id', $scope)->values();
    }

    /** For a message that names the branch rather than its number. */
    public static function name(?User $user): ?string
    {
        $scope = self::for($user);

        return $scope === null ? null : Store::whereKey($scope)->value('name');
    }
}
