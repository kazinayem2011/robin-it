<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    /** Shipped with the app, so this one is always there. */
    public const PLACEHOLDER = '/images/product-placeholder.svg';

    protected $fillable = ['product_id', 'image_path', 'is_primary'];

    /**
     * The resolved URL travels alongside the stored path, never instead of it.
     *
     * Overriding image_path itself would mean the admin's edit form reads back
     * a placeholder where a real path is stored, and saving would write that
     * placeholder over the original. The stored value stays exactly as it was
     * entered; this is the one to render.
     */
    protected $appends = ['image_url'];

    /**
     * Whether the file behind a path is really there, remembered per request.
     *
     * A shop page carries twenty products; without this the same handful of
     * paths would be stat'd over and over in one render.
     *
     * Not named $exists: Eloquent\Model already declares that, non-statically,
     * and PHP refuses to redeclare it as static.
     *
     * @var array<string, bool>
     */
    private static array $fileChecks = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Never hand the browser a path that 404s.
     *
     * It was being given image paths for files that are not in the project —
     * every product's, as it happens: the seed data names thirty files under
     * public/images/products and that directory has never existed. Each was
     * requested, came back 404, drew as a broken image, and only then did the
     * front end's onError swap in the placeholder. So every product flashed
     * broken before it settled, and a shop page fired twenty failed requests
     * to get there.
     *
     * The front end keeps its own fallback for what this cannot see: a file
     * removed between the render and the request, or a remote host that is
     * down.
     */
    public function getImageUrlAttribute(): string
    {
        return self::resolve($this->attributes['image_path'] ?? null);
    }

    /**
     * The usable URL for a stored image path.
     */
    public static function resolve(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return self::PLACEHOLDER;
        }

        // Somebody else's server; we are in no position to check it.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::fileExists($path) ? $path : self::PLACEHOLDER;
    }

    private static function fileExists(string $path): bool
    {
        if (! array_key_exists($path, self::$fileChecks)) {
            self::$fileChecks[$path] = is_file(public_path(ltrim($path, '/')));
        }

        return self::$fileChecks[$path];
    }
}
