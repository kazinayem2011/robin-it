<?php

namespace Tests\Feature\Ops;

use App\Support\ImageDownscale;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bringing an uploaded photograph down to a size a shopper can receive.
 *
 * Nothing resized anything before this: a file passed the 5 MB check and was
 * stored exactly as the camera wrote it. That has cost nothing so far only
 * because the catalogue is placeholders — 1,265 of 1,271 products point at the
 * same SVG. It stops being free the day somebody photographs a shelf: the shop
 * serves about 10 KB a second, so one 5 MB picture is eight minutes.
 *
 * These make real images with GD rather than fixtures, so what is asserted is
 * what the library actually does on this machine.
 */
class ImageDownscaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is not loaded here.');
        }

        Storage::fake('public');
    }

    /* Named away from put(), which is the TestCase's own HTTP helper. */
    private function makeImage(string $name, int $width, int $height, string $format = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);

        // Some actual variation, so the encoder has something to encode and
        // the file is not a single flat colour compressed to nothing.
        for ($i = 0; $i < 400; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                60,
                60,
                imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        $path = "uploads/products/{$name}";
        $full = Storage::disk('public')->path($path);
        @mkdir(dirname($full), 0755, true);

        match ($format) {
            'png' => imagepng($image, $full),
            'webp' => imagewebp($image, $full),
            default => imagejpeg($image, $full, 92),
        };

        return $path;
    }

    public function test_a_large_photograph_is_brought_down_to_the_long_edge(): void
    {
        $path = $this->makeImage('big.jpg', 4000, 3000);

        $result = ImageDownscale::apply($path);

        $this->assertTrue($result['resized']);
        $this->assertSame([ImageDownscale::MAX_EDGE, 1200], $result['to']);

        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame(ImageDownscale::MAX_EDGE, $width);
        $this->assertSame(1200, $height);
    }

    /** Portrait too: it is the long edge that is capped, not the width. */
    public function test_a_tall_photograph_is_capped_on_its_height(): void
    {
        $path = $this->makeImage('tall.jpg', 1500, 4000);

        $result = ImageDownscale::apply($path);

        $this->assertTrue($result['resized']);
        $this->assertSame(ImageDownscale::MAX_EDGE, $result['to'][1]);
        $this->assertLessThan(ImageDownscale::MAX_EDGE, $result['to'][0]);
    }

    public function test_it_actually_saves_bytes(): void
    {
        $path = $this->makeImage('heavy.jpg', 3500, 2500);

        $result = ImageDownscale::apply($path);

        $this->assertTrue($result['resized']);
        $this->assertLessThan($result['bytes_before'], $result['bytes_after']);
    }

    /** Already small enough: left exactly as it is, not re-encoded. */
    public function test_a_small_image_is_left_alone(): void
    {
        $path = $this->makeImage('small.jpg', 800, 600);
        $before = Storage::disk('public')->size($path);

        $result = ImageDownscale::apply($path);

        $this->assertFalse($result['resized']);
        $this->assertSame($before, Storage::disk('public')->size($path));
    }

    /** Exactly at the limit is not over it. */
    public function test_an_image_on_the_limit_is_left_alone(): void
    {
        $path = $this->makeImage('edge.jpg', ImageDownscale::MAX_EDGE, 900);

        $this->assertFalse(ImageDownscale::apply($path)['resized']);
    }

    public function test_a_png_keeps_its_format(): void
    {
        $path = $this->makeImage('big.png', 2400, 1800, 'png');

        $this->assertTrue(ImageDownscale::apply($path)['resized']);
        $this->assertSame(
            IMAGETYPE_PNG,
            getimagesize(Storage::disk('public')->path($path))[2],
        );
    }

    /** A file that is not an image is not something to fail on. */
    public function test_rubbish_is_left_alone_rather_than_thrown_over(): void
    {
        Storage::disk('public')->put('uploads/products/note.jpg', 'not an image');

        $this->assertFalse(ImageDownscale::apply('uploads/products/note.jpg')['resized']);
    }

    public function test_a_missing_file_is_left_alone(): void
    {
        $this->assertFalse(ImageDownscale::apply('uploads/products/nothing-here.jpg')['resized']);
    }
}
