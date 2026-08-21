<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

    /** Folders an upload may target, so `folder` can't be used to write anywhere. */
    private const ALLOWED_FOLDERS = ['products', 'banners', 'blogs', 'brands', 'categories'];

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
            'folder' => 'nullable|string|in:'.implode(',', self::ALLOWED_FOLDERS),
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

        // Never reuse the client's filename: it can carry path traversal or a
        // double extension such as "shell.php.jpg".
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $path = $file->storeAs("uploads/{$folder}", $name, 'public');

        if (! $path) {
            return $this->errorResponse(
                'We could not save that image. Please try again.',
                500,
                ApiCode::SERVER_ERROR
            );
        }

        return $this->successResponse([
            'path' => Storage::url($path),   // e.g. /storage/uploads/products/<uuid>.jpg
            'disk_path' => $path,
            'name' => $name,
            'size' => $file->getSize(),
        ], 'Image uploaded successfully.', 201);
    }

    /**
     * Remove a previously uploaded image.
     *
     * Scoped to the uploads directory so this cannot be used to delete
     * seeded artwork or anything else on the disk.
     */
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

        Storage::disk('public')->delete($path);

        return $this->successResponse([], 'Image removed.');
    }
}
