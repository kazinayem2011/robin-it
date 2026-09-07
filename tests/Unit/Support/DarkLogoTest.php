<?php

namespace Tests\Unit\Support;

use App\Support\DarkLogo;
use Tests\TestCase;

/**
 * The shop's mark carries its strapline as black ink baked into the image, so
 * on the footer, down the side of the admin and throughout the dark theme it
 * is black on near-black and simply is not there. That was already true of the
 * footer and the sidebar before any theme existed — both have always been dark.
 *
 * CSS cannot recolour part of an image, so the fix is a second image. These
 * pin the one property that makes generating it acceptable at all: the brand
 * colours must come out the other side untouched. A shop's logo changing
 * colour because the page did would be a worse bug than the one being fixed.
 */
class DarkLogoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = 'images/__darklogo_test';

        if (! is_dir(public_path($this->dir))) {
            mkdir(public_path($this->dir), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path($this->dir).'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(public_path($this->dir));

        parent::tearDown();
    }

    /** A mark shaped like the real one: brand red, brand yellow, black ink. */
    private function makeLogo(string $name = 'logo.png'): string
    {
        $image = imagecreatetruecolor(9, 3);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $red = imagecolorallocatealpha($image, 209, 33, 39, 0);
        $yellow = imagecolorallocatealpha($image, 245, 238, 49, 0);
        $black = imagecolorallocatealpha($image, 0, 0, 0, 0);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);

        for ($x = 0; $x < 9; $x++) {
            imagesetpixel($image, $x, 0, $red);
            imagesetpixel($image, $x, 1, $x < 5 ? $yellow : $clear);
            imagesetpixel($image, $x, 2, $black);
        }

        imagepng($image, public_path("{$this->dir}/{$name}"));

        return "/{$this->dir}/{$name}";
    }

    private function pixel(string $webPath, int $x, int $y): array
    {
        $image = imagecreatefrompng(public_path(ltrim($webPath, '/')));
        $rgba = imagecolorat($image, $x, $y);

        return [
            ($rgba >> 16) & 0xFF,
            ($rgba >> 8) & 0xFF,
            $rgba & 0xFF,
            ($rgba >> 24) & 0x7F,
        ];
    }

    public function test_it_writes_a_twin_beside_the_logo(): void
    {
        $logo = $this->makeLogo();

        $this->assertSame(
            "/{$this->dir}/logo-dark.png",
            DarkLogo::generate($logo)
        );
        $this->assertFileExists(public_path("{$this->dir}/logo-dark.png"));
    }

    public function test_the_black_ink_is_lifted_to_something_readable(): void
    {
        $twin = DarkLogo::generate($this->makeLogo());

        [$r, $g, $b, $alpha] = $this->pixel($twin, 0, 2);

        // Near-white rather than pure white, and still fully opaque.
        $this->assertGreaterThan(200, min($r, $g, $b));
        $this->assertSame(0, $alpha);
    }

    /**
     * The point of the whole exercise. A logo is a brand asset, and lifting a
     * strapline must not repaint the mark it belongs to.
     */
    public function test_the_brand_colours_come_out_untouched(): void
    {
        $twin = DarkLogo::generate($this->makeLogo());

        $this->assertSame([209, 33, 39, 0], $this->pixel($twin, 0, 0), 'brand red moved');
        $this->assertSame([245, 238, 49, 0], $this->pixel($twin, 0, 1), 'brand yellow moved');
    }

    public function test_transparent_pixels_stay_transparent(): void
    {
        $twin = DarkLogo::generate($this->makeLogo());

        $this->assertSame(127, $this->pixel($twin, 8, 1)[3]);
    }

    public function test_a_mark_with_no_dark_ink_produces_nothing(): void
    {
        // Already drawn for a dark background: a second identical file would
        // say nothing the first does not.
        $image = imagecreatetruecolor(4, 1);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 240, 244, 250, 0));
        imagepng($image, public_path("{$this->dir}/pale.png"));

        $this->assertNull(DarkLogo::generate("/{$this->dir}/pale.png"));
    }

    public function test_it_declines_formats_that_cannot_carry_transparency(): void
    {
        $this->assertNull(DarkLogo::twinFor('/images/logo.jpg'));
        $this->assertNull(DarkLogo::twinFor('/images/logo.svg'));
    }

    public function test_it_declines_a_remote_logo(): void
    {
        $this->assertNull(DarkLogo::generate('https://cdn.example.com/logo.png'));
    }

    public function test_a_missing_file_is_not_an_error(): void
    {
        $this->assertNull(DarkLogo::generate('/images/nothing-here.png'));
    }
}
