<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Bring an uploaded photograph down to a size a shopper can actually receive.
 *
 * Nothing resized anything before this: a file passed the 5 MB check and was
 * stored exactly as the camera wrote it. That cost nothing so far because the
 * catalogue is placeholders — 1,265 of 1,271 products point at the same SVG,
 * and the nineteen real uploads are under 100 KB between them. It stops being
 * free the day somebody photographs a shelf: measured from here, the shop
 * serves about 10 KB a second, so one 5 MB picture is eight minutes.
 *
 * A product photograph is shown at roughly 600px and zoomed to maybe 1200.
 * 1600 on the long edge covers both with room to spare, and is a twentieth of
 * the bytes of a 6000px original.
 *
 * Everything here is best-effort. A file that cannot be read, a format GD was
 * built without, an image already small enough — each leaves the upload
 * exactly as it was. Refusing a photograph because it could not be shrunk
 * would be a worse outcome than serving it whole.
 */
class ImageDownscale
{
    /** Long edge, in pixels. */
    public const MAX_EDGE = 1600;

    /** JPEG and WebP quality. 82 is where the eye stops noticing. */
    private const QUALITY = 82;

    /**
     * A guard against decompression bombs: a 60-megapixel PNG needs about
     * 240 MB decoded, and GD allocates that before anything can be checked.
     */
    private const MAX_PIXELS = 40_000_000;

    /**
     * Shrink the stored file in place. Returns what actually happened, for the
     * caller to report or ignore.
     *
     * @return array{resized: bool, from?: array{int, int}, to?: array{int, int}, bytes_before?: int, bytes_after?: int}
     */
    public static function apply(string $diskPath, string $disk = 'public'): array
    {
        if (! extension_loaded('gd')) {
            return ['resized' => false];
        }

        $full = Storage::disk($disk)->path($diskPath);

        if (! is_file($full) || ! is_readable($full)) {
            return ['resized' => false];
        }

        $info = @getimagesize($full);

        if ($info === false) {
            return ['resized' => false];
        }

        [$width, $height] = $info;
        $type = $info[2];

        if ($width * $height > self::MAX_PIXELS) {
            return ['resized' => false];
        }

        /*
         * An animated GIF loses every frame but the first when it goes through
         * GD, so it is left alone. A still one is not worth the risk of telling
         * the two apart.
         */
        if ($type === IMAGETYPE_GIF) {
            return ['resized' => false];
        }

        $longEdge = max($width, $height);

        if ($longEdge <= self::MAX_EDGE) {
            return ['resized' => false];
        }

        $source = self::read($full, $type);

        if ($source === null) {
            return ['resized' => false];
        }

        $scale = self::MAX_EDGE / $longEdge;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);

        // PNG and WebP can be transparent, and a true-colour canvas starts
        // black — without this every cut-out product photo gains a background.
        if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $before = (int) filesize($full);
        $written = self::write($target, $full, $type);

        /*
         * No imagedestroy(): it has done nothing since PHP 8.0, when GdImage
         * became an object the collector frees on its own, and 8.5 deprecates
         * calling it. Both handles go out of scope on return.
         */

        if (! $written) {
            return ['resized' => false];
        }

        clearstatcache(true, $full);

        return [
            'resized' => true,
            'from' => [$width, $height],
            'to' => [$newWidth, $newHeight],
            'bytes_before' => $before,
            'bytes_after' => (int) filesize($full),
        ];
    }

    private static function read(string $path, int $type): ?\GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image === false ? null : $image;
    }

    /** Written back in the format it arrived in, so the extension stays true. */
    private static function write(\GdImage $image, string $path, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagejpeg($image, $path, self::QUALITY),
            IMAGETYPE_PNG => @imagepng($image, $path, 6),
            IMAGETYPE_WEBP => @imagewebp($image, $path, self::QUALITY),
            default => false,
        };
    }
}
