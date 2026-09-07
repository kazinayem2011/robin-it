<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The logo, redrawn for a dark background.
 *
 * The shop's mark is one image with the strapline baked into it, and that
 * strapline is solid black. On the white header it reads; on the footer, the
 * admin sidebar and the whole dark theme it is black on near-black and simply
 * is not there. That was true before the dark theme existed — the footer and
 * the sidebar have always been dark — so this is not a theming problem to be
 * solved with a token. CSS cannot recolour part of an image; the only honest
 * fix is a second image.
 *
 * Rather than demand one, this makes it: the mark divides cleanly into brand
 * colour and black ink, with no overlap between them, so the black can be
 * lifted to a near-white and *nothing else touched*. The red and the yellow
 * come out of this byte-identical, which is the whole point — a shop's logo
 * must not quietly change colour because the page did.
 *
 * It is a default, not a decree. An admin who has a proper dark-background
 * logo from a designer uploads it into `site_logo_dark` and this never runs
 * for them: see BrandDetails::darkLogoWebPath() for the order of preference.
 */
class DarkLogo
{
    /** What the dark ink becomes. Matches --text-primary rather than a glaring pure white. */
    private const INK = [232, 236, 243];

    /**
     * What counts as "the ink rather than the brand".
     *
     * Measured against the mark itself: it is 80% brand red, 5% brand yellow
     * and 15% pure black, and nothing sits between those. A pixel has to be
     * both nearly colourless and genuinely dark to be touched, so a deep red
     * — which is dark but very much not colourless — is left alone.
     */
    private const MAX_SATURATION = 0.20;

    private const MAX_LIGHTNESS = 0.40;

    /** Formats that can carry transparency. A logo without it has its own
     *  background baked in and there is nothing useful to lift. */
    private const SUPPORTED = ['png', 'webp', 'gif'];

    /**
     * Guard against a pathological upload turning a settings save into a
     * minute of per-pixel PHP. A logo far larger than this is not a logo.
     */
    private const MAX_PIXELS = 4_000_000;

    /** The conventional name of the generated twin, given a logo path. */
    public static function twinFor(string $logoPath): ?string
    {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED, true)) {
            return null;
        }

        $withoutExtension = substr($logoPath, 0, -(strlen($extension) + 1));

        // Always .png: the output has an alpha channel whatever went in.
        return $withoutExtension.'-dark.png';
    }

    /**
     * Draw the twin for a logo, returning its web path.
     *
     * Idempotent, and it only ever writes to the conventional `-dark.png`
     * name — so running it again after a logo changes replaces a file this
     * class made, and can never overwrite something an admin uploaded.
     *
     * Returns null when there is nothing sensible to make: a remote logo, a
     * format without transparency, a missing file, or a mark that turns out to
     * have no dark ink in it at all (already designed for a dark background).
     */
    public static function generate(string $logoWebPath): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $twinWebPath = self::twinFor($logoWebPath);

        if ($twinWebPath === null || preg_match('#^https?://#i', $logoWebPath)) {
            return null;
        }

        $source = public_path(ltrim($logoWebPath, '/'));
        $destination = public_path(ltrim($twinWebPath, '/'));

        if (! is_file($source) || ! is_readable($source)) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width * $height > self::MAX_PIXELS) {
                return null;
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            $lifted = self::liftInk($image, $width, $height);

            // Nothing dark in it: the mark already works on a dark ground and
            // a copy of it would be a second file saying the same thing.
            if ($lifted === 0) {
                return null;
            }

            if (! is_dir(dirname($destination))) {
                @mkdir(dirname($destination), 0755, true);
            }

            /*
             * No imagedestroy(): it has been a no-op since PHP 8.0 — a GdImage
             * is an object the collector handles — and PHP 8.5 deprecates it
             * outright, which put a warning in the log on every settings save.
             */
            return imagepng($image, $destination) ? $twinWebPath : null;
        } catch (\Throwable $e) {
            /*
             * A logo that cannot be converted must never stop an admin saving
             * their settings — the worst case is the strapline stays hard to
             * read, which is where things already stood.
             */
            Log::warning('Could not draw a dark-background logo.', [
                'logo' => $logoWebPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Repaint the dark, colourless pixels and leave every other one alone.
     *
     * The glyph's own antialiasing is preserved by carrying it into the alpha:
     * how dark a pixel was becomes how opaque its light replacement is, so the
     * letterforms keep their edges instead of turning into a blocky stencil.
     *
     * @return int how many pixels were repainted
     */
    private static function liftInk(\GdImage $image, int $width, int $height): int
    {
        $lifted = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);

                // GD packs alpha as 0 (opaque) to 127 (transparent).
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha === 127) {
                    continue;
                }

                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;

                $max = max($red, $green, $blue) / 255;
                $min = min($red, $green, $blue) / 255;
                $lightness = ($max + $min) / 2;

                $saturation = ($max === $min || $lightness === 0.0 || $lightness === 1.0)
                    ? 0.0
                    : ($max - $min) / (1 - abs(2 * $lightness - 1));

                if ($saturation >= self::MAX_SATURATION || $lightness >= self::MAX_LIGHTNESS) {
                    continue;
                }

                $depth = 1 - ($lightness / self::MAX_LIGHTNESS);
                $opacity = 0.35 + (0.65 * $depth);

                // Back to GD's inverted scale, and never more opaque than the
                // pixel already was — a faint edge stays a faint edge.
                $newAlpha = (int) round(127 - ((127 - $alpha) * $opacity));

                imagesetpixel($image, $x, $y, imagecolorallocatealpha(
                    $image,
                    self::INK[0],
                    self::INK[1],
                    self::INK[2],
                    min(127, max(0, $newAlpha))
                ));

                $lifted++;
            }
        }

        return $lifted;
    }
}
