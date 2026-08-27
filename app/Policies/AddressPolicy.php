<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->isAdmin() ? true : null;
    }

    public function update(?User $user, Address $address): bool
    {
        return $user !== null && $address->user_id === $user->id;
    }

    public function delete(?User $user, Address $address): bool
    {
        return $this->update($user, $address);
    }
}
