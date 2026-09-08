<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /**
     * Logo files that ship with the repo, by slug.
     *
     * The homepage brand row was once a hardcoded list of names and paths kept
     * separately from this table, which is why uploading a logo in the admin
     * changed the mega menu and never touched the homepage. The row reads the
     * table now, so these files belong to a brand rather than to a constant in
     * a component — and both the migration that carried them across and the
     * seeder that builds a fresh install read them from here, so the two
     * cannot drift.
     *
     * Gigabyte is absent deliberately. Its artwork read "GIBAT" — a mangled
     * wordmark, not the company's mark — so it draws its name until somebody
     * uploads the real thing. Intel, MSI, Corsair and Samsung were absent for
     * the same reason until their correct marks were recovered from the vector
     * artwork that shipped alongside the wrong files.
     */
    public const BUNDLED_LOGOS = [
        'amd' => '/images/brands/amd.png',
        'nvidia' => '/images/brands/nvidia.png',
        'asus' => '/images/brands/asus.png',
        'razer' => '/images/brands/razer.png',
        'apple' => '/images/brands/apple.png',
        'dell' => '/images/brands/dell.png',
        'logitech' => '/images/brands/logitech.png',
        'hp' => '/images/brands/hp.png',
        'lenovo' => '/images/brands/lenovo.png',
        'intel' => '/images/brands/intel.png',
        'msi' => '/images/brands/msi.png',
        'corsair' => '/images/brands/corsair.png',
        'samsung' => '/images/brands/samsung.png',
    ];

    protected $fillable = ['name', 'slug', 'logo_path', 'is_featured'];

    protected $casts = [
        'is_featured' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->orderBy('name', 'asc');
    }
}
