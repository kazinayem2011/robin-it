<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Implements MustVerifyEmail so a verification link is sent on sign-up and the
 * /verify-email flow works. It is deliberately NOT enforced anywhere: this is a
 * cash-on-delivery storefront where customers sign up with a mobile number, and
 * gating checkout behind an email round-trip would cost real orders.
 *
 * To enforce it, add the 'verified' middleware to the routes that should require
 * it (see routes/web.php).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * `role` is deliberately NOT mass-assignable — it is the only thing standing
     * between a customer and the admin dashboard. Set it explicitly via
     * assignRole(), never from request input.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public const ROLE_ADMIN = 'admin';

    public const ROLE_CUSTOMER = 'customer';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user is an Administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Explicit, auditable role assignment. The only supported way to set a role.
     */
    public function assignRole(string $role): self
    {
        if (! in_array($role, [self::ROLE_ADMIN, self::ROLE_CUSTOMER], true)) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }

        $this->forceFill(['role' => $role]);

        return $this;
    }

    /**
     * Relationship with Orders.
     */
    public function orders()
    {
        return $this->hasMany(Order::class)->latest();
    }

    /**
     * Relationship with Addresses.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Relationship with Wishlist.
     */
    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * Shopping carts belonging to this user.
     */
    public function carts()
    {
        return $this->hasMany(Cart::class);
    }
}
