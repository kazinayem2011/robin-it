<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The name an uploaded image is stored under.
 *
 * Both upload endpoints renamed the file to a UUID and then took the extension
 * straight from whatever the browser called it. The name was safe; the
 * extension was not. Laravel refuses `.php` and `.phtml` on its own, so the
 * double-extension trick the old comment described was covered — but nothing
 * stopped `.html`, `.htm`, `.svg`, `.xhtml` or `.shtml`.
 *
 * That was enough. A real PNG with `<script>` appended still passes
 * getimagesize(), so it validates as an image; stored as `<uuid>.html` under
 * public/storage it is served by the web server as text/html, and the script
 * runs on the shop's own origin. The avatar endpoint made that reachable by
 * anybody who registered an account.
 *
 * So the extension is not taken from the request at all. It is read from the
 * bytes and looked up in the table below — a type absent from it has no name
 * to be stored under, and the upload is refused rather than guessed at.
 */
class UploadedImage
{
    /**
     * What each image type is stored as. The only extensions any upload in
     * this application can end up with.
     */
    private const EXTENSION_FOR_TYPE = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * A storage name for an upload, or null if its contents are not an image
     * type the caller accepts.
     *
     * @param  array<int, string>  $accepted  extensions this endpoint allows,
     *                                        as they appear in its `mimes:` rule
     */
    public static function storageName(UploadedFile $file, array $accepted): ?string
    {
        // Sniffed from the file itself. getClientMimeType() would be the
        // browser's word for it, which is the thing not to trust here.
        $type = strtolower((string) $file->getMimeType());

        $extension = self::EXTENSION_FOR_TYPE[$type] ?? null;

        if ($extension === null || ! in_array($extension, $accepted, true)) {
            return null;
        }

        return Str::uuid()->toString().'.'.$extension;
    }
}
