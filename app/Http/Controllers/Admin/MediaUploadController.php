<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Support\ImageDownscale;
use App\Support\UploadedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image uploads for the admin (product shots, banners, blog covers).
 *
 * There was no upload endpoint at all: the cropper handed back a base64 data
 * URL that was written straight into image_path — a VARCHAR(255) column — so
 * images could never actually be stored.
 */
class MediaUploadController extends Controller
{
    /** Formats we accept. SVG is excluded: it can carry script and would be served inline. */
    private const ALLOWED_MIMES = ['jpeg', 'jpg', 'png', 'webp', 'gif'];

    private const MAX_KILOBYTES = 5120; // 5 MB

    /**
     * Folders an upload may target, and the ability each one belongs to.
     *
     * Keyed rather than a flat list, so `folder` still cannot be used to write
     * anywhere — and a storekeeper who may photograph a product cannot also
     * replace the homepage banner.
     */
    private const FOLDER_ABILITIES = [
        'products' => 'catalogue',
        'brands' => 'catalogue',
        'categories' => 'catalogue',
        'banners' => 'marketing',
        'blogs' => 'marketing',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_KILOBYTES,
            ],
            'folder' => 'nullable|string|in:'.implode(',', array_keys(self::FOLDER_ABILITIES)),
        ], [
            'image.required' => 'Please choose an image to upload.',
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Images must be JPG, PNG, WebP or GIF.',
            'image.max' => 'Images must be under '.(self::MAX_KILOBYTES / 1024).'MB.',
            'folder.in' => 'Unknown upload destination.',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['image'];
        $folder = $validated['folder'] ?? 'products';

        if ($refusal = $this->refuseFolder($request, $folder)) {
            return $refusal;
        }

        // Named from the bytes, not from what the browser called the file —
        // see UploadedImage for what that was letting through.
        $name = UploadedImage::storageName($file, self::ALLOWED_MIMES);

        if ($name === null) {
            return $this->errorResponse(
                'Images must be JPG, PNG, WebP or GIF.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $path = $file->storeAs("uploads/{$folder}", $name, 'public');

        if (! $path) {
            return $this->errorResponse(
                'We could not save that image. Please try again.',
                500,
                ApiCode::SERVER_ERROR
            );
        }

        /*
         * Brought down to a size a shopper can receive. Nothing resized
         * anything before this, so a 6000px camera original was stored and
         * served whole — and the shop moves about 10 KB a second, which makes
         * one 5 MB photograph eight minutes. Best-effort: an image already
         * small enough, or one GD cannot read, is left exactly as it is.
         */
        $downscale = ImageDownscale::apply($path);

        return $this->successResponse([
            'path' => Storage::url($path),   // e.g. /storage/uploads/products/<uuid>.jpg
            'disk_path' => $path,
            'name' => $name,
            'size' => Storage::disk('public')->size($path),
            'resized' => $downscale['resized'],
        ], 'Image uploaded successfully.', 201);
    }

    /**
     * Whether this member of staff may write to this folder.
     *
     * The route admits catalogue and marketing alike, because one endpoint
     * serves both; this is where they part.
     */
    private function refuseFolder(Request $request, string $folder): ?JsonResponse
    {
        $ability = self::FOLDER_ABILITIES[$folder] ?? null;

        if ($ability && $request->user()?->can_($ability)) {
            return null;
        }

        return $this->errorResponse(
            'Your role does not cover uploads for that part of the site.',
            403,
            ApiCode::FORBIDDEN
        );
    }

    /**
     * Remove a previously uploaded image.
     *
     * Scoped to the uploads directory so this cannot be used to delete
     * seeded artwork or anything else on the disk.
     */
    /**
     * How many rows still point at a stored file.
     *
     * Matched on the tail rather than the whole string: the columns hold the
     * public form — "/storage/uploads/products/x.jpg" — while what arrives
     * here has already been trimmed to "uploads/products/x.jpg".
     *
     * Avatars are counted too. Nothing in the admin should be able to delete
     * a customer's photograph by knowing its address.
     */
    private function rowsUsing(string $path): int
    {
        $tail = '%'.$path;

        return DB::table('product_images')->where('image_path', 'like', $tail)->count()
            + DB::table('product_variants')->where('image_url', 'like', $tail)->count()
            + DB::table('brands')->where('logo_path', 'like', $tail)->count()
            + DB::table('users')->where('avatar', 'like', $tail)->count();
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:2048',
        ]);

        $path = ltrim(str_replace('/storage/', '', $validated['path']), '/');

        if (! Str::startsWith($path, 'uploads/') || Str::contains($path, '..')) {
            return $this->errorResponse(
                'That file cannot be removed from here.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * Not while anything is still pointing at it.
         *
         * One file is referenced by any number of rows, and deliberately so:
         * copying a product shares its photographs rather than duplicating
         * them on disk, because the copy is usually the same machine with a
         * different amount of memory in it. Deleting the file on the copy's
         * behalf would empty the original's gallery too, and the first anyone
         * would know is a product page with a placeholder on it.
         *
         * Refused rather than reference-counted: this is a shop's photo
         * library, the answer is almost always "it is still in use", and a
         * sentence saying where is more use than a silent deletion.
         */
        $uses = $this->rowsUsing($path);

        if ($uses > 0) {
            return $this->errorResponse(
                "That image is still used by {$uses} product(s), option(s) or brand(s). "
                    .'Take it off those first, or upload a replacement instead of deleting this one.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        Storage::disk('public')->delete($path);

        return $this->successResponse([], 'Image removed.');
    }
}
