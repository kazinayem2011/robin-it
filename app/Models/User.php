<?php

namespace App\Models;

use App\Support\Roles;
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
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'accepts_marketing' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether this account may reach the admin at all.
     *
     * Kept as isAdmin() because that is the question the middleware and every
     * policy already asks. It means "is staff" now — what they may do once
     * inside is decided by their abilities.
     */
    public function isAdmin(): bool
    {
        return Roles::isStaff($this->role) && $this->is_active !== false;
    }

    /**
     * Nothing to send, and nothing to verify.
     *
     * An account may carry a mobile number instead of an address. The
     * framework would still try to send a verification link on sign-up and
     * would still call the account unverified for ever afterwards, so both
     * questions are answered here rather than left to fail quietly in a queue.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (blank($this->email)) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    public function hasVerifiedEmail(): bool
    {
        return blank($this->email) || parent::hasVerifiedEmail();
    }

    /** Where the shop can actually reach this customer. */
    public function hasEmail(): bool
    {
        return filled($this->email);
    }

    /** The owner, who may do everything including managing staff. */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Whether this account may work in a section of the admin.
     *
     * Sections rather than routes: route lists drift the moment someone adds
     * an endpoint and forgets to update them.
     */
    public function can_(string $ability): bool
    {
        return $this->isAdmin() && Roles::allows($this->role, $ability);
    }

    /** @return array<int, string> */
    public function abilities(): array
    {
        return $this->isAdmin() ? Roles::abilitiesFor($this->role) : [];
    }

    /** The branch this member of staff works, if they are tied to one. */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeStaff($query)
    {
        return $query->whereIn('role', Roles::staffRoles());
    }

    /**
     * Explicit, auditable role assignment. The only supported way to set a role.
     */
    public function assignRole(string $role): self
    {
        if (! in_array($role, [...Roles::staffRoles(), self::ROLE_CUSTOMER], true)) {
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
